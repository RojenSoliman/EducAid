<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in (student or admin)
if (!isset($_SESSION['student_id']) && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

include '../../config/database.php';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$doc_type = $input['doc_type'] ?? '';

// For admins, they can pass student_id. For students, use their own session student_id
$student_id = isset($_SESSION['admin_id']) && !empty($input['student_id']) 
    ? $input['student_id'] 
    : ($_SESSION['student_id'] ?? null);

if (empty($doc_type) || empty($student_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters: doc_type and student_id']);
    exit;
}

$validation_data = [];

try {
    // Map document type to database type
    $db_type_map = [
        'id_picture' => 'id_picture',
        'grades' => 'academic_grades',
        'eaf' => 'eaf',
        'letter_to_mayor' => 'letter_to_mayor',
        'certificate_of_indigency' => 'certificate_of_indigency'
    ];
    
    $db_type = $db_type_map[$doc_type] ?? $doc_type;
    
    // Get basic document info from documents table by student_id and type
    $doc_query = pg_query_params($connection, 
        "SELECT * FROM documents WHERE student_id = $1 AND type = $2 ORDER BY upload_date DESC LIMIT 1",
        [$student_id, $db_type]
    );
    
    if (!$doc_query || pg_num_rows($doc_query) === 0) {
        echo json_encode(['success' => false, 'message' => 'Document not found in database']);
        exit;
    }
    
    $document = pg_fetch_assoc($doc_query);
    $file_path = $document['file_path'];
    $validation_data['ocr_confidence'] = $document['ocr_confidence'];
    $validation_data['upload_date'] = $document['upload_date'];
    
    // ID Picture - Get identity verification data
    if ($doc_type === 'id_picture') {
        $verify_json_path = $file_path . '.verify.json';
        if (file_exists($verify_json_path)) {
            $verify_data = json_decode(file_get_contents($verify_json_path), true);
            if ($verify_data) {
                // Get student info for verification
                $student_query = pg_query_params($connection,
                    "SELECT s.first_name, s.middle_name, s.last_name, yl.name as year_level, u.name as university_name
                     FROM students s
                     LEFT JOIN universities u ON s.university_id = u.university_id
                     LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
                     WHERE s.student_id = $1",
                    [$student_id]
                );
                $student_info = pg_fetch_assoc($student_query);
                
                // Parse verify.json for detailed validation (6-check structure)
                $validation_data['identity_verification'] = [
                    'first_name_match' => $verify_data['first_name_match'] ?? false,
                    'first_name_confidence' => $verify_data['confidence_scores']['first_name'] ?? 0,
                    'middle_name_match' => $verify_data['middle_name_match'] ?? false,
                    'middle_name_confidence' => $verify_data['confidence_scores']['middle_name'] ?? 0,
                    'last_name_match' => $verify_data['last_name_match'] ?? false,
                    'last_name_confidence' => $verify_data['confidence_scores']['last_name'] ?? 0,
                    'year_level_match' => $verify_data['year_level_match'] ?? false,
                    'year_level_confidence' => 0, // Year level is boolean, not percentage
                    'school_match' => $verify_data['university_match'] ?? false,
                    'school_confidence' => $verify_data['confidence_scores']['university'] ?? 0,
                    'official_keywords' => $verify_data['document_keywords_found'] ?? false,
                    'keywords_confidence' => $verify_data['confidence_scores']['document_keywords'] ?? 0,
                    'verification_score' => $verify_data['verification_score'] ?? 0,
                    'passed_checks' => $verify_data['summary']['passed_checks'] ?? 0,
                    'total_checks' => $verify_data['summary']['total_checks'] ?? 6,
                    'average_confidence' => $verify_data['summary']['average_confidence'] ?? round(($document['ocr_confidence'] ?? 0), 1),
                    'recommendation' => $verify_data['summary']['recommendation'] ?? 'No recommendation available'
                ];
            }
        }
        
        // Get OCR text
        $ocr_text_path = $file_path . '.ocr.txt';
        if (file_exists($ocr_text_path)) {
            $validation_data['extracted_text'] = file_get_contents($ocr_text_path);
        }
    }
    
    // Academic Grades - Get detailed grade validation
    elseif ($doc_type === 'grades') {
        // Get from grade_uploads table
        $grades_query = pg_query_params($connection,
            "SELECT * FROM grade_uploads WHERE student_id = $1 AND file_path = $2 ORDER BY upload_date DESC LIMIT 1",
            [$student_id, $file_path]
        );
        
        if ($grades_query && pg_num_rows($grades_query) > 0) {
            $grade_upload = pg_fetch_assoc($grades_query);
            $upload_id = $grade_upload['upload_id'];
            $validation_data['validation_status'] = $grade_upload['validation_status'];
            
            // Get extracted grades with confidence
            $extracted_query = pg_query_params($connection,
                "SELECT * FROM extracted_grades WHERE upload_id = $1 ORDER BY grade_id",
                [$upload_id]
            );
            
            $extracted_grades = [];
            while ($grade = pg_fetch_assoc($extracted_query)) {
                $extracted_grades[] = [
                    'subject_name' => $grade['subject_name'],
                    'grade_value' => $grade['grade_value'],
                    'extraction_confidence' => $grade['extraction_confidence'],
                    'is_passing' => $grade['is_passing']
                ];
            }
            
            if (!empty($extracted_grades)) {
                $validation_data['extracted_grades'] = $extracted_grades;
                
                // Calculate average confidence from extracted grades
                $total_conf = 0;
                foreach ($extracted_grades as $g) {
                    $total_conf += floatval($g['extraction_confidence'] ?? 0);
                }
                $validation_data['ocr_confidence'] = round($total_conf / count($extracted_grades), 1);
            }
        } else {
            // Fallback: check if OCR text exists
            $ocr_text_path = $file_path . '.ocr.txt';
            if (file_exists($ocr_text_path)) {
                $validation_data['extracted_text'] = file_get_contents($ocr_text_path);
            }
        }
    }
    
    // EAF, Letter, Certificate - Get OCR text and verification data
    else {
        // Check for verification data
        $verify_json_path = $file_path . '.verify.json';
        if (file_exists($verify_json_path)) {
            $verify_data = json_decode(file_get_contents($verify_json_path), true);
            if ($verify_data) {
                // Get student info for display
                $student_query = pg_query_params($connection,
                    "SELECT s.first_name, s.middle_name, s.last_name, yl.name as year_level, u.name as university_name, b.name as barangay_name
                     FROM students s
                     LEFT JOIN universities u ON s.university_id = u.university_id
                     LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
                     LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
                     WHERE s.student_id = $1",
                    [$student_id]
                );
                $student_info = pg_fetch_assoc($student_query);
                
                // Parse verify.json for detailed validation
                // Structure varies by document type:
                // - EAF: first_name, middle_name, last_name, year_level, university, document_keywords
                // - Letter/Certificate: first_name, middle_name, last_name, barangay, office_header, document_keywords
                
                if ($doc_type === 'eaf') {
                    $validation_data['identity_verification'] = [
                        'first_name_match' => $verify_data['first_name_match'] ?? false,
                        'first_name_confidence' => $verify_data['confidence_scores']['first_name'] ?? 0,
                        'middle_name_match' => $verify_data['middle_name_match'] ?? false,
                        'middle_name_confidence' => $verify_data['confidence_scores']['middle_name'] ?? 0,
                        'last_name_match' => $verify_data['last_name_match'] ?? false,
                        'last_name_confidence' => $verify_data['confidence_scores']['last_name'] ?? 0,
                        'year_level_match' => $verify_data['year_level_match'] ?? false,
                        'year_level_confidence' => 0,
                        'school_match' => $verify_data['university_match'] ?? false,
                        'school_confidence' => $verify_data['confidence_scores']['university'] ?? 0,
                        'official_keywords' => $verify_data['document_keywords_found'] ?? false,
                        'keywords_confidence' => $verify_data['confidence_scores']['document_keywords'] ?? 0,
                        'passed_checks' => $verify_data['summary']['passed_checks'] ?? 0,
                        'total_checks' => $verify_data['summary']['total_checks'] ?? 6,
                        'average_confidence' => $verify_data['summary']['average_confidence'] ?? round(($document['ocr_confidence'] ?? 0), 1),
                        'recommendation' => $verify_data['summary']['recommendation'] ?? 'No recommendation available',
                        'found_text_snippets' => $verify_data['found_text_snippets'] ?? []
                    ];
                } elseif (in_array($doc_type, ['letter_to_mayor', 'certificate_of_indigency'])) {
                    // Letter to Mayor uses 4 checks: first_name, last_name, barangay, mayor_header
                    // Certificate uses 5 checks: certificate_title, first_name, last_name, barangay, general_trias
                    
                    if ($doc_type === 'letter_to_mayor') {
                        $validation_data['identity_verification'] = [
                            'first_name_match' => $verify_data['first_name'] ?? false,
                            'first_name_confidence' => $verify_data['confidence_scores']['first_name'] ?? 0,
                            'last_name_match' => $verify_data['last_name'] ?? false,
                            'last_name_confidence' => $verify_data['confidence_scores']['last_name'] ?? 0,
                            'barangay_match' => $verify_data['barangay'] ?? false,
                            'barangay_confidence' => $verify_data['confidence_scores']['barangay'] ?? 0,
                            'office_header_found' => $verify_data['mayor_header'] ?? false,
                            'office_header_confidence' => $verify_data['confidence_scores']['mayor_header'] ?? 0,
                            'passed_checks' => $verify_data['summary']['passed_checks'] ?? 0,
                            'total_checks' => $verify_data['summary']['total_checks'] ?? 4,
                            'average_confidence' => $verify_data['summary']['average_confidence'] ?? round(($document['ocr_confidence'] ?? 0), 1),
                            'recommendation' => $verify_data['summary']['recommendation'] ?? 'No recommendation available',
                            'found_text_snippets' => $verify_data['found_text_snippets'] ?? [],
                            'document_type' => $doc_type
                        ];
                    } else {
                        // Certificate of Indigency
                        $validation_data['identity_verification'] = [
                            'certificate_title_found' => $verify_data['certificate_title'] ?? false,
                            'certificate_title_confidence' => $verify_data['confidence_scores']['certificate_title'] ?? 0,
                            'first_name_match' => $verify_data['first_name'] ?? false,
                            'first_name_confidence' => $verify_data['confidence_scores']['first_name'] ?? 0,
                            'last_name_match' => $verify_data['last_name'] ?? false,
                            'last_name_confidence' => $verify_data['confidence_scores']['last_name'] ?? 0,
                            'barangay_match' => $verify_data['barangay'] ?? false,
                            'barangay_confidence' => $verify_data['confidence_scores']['barangay'] ?? 0,
                            'general_trias_found' => $verify_data['general_trias'] ?? false,
                            'general_trias_confidence' => $verify_data['confidence_scores']['general_trias'] ?? 0,
                            'passed_checks' => $verify_data['summary']['passed_checks'] ?? 0,
                            'total_checks' => $verify_data['summary']['total_checks'] ?? 5,
                            'average_confidence' => $verify_data['summary']['average_confidence'] ?? round(($document['ocr_confidence'] ?? 0), 1),
                            'recommendation' => $verify_data['summary']['recommendation'] ?? 'No recommendation available',
                            'found_text_snippets' => $verify_data['found_text_snippets'] ?? [],
                            'document_type' => $doc_type
                        ];
                    }
                }
            }
        }
        
        // Get OCR text for all document types
        $ocr_text_path = $file_path . '.ocr.txt';
        if (file_exists($ocr_text_path)) {
            $validation_data['extracted_text'] = file_get_contents($ocr_text_path);
        }
    }
    
    // Return success with validation data
    echo json_encode([
        'success' => true,
        'validation' => $validation_data,
        'document_type' => $doc_type
    ]);
    
} catch (Exception $e) {
    error_log('Validation details error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving validation data: ' . $e->getMessage()
    ]);
}
?>
