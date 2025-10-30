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
    
    // Get basic document info from documents table by student_id and document_type_code
    $doc_query = pg_query_params($connection, 
        "SELECT * FROM documents WHERE student_id = $1 AND document_type_code = $2 ORDER BY upload_date DESC LIMIT 1",
        [$student_id, $document_type_code]
    );
    
    $document = null;
    $file_path = null;
    $verification_data_path = null;
    
    // If document found in database, use that
    if ($doc_query && pg_num_rows($doc_query) > 0) {
        $document = pg_fetch_assoc($doc_query);
        $file_path = $document['file_path'];
        $verification_data_path = $document['verification_data_path'];
        
        // If verification_data_path is null/empty but file_path exists, construct it
        if (empty($verification_data_path) && !empty($file_path)) {
            $path_info = pathinfo($file_path);
            $file_base = $path_info['dirname'] . '/' . $path_info['filename'];
            $verification_data_path = $file_base . '.verify.json';
        }
        
        $validation_data['ocr_confidence'] = floatval($document['ocr_confidence'] ?? 0);
        $validation_data['upload_date'] = $document['upload_date'];
    } else {
        // Document not in database - search filesystem for registration documents
        // This handles new applicants whose documents haven't been moved to permanent storage yet
        
        // Get student name for file search
        $student_query = pg_query_params($connection,
            "SELECT first_name, last_name FROM students WHERE student_id = $1",
            [$student_id]
        );
        
        if (!$student_query || pg_num_rows($student_query) === 0) {
            echo json_encode(['success' => false, 'message' => 'Student not found']);
            exit;
        }
        
        $student_info = pg_fetch_assoc($student_query);
        $first_name = $student_info['first_name'];
        $last_name = $student_info['last_name'];
        
        // Build search paths based on document type
        $server_base = dirname(__DIR__, 2) . '/assets/uploads/';
        
        // Map document codes to folder names for student directory
        $student_folder_map = [
            '04' => 'id_pictures',
            '00' => 'enrollment_forms',
            '01' => 'grades',
            '02' => 'letter_mayor',
            '03' => 'indigency'
        ];
        
        // Map document codes to temp folder paths
        $temp_folder_map = [
            '04' => 'temp/id_pictures/',
            '00' => 'temp/enrollment_forms/',
            '01' => 'temp/grades/',
            '02' => 'temp/letter_mayor/',
            '03' => 'temp/indigency/'
        ];
        
        $matches = [];
        
        // PRIORITY 1: Search NEW structure - student/{doc_type}/{student_id}/
        $student_folder_name = $student_folder_map[$document_type_code] ?? '';
        if ($student_folder_name) {
            $student_dir = $server_base . 'student/' . $student_folder_name . '/' . $student_id . '/';
            if (is_dir($student_dir)) {
                foreach (glob($student_dir . '*.*') as $file) {
                    // Skip associated files
                    if (preg_match('/\.(verify\.json|ocr\.txt|confidence\.json|tsv)$/i', basename($file))) {
                        continue;
                    }
                    if (is_dir($file)) continue;
                    
                    $matches[filemtime($file)] = $file;
                }
            }
        }
        
        // PRIORITY 2: Search OLD structure - student/{doc_type}/{student_id}_*
        if ($student_folder_name && empty($matches)) {
            $student_flat_dir = $server_base . 'student/' . $student_folder_name . '/';
            if (is_dir($student_flat_dir)) {
                $pattern = $student_flat_dir . $student_id . '_*';
                foreach (glob($pattern) as $file) {
                    // Skip associated files and directories
                    if (preg_match('/\.(verify\.json|ocr\.txt|confidence\.json|tsv)$/i', basename($file))) {
                        continue;
                    }
                    if (is_dir($file)) continue;
                    
                    $matches[filemtime($file)] = $file;
                }
            }
        }
        
        // PRIORITY 3: Search TEMP folder (for newly uploaded documents)
        $search_folder = $server_base . ($temp_folder_map[$document_type_code] ?? '');
        if (is_dir($search_folder) && empty($matches)) {
            foreach (glob($search_folder . '*.*') as $file) {
                $basename = basename($file);
                // Skip associated files
                if (preg_match('/\.(verify\.json|ocr\.txt|confidence\.json|tsv)$/i', $basename)) {
                    continue;
                }
                if (is_dir($file)) continue;
                
                // Match by student_id or name
                $normalized_name = strtolower($basename);
                if (strpos($normalized_name, strtolower($student_id)) !== false ||
                    (strpos($normalized_name, strtolower($first_name)) !== false && 
                     strpos($normalized_name, strtolower($last_name)) !== false)) {
                    $matches[filemtime($file)] = $file;
                }
            }
        }
        
        // Process matches if found
        if (!empty($matches)) {
            krsort($matches); // newest first
            $file_path = reset($matches);
            
            // Construct verification file path - remove file extension first, then add .verify.json
            $path_info = pathinfo($file_path);
            $file_base = $path_info['dirname'] . '/' . $path_info['filename']; // Path without extension
            $verification_data_path = $file_base . '.verify.json';
            
            // Get OCR confidence from .confidence.json or .verify.json
            $confidence_file = $file_base . '.confidence.json';
            if (file_exists($confidence_file)) {
                $conf_data = json_decode(file_get_contents($confidence_file), true);
                $validation_data['ocr_confidence'] = floatval($conf_data['ocr_confidence'] ?? 0);
            } elseif (file_exists($verification_data_path)) {
                $verify_data = json_decode(file_get_contents($verification_data_path), true);
                $validation_data['ocr_confidence'] = floatval($verify_data['summary']['average_confidence'] ?? 
                                                    $verify_data['ocr_confidence'] ?? 0);
            } else {
                // No verification data found - set to 0
                $validation_data['ocr_confidence'] = 0;
            }
            
            $validation_data['upload_date'] = date('Y-m-d H:i:s', filemtime($file_path));
        }
    }
    
    if (!$file_path || !file_exists($file_path)) {
        echo json_encode(['success' => false, 'message' => 'Document file not found on server']);
        exit;
    }
    
    // ID Picture - Get identity verification data
    if ($doc_type === 'id_picture') {
        // Use verification_data_path from database instead of manual construction
        if (!empty($verification_data_path) && file_exists($verification_data_path)) {
            $verify_data = json_decode(file_get_contents($verification_data_path), true);
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
                    'document_type' => $doc_type,
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
                    'recommendation' => $verify_data['summary']['recommendation'] ?? 'No recommendation available',
                    'found_text_snippets' => $verify_data['found_text_snippets'] ?? []
                ];
            }
        }
        
        // Get OCR text
        $path_info = pathinfo($file_path);
        $file_base = $path_info['dirname'] . '/' . $path_info['filename'];
        $ocr_text_path = $file_base . '.ocr.txt';
        if (file_exists($ocr_text_path)) {
            $validation_data['extracted_text'] = file_get_contents($ocr_text_path);
        }
    }
    
    // Academic Grades - Get detailed grade validation from JSON file
    elseif ($doc_type === 'grades') {
        // Use JSON verification file which contains all grade information
        // Remove file extension before adding .verify.json
        $path_info = pathinfo($file_path);
        $file_base = $path_info['dirname'] . '/' . $path_info['filename'];
        $verify_json_path = $file_base . '.verify.json';
        
        if (file_exists($verify_json_path)) {
            // Load verification JSON
            $verify_data = json_decode(file_get_contents($verify_json_path), true);
            
            if ($verify_data && is_array($verify_data)) {
                // Extract validation status
                $validation_data['validation_status'] = $verify_data['overall_success'] 
                    ? ($verify_data['is_eligible'] ? 'passed' : 'failed') 
                    : 'pending';
                
                // Get OCR confidence from summary or confidence_scores
                if (isset($verify_data['summary']['average_confidence'])) {
                    $validation_data['ocr_confidence'] = floatval($verify_data['summary']['average_confidence']);
                } elseif (isset($verify_data['confidence_scores']['grades'])) {
                    $validation_data['ocr_confidence'] = floatval($verify_data['confidence_scores']['grades']);
                } else {
                    $validation_data['ocr_confidence'] = floatval($document['ocr_confidence'] ?? 0);
                }
                
                // Extract grades from enhanced_grade_validation if available, otherwise from grades array
                $extracted_grades = [];
                
                if (isset($verify_data['enhanced_grade_validation']['extracted_subjects'])) {
                    // Use enhanced validation data (has confidence per subject)
                    foreach ($verify_data['enhanced_grade_validation']['extracted_subjects'] as $subject) {
                        // Determine if grade is passing (typically <= 3.0 for Philippine grading, or <= 5.0 for some schools)
                        $grade_val = floatval($subject['rawGrade'] ?? 0);
                        $is_passing = ($grade_val > 0 && $grade_val <= 3.0) || 
                                     (isset($verify_data['failing_grades']) && !in_array($subject['name'], $verify_data['failing_grades']));
                        
                        $extracted_grades[] = [
                            'subject_name' => $subject['name'] ?? 'Unknown Subject',
                            'grade_value' => $subject['rawGrade'] ?? 'N/A',
                            'extraction_confidence' => floatval($subject['confidence'] ?? 95),
                            'is_passing' => $is_passing ? 't' : 'f'
                        ];
                    }
                } elseif (isset($verify_data['grades']) && is_array($verify_data['grades'])) {
                    // Fallback to basic grades array
                    $default_confidence = floatval($verify_data['confidence_scores']['grades'] ?? 90);
                    
                    foreach ($verify_data['grades'] as $grade_item) {
                        if (!isset($grade_item['subject']) || !isset($grade_item['grade'])) continue;
                        
                        $grade_val = floatval($grade_item['grade']);
                        $is_passing = ($grade_val > 0 && $grade_val <= 3.0) || 
                                     (isset($verify_data['failing_grades']) && !in_array($grade_item['subject'], $verify_data['failing_grades']));
                        
                        $extracted_grades[] = [
                            'subject_name' => $grade_item['subject'],
                            'grade_value' => $grade_item['grade'],
                            'extraction_confidence' => $default_confidence,
                            'is_passing' => $is_passing ? 't' : 'f'
                        ];
                    }
                }
                
                if (!empty($extracted_grades)) {
                    $validation_data['extracted_grades'] = $extracted_grades;
                }
                
                // Add summary information
                if (isset($verify_data['summary'])) {
                    $validation_data['summary'] = $verify_data['summary'];
                }
                
                // Add eligibility status
                $validation_data['is_eligible'] = $verify_data['is_eligible'] ?? false;
                $validation_data['all_grades_passing'] = $verify_data['all_grades_passing'] ?? false;
                
                // Add comprehensive validation checks for grades modal display
                $validation_data['identity_verification'] = [
                    'document_type' => 'grades',
                    'year_level_match' => $verify_data['year_level_match'] ?? false,
                    'year_level_confidence' => floatval($verify_data['confidence_scores']['year_level'] ?? 0),
                    'semester_match' => $verify_data['semester_match'] ?? false,
                    'semester_confidence' => floatval($verify_data['confidence_scores']['semester'] ?? 0),
                    'school_year_match' => $verify_data['school_year_match'] ?? false,
                    'school_year_confidence' => floatval($verify_data['confidence_scores']['school_year'] ?? 0),
                    'university_match' => $verify_data['university_match'] ?? false,
                    'university_confidence' => floatval($verify_data['confidence_scores']['university'] ?? 0),
                    'name_match' => $verify_data['name_match'] ?? false,
                    'name_confidence' => floatval($verify_data['confidence_scores']['name'] ?? 0),
                    'all_grades_passing' => $verify_data['all_grades_passing'] ?? false,
                    'grades_confidence' => floatval($verify_data['confidence_scores']['grades'] ?? 0),
                    'is_eligible' => $verify_data['is_eligible'] ?? false,
                    'passed_checks' => $verify_data['summary']['passed_checks'] ?? 0,
                    'total_checks' => $verify_data['summary']['total_checks'] ?? 6,
                    'average_confidence' => $verify_data['summary']['average_confidence'] ?? floatval($document['ocr_confidence'] ?? 0),
                    'eligibility_status' => $verify_data['summary']['eligibility_status'] ?? 'UNKNOWN',
                    'recommendation' => $verify_data['summary']['recommendation'] ?? 'No recommendation available',
                    'found_text_snippets' => $verify_data['found_text_snippets'] ?? [],
                    'validation_method' => $verify_data['validation_method'] ?? 'unknown',
                    'university_code' => $verify_data['university_code'] ?? ''
                ];
            }
        }
        
        // Fallback: check if OCR text exists
        if (empty($validation_data['extracted_text'])) {
            $path_info = pathinfo($file_path);
            $file_base = $path_info['dirname'] . '/' . $path_info['filename'];
            $ocr_text_path = $file_base . '.ocr.txt';
            if (file_exists($ocr_text_path)) {
                $validation_data['extracted_text'] = file_get_contents($ocr_text_path);
            }
        }
    }
    
    // EAF, Letter, Certificate - Get OCR text and verification data
    else {
        // Use verification_data_path from database or filesystem search
        if (!empty($verification_data_path) && file_exists($verification_data_path)) {
            $verify_data = json_decode(file_get_contents($verification_data_path), true);
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
                        'document_type' => $doc_type,
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
        $path_info = pathinfo($file_path);
        $file_base = $path_info['dirname'] . '/' . $path_info['filename'];
        $ocr_text_path = $file_base . '.ocr.txt';
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
