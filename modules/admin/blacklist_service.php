<?php
include __DIR__ . '/../../config/database.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

$admin_id = $_SESSION['admin_id'];

// Get admin info
$adminQuery = pg_query_params($connection, "SELECT email, first_name, last_name FROM admins WHERE admin_id = $1", [$admin_id]);
$admin = pg_fetch_assoc($adminQuery);

if (!$admin) {
    echo json_encode(['status' => 'error', 'message' => 'Admin not found']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Step 1: Initiate blacklist process - verify password and send OTP
    if ($action === 'initiate_blacklist') {
        $student_id = intval($_POST['student_id']);
        $password = $_POST['admin_password'];
        $reason_category = $_POST['reason_category'];
        $detailed_reason = trim($_POST['detailed_reason'] ?? '');
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        
        // Verify admin password
        if (!password_verify($password, $admin['password'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid admin password']);
            exit;
        }
        
        // Validate inputs
        $validReasons = ['fraudulent_activity', 'academic_misconduct', 'system_abuse', 'other'];
        if (!in_array($reason_category, $validReasons)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid reason category']);
            exit;
        }
        
        // Check if student exists and is not already blacklisted
        $studentCheck = pg_query_params($connection, 
            "SELECT student_id, first_name, last_name, email, status FROM students WHERE student_id = $1", 
            [$student_id]
        );
        $student = pg_fetch_assoc($studentCheck);
        
        if (!$student) {
            echo json_encode(['status' => 'error', 'message' => 'Student not found']);
            exit;
        }
        
        if ($student['status'] === 'blacklisted') {
            echo json_encode(['status' => 'error', 'message' => 'Student is already blacklisted']);
            exit;
        }
        
        // Generate OTP
        $otp = sprintf('%06d', rand(0, 999999));
        $expires_at = date('Y-m-d H:i:s', time() + 300); // 5 minutes
        
        // Store session data
        $session_data = json_encode([
            'student_id' => $student_id,
            'reason_category' => $reason_category,
            'detailed_reason' => $detailed_reason,
            'admin_notes' => $admin_notes
        ]);
        
        // Clean old verifications for this admin
        pg_query_params($connection, 
            "DELETE FROM admin_blacklist_verifications WHERE admin_id = $1 AND expires_at < NOW()", 
            [$admin_id]
        );
        
        // Insert new verification record
        $insertResult = pg_query_params($connection,
            "INSERT INTO admin_blacklist_verifications (admin_id, student_id, otp, email, expires_at, session_data) 
             VALUES ($1, $2, $3, $4, $5, $6)",
            [$admin_id, $student_id, $otp, $admin['email'], $expires_at, $session_data]
        );
        
        if (!$insertResult) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to create verification record']);
            exit;
        }
        
        // Send OTP email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'dilucayaka02@gmail.com';
            $mail->Password = 'jlld eygl hksj flvg';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('dilucayaka02@gmail.com', 'EducAid Security');
            $mail->addAddress($admin['email']);
            $mail->isHTML(true);
            $mail->Subject = 'CRITICAL: Blacklist Authorization Required';
            $mail->Body = "
                <h3 style='color: #dc3545;'>🚨 BLACKLIST AUTHORIZATION REQUIRED</h3>
                <p>Hello {$admin['first_name']},</p>
                <p>You are attempting to <strong>permanently blacklist</strong> the following student:</p>
                <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #dc3545; margin: 15px 0;'>
                    <strong>Student:</strong> {$student['first_name']} {$student['last_name']}<br>
                    <strong>Email:</strong> {$student['email']}<br>
                    <strong>Reason:</strong> " . ucwords(str_replace('_', ' ', $reason_category)) . "
                </div>
                <p><strong>Your authorization code is:</strong></p>
                <div style='font-size: 24px; font-weight: bold; color: #dc3545; text-align: center; 
                     background: #fff; border: 2px solid #dc3545; padding: 10px; margin: 10px 0;'>
                    {$otp}
                </div>
                <p><strong>⚠️ WARNING:</strong> This action is IRREVERSIBLE. The student will be permanently blocked from the system.</p>
                <p><em>Code expires in 5 minutes.</em></p>
                <hr>
                <small>If you did not initiate this action, please contact system administrator immediately.</small>
            ";

            $mail->send();
            
            echo json_encode([
                'status' => 'otp_sent',
                'message' => 'Security code sent to your email. Please check your inbox.',
                'student_name' => $student['first_name'] . ' ' . $student['last_name']
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to send security code']);
        }
        exit;
    }
    
    // Step 2: Verify OTP and complete blacklist
    if ($action === 'complete_blacklist') {
        $student_id = intval($_POST['student_id']);
        $otp = $_POST['otp'];
        
        // Get verification record
        $verifyQuery = pg_query_params($connection,
            "SELECT * FROM admin_blacklist_verifications 
             WHERE admin_id = $1 AND student_id = $2 AND otp = $3 AND expires_at > NOW() AND used = false",
            [$admin_id, $student_id, $otp]
        );
        
        $verification = pg_fetch_assoc($verifyQuery);
        
        if (!$verification) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired security code']);
            exit;
        }
        
        // Parse session data
        $session_data = json_decode($verification['session_data'], true);
        
        // Begin transaction
        pg_query($connection, "BEGIN");
        
        try {
            // Update student status to blacklisted
            $updateStudent = pg_query_params($connection,
                "UPDATE students SET status = 'blacklisted' WHERE student_id = $1",
                [$student_id]
            );
            
            if (!$updateStudent) {
                throw new Exception('Failed to update student status');
            }
            
            // Insert blacklist record
            $insertBlacklist = pg_query_params($connection,
                "INSERT INTO blacklisted_students (student_id, reason_category, detailed_reason, blacklisted_by, admin_email, admin_notes) 
                 VALUES ($1, $2, $3, $4, $5, $6)",
                [
                    $student_id,
                    $session_data['reason_category'],
                    $session_data['detailed_reason'],
                    $admin_id,
                    $admin['email'],
                    $session_data['admin_notes']
                ]
            );
            
            if (!$insertBlacklist) {
                throw new Exception('Failed to create blacklist record');
            }
            
            // Mark verification as used
            pg_query_params($connection,
                "UPDATE admin_blacklist_verifications SET used = true WHERE id = $1",
                [$verification['id']]
            );
            
            // Add admin notification
            $studentName = '';
            $studentQuery = pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$student_id]);
            if ($student = pg_fetch_assoc($studentQuery)) {
                $studentName = $student['first_name'] . ' ' . $student['last_name'];
            }
            
            $notification_msg = "BLACKLIST: {$studentName} has been permanently blacklisted by {$admin['first_name']} {$admin['last_name']}";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            
            pg_query($connection, "COMMIT");
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Student has been successfully blacklisted'
            ]);
            
        } catch (Exception $e) {
            pg_query($connection, "ROLLBACK");
            echo json_encode(['status' => 'error', 'message' => 'Failed to blacklist student: ' . $e->getMessage()]);
        }
        
        exit;
    }
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
?>