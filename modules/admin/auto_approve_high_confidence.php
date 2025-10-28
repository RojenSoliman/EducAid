<?php
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/student_notification_helper.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || $input['action'] !== 'auto_approve_high_confidence') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$min_confidence = $input['min_confidence'] ?? 85;

try {
    // Begin transaction
    pg_query($connection, "BEGIN");
    
    // Get high confidence registrations
    $query = "SELECT s.student_id, s.first_name, s.last_name, s.extension_name, s.email,
                     COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) as confidence_score
              FROM students s 
              WHERE s.status = 'under_registration' 
              AND COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) >= $1";
    
    $result = pg_query_params($connection, $query, [$min_confidence]);
    
    if (!$result) {
        throw new Exception("Database error: " . pg_last_error($connection));
    }
    
    $approved_count = 0;
    $students_to_approve = [];
    
    while ($row = pg_fetch_assoc($result)) {
        $students_to_approve[] = $row;
    }
    
    // Process each student
    foreach ($students_to_approve as $student) {
        // Update student status to applicant
        $updateQuery = "UPDATE students SET status = 'applicant' WHERE student_id = $1";
        $updateResult = pg_query_params($connection, $updateQuery, [$student['student_id']]);
        
        if ($updateResult) {
            // Move files from temp to permanent storage using FileManagementService
            require_once __DIR__ . '/../../services/FileManagementService.php';
            $fileService = new FileManagementService($connection);
            $fileMoveResult = $fileService->moveTemporaryFilesToPermanent($student['student_id']);
            
            if (!$fileMoveResult['success']) {
                error_log("Auto-approve FileManagement: Error moving files for student " . $student['student_id'] . ": " . implode(', ', $fileMoveResult['errors']));
            }
            
            // Send approval email
            sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $student['extension_name'], true, 'Auto-approved based on high confidence score (' . number_format($student['confidence_score'], 1) . '%)');
            
            // Add admin notification
            $student_name = trim($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name']);
            $notification_msg = "Auto-approved registration for: " . $student_name . " (ID: " . $student['student_id'] . ") - Confidence: " . number_format($student['confidence_score'], 1) . "%";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            
            // Add student notification
            createStudentNotification(
                $connection,
                $student['student_id'],
                'Registration Auto-Approved!',
                'Great news! Your registration has been automatically approved based on your submitted documents. You can now proceed as an applicant.',
                'success',
                'high',
                'student_dashboard.php'
            );
            
            $approved_count++;
        }
    }
    
    // Commit transaction
    pg_query($connection, "COMMIT");
    
    echo json_encode([
        'success' => true, 
        'count' => $approved_count,
        'message' => "Successfully auto-approved $approved_count high-confidence registrations"
    ]);
    
} catch (Exception $e) {
    // Rollback transaction
    pg_query($connection, "ROLLBACK");
    
    error_log("Auto-approval error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error during auto-approval process'
    ]);
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
        }

        $mail->send();
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

pg_close($connection);
?>