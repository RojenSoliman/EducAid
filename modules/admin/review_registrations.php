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

// Fetch pending registrations
$query = "SELECT s.*, b.name as barangay_name, u.name as university_name, yl.name as year_level_name,
                 ef.file_path as enrollment_form_path, ef.original_filename
          FROM students s
          LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
          LEFT JOIN universities u ON s.university_id = u.university_id
          LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
          LEFT JOIN enrollment_forms ef ON s.student_id = ef.student_id
          WHERE s.status = 'under_registration'
          ORDER BY s.application_date DESC";

$result = pg_query($connection, $query);
$pendingRegistrations = [];
while ($row = pg_fetch_assoc($result)) {
    $pendingRegistrations[] = $row;
}
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
        .registration-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .registration-header {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #ddd;
            border-radius: 8px 8px 0 0;
        }
        .registration-body {
            padding: 20px;
        }
        .student-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        .info-item {
            margin-bottom: 10px;
        }
        .info-label {
            font-weight: 600;
            color: #666;
            font-size: 0.9em;
        }
        .info-value {
            color: #333;
            margin-top: 2px;
        }
        .document-preview {
            max-width: 100%;
            max-height: 300px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .action-buttons {
            border-top: 1px solid #ddd;
            padding: 15px 20px;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
        }
        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        .alert-dismissible {
            margin-bottom: 20px;
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
                <h1 class="fw-bold mb-3">Review Registrations</h1>
                <p class="text-muted mb-4">Review and approve/reject pending student registrations.</p>

                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <?php if (empty($pendingRegistrations)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-clipboard-check display-1 text-muted"></i>
                        <h3 class="mt-3 text-muted">No Pending Registrations</h3>
                        <p class="text-muted">All registrations have been reviewed.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pendingRegistrations as $registration): ?>
                        <div class="registration-card">
                            <div class="registration-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-1">
                                            <?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['middle_name'] . ' ' . $registration['last_name']); ?>
                                        </h5>
                                        <small class="text-muted">
                                            Registered: <?php echo date('M d, Y g:i A', strtotime($registration['application_date'])); ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-warning">Pending Review</span>
                                </div>
                            </div>
                            
                            <div class="registration-body">
                                <div class="student-info">
                                    <div>
                                        <div class="info-item">
                                            <div class="info-label">Email</div>
                                            <div class="info-value"><?php echo htmlspecialchars($registration['email']); ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Mobile</div>
                                            <div class="info-value"><?php echo htmlspecialchars($registration['mobile']); ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Gender</div>
                                            <div class="info-value"><?php echo htmlspecialchars($registration['sex']); ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Birth Date</div>
                                            <div class="info-value"><?php echo date('M d, Y', strtotime($registration['bdate'])); ?></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="info-item">
                                            <div class="info-label">Barangay</div>
                                            <div class="info-value"><?php echo htmlspecialchars($registration['barangay_name']); ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">University</div>
                                            <div class="info-value"><?php echo htmlspecialchars($registration['university_name']); ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Year Level</div>
                                            <div class="info-value"><?php echo htmlspecialchars($registration['year_level_name']); ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Student ID</div>
                                            <div class="info-value"><?php echo htmlspecialchars($registration['unique_student_id']); ?></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($registration['enrollment_form_path']): ?>
                                    <div class="mb-3">
                                        <div class="info-label mb-2">Enrollment Assessment Form</div>
                                        <button type="button" class="btn btn-outline-primary btn-sm" 
                                                onclick="viewDocument('<?php echo htmlspecialchars($registration['enrollment_form_path']); ?>', '<?php echo htmlspecialchars($registration['original_filename']); ?>')">
                                            <i class="bi bi-eye"></i> View Document
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="action-buttons">
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-success" 
                                            onclick="showActionModal(<?php echo $registration['student_id']; ?>, 'approve', '<?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?>')">
                                        <i class="bi bi-check-circle"></i> Approve
                                    </button>
                                    <button type="button" class="btn btn-danger" 
                                            onclick="showActionModal(<?php echo $registration['student_id']; ?>, 'reject', '<?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?>')">
                                        <i class="bi bi-x-circle"></i> Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
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

    <!-- Document Viewer Modal -->
    <div class="modal fade" id="documentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentModalTitle">Document Viewer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="documentContainer"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin/sidebar.js"></script>
    
    <script>
        function showActionModal(studentId, action, studentName) {
            document.getElementById('modal_student_id').value = studentId;
            document.getElementById('modal_action').value = action;
            
            const title = action === 'approve' ? 'Approve Registration' : 'Reject Registration';
            const message = action === 'approve' 
                ? `Are you sure you want to approve the registration for <strong>${studentName}</strong>? This will allow them to log in and proceed with their application.`
                : `Are you sure you want to reject the registration for <strong>${studentName}</strong>? This action cannot be undone.`;
            
            document.getElementById('actionModalTitle').textContent = title;
            document.getElementById('action_message').innerHTML = message;
            
            const confirmBtn = document.getElementById('modal_confirm_btn');
            confirmBtn.className = action === 'approve' ? 'btn btn-success' : 'btn btn-danger';
            confirmBtn.textContent = action === 'approve' ? 'Approve' : 'Reject';
            
            new bootstrap.Modal(document.getElementById('actionModal')).show();
        }

        function viewDocument(filePath, filename) {
            const container = document.getElementById('documentContainer');
            document.getElementById('documentModalTitle').textContent = `Document: ${filename}`;
            
            // Check if it's an image or PDF
            const extension = filename.split('.').pop().toLowerCase();
            
            if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
                container.innerHTML = `<img src="${filePath}" class="document-preview" alt="Document Preview">`;
            } else if (extension === 'pdf') {
                container.innerHTML = `
                    <div class="alert alert-info">
                        <i class="bi bi-file-earmark-pdf"></i> PDF Document
                    </div>
                    <a href="${filePath}" target="_blank" class="btn btn-primary">
                        <i class="bi bi-download"></i> Open PDF in New Tab
                    </a>
                `;
            } else {
                container.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="bi bi-file-earmark"></i> Document format not previewable
                    </div>
                    <a href="${filePath}" target="_blank" class="btn btn-primary">
                        <i class="bi bi-download"></i> Download Document
                    </a>
                `;
            }
            
            new bootstrap.Modal(document.getElementById('documentModal')).show();
        }
    </script>
</body>
</html>

<?php pg_close($connection); ?>
