<?php
session_start();
include '../../config/database.php';

if (!isset($_SESSION['admin_username']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../../unified_login.php");
    exit;
}

$uploadId = intval($_POST['upload_id']);
$gradingSystem = $_POST['grading_system'];
$overallStatus = $_POST['overall_status'];
$adminNotes = $_POST['admin_notes'] ?? '';
$adminId = $_SESSION['admin_id'];
$grades = $_POST['grades'] ?? [];

try {
    // Begin transaction
    pg_query($connection, "BEGIN");
    
    // First, delete any existing extracted grades for this upload
    pg_query_params($connection, "DELETE FROM extracted_grades WHERE upload_id = $1", [$uploadId]);
    
    // Insert manually entered grades
    foreach ($grades as $grade) {
        if (empty($grade['subject']) || empty($grade['value'])) {
            continue; // Skip empty rows
        }
        
        $subject = trim($grade['subject']);
        $gradeValue = $grade['value'];
        
        // Convert grade to numeric and percentage based on system
        $numericGrade = convertToNumeric($gradeValue, $gradingSystem);
        $percentageGrade = convertToPercentage($gradeValue, $gradingSystem);
        $isPassing = ($numericGrade <= 3.00 && $percentageGrade >= 75.0);
        
        $insertGradeQuery = "INSERT INTO extracted_grades 
                            (upload_id, subject_name, grade_value, grade_numeric, grade_percentage, 
                             extraction_confidence, is_passing, manual_entry) 
                            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)";
        
        $result = pg_query_params($connection, $insertGradeQuery, [
            $uploadId,
            $subject,
            $gradeValue,
            $numericGrade,
            $percentageGrade,
            100, // Manual entry has 100% confidence
            $isPassing,
            true
        ]);
        
        if (!$result) {
            throw new Exception('Failed to insert grade: ' . pg_last_error($connection));
        }
    }
    
    // Update the grade upload record
    $updateQuery = "UPDATE grade_uploads SET 
                   validation_status = $1,
                   admin_reviewed = TRUE,
                   admin_notes = $2,
                   reviewed_by = $3,
                   reviewed_at = CURRENT_TIMESTAMP,
                   ocr_confidence = 100,
                   grading_system_used = $4
                   WHERE upload_id = $5";
    
    $result = pg_query_params($connection, $updateQuery, [
        $overallStatus,
        $adminNotes,
        $adminId,
        $gradingSystem,
        $uploadId
    ]);
    
    if (!$result) {
        throw new Exception('Failed to update grade upload: ' . pg_last_error($connection));
    }
    
    // Get student info for notification
    $studentQuery = "SELECT s.student_id, s.first_name, s.last_name 
                    FROM students s 
                    JOIN grade_uploads gu ON s.student_id = gu.student_id 
                    WHERE gu.upload_id = $1";
    $studentResult = pg_query_params($connection, $studentQuery, [$uploadId]);
    $student = pg_fetch_assoc($studentResult);
    
    if ($student) {
        $statusMessage = '';
        switch($overallStatus) {
            case 'passed':
                $statusMessage = 'Your grades have been manually reviewed and approved by administration.';
                break;
            case 'failed':
                $statusMessage = 'Your grades have been reviewed but do not meet the minimum requirements. Please contact administration for guidance.';
                break;
            case 'conditional':
                $statusMessage = 'Your grades are under conditional review. Administration will contact you with next steps.';
                break;
        }
        
        $fullMessage = $statusMessage . ($adminNotes ? ' Admin note: ' . $adminNotes : '');
        
        $notifQuery = "INSERT INTO notifications (student_id, message) VALUES ($1, $2)";
        pg_query_params($connection, $notifQuery, [$student['student_id'], $fullMessage]);
    }
    
    // Commit transaction
    pg_query($connection, "COMMIT");
    
    $_SESSION['success_message'] = 'Manual grade entry completed successfully. ' . count($grades) . ' grades saved.';
    header('Location: validate_grades.php');
    exit;
    
} catch (Exception $e) {
    // Rollback transaction
    pg_query($connection, "ROLLBACK");
    
    $_SESSION['error_message'] = 'Error saving manual grades: ' . $e->getMessage();
    header('Location: validate_grades.php');
    exit;
}

function convertToNumeric($gradeValue, $gradingSystem) {
    switch($gradingSystem) {
        case 'gpa':
            return floatval($gradeValue);
            
        case 'percentage':
            return percentageToGPA(floatval($gradeValue));
            
        case 'letter':
            $letterGrades = [
                'A' => 1.25,
                'B' => 2.00,
                'C' => 2.75,
                'D' => 3.50,
                'F' => 5.00
            ];
            return $letterGrades[$gradeValue] ?? 5.00;
            
        default:
            return 5.00;
    }
}

function convertToPercentage($gradeValue, $gradingSystem) {
    switch($gradingSystem) {
        case 'percentage':
            return floatval($gradeValue);
            
        case 'gpa':
            return gpaToPercentage(floatval($gradeValue));
            
        case 'letter':
            $letterPercentages = [
                'A' => 91.25,
                'B' => 80.00,
                'C' => 77.25,
                'D' => 70.00,
                'F' => 60.00
            ];
            return $letterPercentages[$gradeValue] ?? 60.00;
            
        default:
            return 60.00;
    }
}

function percentageToGPA($percentage) {
    if ($percentage >= 98) return 1.00;
    if ($percentage >= 95) return 1.25;
    if ($percentage >= 92) return 1.50;
    if ($percentage >= 89) return 1.75;
    if ($percentage >= 86) return 2.00;
    if ($percentage >= 83) return 2.25;
    if ($percentage >= 80) return 2.50;
    if ($percentage >= 77) return 2.75;
    if ($percentage >= 75) return 3.00;
    if ($percentage >= 70) return 3.50;
    if ($percentage >= 65) return 4.00;
    return 5.00;
}

function gpaToPercentage($gpa) {
    if ($gpa <= 1.00) return 98.0;
    if ($gpa <= 1.25) return 95.0;
    if ($gpa <= 1.50) return 92.0;
    if ($gpa <= 1.75) return 89.0;
    if ($gpa <= 2.00) return 86.0;
    if ($gpa <= 2.25) return 83.0;
    if ($gpa <= 2.50) return 80.0;
    if ($gpa <= 2.75) return 77.0;
    if ($gpa <= 3.00) return 75.0;
    if ($gpa <= 3.50) return 70.0;
    if ($gpa <= 4.00) return 65.0;
    return 60.0;
}
?>