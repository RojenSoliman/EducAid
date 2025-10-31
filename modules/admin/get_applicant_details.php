<?php
/**
 * Get Applicant Details - View Documents Modal
 * 
 * This endpoint retrieves document information for approved applicants
 * whose files are stored in permanent storage:
 * assets/uploads/student/{filetype}/{studentID}/
 * 
 * Folders: indigency, grades, id_pictures, enrollment_forms, letter_mayor
 */

session_start();
if (!isset($_SESSION['admin_username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include '../../config/database.php';

// Validate student_id parameter
if (!isset($_GET['student_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing student_id parameter']);
    exit;
}

$student_id = trim($_GET['student_id']);

// Fetch student data
$student_query = pg_query_params($connection, 
    "SELECT student_id, first_name, last_name, status FROM students WHERE student_id = $1", 
    [$student_id]);

if (!$student_query || pg_num_rows($student_query) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Student not found', 'student_id' => $student_id]);
    exit;
}

$student = pg_fetch_assoc($student_query);

// Map document type codes to folder names and display labels
$doc_type_config = [
    '04' => ['folder' => 'id_pictures', 'label' => 'ID Picture', 'key' => 'id_picture'],
    '00' => ['folder' => 'enrollment_forms', 'label' => 'EAF', 'key' => 'eaf'],
    '02' => ['folder' => 'letter_mayor', 'label' => 'Letter to Mayor', 'key' => 'letter_to_mayor'],
    '03' => ['folder' => 'indigency', 'label' => 'Certificate of Indigency', 'key' => 'certificate_of_indigency'],
    '01' => ['folder' => 'grades', 'label' => 'Academic Grades', 'key' => 'grades']
];

$documents = [];
$server_root = dirname(__DIR__, 2);
// Use relative path from modules/admin/ directory
// Paths stored in DB as "assets/uploads/..." need ../../ prefix for browser resolution
$web_base = '../../assets/uploads/student/';

// Step 1: Check documents table for approved/permanent documents
$docs_query = pg_query_params($connection, 
    "SELECT document_type_code, file_path, upload_date, status 
     FROM documents 
     WHERE student_id = $1 
     AND status != 'rejected'
     ORDER BY upload_date DESC", 
    [$student_id]);

$db_documents = [];
while ($doc = pg_fetch_assoc($docs_query)) {
    $docTypeCode = $doc['document_type_code'];
    if (!isset($doc_type_config[$docTypeCode])) continue;
    
    $config = $doc_type_config[$docTypeCode];
    $filePath = $doc['file_path'];
    
    // Convert database path format to web-accessible path
    // Database stores: assets/uploads/student/...
    // We need: ../../assets/uploads/student/... (relative to modules/admin/)
    if (strpos($filePath, 'assets/uploads/') === 0) {
        $filePath = '../../' . $filePath;
    }
    
    // Convert temp paths to permanent paths for approved students
    if ($student['status'] === 'active' && strpos($filePath, '/temp/') !== false) {
        $filePath = str_replace('/temp/', '/student/', $filePath);
    }
    
    // Verify file exists
    $relative_from_root = ltrim(str_replace('../../', '', $filePath), '/');
    $server_path = $server_root . '/' . $relative_from_root;
    
    if (file_exists($server_path)) {
        // Store only if not already found (newest first from query)
        if (!isset($db_documents[$config['key']])) {
            $db_documents[$config['key']] = [
                'path' => $filePath,
                'server_path' => $server_path,
                'uploaded_at' => $doc['upload_date'],
                'status' => $doc['status'],
                'source' => 'database'
            ];
        }
    }
}

// Step 2: Search permanent storage directories: student/{folder}/{studentID}/
foreach ($doc_type_config as $code => $config) {
    $folder = $config['folder'];
    $key = $config['key'];
    
    // Skip if already found in database
    if (isset($db_documents[$key])) {
        continue;
    }
    
    // Check permanent storage: student/{folder}/{studentID}/
    $student_dir = $server_root . '/assets/uploads/student/' . $folder . '/' . $student_id . '/';
    
    if (is_dir($student_dir)) {
        $files = glob($student_dir . '*');
        $valid_files = [];
        
        foreach ($files as $file) {
            // Skip directories and associated files
            if (is_dir($file)) continue;
            if (preg_match('/\.(verify\.json|ocr\.txt|confidence\.json|tsv)$/i', $file)) continue;
            
            // Only accept image/PDF files
            if (!preg_match('/\.(jpg|jpeg|png|gif|pdf)$/i', $file)) continue;
            
            $valid_files[filemtime($file)] = $file;
        }
        
        if (!empty($valid_files)) {
            // Get newest file
            krsort($valid_files);
            $newest = reset($valid_files);
            
            $db_documents[$key] = [
                'path' => $web_base . $folder . '/' . $student_id . '/' . basename($newest),
                'server_path' => $newest,
                'uploaded_at' => date('Y-m-d H:i:s', filemtime($newest)),
                'status' => 'approved',
                'source' => 'filesystem'
            ];
        }
    }
}

// Step 3: Build response data with file metadata
foreach ($doc_type_config as $code => $config) {
    $key = $config['key'];
    $label = $config['label'];
    
    if (isset($db_documents[$key])) {
        $doc_info = $db_documents[$key];
        $server_path = $doc_info['server_path'];
        
        // Determine file type
        $is_image = preg_match('/\.(jpg|jpeg|png|gif)$/i', $server_path);
        $is_pdf = preg_match('/\.pdf$/i', $server_path);
        
        // Get file metadata
        $file_size = file_exists($server_path) ? filesize($server_path) : 0;
        $file_date = file_exists($server_path) ? filemtime($server_path) : null;
        
        // Check for OCR/validation data by reading filesystem .verify.json files
        $ocr_data = null;
        
        // Look for .verify.json file
        $verify_json_path = $server_path . '.verify.json';
        $ocr_txt_path = $server_path . '.ocr.txt';
        
        if (file_exists($verify_json_path)) {
            $verify_content = file_get_contents($verify_json_path);
            $verify_raw = json_decode($verify_content, true);
            
            if ($verify_raw && is_array($verify_raw)) {
                // Handle nested structure (EAF has data under "verification" key)
                $verification = isset($verify_raw['verification']) ? $verify_raw['verification'] : $verify_raw;
                
                // If it's nested, merge the top-level fields (like tsv_quality, extracted_data)
                if (isset($verify_raw['verification']) && $verify_raw !== $verification) {
                    // Preserve top-level keys like tsv_quality, extracted_data
                    foreach ($verify_raw as $top_level_key => $value) {
                        if ($top_level_key !== 'verification' && !isset($verification[$top_level_key])) {
                            $verification[$top_level_key] = $value;
                        }
                    }
                }
                
                // Extract confidence from verification data
                $confidence = 0;
                if (isset($verification['summary']['average_confidence'])) {
                    $confidence = floatval($verification['summary']['average_confidence']);
                } elseif (isset($verification['tsv_quality']['avg_confidence'])) {
                    $confidence = floatval($verification['tsv_quality']['avg_confidence']);
                } elseif (isset($verification['summary']['tsv_avg_confidence'])) {
                    $confidence = floatval($verification['summary']['tsv_avg_confidence']);
                } elseif (isset($verification['ocr_confidence'])) {
                    $confidence = floatval($verification['ocr_confidence']);
                }
                
                // Extract verification score
                $verification_score = 0;
                if (isset($verification['verification_score'])) {
                    $verification_score = floatval($verification['verification_score']);
                } elseif (isset($verification['summary']['verification_score'])) {
                    $verification_score = floatval($verification['summary']['verification_score']);
                } elseif (isset($verification['summary']['average_confidence'])) {
                    // Fallback to average confidence as verification score
                    $verification_score = floatval($verification['summary']['average_confidence']);
                }
                
                // Read OCR text if available
                $extracted_text = null;
                if (file_exists($ocr_txt_path)) {
                    $extracted_text = file_get_contents($ocr_txt_path);
                }
                
                $ocr_data = [
                    'confidence' => $confidence,
                    'verification_score' => $verification_score,
                    'verification_status' => isset($verification['is_eligible']) 
                        ? ($verification['is_eligible'] ? 'verified' : 'pending') 
                        : 'pending',
                    'verification' => $verification,
                    'extracted_text' => $extracted_text
                ];
            }
        }
        
        $documents[$key] = [
            'label' => $label,
            'path' => $doc_info['path'],
            'type' => $is_image ? 'image' : ($is_pdf ? 'pdf' : 'unknown'),
            'size' => $file_size,
            'size_formatted' => $file_size > 0 ? number_format($file_size / 1024, 1) . ' KB' : 'Unknown',
            'uploaded_at' => $doc_info['uploaded_at'],
            'date_formatted' => $file_date ? date('M j, Y', $file_date) : 'Unknown',
            'status' => $doc_info['status'] ?? 'approved',
            'source' => $doc_info['source'],
            'ocr_data' => $ocr_data
        ];
    } else {
        // Document not found - mark as missing
        $documents[$key] = [
            'label' => $label,
            'missing' => true
        ];
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'student' => [
        'id' => $student['student_id'],
        'name' => trim($student['first_name'] . ' ' . $student['last_name']),
        'status' => $student['status']
    ],
    'documents' => $documents
]);

pg_close($connection);
