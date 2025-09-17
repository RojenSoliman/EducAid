<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

include '../../config/database.php';
header('Content-Type: application/json');

$uploadId = intval($_GET['upload_id'] ?? 0);
$textOnly = isset($_GET['text_only']) && $_GET['text_only'] == '1';

if (!$uploadId) {
    echo json_encode(['success' => false, 'message' => 'Invalid upload ID']);
    exit;
}

try {
    // Get upload details
    $uploadQuery = "SELECT * FROM grade_uploads WHERE upload_id = $1";
    $uploadResult = pg_query_params($connection, $uploadQuery, [$uploadId]);
    
    if (!$upload = pg_fetch_assoc($uploadResult)) {
        throw new Exception('Grade upload not found');
    }
    
    // If only text is requested, return just the extracted text
    if ($textOnly) {
        echo json_encode([
            'success' => true,
            'confidence' => floatval($upload['ocr_confidence']),
            'extracted_text' => $upload['extracted_text'] ?? 'No text was extracted from this document.'
        ]);
        exit;
    }
    
    // Get extracted grades
    $gradesQuery = "SELECT * FROM extracted_grades WHERE upload_id = $1 ORDER BY subject_name";
    $gradesResult = pg_query_params($connection, $gradesQuery, [$uploadId]);
    
    $grades = [];
    $totalGPA = 0;
    $totalPercentage = 0;
    $count = 0;
    
    while ($grade = pg_fetch_assoc($gradesResult)) {
        $grades[] = [
            'subject_name' => $grade['subject_name'],
            'grade_value' => $grade['grade_value'],
            'grade_numeric' => floatval($grade['grade_numeric']),
            'grade_percentage' => floatval($grade['grade_percentage']),
            'is_passing' => $grade['is_passing'] === 't'
        ];
        
        $totalGPA += floatval($grade['grade_numeric']);
        $totalPercentage += floatval($grade['grade_percentage']);
        $count++;
    }
    
    $averageGPA = $count > 0 ? round($totalGPA / $count, 2) : 0;
    $averagePercentage = $count > 0 ? round($totalPercentage / $count, 1) : 0;
    
    echo json_encode([
        'success' => true,
        'confidence' => floatval($upload['ocr_confidence']),
        'status' => $upload['validation_status'],
        'extracted_text' => $upload['extracted_text'],
        'grades' => $grades,
        'average_gpa' => $averageGPA,
        'average_percentage' => $averagePercentage
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
