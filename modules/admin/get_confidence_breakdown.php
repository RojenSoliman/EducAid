<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$student_id = intval($_GET['id']);

// Get detailed confidence breakdown
function getConfidenceBreakdown($connection, $student_id) {
    $breakdown = [];
    $total_score = 0;
    
    // Get student info
    $studentQuery = "SELECT first_name, last_name, extension_name, email, mobile, bdate, sex, barangay_id, university_id, year_level_id, status FROM students WHERE student_id = $1";
    $studentResult = pg_query_params($connection, $studentQuery, [$student_id]);
    $student = pg_fetch_assoc($studentResult);
    
    // 1. Personal Information Score (30 points)
    $personal_score = 0;
    $personal_details = [];
    
    $fields = [
        'first_name' => 'First Name',
        'last_name' => 'Last Name', 
        'email' => 'Email',
        'mobile' => 'Mobile Number',
        'bdate' => 'Birth Date',
        'sex' => 'Gender',
        'barangay_id' => 'Barangay',
        'university_id' => 'University',
        'year_level_id' => 'Year Level'
    ];
    
    $required_fields_complete = 0;
    foreach ($fields as $field => $label) {
        $is_complete = !empty($student[$field]);
        $personal_details[] = [
            'field' => $label,
            'status' => $is_complete ? 'Complete' : 'Missing',
            'value' => $is_complete ? '✓' : '✗'
        ];
        if ($is_complete) $required_fields_complete++;
    }
    
    // Extension name is optional, so don't count it
    if ($required_fields_complete == 9) {
        $personal_score = 30;
    }
    
    $breakdown['personal'] = [
        'score' => $personal_score,
        'max_score' => 30,
        'details' => $personal_details,
        'summary' => "$required_fields_complete/9 required fields complete"
    ];
    $total_score += $personal_score;
    
    // 2. Document Upload Score (40 points)
    $document_score = 0;
    $document_details = [];
    
    // Check enrollment form
    $enrollmentQuery = "SELECT COUNT(*) as count FROM enrollment_forms WHERE student_id = $1";
    $enrollmentResult = pg_query_params($connection, $enrollmentQuery, [$student_id]);
    $has_enrollment = pg_fetch_result($enrollmentResult, 0, 0) > 0;
    
    $document_details[] = [
        'document' => 'Enrollment Assessment Form',
        'status' => $has_enrollment ? 'Uploaded' : 'Missing',
        'points' => $has_enrollment ? 10 : 0
    ];
    if ($has_enrollment) $document_score += 10;
    
    // Check other documents
    $doc_types = [
        'certificate_of_indigency' => 'Certificate of Indigency',
        'letter_to_mayor' => 'Letter to Mayor',
        'eaf' => 'Enrollment Assessment Form'
    ];
    
    foreach ($doc_types as $type => $label) {
        $docQuery = "SELECT COUNT(*) as count FROM documents WHERE student_id = $1 AND type = $2";
        $docResult = pg_query_params($connection, $docQuery, [$student_id, $type]);
        $has_doc = pg_fetch_result($docResult, 0, 0) > 0;
        
        $document_details[] = [
            'document' => $label,
            'status' => $has_doc ? 'Uploaded' : 'Missing',
            'points' => $has_doc ? 10 : 0
        ];
        if ($has_doc) $document_score += 10;
    }
    
    $breakdown['documents'] = [
        'score' => $document_score,
        'max_score' => 40,
        'details' => $document_details,
        'summary' => count(array_filter($document_details, function($d) { return $d['points'] > 0; })) . "/4 required documents uploaded"
    ];
    $total_score += $document_score;
    
    // 3. OCR Confidence Score (20 points)
    $ocrQuery = "SELECT COALESCE(AVG(ocr_confidence), 0) as avg_confidence FROM documents WHERE student_id = $1 AND ocr_confidence > 0";
    $ocrResult = pg_query_params($connection, $ocrQuery, [$student_id]);
    $avg_ocr = pg_fetch_result($ocrResult, 0, 0);
    $ocr_score = $avg_ocr * 0.20; // Convert to 20 point scale
    
    $breakdown['ocr'] = [
        'score' => $ocr_score,
        'max_score' => 20,
        'details' => [
            ['aspect' => 'Average OCR Confidence', 'value' => number_format($avg_ocr, 1) . '%']
        ],
        'summary' => $avg_ocr > 0 ? "Average document readability: " . number_format($avg_ocr, 1) . "%" : "No OCR analysis available"
    ];
    $total_score += $ocr_score;
    
    // 4. Email Verification Bonus (10 points)
    $email_score = ($student['status'] != 'under_registration') ? 10 : 0;
    
    $breakdown['verification'] = [
        'score' => $email_score,
        'max_score' => 10,
        'details' => [
            ['aspect' => 'Email Verification', 'value' => $email_score > 0 ? 'Completed' : 'Pending']
        ],
        'summary' => $email_score > 0 ? "Email verified during registration" : "Email verification pending"
    ];
    $total_score += $email_score;
    
    $breakdown['total'] = [
        'score' => $total_score,
        'max_score' => 100,
        'percentage' => $total_score
    ];
    
    return $breakdown;
}

try {
    $breakdown = getConfidenceBreakdown($connection, $student_id);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'breakdown' => $breakdown]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error getting confidence breakdown']);
}

pg_close($connection);
?>