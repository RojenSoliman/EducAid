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

// Handle file upload (for re-upload students only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_upload) {
    $upload_result = ['success' => false, 'message' => ''];
    
    if (isset($_POST['document_type']) && isset($_FILES['document_file'])) {
        $doc_type_code = $_POST['document_type'];
        $file = $_FILES['document_file'];
        
        if (!isset($document_types[$doc_type_code])) {
            $upload_result['message'] = 'Invalid document type';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_result['message'] = 'File upload error: ' . $file['error'];
        } else {
            // Use DocumentReuploadService to upload directly to permanent storage
            // This is for rejected applicants who need to re-upload specific documents
            $result = $reuploadService->uploadDocument(
                $student_id,
                $doc_type_code,
                $file['tmp_name'],
                $file['name'],
                $student // Pass student data for OCR verification
            );
            
            if ($result['success']) {
                $upload_result['success'] = true;
                $upload_result['message'] = 'Document uploaded successfully! Your document is now being reviewed by the admin.';
                
                // Refresh page to show new upload
                header("Location: upload_document.php?success=1");
                exit;
            } else {
                $upload_result['message'] = $result['message'] ?? 'Upload failed';
            }
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
            
            <!-- Success Message -->
            <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <strong>Success!</strong> Document uploaded successfully and awaiting admin approval.
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
            <?php if ($can_upload): ?>
            <div class="reupload-banner">
                <div class="banner-content">
                    <i class="bi bi-arrow-repeat"></i>
                    <div>
                        <h5>Document Re-upload Required</h5>
                        <p>Please upload all required documents below. Your uploads will be saved to a temporary folder and sent to the admin for approval. Once approved, they will be moved to permanent storage.</p>
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
                                 onclick="viewDocument('<?= htmlspecialchars($doc['file_path']) ?>', '<?= htmlspecialchars($type_info['name']) ?>')"
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
                                        onclick="viewDocument('<?= htmlspecialchars($doc['file_path']) ?>', '<?= htmlspecialchars($type_info['name']) ?>')">
                                    <i class="bi bi-eye"></i> View
                                </button>
                                <a href="<?= htmlspecialchars($doc['file_path']) ?>" 
                                   download 
                                   class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-download"></i> Download
                                </a>
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
