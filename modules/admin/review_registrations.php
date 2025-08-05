<?php
include __DIR__ . '/../../config/database.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['student_ids'])) {
        $action = $_POST['bulk_action'];
        $student_ids = explode(',', $_POST['student_ids']);
        $student_ids = array_map('intval', $student_ids);
        $student_ids = array_filter($student_ids); // Remove empty values
        
        if (!empty($student_ids) && in_array($action, ['approve', 'reject'])) {
            $success_count = 0;
            $status = $action === 'approve' ? 'applicant' : 'disabled';
            
            foreach ($student_ids as $student_id) {
                $updateQuery = "UPDATE students SET status = $1 WHERE student_id = $2 AND status = 'under_registration'";
                $result = pg_query_params($connection, $updateQuery, [$status, $student_id]);
                
                if ($result && pg_affected_rows($result) > 0) {
                    $success_count++;
                    
                    // Get student email for notification
                    $emailQuery = "SELECT email, first_name, last_name FROM students WHERE student_id = $1";
                    $emailResult = pg_query_params($connection, $emailQuery, [$student_id]);
                    if ($student = pg_fetch_assoc($emailResult)) {
                        sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $action === 'approve', '');
                        
                        // Add admin notification
                        $student_name = $student['first_name'] . ' ' . $student['last_name'];
                        $notification_msg = "Registration " . ($action === 'approve' ? 'approved' : 'rejected') . " for student: " . $student_name . " (ID: " . $student_id . ")";
                        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
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
        $student_id = intval($_POST['student_id']);
        $action = $_POST['action'];
        $remarks = trim($_POST['remarks'] ?? '');
        
        if ($action === 'approve') {
            // Update student status to applicant
            $updateQuery = "UPDATE students SET status = 'applicant' WHERE student_id = $1";
            $result = pg_query_params($connection, $updateQuery, [$student_id]);
            
            if ($result) {
                // Get student email for notification
                $emailQuery = "SELECT email, first_name, last_name FROM students WHERE student_id = $1";
                $emailResult = pg_query_params($connection, $emailQuery, [$student_id]);
                $student = pg_fetch_assoc($emailResult);
                
                // Send approval email
                sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], true, $remarks);
                
                // Add admin notification
                $student_name = $student['first_name'] . ' ' . $student['last_name'];
                $notification_msg = "Registration approved for student: " . $student_name . " (ID: " . $student_id . ")";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                
                $_SESSION['success_message'] = "Registration approved successfully!";
            }
        } elseif ($action === 'reject') {
            // Update student status to disabled
            $updateQuery = "UPDATE students SET status = 'disabled' WHERE student_id = $1";
            $result = pg_query_params($connection, $updateQuery, [$student_id]);
            
            if ($result) {
                // Get student email for notification
                $emailQuery = "SELECT email, first_name, last_name FROM students WHERE student_id = $1";
                $emailResult = pg_query_params($connection, $emailQuery, [$student_id]);
                $student = pg_fetch_assoc($emailResult);
                
                // Send rejection email
                sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], false, $remarks);
                
                // Add admin notification
                $student_name = $student['first_name'] . ' ' . $student['last_name'];
                $notification_msg = "Registration rejected for student: " . $student_name . " (ID: " . $student_id . ")" . ($remarks ? " - Reason: " . $remarks : "");
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                
                $_SESSION['success_message'] = "Registration rejected.";
            }
        }
        
        header("Location: review_registrations.php");
        exit;
    }
}

function sendApprovalEmail($email, $firstName, $lastName, $approved, $remarks = '') {
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
            $mail->Body    = "
                <h3>Registration Approved!</h3>
                <p>Dear {$firstName} {$lastName},</p>
                <p>Your EducAid registration has been <strong>approved</strong>. You can now log in to your account and proceed with your application.</p>
                " . (!empty($remarks) ? "<p><strong>Admin Notes:</strong> {$remarks}</p>" : "") . "
                <p>You can log in at: <a href='http://localhost/EducAid/unified_login.php'>EducAid Login</a></p>
                <p>Best regards,<br>EducAid Admin Team</p>
            ";
        } else {
            $mail->Subject = 'EducAid Registration Update';
            $mail->Body    = "
                <h3>Registration Status Update</h3>
                <p>Dear {$firstName} {$lastName},</p>
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

$whereClause = implode(' AND ', $whereConditions);

// Valid sort columns
$validSorts = ['application_date', 'first_name', 'last_name'];
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

// Fetch pending registrations with pagination
$query = "SELECT s.*, b.name as barangay_name, u.name as university_name, yl.name as year_level_name,
                 ef.file_path as enrollment_form_path, ef.original_filename
          FROM students s
          LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
          LEFT JOIN universities u ON s.university_id = u.university_id
          LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
          LEFT JOIN enrollment_forms ef ON s.student_id = ef.student_id
          WHERE $whereClause
          ORDER BY s.$sort_by $sort_order
          LIMIT $limit OFFSET $offset";

$result = pg_query_params($connection, $query, $params);
$pendingRegistrations = [];
while ($row = pg_fetch_assoc($result)) {
    $pendingRegistrations[] = $row;
}

// Fetch filter options
$barangays = pg_fetch_all(pg_query($connection, "SELECT barangay_id, name FROM barangays ORDER BY name"));
$universities = pg_fetch_all(pg_query($connection, "SELECT university_id, name FROM universities ORDER BY name"));
$yearLevels = pg_fetch_all(pg_query($connection, "SELECT year_level_id, name FROM year_levels ORDER BY sort_order"));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Registrations - EducAid Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../assets/css/admin/homepage.css">
    <link rel="stylesheet" href="../../assets/css/admin/sidebar.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
        .filter-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
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
        .document-viewer-container {
            position: relative;
            height: 70vh;
            overflow: hidden;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #f8f9fa;
            cursor: grab;
        }
        .document-viewer-container.dragging {
            cursor: grabbing;
        }
        .document-viewer-image {
            max-width: none;
            max-height: none;
            transition: transform 0.2s ease;
            user-select: none;
            -webkit-user-drag: none;
        }
        .zoom-controls {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
            background: rgba(0,0,0,0.7);
            border-radius: 8px;
            padding: 5px;
            display: flex;
            gap: 5px;
        }
        .zoom-btn {
            background: rgba(255,255,255,0.9);
            border: none;
            border-radius: 4px;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s;
        }
        .zoom-btn:hover {
            background: white;
        }
        .zoom-info {
            position: absolute;
            bottom: 10px;
            left: 10px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        .fullscreen-btn {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 10;
            background: rgba(0,0,0,0.7);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div id="wrapper">
        <?php include '../../includes/admin/admin_sidebar.php'; ?>
        
        <section class="home-section" id="mainContent">
            <nav>
                <div class="sidebar-toggle px-4 py-3">
                    <i class="bi bi-list" id="menu-toggle"></i>
                </div>
            </nav>

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
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="review_registrations.php" class="btn btn-outline-secondary">Clear</a>
                            </div>
                        </div>
                    </form>
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
                                    <th>Documents</th>
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
                                                        <?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?>
                                                    </div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($registration['unique_student_id']); ?></small>
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
                                            <?php if ($registration['enrollment_form_path']): ?>
                                                <button type="button" class="btn btn-outline-primary btn-sm" 
                                                        onclick="viewDocument('<?php echo htmlspecialchars($registration['enrollment_form_path']); ?>', '<?php echo htmlspecialchars($registration['original_filename']); ?>')">
                                                    <i class="bi bi-file-earmark"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-success btn-sm" 
                                                        onclick="showActionModal(<?php echo $registration['student_id']; ?>, 'approve', '<?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?>')">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm" 
                                                        onclick="showActionModal(<?php echo $registration['student_id']; ?>, 'reject', '<?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?>')">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info btn-sm" 
                                                        onclick="viewDetails(<?php echo $registration['student_id']; ?>)">
                                                    <i class="bi bi-eye"></i>
                                                </button>
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

    <!-- Document Viewer Modal -->
    <div class="modal fade" id="documentModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentModalTitle">Document Viewer</h5>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="downloadDocument()" id="downloadBtn" style="display: none;">
                            <i class="bi bi-download"></i> Download
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-0">
                    <div id="documentContainer"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin/sidebar.js"></script>
    
    <script>
        let selectedStudents = [];
        let currentDocumentPath = '';
        
        // Document viewer variables
        let zoomLevel = 1;
        let isDragging = false;
        let startX, startY;
        let translateX = 0;
        let translateY = 0;

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

        function viewDocument(filePath, filename) {
            currentDocumentPath = filePath;
            const container = document.getElementById('documentContainer');
            document.getElementById('documentModalTitle').textContent = `Document: ${filename}`;
            
            // Reset zoom variables
            zoomLevel = 1;
            translateX = 0;
            translateY = 0;
            
            // Check if it's an image or PDF
            const extension = filename.split('.').pop().toLowerCase();
            
            if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
                container.innerHTML = `
                    <div class="document-viewer-container" id="imageContainer">
                        <div class="zoom-controls">
                            <button class="zoom-btn" onclick="zoomIn()" title="Zoom In">
                                <i class="bi bi-zoom-in"></i>
                            </button>
                            <button class="zoom-btn" onclick="zoomOut()" title="Zoom Out">
                                <i class="bi bi-zoom-out"></i>
                            </button>
                            <button class="zoom-btn" onclick="resetZoom()" title="Reset Zoom">
                                <i class="bi bi-arrows-fullscreen"></i>
                            </button>
                            <button class="zoom-btn" onclick="fitToScreen()" title="Fit to Screen">
                                <i class="bi bi-aspect-ratio"></i>
                            </button>
                        </div>
                        <button class="fullscreen-btn" onclick="toggleFullscreen()" title="Fullscreen">
                            <i class="bi bi-fullscreen"></i>
                        </button>
                        <div class="zoom-info" id="zoomInfo">100%</div>
                        <img src="${filePath}" id="documentImage" class="document-viewer-image" alt="Document" 
                             onload="initializeImageViewer()" onerror="handleImageError()">
                    </div>
                `;
                document.getElementById('downloadBtn').style.display = 'inline-block';
            } else if (extension === 'pdf') {
                container.innerHTML = `
                    <div class="text-center p-5">
                        <div class="alert alert-info">
                            <i class="bi bi-file-earmark-pdf fs-1"></i>
                            <h5 class="mt-3">PDF Document</h5>
                            <p>Click the button below to open the PDF in a new tab for better viewing experience.</p>
                        </div>
                        <a href="${filePath}" target="_blank" class="btn btn-primary btn-lg">
                            <i class="bi bi-box-arrow-up-right"></i> Open PDF in New Tab
                        </a>
                    </div>
                `;
                document.getElementById('downloadBtn').style.display = 'inline-block';
            } else {
                container.innerHTML = `
                    <div class="text-center p-5">
                        <div class="alert alert-warning">
                            <i class="bi bi-file-earmark fs-1"></i>
                            <h5 class="mt-3">Document Preview Not Available</h5>
                            <p>This file format cannot be previewed in the browser.</p>
                        </div>
                        <a href="${filePath}" target="_blank" class="btn btn-primary">
                            <i class="bi bi-download"></i> Download Document
                        </a>
                    </div>
                `;
                document.getElementById('downloadBtn').style.display = 'inline-block';
            }
            
            new bootstrap.Modal(document.getElementById('documentModal')).show();
        }

        function initializeImageViewer() {
            const image = document.getElementById('documentImage');
            const container = document.getElementById('imageContainer');
            
            // Set initial position to center
            fitToScreen();
            
            // Add event listeners for pan functionality
            container.addEventListener('mousedown', startDrag);
            container.addEventListener('mousemove', drag);
            container.addEventListener('mouseup', endDrag);
            container.addEventListener('mouseleave', endDrag);
            
            // Add wheel event for zoom
            container.addEventListener('wheel', handleWheel);
            
            // Prevent context menu
            image.addEventListener('contextmenu', e => e.preventDefault());
        }

        function handleImageError() {
            document.getElementById('documentContainer').innerHTML = `
                <div class="text-center p-5">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle fs-1"></i>
                        <h5 class="mt-3">Error Loading Image</h5>
                        <p>The image could not be loaded. It may be corrupted or in an unsupported format.</p>
                    </div>
                    <a href="${currentDocumentPath}" target="_blank" class="btn btn-primary">
                        <i class="bi bi-download"></i> Try Downloading
                    </a>
                </div>
            `;
        }

        function updateImageTransform() {
            const image = document.getElementById('documentImage');
            if (image) {
                image.style.transform = `translate(${translateX}px, ${translateY}px) scale(${zoomLevel})`;
                document.getElementById('zoomInfo').textContent = Math.round(zoomLevel * 100) + '%';
            }
        }

        function zoomIn() {
            zoomLevel = Math.min(zoomLevel * 1.25, 5);
            updateImageTransform();
        }

        function zoomOut() {
            zoomLevel = Math.max(zoomLevel * 0.8, 0.1);
            updateImageTransform();
        }

        function resetZoom() {
            zoomLevel = 1;
            translateX = 0;
            translateY = 0;
            updateImageTransform();
        }

        function fitToScreen() {
            const image = document.getElementById('documentImage');
            const container = document.getElementById('imageContainer');
            
            if (image && container) {
                const containerRect = container.getBoundingClientRect();
                const imageRect = image.getBoundingClientRect();
                
                const scaleX = (containerRect.width - 40) / image.naturalWidth;
                const scaleY = (containerRect.height - 40) / image.naturalHeight;
                
                zoomLevel = Math.min(scaleX, scaleY, 1);
                translateX = 0;
                translateY = 0;
                updateImageTransform();
            }
        }

        function toggleFullscreen() {
            const modal = document.getElementById('documentModal');
            if (!document.fullscreenElement) {
                modal.requestFullscreen().catch(err => {
                    console.log('Error attempting to enable fullscreen:', err);
                });
            } else {
                document.exitFullscreen();
            }
        }

        function startDrag(e) {
            if (e.target.classList.contains('zoom-btn') || e.target.closest('.zoom-btn')) return;
            
            isDragging = true;
            startX = e.clientX - translateX;
            startY = e.clientY - translateY;
            document.getElementById('imageContainer').classList.add('dragging');
            e.preventDefault();
        }

        function drag(e) {
            if (!isDragging) return;
            
            translateX = e.clientX - startX;
            translateY = e.clientY - startY;
            updateImageTransform();
            e.preventDefault();
        }

        function endDrag() {
            isDragging = false;
            const container = document.getElementById('imageContainer');
            if (container) {
                container.classList.remove('dragging');
            }
        }

        function handleWheel(e) {
            e.preventDefault();
            
            const delta = e.deltaY > 0 ? 0.9 : 1.1;
            const newZoom = Math.max(0.1, Math.min(5, zoomLevel * delta));
            
            // Calculate zoom center point
            const rect = e.currentTarget.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            // Adjust translation to zoom towards cursor
            const factor = newZoom / zoomLevel;
            translateX = x - (x - translateX) * factor;
            translateY = y - (y - translateY) * factor;
            
            zoomLevel = newZoom;
            updateImageTransform();
        }

        function downloadDocument() {
            if (currentDocumentPath) {
                const link = document.createElement('a');
                link.href = currentDocumentPath;
                link.download = '';
                link.click();
            }
        }

        // Handle fullscreen changes
        document.addEventListener('fullscreenchange', function() {
            const fullscreenBtn = document.querySelector('.fullscreen-btn i');
            if (fullscreenBtn) {
                fullscreenBtn.className = document.fullscreenElement ? 'bi bi-fullscreen-exit' : 'bi bi-fullscreen';
            }
        });

        // Reset zoom when modal is closed
        document.getElementById('documentModal').addEventListener('hidden.bs.modal', function() {
            zoomLevel = 1;
            translateX = 0;
            translateY = 0;
            currentDocumentPath = '';
            document.getElementById('downloadBtn').style.display = 'none';
        });
    </script>
</body>
</html>

<?php pg_close($connection); ?>
