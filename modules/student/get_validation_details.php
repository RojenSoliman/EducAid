<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in (student or admin)
if (!isset($_SESSION['student_id']) && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

include '../../config/database.php';

// Get data from either GET (for AJAX fetch) or POST (for JSON body)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $doc_type = $_GET['doc_type'] ?? '';
    $student_id_param = $_GET['student_id'] ?? '';
} else {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    $doc_type = $input['doc_type'] ?? '';
    $student_id_param = $input['student_id'] ?? '';
}

// For admins, they can pass student_id. For students, use their own session student_id
$student_id = isset($_SESSION['admin_id']) && !empty($student_id_param) 
    ? $student_id_param 
    : ($_SESSION['student_id'] ?? null);

if (empty($doc_type) || empty($student_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters: doc_type and student_id']);
    exit;
}

/**
 * Get validation details for UNDER_REGISTRATION students (TEMP directory)
 * Files are stored in: assets/uploads/temp/{doc_type}/{student_id}_*
 */
function getValidationDetails_Registration($connection, $student_id, $document_type_code, $doc_type) {
    $server_base = dirname(__DIR__, 2) . '/assets/uploads/temp/';
    
    // Map document codes to temp folder names
    $temp_folder_map = [
        '04' => 'id_pictures',
        '00' => 'enrollment_forms',
        '01' => 'grades',
        '02' => 'letter_mayor',
        '03' => 'indigency'
    ];
    
    $folder_name = $temp_folder_map[$document_type_code] ?? '';
    if (empty($folder_name)) {
        return ['success' => false, 'message' => 'Invalid document type'];
    }
    
    $search_dir = $server_base . $folder_name . '/';
    
    // Find file matching student_id pattern
    $file_path = null;
    $verify_json_path = null;
    
    if (is_dir($search_dir)) {
        $pattern = $search_dir . $student_id . '_*';
        $files = glob($pattern);
        
        // Filter out OCR-related files, keep only main document
        foreach ($files as $f) {
            if (preg_match('/\.(ocr\.txt|verify\.json|confidence\.json|tsv)$/i', $f)) continue;
            if (is_file($f)) {
                $file_path = $f;
                // Get verify.json path - same filename but add .verify.json
                $verify_json_path = $f . '.verify.json';
                break;
            }
        }
    }
    
    if (!$file_path || !file_exists($file_path)) {
        return ['success' => false, 'message' => 'Document file not found in temp directory'];
    }
    
    // Load verification data from .verify.json if exists
    $verification_data = null;
    if ($verify_json_path && file_exists($verify_json_path)) {
        $verification_data = json_decode(file_get_contents($verify_json_path), true);
    }
    
    // Load OCR text if exists
    $ocr_text = null;
    $ocr_text_path = $file_path . '.ocr.txt';
    if (file_exists($ocr_text_path)) {
        $ocr_text = file_get_contents($ocr_text_path);
    }
    
    return [
        'success' => true,
        'file_path' => $file_path,
        'verify_json_path' => $verify_json_path,
        'verification_data' => $verification_data,
        'ocr_text' => $ocr_text,
        'storage_type' => 'temp'
    ];
}

/**
 * Get validation details for APPROVED/REUPLOAD students (PERMANENT directory)
 * Files are stored in: assets/uploads/student/{doc_type}/{student_id}/filename_timestamp.ext
 */
function getValidationDetails_Permanent($connection, $student_id, $document_type_code, $doc_type) {
    $server_base = dirname(__DIR__, 2) . '/assets/uploads/student/';
    
    // Map document codes to permanent folder names
    $folder_map = [
        '04' => 'id_pictures',
        '00' => 'enrollment_forms',
        '01' => 'grades',
        '02' => 'letter_to_mayor',  // Note: different from temp (letter_mayor)
        '03' => 'indigency'
    ];
    
    $folder_name = $folder_map[$document_type_code] ?? '';
    if (empty($folder_name)) {
        return ['success' => false, 'message' => 'Invalid document type'];
    }
    
    // NEW structure: assets/uploads/student/{doc_type}/{student_id}/
    $student_dir = $server_base . $folder_name . '/' . $student_id . '/';
    
    $file_path = null;
    $verify_json_path = null;
    
    if (is_dir($student_dir)) {
        // Get all files in student's directory (excluding OCR files)
        $files = glob($student_dir . '*');
        
        // Filter and sort by modification time (newest first)
        $main_files = [];
        foreach ($files as $f) {
            if (preg_match('/\.(ocr\.txt|verify\.json|confidence\.json|tsv)$/i', $f)) continue;
            if (is_file($f)) {
                $main_files[] = $f;
            }
        }
        
        if (!empty($main_files)) {
            // Sort by modification time (newest first)
            usort($main_files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            $file_path = $main_files[0];  // Get newest file
            // Get verify.json path - same filename but add .verify.json
            $verify_json_path = $file_path . '.verify.json';
        }
    }
    
    if (!$file_path || !file_exists($file_path)) {
        return ['success' => false, 'message' => 'Document file not found in permanent directory'];
    }
    
    // Load verification data from .verify.json if exists
    $verification_data = null;
    if ($verify_json_path && file_exists($verify_json_path)) {
        $verification_data = json_decode(file_get_contents($verify_json_path), true);
    }
    
    // Load OCR text if exists
    $ocr_text = null;
    $ocr_text_path = $file_path . '.ocr.txt';
    if (file_exists($ocr_text_path)) {
        $ocr_text = file_get_contents($ocr_text_path);
    }
    
    return [
        'success' => true,
        'file_path' => $file_path,
        'verify_json_path' => $verify_json_path,
        'verification_data' => $verification_data,
        'ocr_text' => $ocr_text,
        'storage_type' => 'permanent'
    ];
}

$validation_data = [];

try {
    // Map document type to database document_type_code
    $type_to_code_map = [
        'id_picture' => '04',
        'grades' => '01',
        'eaf' => '00',
        'letter_to_mayor' => '02',
        'certificate_of_indigency' => '03'
    ];
    
    $document_type_code = $type_to_code_map[$doc_type] ?? null;
    
    if (!$document_type_code) {
        echo json_encode(['success' => false, 'message' => 'Invalid document type: ' . $doc_type]);
        exit;
    }
    
    // Get student status to determine which directory to search
    $student_query = pg_query_params($connection,
        "SELECT status, first_name, last_name FROM students WHERE student_id = $1",
        [$student_id]
    );
    
    if (!$student_query || pg_num_rows($student_query) === 0) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }
    
    $student_info = pg_fetch_assoc($student_query);
    $student_status = $student_info['status'];
    
    // Determine which function to use based on student status
    // UNDER_REGISTRATION = temp directory
    // APPLICANT/ACTIVE/GIVEN/etc = permanent directory
    if ($student_status === 'under_registration') {
        $file_result = getValidationDetails_Registration($connection, $student_id, $document_type_code, $doc_type);
    } else {
        $file_result = getValidationDetails_Permanent($connection, $student_id, $document_type_code, $doc_type);
    }
    
    if (!$file_result['success']) {
        echo json_encode($file_result);
        exit;
    }
    
    // Extract file information
    $file_path = $file_result['file_path'];
    $verify_json_path = $file_result['verify_json_path'];
    $verification_data_from_file = $file_result['verification_data'];
    $ocr_text = $file_result['ocr_text'];
    
    // Get OCR confidence from verification data
    if ($verification_data_from_file) {
        if (isset($verification_data_from_file['summary']['average_confidence'])) {
            $validation_data['ocr_confidence'] = floatval($verification_data_from_file['summary']['average_confidence']);
        } elseif (isset($verification_data_from_file['ocr_confidence'])) {
            $validation_data['ocr_confidence'] = floatval($verification_data_from_file['ocr_confidence']);
        } else {
            $validation_data['ocr_confidence'] = 0;
        }
    } else {
        $validation_data['ocr_confidence'] = 0;
    }
    
    $validation_data['upload_date'] = date('Y-m-d H:i:s', filemtime($file_path));
    $validation_data['storage_type'] = $file_result['storage_type'];
    
    // ===========================================
    // PROCESS VALIDATION DETAILS BY DOCUMENT TYPE
    // ===========================================
    
    // ID Picture - Get identity verification data
    if ($doc_type === 'id_picture') {
        if ($verification_data_from_file) {
            // ID Picture uses "checks" object format
            if (isset($verification_data_from_file['checks'])) {
                $checks = $verification_data_from_file['checks'];
                $validation_data['identity_verification'] = [
                    'document_type' => $doc_type,
                    'first_name_match' => $checks['first_name_match']['passed'] ?? false,
                    'first_name_confidence' => $checks['first_name_match']['similarity'] ?? 0,
                    'middle_name_match' => $checks['middle_name_match']['passed'] ?? false,
                    'middle_name_confidence' => $checks['middle_name_match']['similarity'] ?? 0,
                    'last_name_match' => $checks['last_name_match']['passed'] ?? false,
                    'last_name_confidence' => $checks['last_name_match']['similarity'] ?? 0,
                    'year_level_match' => false, // ID Picture doesn't verify year level
                    'year_level_confidence' => 0,
                    'school_match' => $checks['university_match']['passed'] ?? false,
                    'school_confidence' => $checks['university_match']['similarity'] ?? 0,
                    'official_keywords' => $checks['document_keywords_found']['passed'] ?? false,
                    'keywords_confidence' => 100, // keywords_found doesn't have similarity score
                    'verification_score' => $verification_data_from_file['verification_score'] ?? 0,
                    'passed_checks' => $verification_data_from_file['summary']['passed_checks'] ?? 0,
                    'total_checks' => $verification_data_from_file['summary']['total_checks'] ?? 6,
                    'average_confidence' => $verification_data_from_file['summary']['average_confidence'] ?? 0,
                    'recommendation' => $verification_data_from_file['summary']['recommendation'] ?? 'No recommendation available',
                    'found_text_snippets' => []
                ];
                
                // Build found_text_snippets from checks data
                foreach ($checks as $checkKey => $checkData) {
                    if (isset($checkData['expected'])) {
                        $validation_data['identity_verification']['found_text_snippets'][$checkKey] = 
                            'Expected: ' . $checkData['expected'] . 
                            ($checkData['found_in_ocr'] ? ' (Found)' : ' (Not Found)');
                    }
                }
            } else {
                // Fallback to flat structure (old format)
                $validation_data['identity_verification'] = [
                    'document_type' => $doc_type,
                    'first_name_match' => $verification_data_from_file['first_name_match'] ?? false,
                    'first_name_confidence' => $verification_data_from_file['confidence_scores']['first_name'] ?? 0,
                    'middle_name_match' => $verification_data_from_file['middle_name_match'] ?? false,
                    'middle_name_confidence' => $verification_data_from_file['confidence_scores']['middle_name'] ?? 0,
                    'last_name_match' => $verification_data_from_file['last_name_match'] ?? false,
                    'last_name_confidence' => $verification_data_from_file['confidence_scores']['last_name'] ?? 0,
                    'year_level_match' => $verification_data_from_file['year_level_match'] ?? false,
                    'year_level_confidence' => 0,
                    'school_match' => $verification_data_from_file['university_match'] ?? false,
                    'school_confidence' => $verification_data_from_file['confidence_scores']['university'] ?? 0,
                    'official_keywords' => $verification_data_from_file['document_keywords_found'] ?? false,
                    'keywords_confidence' => $verification_data_from_file['confidence_scores']['document_keywords'] ?? 0,
                    'verification_score' => $verification_data_from_file['verification_score'] ?? 0,
                    'passed_checks' => $verification_data_from_file['summary']['passed_checks'] ?? 0,
                    'total_checks' => $verification_data_from_file['summary']['total_checks'] ?? 6,
                    'average_confidence' => $verification_data_from_file['summary']['average_confidence'] ?? 0,
                    'recommendation' => $verification_data_from_file['summary']['recommendation'] ?? 'No recommendation available',
                    'found_text_snippets' => $verification_data_from_file['found_text_snippets'] ?? []
                ];
            }
        }
        
        if ($ocr_text) {
            $validation_data['extracted_text'] = $ocr_text;
        }
    }
    
    // Academic Grades
    elseif ($doc_type === 'grades') {
        if ($verification_data_from_file) {
            $validation_data['validation_status'] = $verification_data_from_file['overall_success'] 
                ? ($verification_data_from_file['is_eligible'] ? 'passed' : 'failed') 
                : 'pending';
            
            $extracted_grades = [];
            
            if (isset($verification_data_from_file['enhanced_grade_validation']['extracted_subjects'])) {
                foreach ($verification_data_from_file['enhanced_grade_validation']['extracted_subjects'] as $subject) {
                    $grade_val = floatval($subject['rawGrade'] ?? 0);
                    $is_passing = ($grade_val > 0 && $grade_val <= 3.0);
                    
                    $extracted_grades[] = [
                        'subject_name' => $subject['name'] ?? 'Unknown Subject',
                        'grade_value' => $subject['rawGrade'] ?? 'N/A',
                        'extraction_confidence' => floatval($subject['confidence'] ?? 95),
                        'is_passing' => $is_passing ? 't' : 'f'
                    ];
                }
            } elseif (isset($verification_data_from_file['grades'])) {
                foreach ($verification_data_from_file['grades'] as $grade_item) {
                    $grade_val = floatval($grade_item['grade'] ?? 0);
                    $is_passing = ($grade_val > 0 && $grade_val <= 3.0);
                    
                    $extracted_grades[] = [
                        'subject_name' => $grade_item['subject'] ?? 'Unknown',
                        'grade_value' => $grade_item['grade'] ?? 'N/A',
                        'extraction_confidence' => 90,
                        'is_passing' => $is_passing ? 't' : 'f'
                    ];
                }
            }
            
            if (!empty($extracted_grades)) {
                $validation_data['extracted_grades'] = $extracted_grades;
            }
            
            $validation_data['summary'] = $verification_data_from_file['summary'] ?? [];
            $validation_data['is_eligible'] = $verification_data_from_file['is_eligible'] ?? false;
            $validation_data['all_grades_passing'] = $verification_data_from_file['all_grades_passing'] ?? false;
            
            $validation_data['identity_verification'] = [
                'document_type' => 'grades',
                'year_level_match' => $verification_data_from_file['year_level_match'] ?? false,
                'year_level_confidence' => floatval($verification_data_from_file['confidence_scores']['year_level'] ?? 0),
                'semester_match' => $verification_data_from_file['semester_match'] ?? false,
                'semester_confidence' => floatval($verification_data_from_file['confidence_scores']['semester'] ?? 0),
                'school_year_match' => $verification_data_from_file['school_year_match'] ?? false,
                'school_year_confidence' => floatval($verification_data_from_file['confidence_scores']['school_year'] ?? 0),
                'university_match' => $verification_data_from_file['university_match'] ?? false,
                'university_confidence' => floatval($verification_data_from_file['confidence_scores']['university'] ?? 0),
                'name_match' => $verification_data_from_file['name_match'] ?? false,
                'name_confidence' => floatval($verification_data_from_file['confidence_scores']['name'] ?? 0),
                'all_grades_passing' => $verification_data_from_file['all_grades_passing'] ?? false,
                'grades_confidence' => floatval($verification_data_from_file['confidence_scores']['grades'] ?? 0),
                'is_eligible' => $verification_data_from_file['is_eligible'] ?? false,
                'passed_checks' => $verification_data_from_file['summary']['passed_checks'] ?? 0,
                'total_checks' => $verification_data_from_file['summary']['total_checks'] ?? 6,
                'average_confidence' => $verification_data_from_file['summary']['average_confidence'] ?? 0,
                'eligibility_status' => $verification_data_from_file['summary']['eligibility_status'] ?? 'UNKNOWN',
                'recommendation' => $verification_data_from_file['summary']['recommendation'] ?? '',
                'found_text_snippets' => $verification_data_from_file['found_text_snippets'] ?? []
            ];
        }
        
        if ($ocr_text) {
            $validation_data['extracted_text'] = $ocr_text;
        }
    }
    
    // EAF, Letter, Certificate
    else {
        if ($verification_data_from_file) {
            // Check if data is nested under "verification" key (EAF format)
            $verif_data = isset($verification_data_from_file['verification']) 
                ? $verification_data_from_file['verification'] 
                : $verification_data_from_file;
            
            $validation_data['identity_verification'] = [
                'document_type' => $doc_type,
                'first_name_match' => $verif_data['first_name_match'] ?? false,
                'first_name_confidence' => $verif_data['confidence_scores']['first_name'] ?? 0,
                'middle_name_match' => $verif_data['middle_name_match'] ?? false,
                'middle_name_confidence' => $verif_data['confidence_scores']['middle_name'] ?? 0,
                'last_name_match' => $verif_data['last_name_match'] ?? false,
                'last_name_confidence' => $verif_data['confidence_scores']['last_name'] ?? 0,
                'year_level_match' => $verif_data['year_level_match'] ?? false,
                'year_level_confidence' => $verif_data['confidence_scores']['year_level'] ?? 0,
                'university_match' => $verif_data['university_match'] ?? false,
                'university_confidence' => $verif_data['confidence_scores']['university'] ?? 0,
                'document_keywords_found' => $verif_data['document_keywords_found'] ?? false,
                'document_keywords_confidence' => $verif_data['confidence_scores']['document_keywords'] ?? 0,
                'verification_score' => $verif_data['verification_score'] ?? 0,
                'passed_checks' => $verif_data['summary']['passed_checks'] ?? 0,
                'total_checks' => $verif_data['summary']['total_checks'] ?? 0,
                'average_confidence' => $verif_data['summary']['average_confidence'] ?? 0,
                'recommendation' => $verif_data['summary']['recommendation'] ?? '',
                'found_text_snippets' => $verif_data['found_text_snippets'] ?? []
            ];
        }
        
        if ($ocr_text) {
            $validation_data['extracted_text'] = $ocr_text;
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
