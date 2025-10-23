<?php
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/DocumentService.php';

session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Initialize DocumentService
$docService = new DocumentService($connection);

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['student_ids'])) {
        $action = $_POST['bulk_action'];
        $student_ids = explode(',', $_POST['student_ids']);
        $student_ids = array_map('trim', $student_ids); // Remove whitespace instead of intval
        $student_ids = array_filter($student_ids); // Remove empty values
        
        if (!empty($student_ids) && in_array($action, ['approve', 'reject'])) {
            $success_count = 0;
            
            foreach ($student_ids as $student_id) {
                if ($action === 'approve') {
                    // Update student status to applicant
                    $updateQuery = "UPDATE students SET status = 'applicant' WHERE student_id = $1 AND status = 'under_registration'";
                    $result = pg_query_params($connection, $updateQuery, [$student_id]);
                    
                    if ($result && pg_affected_rows($result) > 0) {
                        // Use DocumentService to move all documents from temp to permanent storage
                        $moveResult = $docService->moveToPermStorage($student_id);
                        
                        if ($moveResult['success']) {
                            error_log("DocumentService: Successfully moved " . $moveResult['moved_count'] . " documents for student $student_id");
                        } else {
                            error_log("DocumentService: Error moving documents for student $student_id - " . ($moveResult['error'] ?? 'Unknown error'));
                        }
                        
                        $success_count++;
                        
                        // Get student email for notification
                        $emailQuery = "SELECT email, first_name, last_name, extension_name FROM students WHERE student_id = $1";
                        $emailResult = pg_query_params($connection, $emailQuery, [$student_id]);
                        if ($student = pg_fetch_assoc($emailResult)) {
                            sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $student['extension_name'], true, '');
                            
                            // Add admin notification
                            $student_name = trim($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name']);
                            $notification_msg = "Registration approved for student: " . $student_name . " (ID: " . $student_id . ")";
                            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                        }
                    }
                } elseif ($action === 'reject') {
                    // Get student information before deletion
                    $studentQuery = "SELECT email, first_name, last_name, extension_name FROM students WHERE student_id = $1 AND status = 'under_registration'";
                    $studentResult = pg_query_params($connection, $studentQuery, [$student_id]);
                    $student = pg_fetch_assoc($studentResult);
                    
                    if ($student) {
                        // Get and delete ALL temporary files before deleting database records
                        
                        // 1. Delete enrollment form file
                        $enrollmentQuery = "SELECT file_path FROM enrollment_forms WHERE student_id = $1";
                        $enrollmentResult = pg_query_params($connection, $enrollmentQuery, [$student_id]);
                        
                        if ($enrollmentRow = pg_fetch_assoc($enrollmentResult)) {
                            $tempFilePath = $enrollmentRow['file_path'];
                            if (file_exists($tempFilePath)) {
                                unlink($tempFilePath);
                            }
                        }
                        
                        // 2. Delete document files (letter to mayor and certificate of indigency)
                        $documentsQuery = "SELECT file_path FROM documents WHERE student_id = $1";
                        $documentsResult = pg_query_params($connection, $documentsQuery, [$student_id]);
                        
                        while ($docRow = pg_fetch_assoc($documentsResult)) {
                            $docFilePath = $docRow['file_path'];
                            if (file_exists($docFilePath)) {
                                unlink($docFilePath);
                            }
                        }
                        
                        // 3. Clean up any remaining files in organized temp directories
                        $tempDirs = [
                            __DIR__ . '/../../assets/uploads/temp/enrollment_forms/',
                            __DIR__ . '/../../assets/uploads/temp/letter_mayor/',
                            __DIR__ . '/../../assets/uploads/temp/indigency/'
                        ];
                        
                        foreach ($tempDirs as $dir) {
                            if (is_dir($dir)) {
                                $files = glob($dir . $student_id . '_*');
                                foreach ($files as $file) {
                                    if (is_file($file)) {
                                        unlink($file);
                                    }
                                }
                            }
                        }
                        
                        // Delete related records first (due to foreign key constraints)
                        pg_query_params($connection, "DELETE FROM qr_logs WHERE student_id = $1", [$student_id]);
                        pg_query_params($connection, "DELETE FROM distributions WHERE student_id = $1", [$student_id]);
                        pg_query_params($connection, "DELETE FROM enrollment_forms WHERE student_id = $1", [$student_id]);
                        pg_query_params($connection, "DELETE FROM documents WHERE student_id = $1", [$student_id]);
                        
                        // Finally delete the student record
                        $deleteResult = pg_query_params($connection, "DELETE FROM students WHERE student_id = $1", [$student_id]);
                        
                        if ($deleteResult) {
                            $success_count++;
                            
                            // Send rejection email
                            sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $student['extension_name'], false, '');
                            
                            // Add admin notification
                            $student_name = trim($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name']);
                            $notification_msg = "Registration rejected and removed for student: " . $student_name . " (ID: " . $student_id . ") - Slot freed up and files deleted";
                            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                        }
                    }
                }
            }
            
            $action_text = $action === 'approve' ? 'approved' : 'rejected';
            $_SESSION['success_message'] = "$success_count registration(s) $action_text successfully!";
        }
        
        header("Location: review_registrations.php?" . http_build_query($_GET));
        exit;
    }
    
    // Handle individual actions
    if (isset($_POST['action']) && isset($_POST['student_id'])) {
        $student_id = trim($_POST['student_id']); // Remove intval for TEXT student_id
        $action = $_POST['action'];
        $remarks = trim($_POST['remarks'] ?? '');
        
        if ($action === 'approve') {
            // Update student status to applicant and mark as new registration (no upload needed)
            $updateQuery = "UPDATE students SET status = 'applicant', needs_document_upload = FALSE WHERE student_id = $1";
            $result = pg_query_params($connection, $updateQuery, [$student_id]);
            
            if ($result) {
                // Use DocumentService to move all documents from temp to permanent storage
                $moveResult = $docService->moveToPermStorage($student_id);
                
                if ($moveResult['success']) {
                    error_log("DocumentService: Successfully moved " . $moveResult['moved_count'] . " documents for student $student_id");
                } else {
                    error_log("DocumentService: Error moving documents for student $student_id - " . ($moveResult['error'] ?? 'Unknown error'));
                }
                
                // Get student email for notification
                $emailQuery = "SELECT email, first_name, last_name, extension_name FROM students WHERE student_id = $1";
                $emailResult = pg_query_params($connection, $emailQuery, [$student_id]);
                $student = pg_fetch_assoc($emailResult);

                // Send approval email
                sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $student['extension_name'], true, $remarks);

                // Add admin notification
                $student_name = trim($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name']);
                $notification_msg = "Registration approved for student: " . $student_name . " (ID: " . $student_id . ")";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                
                // Log to audit trail
                $audit_query = "INSERT INTO audit_logs (
                    user_id, user_type, username, event_type, event_category, 
                    action_description, status, ip_address, affected_table, 
                    affected_record_id, metadata
                ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)";
                
                pg_query_params($connection, $audit_query, [
                    $_SESSION['admin_id'] ?? null,
                    'admin',
                    $_SESSION['admin_username'] ?? 'unknown',
                    'applicant_approved',
                    'applicant_management',
                    "Student $student_id registered and approved",
                    'success',
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    'students',
                    null,
                    json_encode([
                        'student_id' => $student_id,
                        'student_name' => $student_name,
                        'remarks' => $remarks,
                        'files_moved' => $moveResult['moved_count'] ?? 0
                    ])
                ]);
                
                $_SESSION['success_message'] = "Registration approved successfully!";
            }
        } elseif ($action === 'reject') {
            // Get student information before deletion
            $studentQuery = "SELECT email, first_name, last_name, extension_name FROM students WHERE student_id = $1";
            $studentResult = pg_query_params($connection, $studentQuery, [$student_id]);
            $student = pg_fetch_assoc($studentResult);
            
            if ($student) {
                $student_name = trim($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name']);
                $files_deleted = 0;
                
                // Get all document file paths from documents table
                $documentsQuery = "SELECT file_path, ocr_text_path, verification_data_path FROM documents WHERE student_id = $1";
                $documentsResult = pg_query_params($connection, $documentsQuery, [$student_id]);
                
                while ($docRow = pg_fetch_assoc($documentsResult)) {
                    // Delete main file
                    if (!empty($docRow['file_path']) && file_exists($docRow['file_path'])) {
                        unlink($docRow['file_path']);
                        $files_deleted++;
                    }
                    
                    // Delete OCR text file
                    if (!empty($docRow['ocr_text_path']) && file_exists($docRow['ocr_text_path'])) {
                        unlink($docRow['ocr_text_path']);
                        $files_deleted++;
                    }
                    
                    // Delete verification JSON file
                    if (!empty($docRow['verification_data_path']) && file_exists($docRow['verification_data_path'])) {
                        unlink($docRow['verification_data_path']);
                        $files_deleted++;
                    }
                    
                    // Delete associated files (.tsv, .confidence.json) based on main file path
                    if (!empty($docRow['file_path'])) {
                        $base_path = pathinfo($docRow['file_path'], PATHINFO_DIRNAME) . '/' . pathinfo($docRow['file_path'], PATHINFO_FILENAME);
                        
                        // Delete .tsv file
                        $tsv_file = $base_path . '.tsv';
                        if (file_exists($tsv_file)) {
                            unlink($tsv_file);
                            $files_deleted++;
                        }
                        
                        // Delete .confidence.json file
                        $confidence_file = $base_path . '.confidence.json';
                        if (file_exists($confidence_file)) {
                            unlink($confidence_file);
                            $files_deleted++;
                        }
                    }
                }
                
                // Clean up any remaining files in temp directories using glob patterns
                $tempDirs = [
                    __DIR__ . '/../../assets/uploads/temp/id_pictures/',
                    __DIR__ . '/../../assets/uploads/temp/enrollment_forms/',
                    __DIR__ . '/../../assets/uploads/temp/letter_mayor/',
                    __DIR__ . '/../../assets/uploads/temp/indigency/',
                    __DIR__ . '/../../assets/uploads/temp/grades/'
                ];
                
                foreach ($tempDirs as $dir) {
                    if (is_dir($dir)) {
                        // Get all files matching student_id pattern (including associated files)
                        $files = glob($dir . $student_id . '_*');
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                unlink($file);
                                $files_deleted++;
                            }
                        }
                    }
                }
                
                // Delete database records (respecting foreign key constraints)
                pg_query_params($connection, "DELETE FROM qr_logs WHERE student_id = $1", [$student_id]);
                pg_query_params($connection, "DELETE FROM distributions WHERE student_id = $1", [$student_id]);
                pg_query_params($connection, "DELETE FROM documents WHERE student_id = $1", [$student_id]);
                pg_query_params($connection, "DELETE FROM grade_uploads WHERE student_id = $1", [$student_id]);
                
                // Finally delete the student record - this frees up the slot completely
                $deleteResult = pg_query_params($connection, "DELETE FROM students WHERE student_id = $1", [$student_id]);
                
                if ($deleteResult) {
                    // Send rejection email
                    sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $student['extension_name'], false, $remarks);
                    
                    // Add admin notification
                    $notification_msg = "Registration rejected for student: " . $student_name . " (ID: " . $student_id . ")" . ($remarks ? " - Reason: " . $remarks : "");
                    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                    
                    // Log to audit trail
                    $audit_query = "INSERT INTO audit_logs (
                        user_id, user_type, username, event_type, event_category, 
                        action_description, status, ip_address, affected_table, 
                        affected_record_id, metadata
                    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)";
                    
                    pg_query_params($connection, $audit_query, [
                        $_SESSION['admin_id'] ?? null,
                        'admin',
                        $_SESSION['admin_username'] ?? 'unknown',
                        'applicant_rejected',
                        'applicant_management',
                        "Student $student_id registration rejected and deleted",
                        'success',
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        'students',
                        null,
                        json_encode([
                            'student_id' => $student_id,
                            'student_name' => $student_name,
                            'remarks' => $remarks,
                            'files_deleted' => $files_deleted
                        ])
                    ]);
                    
                    $_SESSION['success_message'] = "Registration rejected. Student data removed and $files_deleted files deleted. Slot has been freed up.";
                } else {
                    $_SESSION['error_message'] = "Error rejecting registration.";
                }
            } else {
                $_SESSION['error_message'] = "Student not found.";
            }
        }
        
        header("Location: review_registrations.php");
        exit;
    }
}

function sendApprovalEmail($email, $firstName, $lastName, $extensionName, $approved, $remarks = '') {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'dilucayaka02@gmail.com'; // CHANGE for production
        $mail->Password   = 'jlld eygl hksj flvg';    // CHANGE for production
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('dilucayaka02@gmail.com', 'EducAid');
        $mail->addAddress($email);

        $mail->isHTML(true);
        
        if ($approved) {
            $mail->Subject = 'EducAid Registration Approved';
            $fullName = trim($firstName . ' ' . $lastName . ' ' . $extensionName);
            $mail->Body    = "
                <h3>Registration Approved!</h3>
                <p>Dear {$fullName},</p>
                <p>Your EducAid registration has been <strong>approved</strong>. You can now log in to your account and proceed with your application.</p>
                " . (!empty($remarks) ? "<p><strong>Admin Notes:</strong> {$remarks}</p>" : "") . "
                <p>You can log in at: <a href='http://localhost/EducAid/unified_login.php'>EducAid Login</a></p>
                <p>Best regards,<br>EducAid Admin Team</p>
            ";
        } else {
            $mail->Subject = 'EducAid Registration Update';
            $fullName = trim($firstName . ' ' . $lastName . ' ' . $extensionName);
            $mail->Body    = "
                <h3>Registration Status Update</h3>
                <p>Dear {$fullName},</p>
                <p>Thank you for your interest in EducAid. Unfortunately, your registration could not be approved at this time.</p>
                " . (!empty($remarks) ? "<p><strong>Reason:</strong> {$remarks}</p>" : "") . "
                <p>If you believe this is an error or would like to reapply, please contact our office.</p>
                <p>Best regards,<br>EducAid Admin Team</p>
            ";
        }

        $mail->send();
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

// Pagination and filtering
$limit = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Search and filter parameters
$search = trim($_GET['search'] ?? '');
$barangay_filter = $_GET['barangay'] ?? '';
$university_filter = $_GET['university'] ?? '';
$year_level_filter = $_GET['year_level'] ?? '';
$confidence_filter = $_GET['confidence'] ?? '';
$sort_by = $_GET['sort'] ?? 'application_date';
$sort_order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$whereConditions = ["s.status = 'under_registration'"];
$params = [];
$paramCount = 1;

if (!empty($search)) {
    $whereConditions[] = "(s.first_name ILIKE $" . $paramCount . " OR s.last_name ILIKE $" . $paramCount . " OR s.email ILIKE $" . $paramCount . ")";
    $params[] = "%$search%";
    $paramCount++;
}

if (!empty($barangay_filter)) {
    $whereConditions[] = "s.barangay_id = $" . $paramCount;
    $params[] = $barangay_filter;
    $paramCount++;
}

if (!empty($university_filter)) {
    $whereConditions[] = "s.university_id = $" . $paramCount;
    $params[] = $university_filter;
    $paramCount++;
}

if (!empty($year_level_filter)) {
    $whereConditions[] = "s.year_level_id = $" . $paramCount;
    $params[] = $year_level_filter;
    $paramCount++;
}

if (!empty($confidence_filter)) {
    if ($confidence_filter === 'very_high') {
        $whereConditions[] = "COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) >= 85";
    } elseif ($confidence_filter === 'high') {
        $whereConditions[] = "COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) >= 70 AND COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) < 85";
    } elseif ($confidence_filter === 'medium') {
        $whereConditions[] = "COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) >= 50 AND COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) < 70";
    } elseif ($confidence_filter === 'low') {
        $whereConditions[] = "COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) < 50";
    }
}

$whereClause = implode(' AND ', $whereConditions);

// Valid sort columns - add confidence_score
$validSorts = ['application_date', 'first_name', 'last_name', 'confidence_score'];
if (!in_array($sort_by, $validSorts)) $sort_by = 'application_date';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

// Count total records
$countQuery = "SELECT COUNT(*) FROM students s
               LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
               LEFT JOIN universities u ON s.university_id = u.university_id
               LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
               WHERE $whereClause";

$countResult = pg_query_params($connection, $countQuery, $params);
$totalRecords = intval(pg_fetch_result($countResult, 0, 0));
$totalPages = ceil($totalRecords / $limit);

// Fetch pending registrations with pagination including confidence scores
$query = "SELECT s.*, b.name as barangay_name, u.name as university_name, yl.name as year_level_name,
                 COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) as confidence_score,
                 get_confidence_level(COALESCE(s.confidence_score, calculate_confidence_score(s.student_id))) as confidence_level
          FROM students s
          LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
          LEFT JOIN universities u ON s.university_id = u.university_id
          LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
          WHERE $whereClause
          ORDER BY s.$sort_by $sort_order
          LIMIT $limit OFFSET $offset";

$result = pg_query_params($connection, $query, $params);
$pendingRegistrations = [];
while ($row = pg_fetch_assoc($result)) {
    $pendingRegistrations[] = $row;
}

// Handle AJAX requests for real-time updates
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    // Return only the table body content for AJAX updates
    ob_start();
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="fw-bold mb-1">Review Registrations</h1>
            <p class="text-muted mb-0">Review and approve/reject pending student registrations.</p>
        </div>
        <div class="text-end">
            <span class="badge bg-warning fs-6"><?php echo $totalRecords; ?> Pending</span>
        </div>
    </div>
    
    <tbody>
        <?php if (empty($pendingRegistrations)): ?>
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                    No pending registrations found.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($pendingRegistrations as $registration): ?>
                <tr>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input row-select" value="<?php echo $registration['student_id']; ?>" onchange="updateSelection()">
                    </td>
                    <td>
                        <?php if ($registration['photo_path']): ?>
                            <img src="<?php echo htmlspecialchars($registration['photo_path']); ?>" 
                                 alt="Student Photo" class="student-photo">
                        <?php else: ?>
                            <div class="student-photo bg-secondary d-flex align-items-center justify-content-center">
                                <i class="bi bi-person text-white"></i>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-bold"><?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?></div>
                        <small class="text-muted"><?php echo htmlspecialchars($registration['email']); ?></small>
                    </td>
                    <td>
                        <div class="fw-bold"><?php echo htmlspecialchars($registration['barangay_name'] ?? 'N/A'); ?></div>
                        <small class="text-muted"><?php echo htmlspecialchars($registration['university_name'] ?? 'N/A'); ?></small>
                    </td>
                    <td>
                        <?php
                        $confidence = floatval($registration['confidence_score']);
                        $level = $registration['confidence_level'];
                        $badgeClass = '';
                        switch($level) {
                            case 'Very High': $badgeClass = 'bg-success'; break;
                            case 'High': $badgeClass = 'bg-info'; break;
                            case 'Medium': $badgeClass = 'bg-warning'; break;
                            case 'Low': $badgeClass = 'bg-danger'; break;
                            default: $badgeClass = 'bg-secondary';
                        }
                        ?>
                        <div class="confidence-badge <?php echo $badgeClass; ?>" title="<?php echo $level; ?>">
                            <?php echo round($confidence, 1); ?>%
                        </div>
                        <div>
                            <small class="text-muted"><?php echo $level; ?></small>
                        </div>
                    </td>
                    <td>
                        <small class="text-muted"><?php echo date('M d, Y', strtotime($registration['created_at'])); ?></small>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-info btn-sm" 
                                    onclick="viewDetails('<?php echo $registration['student_id']; ?>')"
                                    title="View Details">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-success btn-sm" 
                                    onclick="showActionModal('<?php echo $registration['student_id']; ?>', 'approve', '<?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?>')"
                                    title="Approve">
                                <i class="bi bi-check-lg"></i>
                            </button>
                            <button class="btn btn-danger btn-sm" 
                                    onclick="showActionModal('<?php echo $registration['student_id']; ?>', 'reject', '<?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?>')"
                                    title="Reject">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
    
    <div class="pagination-info">
        Showing <?php echo min(($page - 1) * $limit + 1, $totalRecords); ?> to <?php echo min($page * $limit, $totalRecords); ?> of <?php echo $totalRecords; ?> entries
    </div>
    <?php
    echo ob_get_clean();
    exit;
}

// Fetch filter options
$barangays = pg_fetch_all(pg_query($connection, "SELECT barangay_id, name FROM barangays ORDER BY name"));
$universities = pg_fetch_all(pg_query($connection, "SELECT university_id, name FROM universities ORDER BY name"));
$yearLevels = pg_fetch_all(pg_query($connection, "SELECT year_level_id, name FROM year_levels ORDER BY sort_order"));
?>

<?php $page_title='Review Registrations'; $extra_css=[]; include '../../includes/admin/admin_head.php'; ?>
<style>
    /* existing page styles */
        .filter-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        /* Image Viewer Enhancements */
        .doc-viewer-wrapper { position: relative; background:#111; border-radius:8px; overflow:hidden; touch-action:none; user-select:none; }
        .doc-viewer-stage { position:relative; width:100%; height:70vh; max-height:800px; display:flex; align-items:center; justify-content:center; background:#111; }
        .doc-viewer-stage img { max-width:100%; max-height:100%; will-change: transform; cursor:grab; transition: filter .2s; z-index:1; }
        .doc-viewer-stage img:active { cursor:grabbing; }
        .doc-controls { position:absolute; top:10px; right:10px; display:flex; gap:6px; z-index:50; pointer-events:auto; }
        .doc-controls button { background:rgba(0,0,0,.55); color:#fff; border:1px solid rgba(255,255,255,.2); padding:6px 10px; border-radius:6px; font-size:14px; backdrop-filter: blur(4px); pointer-events:auto; }
        .doc-controls button:hover { background:rgba(255,255,255,.15); }
        .doc-zoom-indicator { position:absolute; left:12px; top:12px; background:rgba(0,0,0,.55); color:#fff; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600; letter-spacing:.5px; z-index:50; pointer-events:none; }
        .doc-hint { position:absolute; bottom:10px; left:50%; transform:translateX(-50%); background:rgba(0,0,0,.55); color:#fff; padding:4px 12px; border-radius:20px; font-size:12px; opacity:.85; z-index:50; pointer-events:none; }
        @media (max-width: 768px){ .doc-viewer-stage{ height:60vh; } }
        .table-responsive {
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        .table thead th {
            background: #495057;
            color: white;
            border: none;
            font-weight: 600;
        }
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .student-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .status-badge {
            font-size: 0.75em;
            padding: 4px 8px;
        }
        .bulk-actions {
            background: #e9ecef;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
        }
        .pagination-info {
            color: #6c757d;
            font-size: 0.9em;
        }
        .sort-link {
            color: white;
            text-decoration: none;
        }
        .sort-link:hover {
            color: #ffc107;
        }
        .sort-active {
            color: #ffc107 !important;
        }
        .confidence-badge {
            font-size: 0.8em;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 12px;
            display: inline-block;
            min-width: 50px;
            text-align: center;
        }
        .quick-actions {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .quick-actions h5 {
            color: white;
            margin-bottom: 5px;
        }
        .quick-actions small {
            color: rgba(255, 255, 255, 0.8);
        }
        .auto-approve-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .auto-approve-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
            color: white;
        }
        .refresh-btn {
            background: linear-gradient(45deg, #17a2b8, #6f42c1);
            border: none;
            color: white;
            font-weight: 600;
        }
        .refresh-btn:hover {
            color: white;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <?php include '../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include '../../includes/admin/admin_sidebar.php'; ?>
        <?php include '../../includes/admin/admin_header.php'; ?>
        
        <section class="home-section" id="mainContent">

            <div class="container-fluid py-4 px-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="fw-bold mb-1">Review Registrations</h1>
                        <p class="text-muted mb-0">Review and approve/reject pending student registrations.</p>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-warning fs-6"><?php echo $totalRecords; ?> Pending</span>
                    </div>
                </div>

                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <!-- Filters Section -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name or email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Barangay</label>
                            <select name="barangay" class="form-select">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['barangay_id']; ?>" <?php echo $barangay_filter == $barangay['barangay_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">University</label>
                            <select name="university" class="form-select">
                                <option value="">All Universities</option>
                                <?php foreach ($universities as $university): ?>
                                    <option value="<?php echo $university['university_id']; ?>" <?php echo $university_filter == $university['university_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($university['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Year Level</label>
                            <select name="year_level" class="form-select">
                                <option value="">All Years</option>
                                <?php foreach ($yearLevels as $yearLevel): ?>
                                    <option value="<?php echo $yearLevel['year_level_id']; ?>" <?php echo $year_level_filter == $yearLevel['year_level_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($yearLevel['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Confidence Level</label>
                            <select name="confidence" class="form-select">
                                <option value="">All Levels</option>
                                <option value="very_high" <?php echo $confidence_filter == 'very_high' ? 'selected' : ''; ?>>Very High (85%+)</option>
                                <option value="high" <?php echo $confidence_filter == 'high' ? 'selected' : ''; ?>>High (70-84%)</option>
                                <option value="medium" <?php echo $confidence_filter == 'medium' ? 'selected' : ''; ?>>Medium (50-69%)</option>
                                <option value="low" <?php echo $confidence_filter == 'low' ? 'selected' : ''; ?>>Low (<50%)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="review_registrations.php" class="btn btn-outline-secondary">Clear</a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Auto-Approve Section -->
                <div class="quick-actions">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-1"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h5>
                            <small>Streamline review process for high-confidence registrations</small>
                        </div>
                        <div class="d-flex gap-2">
                            <?php
                            // Count high confidence registrations
                            $highConfidenceQuery = "SELECT COUNT(*) FROM students s WHERE s.status = 'under_registration' AND COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) >= 85";
                            $highConfidenceResult = pg_query($connection, $highConfidenceQuery);
                            $highConfidenceCount = pg_fetch_result($highConfidenceResult, 0, 0);
                            ?>
                            <?php if ($highConfidenceCount > 0): ?>
                                <button type="button" class="btn auto-approve-btn" onclick="autoApproveHighConfidence()">
                                    <i class="bi bi-lightning"></i> Auto-Approve High Confidence 
                                    <span class="badge bg-white text-success ms-1"><?php echo $highConfidenceCount; ?></span>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-light" disabled>
                                    <i class="bi bi-lightning"></i> No High Confidence Registrations
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn refresh-btn" onclick="refreshConfidenceScores()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh Scores
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Bulk Actions (hidden by default) -->
                <div class="bulk-actions" id="bulkActions">
                    <div class="d-flex align-items-center gap-3">
                        <span><strong id="selectedCount">0</strong> selected</span>
                        <button type="button" class="btn btn-success btn-sm" onclick="bulkAction('approve')">
                            <i class="bi bi-check-circle"></i> Approve Selected
                        </button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="bulkAction('reject')">
                            <i class="bi bi-x-circle"></i> Reject Selected
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">
                            Clear Selection
                        </button>
                    </div>
                </div>

                <?php if (empty($pendingRegistrations)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-clipboard-check display-1 text-muted"></i>
                        <h3 class="mt-3 text-muted">No Pending Registrations</h3>
                        <p class="text-muted">
                            <?php if (!empty($search) || !empty($barangay_filter) || !empty($university_filter) || !empty($year_level_filter)): ?>
                                No registrations match your filter criteria. <a href="review_registrations.php">Clear filters</a>
                            <?php else: ?>
                                All registrations have been reviewed.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <!-- Results Table -->
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th width="40">
                                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                    </th>
                                    <th>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'first_name', 'order' => $sort_by === 'first_name' && $sort_order === 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link <?php echo $sort_by === 'first_name' ? 'sort-active' : ''; ?>">
                                            Name <?php if ($sort_by === 'first_name') echo $sort_order === 'ASC' ? '↑' : '↓'; ?>
                                        </a>
                                    </th>
                                    <th>Contact</th>
                                    <th>Barangay</th>
                                    <th>University</th>
                                    <th>Year</th>
                                    <th>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'application_date', 'order' => $sort_by === 'application_date' && $sort_order === 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link <?php echo $sort_by === 'application_date' ? 'sort-active' : ''; ?>">
                                            Applied <?php if ($sort_by === 'application_date') echo $sort_order === 'ASC' ? '↑' : '↓'; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'confidence_score', 'order' => $sort_by === 'confidence_score' && $sort_order === 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link <?php echo $sort_by === 'confidence_score' ? 'sort-active' : ''; ?>">
                                            Confidence <?php if ($sort_by === 'confidence_score') echo $sort_order === 'ASC' ? '↑' : '↓'; ?>
                                        </a>
                                    </th>
                                    <th width="150">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingRegistrations as $registration): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="row-select" value="<?php echo $registration['student_id']; ?>" onchange="updateSelection()">
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div>
                                                    <div class="fw-semibold">
                                                        <?php echo htmlspecialchars(trim($registration['first_name'] . ' ' . $registration['last_name'] . ' ' . $registration['extension_name'])); ?>
                                                    </div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($registration['student_id']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <div><?php echo htmlspecialchars($registration['email']); ?></div>
                                                <div class="text-muted"><?php echo htmlspecialchars($registration['mobile']); ?></div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($registration['barangay_name']); ?></td>
                                        <td>
                                            <div class="small">
                                                <?php echo htmlspecialchars($registration['university_name']); ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($registration['year_level_name']); ?></td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo date('M d, Y', strtotime($registration['application_date'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php 
                                            $score = $registration['confidence_score'];
                                            $level = $registration['confidence_level'];
                                            $badgeClass = '';
                                            if ($score >= 85) $badgeClass = 'bg-success';
                                            elseif ($score >= 70) $badgeClass = 'bg-primary';
                                            elseif ($score >= 50) $badgeClass = 'bg-warning';
                                            else $badgeClass = 'bg-danger';
                                            ?>
                                            <div class="d-flex align-items-center">
                                                <span class="badge <?php echo $badgeClass; ?> text-white me-1"><?php echo number_format($score, 1); ?>%</span>
                                                <small class="text-muted"><?php echo $level; ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-success btn-sm" 
                                                        onclick="showActionModal('<?php echo $registration['student_id']; ?>', 'approve', '<?php echo htmlspecialchars(trim($registration['first_name'] . ' ' . $registration['last_name'] . ' ' . $registration['extension_name'])); ?>')">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm" 
                                                        onclick="showActionModal('<?php echo $registration['student_id']; ?>', 'reject', '<?php echo htmlspecialchars(trim($registration['first_name'] . ' ' . $registration['last_name'] . ' ' . $registration['extension_name'])); ?>')">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info btn-sm" 
                                                        onclick="viewDetails('<?php echo $registration['student_id']; ?>')">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm" 
                                                        onclick="showBlacklistModal('<?php echo $registration['student_id']; ?>', '<?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($registration['email'], ENT_QUOTES); ?>', {
                                                            barangay: '<?php echo htmlspecialchars($registration['barangay'] ?? 'N/A', ENT_QUOTES); ?>',
                                                            university: '<?php echo htmlspecialchars($registration['university'] ?? 'N/A', ENT_QUOTES); ?>',
                                                            status: 'Under Registration'
                                                        })"
                                                        title="Blacklist Student">
                                                    <i class="bi bi-shield-exclamation"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <div class="pagination-info">
                                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRecords); ?> of <?php echo $totalRecords; ?> registrations
                            </div>
                            <nav>
                                <ul class="pagination mb-0">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $start = max(1, $page - 2);
                                    $end = min($totalPages, $page + 2);
                                    
                                    if ($start > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                                        </li>
                                        <?php if ($start > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $start; $i <= $end; $i++): ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($end < $totalPages): ?>
                                        <?php if ($end < $totalPages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Action Modal -->
    <div class="modal fade" id="actionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="actionModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="student_id" id="modal_student_id">
                        <input type="hidden" name="action" id="modal_action">
                        
                        <p id="action_message"></p>
                        
                        <div class="mb-3">
                            <label for="remarks" class="form-label">Remarks (Optional)</label>
                            <textarea class="form-control" name="remarks" id="remarks" rows="3" 
                                      placeholder="Add any comments or reasons for this decision..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" id="modal_confirm_btn">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Student Details Modal -->
    <div class="modal fade" id="studentDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Student Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="studentDetailsContent">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin/sidebar.js"></script>
    
    <script>
        let selectedStudents = [];
        
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.row-select');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateSelection();
        }

        function updateSelection() {
            const checkboxes = document.querySelectorAll('.row-select:checked');
            selectedStudents = Array.from(checkboxes).map(cb => cb.value);
            
            const count = selectedStudents.length;
            document.getElementById('selectedCount').textContent = count;
            document.getElementById('bulkActions').style.display = count > 0 ? 'block' : 'none';
            
            // Update select all checkbox
            const allCheckboxes = document.querySelectorAll('.row-select');
            const selectAll = document.getElementById('selectAll');
            selectAll.indeterminate = count > 0 && count < allCheckboxes.length;
            selectAll.checked = count === allCheckboxes.length && count > 0;
        }

        function clearSelection() {
            document.querySelectorAll('.row-select').forEach(cb => cb.checked = false);
            document.getElementById('selectAll').checked = false;
            updateSelection();
        }

        function bulkAction(action) {
            if (selectedStudents.length === 0) {
                alert('Please select students first.');
                return;
            }

            const actionText = action === 'approve' ? 'approve' : 'reject';
            const message = `Are you sure you want to ${actionText} ${selectedStudents.length} selected registration(s)?`;
            
            if (!confirm(message)) return;

            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="bulk_action" value="${action}">
                <input type="hidden" name="student_ids" value="${selectedStudents.join(',')}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function showActionModal(studentId, action, studentName) {
            document.getElementById('modal_student_id').value = studentId;
            document.getElementById('modal_action').value = action;
            
            const title = action === 'approve' ? 'Approve Registration' : 'Reject Registration';
            const message = action === 'approve' 
                ? `Are you sure you want to approve the registration for <strong>${studentName}</strong>? This will allow them to log in and proceed with their application.`
                : `Are you sure you want to reject the registration for <strong>${studentName}</strong>? This action cannot be undone and will free up a slot.`;
            
            document.getElementById('actionModalTitle').textContent = title;
            document.getElementById('action_message').innerHTML = message;
            
            const confirmBtn = document.getElementById('modal_confirm_btn');
            confirmBtn.className = action === 'approve' ? 'btn btn-success' : 'btn btn-danger';
            confirmBtn.textContent = action === 'approve' ? 'Approve' : 'Reject';
            
            new bootstrap.Modal(document.getElementById('actionModal')).show();
        }

        function viewDetails(studentId) {
            fetch(`get_student_details.php?id=${studentId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('studentDetailsContent').innerHTML = html;
                    new bootstrap.Modal(document.getElementById('studentDetailsModal')).show();
                })
                .catch(error => {
                    alert('Error loading student details. Please try again.');
                });
        }

        function autoApproveHighConfidence() {
            if (!confirm('Are you sure you want to auto-approve all registrations with Very High confidence scores (85%+)?\n\nThis action will approve all students who meet the criteria and cannot be undone.')) {
                return;
            }

            // Show loading state
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-spinner bi-spin"></i> Processing...';
            btn.disabled = true;

            fetch('auto_approve_high_confidence.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'auto_approve_high_confidence',
                    min_confidence: 85
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Successfully auto-approved ${data.count} high-confidence registrations!`);
                    location.reload();
                } else {
                    alert('Error during auto-approval: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error during auto-approval. Please try again.');
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        function refreshConfidenceScores() {
            if (!confirm('This will recalculate confidence scores for all pending registrations. Continue?')) {
                return;
            }

            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-spinner bi-spin"></i> Refreshing...';
            btn.disabled = true;

            fetch('refresh_confidence_scores.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Updated confidence scores for ${data.count} registrations!`);
                    location.reload();
                } else {
                    alert('Error refreshing scores: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error refreshing scores. Please try again.');
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        // Real-time updates
        let isUpdating = false;
        let lastUpdateData = null;

        function updateTableData() {
            if (isUpdating) return;
            isUpdating = true;

            const currentUrl = new URL(window.location);
            const params = new URLSearchParams(currentUrl.search);
            params.set('ajax', '1');

            fetch(window.location.pathname + '?' + params.toString())
                .then(response => response.text())
                .then(data => {
                    if (data !== lastUpdateData) {
                        // Parse the response to extract table content and stats
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = data;
                        
                        // Update table body
                        const newTableBody = tempDiv.querySelector('tbody');
                        const currentTableBody = document.querySelector('tbody');
                        if (newTableBody && currentTableBody && newTableBody.innerHTML !== currentTableBody.innerHTML) {
                            currentTableBody.innerHTML = newTableBody.innerHTML;
                        }

                        // Update pending count badge
                        const newBadge = tempDiv.querySelector('.badge.bg-warning');
                        const currentBadge = document.querySelector('.badge.bg-warning');
                        if (newBadge && currentBadge) {
                            currentBadge.textContent = newBadge.textContent;
                        }

                        // Update pagination info if it exists
                        const newPaginationInfo = tempDiv.querySelector('.pagination-info');
                        const currentPaginationInfo = document.querySelector('.pagination-info');
                        if (newPaginationInfo && currentPaginationInfo) {
                            currentPaginationInfo.textContent = newPaginationInfo.textContent;
                        }

                        lastUpdateData = data;
                    }
                })
                .catch(error => {
                    console.log('Update failed:', error);
                })
                .finally(() => {
                    isUpdating = false;
                    setTimeout(updateTableData, 100); // Update every 100ms
                });
        }

        // Start real-time updates when page loads
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(updateTableData, 100);
        });
    </script>

    <!-- Document Viewer Modal (lightweight) -->
    <div class="modal fade" id="documentViewerModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentViewerTitle">Document</h5>
                    <button type="button" class="btn btn-outline-primary btn-sm me-2" id="docDownloadBtn" style="display:none;">
                        <i class="bi bi-download"></i> Download
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="documentViewerBody">
                    <div class="text-center py-5 text-muted small">Loading document...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function viewStudentDocument(studentId, docType) {
            const modalEl = document.getElementById('documentViewerModal');
            const body = document.getElementById('documentViewerBody');
            const title = document.getElementById('documentViewerTitle');
            const dlBtn = document.getElementById('docDownloadBtn');
            body.innerHTML = '<div class="text-center py-5 text-muted small">Loading document...</div>';
            dlBtn.style.display = 'none';
            title.textContent = 'Document';
            
            fetch('get_student_document.php?student_id=' + encodeURIComponent(studentId) + '&type=' + encodeURIComponent(docType))
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { 
                        body.innerHTML = '<div class="alert alert-danger m-3">' + (data.message || 'Error loading document') + '</div>'; 
                        return; 
                    }
                    
                    title.textContent = data.documentName || data.filename || 'Document';
                    if (data.downloadUrl) { 
                        dlBtn.href = data.downloadUrl; 
                        dlBtn.style.display = 'inline-block'; 
                    }
                    
                    let filePath = data.filePath || data.file_path || '';
                    
                    // Fix path for module location: if path starts with assets/, prepend ../../
                    if (filePath.startsWith('assets/') || filePath.startsWith('modules/')) {
                        filePath = '../../' + filePath;
                    }
                    
                    const ext = filePath.split('.').pop().toLowerCase();
                    
                    // Debug logging
                    console.log('Document API response:', data);
                    console.log('Using filePath:', filePath);
                    
                    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                        body.innerHTML = `
                            <div class="doc-viewer-wrapper">
                                <div class="doc-zoom-indicator" data-zoom-indicator>100%</div>
                                <div class="doc-controls">
                                    <button type="button" data-zoom-in title="Zoom In"><i class="bi bi-zoom-in"></i></button>
                                    <button type="button" data-zoom-out title="Zoom Out"><i class="bi bi-zoom-out"></i></button>
                                    <button type="button" data-reset title="Reset"><i class="bi bi-arrow-counterclockwise"></i></button>
                                </div>
                                <div class="doc-viewer-stage" data-stage>
                                    <img src="${filePath}" alt="Document" draggable="false" loading="lazy" 
                                         onerror="this.parentElement.parentElement.innerHTML='<div class=\\'alert alert-danger m-3\\'>Image failed to load.<br>Path: <code>${filePath}</code><br>Original: <code>${data.debug_original_path || ''}</code></div>'">
                                </div>
                                <div class="doc-hint">Scroll to zoom • Drag to pan</div>
                            </div>
                        `;
                        setTimeout(() => initImageViewer(body.querySelector('.doc-viewer-wrapper')), 100);
                    } else if (ext === 'pdf') {
                        body.innerHTML = `<iframe src="${filePath}" style="width:100%;height:70vh;border:none;"></iframe>`;
                    } else {
                        body.innerHTML = `<div class="alert alert-info m-3">Cannot preview this file type. <a href="${data.downloadUrl || filePath}" class="alert-link">Download instead</a></div>`;
                    }
                })
                .catch(err => {
                    console.error(err);
                    body.innerHTML = '<div class="alert alert-danger m-3">Network error loading document.</div>';
                });
                
            new bootstrap.Modal(modalEl).show();
        }

        function initImageViewer(wrapper){
            if(!wrapper) return;
            const stage = wrapper.querySelector('[data-stage]');
            const img = stage.querySelector('img');
            const zoomIndicator = wrapper.querySelector('[data-zoom-indicator]');
            const controls = wrapper.querySelector('.doc-controls');
            let scale = 1;
            let minScale = 0.2;
            let maxScale = 8;
            let originX = 0; // translation
            let originY = 0;
            let isDragging = false;
            let startX=0, startY=0;
            let lastX=0, lastY=0;
            let pinchStartDist = 0;
            let pinchStartScale = 1;

            function applyTransform(){
                img.style.transform = `translate(${originX}px, ${originY}px) scale(${scale})`;
                if(zoomIndicator) zoomIndicator.textContent = Math.round(scale*100)+ '%';
            }
            function clamp(val,min,max){ return Math.min(Math.max(val,min),max); }
            function zoom(delta, centerX, centerY){
                const prevScale = scale;
                scale = clamp(scale * (delta>0?1.1:0.9), minScale, maxScale);
                // Adjust translation so zoom centers on pointer (if coordinates provided)
                if(centerX!=null && centerY!=null){
                    const rect = img.getBoundingClientRect();
                    const offsetX = centerX - (rect.left + rect.width/2);
                    const offsetY = centerY - (rect.top + rect.height/2);
                    originX -= offsetX * (scale/prevScale -1);
                    originY -= offsetY * (scale/prevScale -1);
                }
                applyTransform();
            }
            // Wheel zoom
            wrapper.addEventListener('wheel', e=>{
                e.preventDefault();
                zoom(e.deltaY, e.clientX, e.clientY);
            }, { passive:false });
            // Drag
            function startDrag(e){
                isDragging = true;
                startX = (e.touches? e.touches[0].clientX : e.clientX);
                startY = (e.touches? e.touches[0].clientY : e.clientY);
                lastX = originX; lastY = originY;
            }
            function moveDrag(e){
                if(!isDragging) return;
                const x = (e.touches? e.touches[0].clientX : e.clientX);
                const y = (e.touches? e.touches[0].clientY : e.clientY);
                originX = lastX + (x - startX);
                originY = lastY + (y - startY);
                applyTransform();
                e.preventDefault();
            }
            function endDrag(){ isDragging=false; }
            img.addEventListener('mousedown', startDrag);
            window.addEventListener('mousemove', moveDrag);
            window.addEventListener('mouseup', endDrag);

            img.addEventListener('touchstart', e=>{
                if(e.touches.length===1){ startDrag(e); }
                else if(e.touches.length===2){ // pinch start
                    pinchStartDist = Math.hypot(
                        e.touches[0].clientX - e.touches[1].clientX,
                        e.touches[0].clientY - e.touches[1].clientY
                    );
                    pinchStartScale = scale;
                }
            }, { passive:false });
            img.addEventListener('touchmove', e=>{
                if(e.touches.length===1 && isDragging){ moveDrag(e); }
                else if(e.touches.length===2){
                    e.preventDefault();
                    const newDist = Math.hypot(
                        e.touches[0].clientX - e.touches[1].clientX,
                        e.touches[0].clientY - e.touches[1].clientY
                    );
                    const factor = newDist / pinchStartDist;
                    scale = clamp(pinchStartScale * factor, minScale, maxScale);
                    applyTransform();
                }
            }, { passive:false });
            window.addEventListener('touchend', e=>{ if(e.touches.length===0) endDrag(); }, { passive:true });

            // Buttons
            if(controls){
                controls.addEventListener('click', e=>{
                    const btn = e.target.closest('button');
                    if (!btn) return;
                    const action = btn.getAttribute('data-zoom-in') !== null ? 'zoom-in' : 
                                   btn.getAttribute('data-zoom-out') !== null ? 'zoom-out' :
                                   btn.getAttribute('data-reset') !== null ? 'reset' : null;
                    if(action==='zoom-in') zoom(-1); // negative delta => zoom in
                    else if(action==='zoom-out') zoom(1);
                    else if(action==='reset'){ scale=1; originX=originY=0; applyTransform(); }
                });
            }
            // Prevent background scroll while interacting
            ['wheel','touchmove'].forEach(ev=>{
                wrapper.addEventListener(ev, e=>{ e.preventDefault(); }, { passive:false });
            });
            applyTransform();
        }
    </script>

    <!-- Include Blacklist Modal -->
    <?php include '../../includes/admin/blacklist_modal.php'; ?>
</body>
</html>

<?php pg_close($connection); ?>
