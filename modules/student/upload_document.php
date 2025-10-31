<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/UnifiedFileService.php';
require_once __DIR__ . '/../../services/DocumentReuploadService.php';
require_once __DIR__ . '/../../services/EnrollmentFormOCRService.php';
require_once __DIR__ . '/../../services/CourseMappingService.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../../unified_login.php");
    exit;
}

$student_id = $_SESSION['student_id'];
$fileService = new UnifiedFileService($connection);
$reuploadService = new DocumentReuploadService($connection);

// Get student information and upload permission
$student_query = pg_query_params($connection,
    "SELECT s.*, 
            COALESCE(s.needs_document_upload, FALSE) as needs_upload,
            s.documents_to_reupload,
            b.name as barangay_name,
            u.name as university_name,
            yl.name as year_level_name
     FROM students s
     LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
     LEFT JOIN universities u ON s.university_id = u.university_id
     LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
     WHERE s.student_id = $1",
    [$student_id]
);

if (!$student_query || pg_num_rows($student_query) === 0) {
    die("Student not found");
}

$student = pg_fetch_assoc($student_query);

// PostgreSQL returns 'f'/'t' strings for booleans
$needs_upload = ($student['needs_upload'] === 't' || $student['needs_upload'] === true);
$student_status = $student['status'] ?? 'applicant';

// TESTING MODE: Allow re-upload if ?test_reupload=1 is in URL (REMOVE IN PRODUCTION)
$test_mode = isset($_GET['test_reupload']) && $_GET['test_reupload'] == '1';
if ($test_mode) {
    $needs_upload = true;
}

// Only allow uploads if:
// 1. Student needs upload (needs_document_upload = true) AND
// 2. Student is NOT active (active students are approved and in read-only mode) AND
// 3. Student is NOT given (students who received aid are in read-only mode)
$can_upload = $needs_upload && $student_status !== 'active' && $student_status !== 'given' && !$test_mode;

// Student is in read-only mode if they're a new registrant OR they're already active OR they have received aid
$is_new_registrant = !$needs_upload || $student_status === 'active' || $student_status === 'given';

// Get list of documents that need re-upload (if any)
$documents_to_reupload = [];
if ($needs_upload) {
    // Check if documents_to_reupload column exists and has data
    $colCheck = pg_query($connection, 
        "SELECT 1 FROM information_schema.columns 
         WHERE table_name='students' AND column_name='documents_to_reupload'");
    
    if ($colCheck && pg_num_rows($colCheck) > 0 && !empty($student['documents_to_reupload'])) {
        $documents_to_reupload = json_decode($student['documents_to_reupload'], true) ?: [];
    }
    
    // If no specific documents listed, allow all uploads
    if (empty($documents_to_reupload)) {
        $documents_to_reupload = ['00', '01', '02', '03', '04']; // All document types
    }
}

// Get existing documents
$docs_query = pg_query_params($connection,
    "SELECT document_type_code, file_path, upload_date, 
            ocr_confidence, verification_score,
            verification_status, verification_details
     FROM documents 
     WHERE student_id = $1
     ORDER BY upload_date DESC",
    [$student_id]
);

$existing_documents = [];
while ($doc = pg_fetch_assoc($docs_query)) {
    // Convert absolute file path to web-accessible relative path
    $file_path = $doc['file_path'];
    
    // Check if it's an absolute path
    if (strpos($file_path, 'c:\\xampp\\htdocs\\EducAid\\') === 0 || strpos($file_path, 'C:\\xampp\\htdocs\\EducAid\\') === 0) {
        // Convert to relative path from this module's location
        $file_path = '../../' . str_replace(['c:\\xampp\\htdocs\\EducAid\\', 'C:\\xampp\\htdocs\\EducAid\\'], '', $file_path);
        $file_path = str_replace('\\', '/', $file_path); // Convert backslashes to forward slashes
    } elseif (strpos($file_path, '/xampp/htdocs/EducAid/') === 0 || strpos($file_path, dirname(dirname(__DIR__)) . '/') === 0) {
        // Linux/Mac absolute path
        $file_path = '../../' . str_replace(dirname(dirname(__DIR__)) . '/', '', $file_path);
    } elseif (strpos($file_path, 'assets/uploads/') === 0) {
        // Relative path stored in DB - add ../../ prefix for web access from modules/student/
        $file_path = '../../' . $file_path;
    }
    
    $doc['file_path'] = $file_path;
    $existing_documents[$doc['document_type_code']] = $doc;
}

// Document type mapping
$document_types = [
    '04' => [
        'code' => '04',
        'name' => 'ID Picture',
        'icon' => 'person-badge',
        'accept' => 'image/jpeg,image/jpg,image/png',
        'required' => false
    ],
    '00' => [
        'code' => '00',
        'name' => 'Enrollment Assistance Form (EAF)',
        'icon' => 'file-earmark-text',
        'accept' => 'image/jpeg,image/jpg,image/png,application/pdf',
        'required' => true
    ],
    '01' => [
        'code' => '01',
        'name' => 'Academic Grades',
        'icon' => 'file-earmark-bar-graph',
        'accept' => 'image/jpeg,image/jpg,image/png,application/pdf',
        'required' => true
    ],
    '02' => [
        'code' => '02',
        'name' => 'Letter to Mayor',
        'icon' => 'envelope',
        'accept' => 'image/jpeg,image/jpg,image/png,application/pdf',
        'required' => true
    ],
    '03' => [
        'code' => '03',
        'name' => 'Certificate of Indigency',
        'icon' => 'award',
        'accept' => 'image/jpeg,image/jpg,image/png,application/pdf',
        'required' => true
    ]
];

// Initialize session for temporary uploads if not exists
if (!isset($_SESSION['temp_uploads'])) {
    $_SESSION['temp_uploads'] = [];
}

// Handle AJAX OCR processing before regular form handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_document'])) {
    // Suppress error display for AJAX requests (log errors instead)
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    
    // Clean output buffer and set JSON header immediately
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    try {
        if (!$can_upload) {
            echo json_encode(['success' => false, 'message' => 'Document uploads are currently disabled for your account.']);
            exit;
        }

        $doc_type_code = $_POST['document_type'] ?? '';

        if (!isset($document_types[$doc_type_code])) {
            echo json_encode(['success' => false, 'message' => 'Invalid document type provided.']);
            exit;
        }

        if (!isset($_SESSION['temp_uploads'][$doc_type_code])) {
            echo json_encode(['success' => false, 'message' => 'No temporary file found. Please upload the document again.']);
            exit;
        }

        $tempData = $_SESSION['temp_uploads'][$doc_type_code];
        $tempPath = $tempData['path'] ?? null;

        if (!$tempPath || !file_exists($tempPath)) {
            echo json_encode(['success' => false, 'message' => 'Temporary file is missing or expired. Please re-upload the document.']);
            exit;
        }

        // Use NEW TSV-based OCR for Enrollment Forms (document type '00')
        if ($doc_type_code === '00') {
            try {
                $enrollmentOCR = new EnrollmentFormOCRService($connection);
                $courseMappingService = new CourseMappingService($connection);
                
                $studentData = [
                    'first_name' => $student['first_name'] ?? '',
                    'middle_name' => $student['middle_name'] ?? '',
                    'last_name' => $student['last_name'] ?? '',
                    'university_name' => $student['university_name'] ?? '',
                    'year_level' => $student['year_level_name'] ?? ''
                ];
                
                $ocrResult = $enrollmentOCR->processEnrollmentForm($tempPath, $studentData);
                
                if (!$ocrResult['success']) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'TSV OCR processing failed: ' . ($ocrResult['error'] ?? 'Unknown error')
                    ]);
                    exit;
                }
                
                $extracted = $ocrResult['data'];
                $overallConfidence = $ocrResult['overall_confidence'];
                $tsvQuality = $ocrResult['tsv_quality'];
                
                // Process course if found
                $courseData = null;
                if ($extracted['course']['found']) {
                    $courseMatch = $courseMappingService->findMatchingCourse($extracted['course']['normalized']);
                    
                    if ($courseMatch) {
                        $courseData = [
                            'raw_course' => $extracted['course']['raw'],
                            'normalized_course' => $courseMatch['normalized_course'],
                            'course_category' => $courseMatch['course_category'],
                            'program_duration' => $courseMatch['program_duration_years'],
                            'confidence' => $courseMatch['confidence']
                        ];
                    }
                }
                
                // Store TSV OCR results in session
                $_SESSION['temp_uploads'][$doc_type_code]['ocr_confidence'] = $overallConfidence;
                $_SESSION['temp_uploads'][$doc_type_code]['verification_score'] = $ocrResult['verification_passed'] ? 100 : ($overallConfidence * 0.8);
                $_SESSION['temp_uploads'][$doc_type_code]['verification_status'] = $ocrResult['verification_passed'] ? 'verified' : 'pending';
                $_SESSION['temp_uploads'][$doc_type_code]['ocr_processed_at'] = date('Y-m-d H:i:s');
                $_SESSION['temp_uploads'][$doc_type_code]['tsv_quality'] = $tsvQuality;
                $_SESSION['temp_uploads'][$doc_type_code]['extracted_data'] = $extracted;
                $_SESSION['temp_uploads'][$doc_type_code]['course_data'] = $courseData;
                
                echo json_encode([
                    'success' => true,
                    'message' => 'TSV OCR processing completed successfully.',
                    'ocr_confidence' => round($overallConfidence, 2),
                    'verification_score' => round($_SESSION['temp_uploads'][$doc_type_code]['verification_score'], 2),
                    'verification_status' => $ocrResult['verification_passed'] ? 'verified' : 'pending',
                    'tsv_quality' => [
                        'total_words' => $tsvQuality['total_words'],
                        'avg_confidence' => $tsvQuality['avg_confidence'],
                        'quality_score' => $tsvQuality['quality_score']
                    ],
                    'course_detected' => $courseData !== null,
                    'course_name' => $courseData['normalized_course'] ?? null
                ]);
                exit;
                
            } catch (Exception $e) {
                error_log('TSV OCR Error for enrollment form: ' . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'TSV OCR processing error: ' . $e->getMessage()
                ]);
                exit;
            }
        }
        
        // For other document types, use the old DocumentReuploadService
        $ocrResult = $reuploadService->processTempOcr(
            $student_id,
            $doc_type_code,
            $tempPath,
            [
                'student_id' => $student_id,
                'first_name' => $student['first_name'] ?? '',
                'last_name' => $student['last_name'] ?? '',
                'middle_name' => $student['middle_name'] ?? '',
                'university_id' => $student['university_id'] ?? null,
                'year_level_id' => $student['year_level_id'] ?? null,
                'university_name' => $student['university_name'] ?? '',
                'year_level_name' => $student['year_level_name'] ?? '',
                'barangay_name' => $student['barangay_name'] ?? ''
            ]
        );

        if ($ocrResult['success']) {
            $_SESSION['temp_uploads'][$doc_type_code]['ocr_confidence'] = $ocrResult['ocr_confidence'] ?? 0;
            $_SESSION['temp_uploads'][$doc_type_code]['verification_score'] = $ocrResult['verification_score'] ?? 0;
            $_SESSION['temp_uploads'][$doc_type_code]['verification_status'] = $ocrResult['verification_status'] ?? 'pending';
            $_SESSION['temp_uploads'][$doc_type_code]['ocr_processed_at'] = date('Y-m-d H:i:s');

            echo json_encode([
                'success' => true,
                'message' => 'OCR processing completed successfully.',
                'ocr_confidence' => round($ocrResult['ocr_confidence'] ?? 0, 2),
                'verification_score' => round($ocrResult['verification_score'] ?? 0, 2),
                'verification_status' => $ocrResult['verification_status'] ?? 'pending'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $ocrResult['message'] ?? 'OCR processing failed. Please try again.'
            ]);
        }
    } catch (Exception $e) {
        error_log('AJAX OCR Error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'An unexpected error occurred: ' . $e->getMessage()
        ]);
    }

    exit;
}

// Handle AJAX file upload to session (preview stage)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_upload']) && $can_upload) {
    // Suppress error display for AJAX requests
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    
    // Clean output buffer and set JSON header immediately
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    
    try {
        $doc_type_code = $_POST['document_type'] ?? '';
        
        if (!isset($document_types[$doc_type_code])) {
            error_log("AJAX Upload: Invalid document type: $doc_type_code");
            echo json_encode(['success' => false, 'message' => 'Invalid document type']);
            exit;
        }
        
        if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            $error_code = $_FILES['document_file']['error'] ?? 'none';
            error_log("AJAX Upload: File upload error code: $error_code");
            echo json_encode(['success' => false, 'message' => 'File upload error']);
            exit;
        }
        
        $file = $_FILES['document_file'];
        
        // Use DocumentReuploadService to upload to TEMP folder (WITHOUT automatic OCR)
        $result = $reuploadService->uploadToTemp(
            $student_id,
            $doc_type_code,
            $file['tmp_name'],
            $file['name'],
            [
                'student_id' => $student_id,
                'first_name' => $student['first_name'],
                'last_name' => $student['last_name'],
                'university_id' => $student['university_id'],
                'year_level_id' => $student['year_level_id']
            ]
        );
        
        if ($result['success']) {
            // Store temp file info in session for confirmation
            $_SESSION['temp_uploads'][$doc_type_code] = [
                'path' => $result['temp_path'],
                'original_name' => $file['name'],
                'extension' => pathinfo($file['name'], PATHINFO_EXTENSION),
                'size' => $file['size'],
                'uploaded_at' => time(),
                'ocr_confidence' => 0,
                'verification_score' => 0
            ];
            
            echo json_encode([
                'success' => true,
                'message' => 'File uploaded successfully. Click "Process OCR" to analyze the document.',
                'data' => [
                    'filename' => $file['name'],
                    'size' => $file['size'],
                    'extension' => pathinfo($file['name'], PATHINFO_EXTENSION)
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => $result['message']]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()]);
    }
    exit;
}

// Handle file upload to session (preview stage)
$upload_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_upload) {
    
    // Handle preview upload (temporary)
    if (isset($_POST['document_type']) && isset($_FILES['document_file']) && !isset($_POST['confirm_upload'])) {
        $doc_type_code = $_POST['document_type'];
        $file = $_FILES['document_file'];
        
        error_log("Preview upload - Student: $student_id, DocType: $doc_type_code, File: " . $file['name']);
        
        if (!isset($document_types[$doc_type_code])) {
            $upload_result = ['success' => false, 'message' => 'Invalid document type'];
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_result = ['success' => false, 'message' => 'File upload error: ' . $file['error']];
        } else {
            // Use DocumentReuploadService to upload to TEMP folder (with OCR processing)
            $result = $reuploadService->uploadToTemp(
                $student_id,
                $doc_type_code,
                $file['tmp_name'],
                $file['name'],
                [
                    'student_id' => $student_id,
                    'first_name' => $student['first_name'],
                    'last_name' => $student['last_name'],
                    'university_id' => $student['university_id'],
                    'year_level_id' => $student['year_level_id']
                ]
            );
            
            if ($result['success']) {
                // Store temp file info in session for confirmation
                $_SESSION['temp_uploads'][$doc_type_code] = [
                    'path' => $result['temp_path'],
                    'original_name' => $file['name'],
                    'extension' => pathinfo($file['name'], PATHINFO_EXTENSION),
                    'size' => $file['size'],
                    'uploaded_at' => time(),
                    'ocr_confidence' => $result['ocr_confidence'] ?? 0,
                    'verification_score' => $result['verification_score'] ?? 0
                ];
                
                $upload_result = [
                    'success' => true,
                    'message' => 'File ready for preview. Click "Confirm & Submit" to finalize.',
                    'preview' => true,
                    'ocr_confidence' => $result['ocr_confidence'] ?? 0,
                    'verification_score' => $result['verification_score'] ?? 0
                ];
                
                error_log("Preview saved to TEMP: " . $result['temp_path']);
            } else {
                $upload_result = ['success' => false, 'message' => $result['message']];
            }
        }
    }
    
    // Handle final confirmation (permanent upload)
    elseif (isset($_POST['confirm_upload']) && isset($_POST['document_type'])) {
        $doc_type_code = $_POST['document_type'];
        
        error_log("Confirm upload - Student: $student_id, DocType: $doc_type_code");
        
        if (!isset($_SESSION['temp_uploads'][$doc_type_code])) {
            error_log("ERROR: No temp upload found in session for document type: $doc_type_code");
            error_log("Session temp_uploads: " . print_r($_SESSION['temp_uploads'], true));
            $upload_result = ['success' => false, 'message' => 'No file to confirm. Please upload first.'];
        } else {
            $temp_data = $_SESSION['temp_uploads'][$doc_type_code];
            
            error_log("Temp data found: " . print_r($temp_data, true));
            
            // Check if temp file still exists
            if (!file_exists($temp_data['path'])) {
                error_log("ERROR: Temp file does not exist: " . $temp_data['path']);
                $upload_result = ['success' => false, 'message' => 'Temporary file has expired. Please upload again.'];
            } else {
                // Use DocumentReuploadService to move from TEMP to PERMANENT
                $result = $reuploadService->confirmUpload(
                    $student_id,
                    $doc_type_code,
                    $temp_data['path']
                );
                
                error_log("ConfirmUpload result: " . print_r($result, true));
                
                if ($result['success']) {
                    // Clear session temp data
                    unset($_SESSION['temp_uploads'][$doc_type_code]);
                    
                    $upload_result = ['success' => true, 'message' => 'Document submitted successfully and is now under review!'];
                    
                    // Refresh page to show new upload
                    header("Location: upload_document.php?success=1");
                    exit;
                } else {
                    $upload_result = ['success' => false, 'message' => $result['message'] ?? 'Upload failed'];
                    error_log("Permanent upload failed: " . $upload_result['message']);
                }
            }
        }
    }
    
    // Handle cancel preview
    elseif (isset($_POST['cancel_preview']) && isset($_POST['document_type'])) {
        $doc_type_code = $_POST['document_type'];
        
        if (isset($_SESSION['temp_uploads'][$doc_type_code])) {
            $temp_data = $_SESSION['temp_uploads'][$doc_type_code];
            
            // Use DocumentReuploadService to cancel preview
            $reuploadService->cancelPreview($temp_data['path']);
            unset($_SESSION['temp_uploads'][$doc_type_code]);
            
            $upload_result = ['success' => true, 'message' => 'Preview cancelled.'];
        }
    }
    
    // Handle re-upload of existing document (delete existing and start fresh)
    elseif (isset($_POST['start_reupload']) && isset($_POST['document_type'])) {
        $doc_type_code = $_POST['document_type'];
        
        // First, get the file path before deleting from database
        $file_query = pg_query_params($connection,
            "SELECT file_path FROM documents WHERE student_id = $1 AND document_type_code = $2",
            [$student_id, $doc_type_code]
        );
        
        $file_to_delete = null;
        if ($file_query && pg_num_rows($file_query) > 0) {
            $file_row = pg_fetch_assoc($file_query);
            $file_to_delete = $file_row['file_path'];
        }
        
        // Delete existing document from database
        $delete_query = pg_query_params($connection,
            "DELETE FROM documents WHERE student_id = $1 AND document_type_code = $2",
            [$student_id, $doc_type_code]
        );
        
        if ($delete_query) {
            // Delete the actual file and associated OCR files if they exist
            if ($file_to_delete) {
                $server_root = dirname(__DIR__, 2);
                
                // Convert web path to server path
                $file_path = $file_to_delete;
                if (strpos($file_path, '../../') === 0) {
                    $file_path = $server_root . '/' . substr($file_path, 6);
                }
                
                // Get the directory containing the file
                $file_dir = dirname($file_path);
                $file_basename = basename($file_path);
                
                error_log("Re-upload: Deleting files from directory - $file_dir");
                
                // Delete main file
                if (file_exists($file_path)) {
                    @unlink($file_path);
                    error_log("Re-upload: Deleted main file - $file_path");
                }
                
                // Delete associated OCR files (same directory, same basename + extensions)
                $ocr_extensions = ['.ocr.txt', '.tsv', '.verify.json', '.ocr.json'];
                foreach ($ocr_extensions as $ext) {
                    $ocr_file = $file_path . $ext;
                    if (file_exists($ocr_file)) {
                        @unlink($ocr_file);
                        error_log("Re-upload: Deleted OCR file - $ocr_file");
                    }
                }
                
                // Check if files are in a student-specific subdirectory (e.g., /student/{doc_type}/{student_id}/)
                // If the directory only contains this student's files, delete the entire directory
                if (is_dir($file_dir) && basename($file_dir) == $student_id) {
                    // This is a student-specific directory, delete all files in it
                    $files_in_dir = glob($file_dir . '/*');
                    foreach ($files_in_dir as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                            error_log("Re-upload: Deleted file from student directory - $file");
                        }
                    }
                    
                    // Try to remove the now-empty directory
                    if (count(glob($file_dir . '/*')) === 0) {
                        @rmdir($file_dir);
                        error_log("Re-upload: Removed empty student directory - $file_dir");
                    }
                }
            }
            
            // Clear any temp uploads for this document type
            if (isset($_SESSION['temp_uploads'][$doc_type_code])) {
                $temp_data = $_SESSION['temp_uploads'][$doc_type_code];
                $reuploadService->cancelPreview($temp_data['path']);
                unset($_SESSION['temp_uploads'][$doc_type_code]);
            }
            
            // Redirect to refresh the page and show upload form
            header("Location: upload_document.php?reupload_started=" . $doc_type_code);
            exit;
        } else {
            $upload_result = ['success' => false, 'message' => 'Failed to delete existing document. Please try again.'];
        }
    }
}

$page_title = 'Upload Documents';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - EducAid</title>
    
    <!-- Bootstrap 5.3.3 + Icons -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/css/student/sidebar.css">
    <link rel="stylesheet" href="../../assets/css/student/distribution_notifications.css">
    
    <style>
        body:not(.js-ready) .sidebar { visibility: hidden; transition: none !important; }
        
        .home-section {
            margin-left: 260px;
            padding: 2rem;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            background: #f8f9fa;
        }
        
        .sidebar.close ~ .home-section {
            margin-left: 78px;
        }
        
        @media (max-width: 768px) {
            .home-section {
                margin-left: 0;
                padding: 1rem;
            }
        }
        
        .page-header {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin: 0 0 0.5rem 0;
            color: #212529;
        }
        
        .page-header p {
            margin: 0;
            color: #6c757d;
        }
        
        .document-card {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .document-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .document-card.uploaded {
            border-color: #10b981;
            background: linear-gradient(to bottom, #f0fdf4, white);
        }
        
        .document-card.required {
            border-left: 4px solid #ef4444;
        }
        
        .document-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .document-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #0068da 0%, #0056b3 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            flex-shrink: 0;
        }
        
        .document-title {
            flex-grow: 1;
        }
        
        .document-title h5 {
            margin: 0 0 0.25rem 0;
            font-size: 1.125rem;
            font-weight: 600;
        }
        
        .document-preview {
            max-width: 100%;
            height: 200px;
            object-fit: contain;
            border-radius: 8px;
            margin: 1rem 0;
            cursor: pointer;
            border: 2px solid #e9ecef;
            transition: all 0.2s ease;
        }
        
        .document-preview:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }
        
        .upload-zone {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 1rem 0;
        }
        
        .upload-zone:hover {
            border-color: #0068da;
            background: #eef2ff;
        }
        
        .upload-zone.dragover {
            border-color: #0068da;
            background: #dbeafe;
            transform: scale(1.02);
        }
        
        .upload-zone i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        
        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .view-only-banner, .reupload-banner {
            padding: 1.25rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .view-only-banner {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
        }
        
        .view-only-banner.approved {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .reupload-banner {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .banner-content {
            display: flex;
            align-items: start;
            gap: 1rem;
        }
        
        .banner-content i {
            font-size: 2rem;
            flex-shrink: 0;
            margin-top: 0.25rem;
        }
        
        .banner-content h5 {
            margin: 0 0 0.5rem 0;
            font-weight: 600;
        }
        
        .banner-content p {
            margin: 0;
            opacity: 0.95;
        }
        
        .confidence-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.75rem;
        }
        
        .confidence-badge {
            padding: 0.25rem 0.625rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            color: white;
        }
        
        .document-meta {
            color: #6c757d;
            font-size: 0.875rem;
            margin-top: 0.75rem;
        }
        
        .document-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .pdf-preview {
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            margin: 1rem 0;
        }
        
        .pdf-preview i {
            font-size: 4rem;
            color: #dc3545;
        }
        
        .preview-document {
            background: #fffbea;
            border: 2px solid #fbbf24;
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        
        .preview-document .document-preview {
            border: 3px solid #fbbf24;
        }
        
        .preview-document .document-meta {
            background: white;
            padding: 0.75rem;
            border-radius: 8px;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <!-- Student Topbar -->
    <?php include '../../includes/student/student_topbar.php'; ?>
    
    <div id="wrapper" style="padding-top: var(--topbar-h, 60px);">
    <?php include '../../includes/student/student_sidebar.php'; ?>
    
    <!-- Main Header -->
    <?php include '../../includes/student/student_header.php'; ?>
    
    <section class="home-section" id="page-content-wrapper">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="flex-grow-1">
                        <h1><i class="bi bi-cloud-upload"></i> Upload Documents</h1>
                        <p>Manage your application documents</p>
                    </div>
                    <div class="text-center me-3" id="realtime-indicator" style="display: none;">
                        <small class="text-success d-block">
                            <i class="bi bi-arrow-repeat" style="animation: spin 2s linear infinite;"></i>
                            <span>Auto-updating</span>
                        </small>
                        <small class="text-muted" style="font-size: 0.7rem;">Checks every 1s</small>
                    </div>
                    <a href="student_homepage.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <style>
                @keyframes spin {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }
                
                @keyframes flashGreen {
                    0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
                    50% { box-shadow: 0 0 20px 10px rgba(16, 185, 129, 0.4); }
                    100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
                }
                
                .document-card.updated {
                    animation: flashGreen 1s ease-out;
                }
            </style>
            
            <!-- Testing Mode Banner -->
            <?php if ($test_mode): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-tools"></i> <strong>Testing Mode Active!</strong> Re-upload is enabled for testing OCR results. 
                Remove <code>?test_reupload=1</code> from URL to return to normal mode.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Debug Info (for testing) -->
            <?php if (isset($_GET['debug'])): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <strong>Debug Info:</strong><br>
                - student_status: <?= htmlspecialchars($student_status) ?><br>
                - needs_upload: <?= $needs_upload ? 'TRUE' : 'FALSE' ?><br>
                - can_upload: <?= $can_upload ? 'TRUE' : 'FALSE' ?><br>
                - documents_to_reupload: <?= !empty($documents_to_reupload) ? implode(', ', $documents_to_reupload) : 'NONE' ?><br>
                - is_new_registrant: <?= $is_new_registrant ? 'TRUE' : 'FALSE' ?><br>
                - test_mode: <?= $test_mode ? 'ENABLED' : 'DISABLED' ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Success Message -->
            <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <strong>Success!</strong> Document submitted successfully and awaiting admin approval.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Re-upload Started Message -->
            <?php if (isset($_GET['reupload_started'])): ?>
            <?php 
                $reupload_doc_code = $_GET['reupload_started'];
                $reupload_doc_name = $document_types[$reupload_doc_code]['name'] ?? 'Document';
            ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-arrow-repeat"></i> <strong>Re-upload Started!</strong> 
                The existing <?= htmlspecialchars($reupload_doc_name) ?> has been removed. 
                You can now upload a new file below.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Preview Success Message -->
            <?php if ($upload_result && $upload_result['success'] && isset($upload_result['preview'])): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle"></i> <strong>Preview Ready!</strong> <?= htmlspecialchars($upload_result['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php elseif ($upload_result && $upload_result['success']): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <strong>Success!</strong> <?= htmlspecialchars($upload_result['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Error Message -->
            <?php if ($upload_result && !$upload_result['success']): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <strong>Upload Failed!</strong> <?= htmlspecialchars($upload_result['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- View-Only Banner (New Registrants or Active Students or Students who received aid) -->
            <?php if ($is_new_registrant): ?>
            <div class="view-only-banner <?= ($student_status === 'active' || $student_status === 'given') ? 'approved' : '' ?>">
                <div class="banner-content">
                    <i class="bi bi-<?= ($student_status === 'active' || $student_status === 'given') ? 'check-circle' : 'info-circle' ?>"></i>
                    <div>
                        <?php if ($student_status === 'given'): ?>
                        <h5><i class="bi bi-lock-fill"></i> Aid Received - Read-Only Mode</h5>
                        <p>Your educational assistance has been distributed! Your status is now <strong>GIVEN</strong> and your documents have been locked for record-keeping purposes. You cannot modify or re-upload documents at this time. If you need assistance, please contact the admin.</p>
                        <?php elseif ($student_status === 'active'): ?>
                        <h5><i class="bi bi-lock-fill"></i> Documents Approved - Read-Only Mode</h5>
                        <p>Congratulations! Your application has been approved and your status is now <strong>ACTIVE</strong>. Your documents have been verified and locked for security. You cannot modify or re-upload documents at this time. If you need to make changes, please contact the admin.</p>
                        <?php else: ?>
                        <h5>View-Only Mode</h5>
                        <p>You registered through our online system and submitted all required documents during registration. Your documents are currently under review by our admin team. You cannot re-upload documents at this time.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Re-upload Banner (Existing Students) -->
            <?php if ($can_upload && !$test_mode): ?>
            <div class="reupload-banner">
                <div class="banner-content">
                    <i class="bi bi-arrow-repeat"></i>
                    <div>
                        <h5>Document Re-upload Required</h5>
                        <p>Please upload the required documents below. Your uploads will be saved directly to permanent storage and sent to the admin for immediate review.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Document Cards -->
            <div class="row">
                <?php foreach ($document_types as $type_code => $type_info): ?>
                <?php 
                    $has_document = isset($existing_documents[$type_code]);
                    $doc = $has_document ? $existing_documents[$type_code] : null;
                    $is_image = $has_document && preg_match('/\.(jpg|jpeg|png|gif)$/i', $doc['file_path']);
                    $is_pdf = $has_document && preg_match('/\.pdf$/i', $doc['file_path']);
                    
                    // Check if this document needs re-upload
                    $needs_reupload = $can_upload && in_array($type_code, $documents_to_reupload);
                    $is_view_only = !$needs_reupload;
                ?>
                <div class="col-lg-6">
                    <div class="document-card <?= $has_document ? 'uploaded' : '' ?> <?= $type_info['required'] ? 'required' : '' ?> <?= $needs_reupload ? 'border-warning' : '' ?>">
                        <div class="document-header">
                            <div class="document-icon">
                                <i class="bi bi-<?= $type_info['icon'] ?>"></i>
                            </div>
                            <div class="document-title">
                                <h5>
                                    <?= htmlspecialchars($type_info['name']) ?>
                                </h5>
                                <div>
                                    <?php if ($has_document): ?>
                                    <span class="status-badge bg-success text-white">
                                        <i class="bi bi-check-circle-fill"></i> Uploaded
                                    </span>
                                    <?php else: ?>
                                    <span class="status-badge bg-secondary text-white">
                                        <i class="bi bi-x-circle"></i> Not Uploaded
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($needs_reupload): ?>
                                    <span class="badge bg-warning text-dark ms-2">
                                        <i class="bi bi-arrow-repeat"></i> Needs Re-upload
                                    </span>
                                    <?php elseif ($is_view_only): ?>
                                    <span class="badge bg-info text-white ms-2">
                                        <i class="bi bi-eye"></i> View Only
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($type_info['required']): ?>
                                    <span class="badge bg-danger ms-2">Required</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($has_document): ?>
                        <!-- Show existing document -->
                        <div class="existing-document">
                            <?php if ($is_image): ?>
                            <img src="<?= htmlspecialchars($doc['file_path']) ?>" 
                                 class="document-preview"
                                 onclick="viewDocument('<?= addslashes($doc['file_path']) ?>', '<?= addslashes($type_info['name']) ?>')"
                                 alt="<?= htmlspecialchars($type_info['name']) ?>">
                            <?php elseif ($is_pdf): ?>
                            <div class="pdf-preview">
                                <i class="bi bi-file-pdf-fill"></i>
                                <p class="mb-0 mt-2"><strong>PDF Document</strong></p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="document-meta">
                                <i class="bi bi-calendar3"></i> 
                                Uploaded: <?= date('M d, Y g:i A', strtotime($doc['upload_date'])) ?>
                            </div>
                            
                            <div class="document-actions">
                                <button class="btn btn-primary btn-sm" 
                                        onclick="viewDocument('<?= addslashes($doc['file_path']) ?>', '<?= addslashes($type_info['name']) ?>')">
                                    <i class="bi bi-eye"></i> View
                                </button>
                                <a href="<?= htmlspecialchars($doc['file_path']) ?>" 
                                   download 
                                   class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-download"></i> Download
                                </a>
                                <?php if ($needs_reupload): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="document_type" value="<?= $type_code ?>">
                                    <input type="hidden" name="start_reupload" value="1">
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        <i class="bi bi-arrow-repeat"></i> Re-upload
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <!-- Upload form (only for documents that need re-upload) -->
                        <?php if ($needs_reupload): ?>
                            <?php 
                            // Check if there's a preview file in session
                            $has_preview = isset($_SESSION['temp_uploads'][$type_code]);
                            $preview_data = $has_preview ? $_SESSION['temp_uploads'][$type_code] : null;
                            ?>
                            
                            <?php if ($has_preview): ?>
                            <!-- Preview Mode -->
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-exclamation-triangle"></i> 
                                <strong>Preview Mode:</strong> File ready for submission. Review and confirm below.
                            </div>
                            
                            <div class="preview-document">
                                <?php 
                                $preview_is_image = in_array($preview_data['extension'], ['jpg', 'jpeg', 'png', 'gif']);
                                $preview_is_pdf = $preview_data['extension'] === 'pdf';
                                ?>
                                
                                <?php if ($preview_is_image): ?>
                                <img src="data:image/<?= $preview_data['extension'] ?>;base64,<?= base64_encode(file_get_contents($preview_data['path'])) ?>" 
                                     class="document-preview"
                                     alt="Preview">
                                <?php elseif ($preview_is_pdf): ?>
                                <div class="pdf-preview">
                                    <i class="bi bi-file-pdf-fill"></i>
                                    <p class="mb-0 mt-2"><strong>PDF Document Ready</strong></p>
                                    <small class="text-muted"><?= htmlspecialchars($preview_data['original_name']) ?></small>
                                </div>
                                <?php endif; ?>
                                
                                <div class="document-meta">
                                    <i class="bi bi-file-earmark"></i> <?= htmlspecialchars($preview_data['original_name']) ?>
                                    <span class="ms-2">
                                        <i class="bi bi-hdd"></i> <?= number_format($preview_data['size'] / 1024, 2) ?> KB
                                    </span>
                                    <?php if (isset($preview_data['uploaded_at'])): ?>
                                    <span class="ms-2">
                                        <i class="bi bi-clock"></i> <?= is_numeric($preview_data['uploaded_at']) ? date('g:i A', $preview_data['uploaded_at']) : $preview_data['uploaded_at'] ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="document-actions mt-3">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="document_type" value="<?= $type_code ?>">
                                        <input type="hidden" name="confirm_upload" value="1">
                                        <button type="submit" 
                                                class="btn btn-success btn-sm"
                                                id="confirm-btn-<?= $type_code ?>">
                                            <i class="bi bi-check-circle"></i> Confirm & Submit
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="document_type" value="<?= $type_code ?>">
                                        <input type="hidden" name="cancel_preview" value="1">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="bi bi-x-circle"></i> Cancel & Replace
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php else: ?>
                            <!-- Upload Zone -->
                            <div class="upload-zone" id="upload-zone-<?= $type_code ?>" onclick="document.getElementById('file-<?= $type_code ?>').click()">
                                <i class="bi bi-cloud-upload"></i>
                                <p class="mb-2 mt-2"><strong>Click to upload or drag and drop</strong></p>
                                <p class="text-muted small mb-0">
                                    Accepted: <?= str_replace(['image/', 'application/'], '', $type_info['accept']) ?>
                                </p>
                                <form method="POST" enctype="multipart/form-data" id="form-<?= $type_code ?>" style="display: none;">
                                    <input type="hidden" name="document_type" value="<?= $type_code ?>">
                                    <input type="file" 
                                           name="document_file" 
                                           id="file-<?= $type_code ?>"
                                           accept="<?= $type_info['accept'] ?>"
                                           onchange="this.form.submit()">
                                </form>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                        <div class="alert alert-light">
                            <i class="bi bi-info-circle"></i> No document uploaded yet. <?= $is_view_only ? '(View-only mode)' : '' ?>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    
    </div><!-- #wrapper -->
    
    <!-- Priority Notification Modal (for rejected documents) -->
    <?php include '../../includes/student/priority_notification_modal.php'; ?>
    
    <!-- Document Viewer Modal -->
    <div class="modal fade" id="documentViewerModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentViewerTitle">Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center" style="background: #000;">
                    <img id="documentViewerImage" src="" style="max-width: 100%; max-height: 80vh; display: none;">
                    <iframe id="documentViewerPdf" src="" style="width: 100%; height: 80vh; display: none; border: none;"></iframe>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/student/sidebar.js"></script>
    
    <!-- Real-Time Distribution Monitor -->
    <script src="../../assets/js/student/distribution_monitor.js"></script>
    
    <script>
        // Mark body as ready after scripts load
        document.body.classList.add('js-ready');
        
        // AJAX Upload Function (prevents page refresh)
        async function handleFileUpload(typeCode, file) {
            const formData = new FormData();
            formData.append('ajax_upload', '1');
            formData.append('document_type', typeCode);
            formData.append('document_file', file);
            
            try {
                const response = await fetch('upload_document.php', {
                    method: 'POST',
                    body: formData
                });
                
                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Server returned non-JSON response:', text.substring(0, 500));
                    throw new Error('Server error: Expected JSON response but got ' + contentType);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    // Small delay to ensure response is fully processed before reload
                    await new Promise(resolve => setTimeout(resolve, 100));
                    
                    // Reload the page to show the preview (remove any URL parameters)
                    window.location.href = 'upload_document.php';
                } else {
                    alert('Upload failed: ' + data.message);
                }
            } catch (error) {
                console.error('Upload error:', error);
                
                // If it's a "Failed to fetch" error but the file might have uploaded,
                // reload the page after a delay to check
                if (error.message && error.message.includes('Failed to fetch')) {
                    console.log('Network error detected, reloading page to check upload status...');
                    setTimeout(() => {
                        window.location.href = 'upload_document.php';
                    }, 500);
                } else {
                    alert('Upload failed: ' + (error.message || 'Please try again.'));
                }
            }
        }
        
        function viewDocument(filePath, title) {
            const modal = new bootstrap.Modal(document.getElementById('documentViewerModal'));
            const img = document.getElementById('documentViewerImage');
            const pdf = document.getElementById('documentViewerPdf');
            const titleEl = document.getElementById('documentViewerTitle');
            
            titleEl.textContent = title;
            
            // Reset
            img.style.display = 'none';
            pdf.style.display = 'none';
            img.src = '';
            pdf.src = '';
            
            if (filePath.match(/\.(jpg|jpeg|png|gif)$/i)) {
                img.src = filePath;
                img.style.display = 'block';
            } else if (filePath.match(/\.pdf$/i)) {
                pdf.src = filePath;
                pdf.style.display = 'block';
            }
            
            modal.show();
        }
        
        function showUploadForm(typeCode) {
            document.getElementById('file-' + typeCode).click();
        }
        
        // Attach AJAX upload handlers to file inputs
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[type="file"][name="document_file"]').forEach(input => {
                input.addEventListener('change', function(e) {
                    e.preventDefault();
                    
                    if (this.files.length > 0) {
                        const typeCode = this.closest('form').querySelector('input[name="document_type"]').value;
                        handleFileUpload(typeCode, this.files[0]);
                    }
                });
            });
        });
        
        // Drag and drop support
        document.querySelectorAll('.upload-zone').forEach(zone => {
            zone.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.add('dragover');
            });
            
            zone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.remove('dragover');
            });
            
            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.remove('dragover');
                
                const zoneId = zone.id.replace('upload-zone-', '');
                
                if (e.dataTransfer.files.length > 0) {
                    handleFileUpload(zoneId, e.dataTransfer.files[0]);
                }
            });
        });
        
        // Real-time status update checker
        let lastStatusCheck = '';
        let isCheckingStatus = false;
        
        // Show approval notification
        function showApprovalNotification() {
            // Create toast notification
            const toast = document.createElement('div');
            toast.className = 'position-fixed top-0 end-0 p-3';
            toast.style.zIndex = '9999';
            toast.innerHTML = `
                <div class="toast show align-items-center text-white bg-success border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <strong>Congratulations!</strong> Your application has been approved! 🎉
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            document.body.appendChild(toast);
            
            // Play a subtle success sound if available
            try {
                const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBTGH0fPTgjMGHm7A7+OZUR0KR5vi8bllHAU2jdXzzH0pBSh+zPLaizsIGGS56+mjUBcLTKXh8bllHAY0idXz0H8qBSd+y/Lbiz4KGGi56eqiTxYKSp/h8bpmHQU3jdTz0H4rBSZ9zPPai0AKGWS46OmmUhgKTKPh8bllHAY0idTzz38rBSZ+zPPajEEKGGe56emnUxcLTKLh8bllHAU2jdTz0H8rBSd+y/PajEAKGWa56eqmUhcLTKPh8rplHAU2jdTzz34rBSd+y/PajEELGWW46OqmUhgLTKPh8rpkHAY3jdT00H8rBSZ9zPLajEEKGGa56eqmURgLTKLg8rplHQU3jdTzzn8rBSZ9y/PajD8KF2S56+mnUhcKS6Lg8rpkHAY3jdXy0H4rBSV9y/PajEELGGW46OqnUhcLTKPh8rpkHAY3jdTy0H8rBSZ+y/PajD8JGGa66OmnUhgLTKPh8rpkHAY3jdXyz34qBSZ9y/PajEEKGGW46OqnUxgLTKLh8rpkHAY3jdTy0H8rBSZ+y/PajEAKGGW46OqmUhcLTKPh8rpkHAY3jdTz0H8rBSZ9y/PajEELGWa56eqnUhcLTKLh8rpkHAY3jdTyz34qBSZ9y/PajD8KF2S56+mnUhgLTKPh8rpkHAY2jdTy0H8qBSZ9y/PajEEKGGa56OqnUhcLTKLh8rpkHAY3jdXyz38qBSZ9y/PajD8KF2W56+mnUhgLTKPh8rpkHAY3jdTy0H8qBSZ+y/PajEEKGGa56OqmUhcLTKLh8rpkHAY3jdXy0H8qBSZ+y/PajD8KF2W56+mnUhcKS6Lg8rplHAU2jdTzz34rBSZ9y/PajEEKGGa56OqmUhcKTKPh8rpkHAY3jdXy0H8qBSZ9y/PajD8JGGa56+mnUhcLTKPh8rpkHAY3jdTz0H8rBSZ+y/PajEEKGGa46OqmUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEELGWa56OqnUhcLTKPh8rpkHAY3jdXy0H8qBSZ9y/PbjEEKGGW56eqnUxgLTKLh8rpkHAY3jdTy0H4rBSZ9y/PajEEKGGa56OqmUhcLTKLh8rpkHAY2jdTz0H8rBSZ+y/PajEEKGGa46OqmUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEEKGGa56OqnUhcKTKLh8rpkHAY3jdXy0H4rBSZ9y/PajEEKGGa56OqmUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEEKGGa56OqnUhcLTKLh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2S56+mmUhgLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEEKGGa56OqmUhcLTKPh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2S56+mnUhcKTKPh8rpkHAY2jdTy0H8qBSZ9y/PajEEKGGa56OqmUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2W56+mnUhcKS6Lg8rplHAU2jdTzz34rBSZ9y/PajEEKGGa56OqmUhcLTKPh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEEKGGa56OqnUhcLTKPh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2W56+mnUhgLTKPh8rpkHAY2jdTz0H8rBSZ9y/PajEEKGGa46OqmUhcKTKPh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2W56+mnUhgLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KF2W56+mnUhgLTKPh8rpkHAY3jdTy0H4rBSZ9y/PajEEKGGa56OqmUhcKTKLh8rpkHAY3jdXy0H4rBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajEEKGGa56OqmUhcLTKPh8rpkHAY3jdTy0H4rBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KF2W56+mnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8KGGa56OqnUhcLTKPh8rpkHAY3jdTy0H8qBSZ9y/PajD8=');
                audio.volume = 0.3;
                audio.play().catch(() => {}); // Ignore if audio fails
            } catch (e) {}
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                toast.remove();
            }, 5000);
        }
        
        async function checkDocumentStatus() {
            // Skip if already checking
            if (isCheckingStatus) return;
            
            isCheckingStatus = true;
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                const html = await response.text();
                
                // Only update if content has changed
                if (html !== lastStatusCheck) {
                    // Parse response to extract document cards
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newDocumentCards = doc.querySelectorAll('.document-card');
                    const currentDocumentCards = document.querySelectorAll('.document-card');
                    
                    // Update each document card if content changed
                    newDocumentCards.forEach((newCard, index) => {
                        if (currentDocumentCards[index]) {
                            const currentCardHTML = currentDocumentCards[index].innerHTML;
                            const newCardHTML = newCard.innerHTML;
                            
                            if (currentCardHTML !== newCardHTML) {
                                // Smooth update with fade effect
                                currentDocumentCards[index].style.opacity = '0.5';
                                setTimeout(() => {
                                    currentDocumentCards[index].innerHTML = newCardHTML;
                                    currentDocumentCards[index].style.opacity = '1';
                                    
                                    // Add flash animation
                                    currentDocumentCards[index].classList.add('updated');
                                    setTimeout(() => {
                                        currentDocumentCards[index].classList.remove('updated');
                                    }, 1000);
                                }, 200);
                                
                                console.log('✅ Document card updated:', index);
                            }
                        }
                    });
                    
                    // Also check if banner status changed (applicant -> active)
                    const newBanner = doc.querySelector('.view-only-banner, .reupload-banner');
                    const currentBanner = document.querySelector('.view-only-banner, .reupload-banner');
                    
                    if (newBanner && currentBanner) {
                        const newBannerHTML = newBanner.outerHTML;
                        const currentBannerHTML = currentBanner.outerHTML;
                        
                        if (newBannerHTML !== currentBannerHTML) {
                            // Check if status changed to active (approved)
                            const wasApproved = newBanner.classList.contains('approved') && !currentBanner.classList.contains('approved');
                            
                            currentBanner.outerHTML = newBannerHTML;
                            console.log('🎉 Banner status updated');
                            
                            // Show celebration notification if approved
                            if (wasApproved) {
                                showApprovalNotification();
                            }
                        }
                    }
                    
                    lastStatusCheck = html;
                }
            } catch (error) {
                console.error('Status check failed:', error);
            } finally {
                isCheckingStatus = false;
            }
        }
        
        // Start real-time status checking for all students
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Real-time status updates enabled for all students');
            
            const indicator = document.getElementById('realtime-indicator');
            
            // Show the auto-update indicator
            if (indicator) {
                indicator.style.display = 'block';
            }
            
            // Check immediately on load
            setTimeout(checkDocumentStatus, 1000);
            // Then check every 1 second
            setInterval(checkDocumentStatus, 1000);
        });
    </script>
</body>
</html>
