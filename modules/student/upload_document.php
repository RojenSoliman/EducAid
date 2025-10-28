<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/DocumentService.php';
require_once __DIR__ . '/../../services/DocumentReuploadService.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../../unified_login.php");
    exit;
}

$student_id = $_SESSION['student_id'];
$docService = new DocumentService($connection);
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

// TESTING MODE: Allow re-upload if ?test_reupload=1 is in URL (REMOVE IN PRODUCTION)
$test_mode = isset($_GET['test_reupload']) && $_GET['test_reupload'] == '1';
if ($test_mode) {
    $needs_upload = true;
}

$can_upload = $needs_upload; // Only students who need re-upload can upload
$is_new_registrant = !$needs_upload;

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
            verification_data_path, ocr_text_path
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
                'year_level_id' => $student['year_level_id'] ?? null
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
            $upload_result = ['success' => false, 'message' => 'No file to confirm. Please upload first.'];
        } else {
            $temp_data = $_SESSION['temp_uploads'][$doc_type_code];
            
            // Use DocumentReuploadService to move from TEMP to PERMANENT
            $result = $reuploadService->confirmUpload(
                $student_id,
                $doc_type_code,
                $temp_data['path']
            );
            
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    
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
                    <div>
                        <h1><i class="bi bi-cloud-upload"></i> Upload Documents</h1>
                        <p>Manage your application documents</p>
                    </div>
                    <a href="student_homepage.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
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
            
            <!-- View-Only Banner (New Registrants) -->
            <?php if ($is_new_registrant): ?>
            <div class="view-only-banner">
                <div class="banner-content">
                    <i class="bi bi-info-circle"></i>
                    <div>
                        <h5>View-Only Mode</h5>
                        <p>You registered through our online system and submitted all required documents during registration. Your documents are currently under review by our admin team. You cannot re-upload documents at this time.</p>
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
                            
                            <!-- Confidence Badges -->
                            <?php if ($doc['ocr_confidence'] || $doc['verification_score']): ?>
                            <div class="confidence-badges">
                                <?php if ($doc['ocr_confidence']): ?>
                                <?php 
                                    $ocr_conf = floatval($doc['ocr_confidence']);
                                    $ocr_color = $ocr_conf >= 80 ? 'success' : ($ocr_conf >= 60 ? 'warning' : 'danger');
                                ?>
                                <span class="confidence-badge bg-<?= $ocr_color ?>">
                                    <i class="bi bi-robot"></i> OCR: <?= round($ocr_conf) ?>%
                                </span>
                                <?php endif; ?>
                                
                                <?php if ($doc['verification_score']): ?>
                                <?php 
                                    $verify_score = floatval($doc['verification_score']);
                                    $verify_color = $verify_score >= 80 ? 'success' : ($verify_score >= 60 ? 'warning' : 'danger');
                                ?>
                                <span class="confidence-badge bg-<?= $verify_color ?>">
                                    <i class="bi bi-check-circle-fill"></i> Verified: <?= round($verify_score) ?>%
                                </span>
                                <?php endif; ?>
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
                                <?php 
                                // Check if OCR files exist
                                $ocr_text_file = $doc['file_path'] . '.ocr.txt';
                                $verify_json_file = $doc['file_path'] . '.verify.json';
                                $server_root = dirname(__DIR__, 2);
                                $ocr_text_exists = file_exists($server_root . '/' . ltrim(str_replace('../../', '', $ocr_text_file), '/'));
                                $verify_json_exists = file_exists($server_root . '/' . ltrim(str_replace('../../', '', $verify_json_file), '/'));
                                ?>
                                <?php if ($ocr_text_exists || $verify_json_exists): ?>
                                <div class="btn-group btn-group-sm" role="group">
                                    <?php if ($ocr_text_exists): ?>
                                    <a href="<?= htmlspecialchars($ocr_text_file) ?>" 
                                       target="_blank"
                                       class="btn btn-outline-info btn-sm">
                                        <i class="bi bi-file-text"></i> OCR Text
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($verify_json_exists): ?>
                                    <a href="<?= htmlspecialchars($verify_json_file) ?>" 
                                       target="_blank"
                                       class="btn btn-outline-success btn-sm">
                                        <i class="bi bi-file-code"></i> Verification
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($needs_reupload): ?>
                                <button class="btn btn-warning btn-sm" 
                                        onclick="showUploadForm('<?= $type_code ?>')">
                                    <i class="bi bi-arrow-repeat"></i> Re-upload
                                </button>
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
                                
                                <?php 
                                $has_ocr = isset($preview_data['ocr_confidence']) && floatval($preview_data['ocr_confidence']) > 0;
                                ?>
                                <div class="confidence-badges <?= $has_ocr ? '' : 'd-none' ?>" id="ocr-badges-<?= $type_code ?>">
                                    <?php if ($has_ocr): ?>
                                        <?php 
                                            $ocr_conf = floatval($preview_data['ocr_confidence']);
                                            $ocr_color = $ocr_conf >= 80 ? 'success' : ($ocr_conf >= 60 ? 'warning' : 'danger');
                                        ?>
                                        <span class="confidence-badge bg-<?= $ocr_color ?>">
                                            <i class="bi bi-robot"></i> OCR Confidence: <?= round($ocr_conf) ?>%
                                        </span>
                                        <?php if (isset($preview_data['verification_score'])): ?>
                                            <?php 
                                                $verify_score = floatval($preview_data['verification_score']);
                                                if (abs($verify_score - $ocr_conf) > 0.1) {
                                                    $verify_color = $verify_score >= 80 ? 'success' : ($verify_score >= 60 ? 'warning' : 'danger');
                                                    echo '<span class="confidence-badge bg-' . $verify_color . '"><i class="bi bi-check-circle-fill"></i> Verification: ' . round($verify_score) . '%</span>';
                                                }
                                            ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-2 small" id="ocr-status-<?= $type_code ?>">
                                    <?php if ($has_ocr): ?>
                                        <span class="text-success"><i class="bi bi-check-circle"></i> OCR processed successfully.</span>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="bi bi-robot"></i> OCR not processed yet. Click "Process OCR" to analyze this document.</span>
                                    <?php endif; ?>
                                </div>
                                
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
                                    <?php $processLabel = $has_ocr ? 'Reprocess OCR' : 'Process OCR'; ?>
                                    <button type="button"
                                            class="btn btn-primary btn-sm"
                                            id="process-btn-<?= $type_code ?>"
                                            data-default-label="<?= htmlspecialchars($processLabel) ?>"
                                            onclick="processDocument('<?= $type_code ?>')">
                                        <i class="bi bi-cpu"></i> <?= htmlspecialchars($processLabel) ?>
                                    </button>

                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="document_type" value="<?= $type_code ?>">
                                        <input type="hidden" name="confirm_upload" value="1">
                                        <button type="submit" class="btn btn-success btn-sm">
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/student/sidebar.js"></script>
    
    <!-- Real-Time Distribution Monitor -->
    <script src="../../assets/js/student/distribution_monitor.js"></script>
    
    <script>
        // Mark body as ready after scripts load
        document.body.classList.add('js-ready');
        
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
        
        // OCR Processing Function
        async function processDocument(typeCode) {
            const button = document.getElementById('process-btn-' + typeCode);
            const statusEl = document.getElementById('ocr-status-' + typeCode);
            const badgesEl = document.getElementById('ocr-badges-' + typeCode);

            if (!button) {
                console.error('Process button not found for type:', typeCode);
                return;
            }

            const originalLabel = button.dataset.defaultLabel || button.innerText;

            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Processing...';

            if (statusEl) {
                statusEl.classList.remove('text-success', 'text-danger');
                statusEl.classList.add('text-muted');
                statusEl.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split"></i> Processing OCR...</span>';
            }

            const formData = new FormData();
            formData.append('process_document', '1');
            formData.append('document_type', typeCode);

            try {
                const response = await fetch('upload_document.php', {
                    method: 'POST',
                    body: formData
                });

                // Log response for debugging
                const responseText = await response.text();
                console.log('OCR Response:', responseText);

                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (jsonError) {
                    console.error('JSON Parse Error:', jsonError);
                    console.error('Response was:', responseText.substring(0, 500));
                    throw new Error('Server returned invalid response. Check console for details.');
                }

                if (!data.success) {
                    throw new Error(data.message || 'OCR processing failed.');
                }

                const ocrScore = Math.round(data.ocr_confidence || 0);
                const verificationScore = Math.round(data.verification_score || ocrScore);

                if (badgesEl) {
                    badgesEl.classList.remove('d-none');
                    const ocrColor = getConfidenceColor(ocrScore);
                    let badgesHtml = `
                        <span class="confidence-badge bg-${ocrColor}">
                            <i class="bi bi-robot"></i> OCR Confidence: ${ocrScore}%
                        </span>
                    `;

                    if (Math.abs(verificationScore - ocrScore) > 0.1) {
                        const verifyColor = getConfidenceColor(verificationScore);
                        badgesHtml += `
                            <span class="confidence-badge bg-${verifyColor}">
                                <i class="bi bi-check-circle-fill"></i> Verification: ${verificationScore}%
                            </span>
                        `;
                    }

                    badgesEl.innerHTML = badgesHtml;
                }

                if (statusEl) {
                    statusEl.classList.remove('text-muted', 'text-danger');
                    statusEl.classList.add('text-success');
                    statusEl.innerHTML = `<span class="text-success"><i class="bi bi-check-circle"></i> OCR processed successfully. Confidence ${ocrScore}%.</span>`;
                }

                button.dataset.defaultLabel = 'Reprocess OCR';
                button.innerHTML = '<i class="bi bi-arrow-repeat"></i> Reprocess OCR';

            } catch (error) {
                console.error('OCR processing error:', error);
                
                if (statusEl) {
                    statusEl.classList.remove('text-muted', 'text-success');
                    statusEl.classList.add('text-danger');
                    statusEl.innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> ${error.message}</span>`;
                }

                button.innerHTML = `<i class="bi bi-cpu"></i> ${originalLabel}`;

            } finally {
                button.disabled = false;
            }
        }

        function getConfidenceColor(score) {
            if (score >= 80) {
                return 'success';
            }
            if (score >= 60) {
                return 'warning';
            }
            return 'danger';
        }
        
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
                const fileInput = document.getElementById('file-' + zoneId);
                
                if (e.dataTransfer.files.length > 0) {
                    fileInput.files = e.dataTransfer.files;
                    fileInput.form.submit();
                }
            });
        });
    </script>
</body>
</html>
