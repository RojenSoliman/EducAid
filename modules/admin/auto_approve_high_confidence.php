<?php
include __DIR__ . '/../../config/database.php';
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
            // Move enrollment form from temporary to permanent location
            $enrollmentQuery = "SELECT file_path, original_filename FROM enrollment_forms WHERE student_id = $1";
            $enrollmentResult = pg_query_params($connection, $enrollmentQuery, [$student['student_id']]);
            
            if ($enrollmentRow = pg_fetch_assoc($enrollmentResult)) {
                $tempPath = $enrollmentRow['file_path'];
                $originalFilename = $enrollmentRow['original_filename'];
                
                if (file_exists($tempPath)) {
                    $permanentDir = '../../assets/uploads/student/enrollment_forms/';
                    if (!is_dir($permanentDir)) {
                        mkdir($permanentDir, 0755, true);
                    }
                    
                    $newFilename = $student['student_id'] . '_' . $originalFilename;
                    $permanentPath = $permanentDir . $newFilename;
                    
                    if (rename($tempPath, $permanentPath)) {
                        // Update database with new path
                        pg_query_params($connection, 
                            "UPDATE enrollment_forms SET file_path = $1 WHERE student_id = $2", 
                            [$permanentPath, $student['student_id']]
                        );
                    }
                }
            }
            
            // Move documents from temporary to permanent locations
            $documentsQuery = "SELECT document_id, type, file_path FROM documents WHERE student_id = $1";
            $documentsResult = pg_query_params($connection, $documentsQuery, [$student['student_id']]);
            
            while ($docRow = pg_fetch_assoc($documentsResult)) {
                $tempDocPath = $docRow['file_path'];
                $docType = $docRow['type'];
                $docId = $docRow['document_id'];
                
                // Determine permanent directory based on document type
                if ($docType === 'letter_to_mayor') {
                    $permanentDocDir = '../../assets/uploads/student/letter_to_mayor/';
                } elseif ($docType === 'certificate_of_indigency') {
                    $permanentDocDir = '../../assets/uploads/student/indigency/';
                } elseif ($docType === 'eaf') {
                    $permanentDocDir = '../../assets/uploads/student/enrollment_forms/';
                } else {
                    continue; // Skip unknown document types
                }
                
                // Create permanent directory if it doesn't exist
                if (!is_dir($permanentDocDir)) {
                    mkdir($permanentDocDir, 0755, true);
                }
                
                // Define permanent path
                $filename = basename($tempDocPath);
                $permanentDocPath = $permanentDocDir . $filename;
                
                // Move file from temporary to permanent location
                if (file_exists($tempDocPath) && rename($tempDocPath, $permanentDocPath)) {
                    // Update database with permanent path
                    pg_query_params($connection, 
                        "UPDATE documents SET file_path = $1 WHERE document_id = $2", 
                        [$permanentDocPath, $docId]
                    );
                }
            }
            
            // Send approval email
            sendApprovalEmail($student['email'], $student['first_name'], $student['last_name'], $student['extension_name'], true, 'Auto-approved based on high confidence score (' . number_format($student['confidence_score'], 1) . '%)');
            
            // Add admin notification
            $student_name = trim($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name']);
            $notification_msg = "Auto-approved registration for: " . $student_name . " (ID: " . $student['student_id'] . ") - Confidence: " . number_format($student['confidence_score'], 1) . "%";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            
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