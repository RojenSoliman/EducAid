<?php
include '../../config/database.php';
// Check if student is logged in
session_start();
// Redirect if not logged in
if (!isset($_SESSION['student_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
// Session flash for upload status
$flash_success = false;
$flash_fail = false;
if (isset($_SESSION['upload_success'])) {
    $flash_success = true;
    unset($_SESSION['upload_success']);
}
if (isset($_SESSION['upload_fail'])) {
    $flash_fail = true;
    unset($_SESSION['upload_fail']);
}

// Get student ID
$student_id = $_SESSION['student_id'];

// Check if all required documents are uploaded
$query = "SELECT COUNT(*) AS total_uploaded FROM documents WHERE student_id = $1 AND type IN ('id_picture', 'certificate_of_indigency', 'letter_to_mayor')";
/** @phpstan-ignore-next-line */
$result = pg_query_params($connection, $query, [$student_id]);
$row = pg_fetch_assoc($result);

// Check if grades are uploaded
$grades_query = "SELECT COUNT(*) AS grades_uploaded FROM grade_uploads WHERE student_id = $1";
$grades_result = pg_query_params($connection, $grades_query, [$student_id]);
$grades_row = pg_fetch_assoc($grades_result);

// Get latest grade upload status
$latest_grades_query = "SELECT * FROM grade_uploads WHERE student_id = $1 ORDER BY upload_date DESC LIMIT 1";
$latest_grades_result = pg_query_params($connection, $latest_grades_query, [$student_id]);
$latest_grades = pg_fetch_assoc($latest_grades_result);

if ($row['total_uploaded'] == 3 && $grades_row['grades_uploaded'] > 0) {
    $allDocumentsUploaded = true;
} else {
    $allDocumentsUploaded = false;
}
// If documents are not complete, clear any flash so form shows cleanly after a rejection
if (!$allDocumentsUploaded) {
    $flash_success = false;
    $flash_fail = false;
}

// Handle the file uploads
// Handle the file uploads
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES['documents']) && !$allDocumentsUploaded) {
    $student_name = $_SESSION['student_username']; // Assuming student_username is stored in the session
    $student_id = $_SESSION['student_id']; // Assuming student_id is stored in the session

    // Create a folder for the student if it doesn't exist
    $uploadDir = "../../assets/uploads/students/{$student_name}/";
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Process the uploaded files with PRG pattern
    $upload_success = false;
    $upload_fail = false;
    foreach ($_FILES['documents']['name'] as $index => $fileName) {
        $fileTmpName = $_FILES['documents']['tmp_name'][$index];
        $fileType = $_POST['document_type'][$index];

        // Validate the document type
        if (!in_array($fileType, ['id_picture', 'certificate_of_indigency', 'letter_to_mayor'])) {
            continue;
        }

        // Move the uploaded file to the student’s folder
        $filePath = $uploadDir . basename($fileName);
        if (move_uploaded_file($fileTmpName, $filePath)) {
            // Insert record into the documents table using escaped values
            // Escape values and insert record into the documents table
            /** @phpstan-ignore-next-line */
            @$esc_student_id = pg_escape_string($connection, $student_id);
            /** @phpstan-ignore-next-line */
            @$esc_type = pg_escape_string($connection, $fileType);
            /** @phpstan-ignore-next-line */
            @$esc_file_path = pg_escape_string($connection, $filePath);
            $sql = "INSERT INTO documents (student_id, type, file_path) VALUES ('{$esc_student_id}', '{$esc_type}', '{$esc_file_path}')";
            /** @phpstan-ignore-next-line */
            @pg_query($connection, $sql);
            $upload_success = true;
        } else {
            $upload_fail = true;
        }
    }

    // Set flash and redirect to avoid form resubmission
    if ($upload_success) {
        $_SESSION['upload_success'] = true;
    } else {
        $_SESSION['upload_fail'] = true;
    }
    header("Location: upload_document.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Document Upload - EducAid</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/student/sidebar.css" />
  <link rel="stylesheet" href="../../assets/css/student/upload.css" />
  <style>
    .grades-analysis-card {
      border: 2px solid #e9ecef;
      border-radius: 10px;
      padding: 20px;
      margin-top: 15px;
      background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    }
    
    .analysis-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 15px;
    }
    
    .confidence-badge {
      background: #17a2b8;
      color: white;
      padding: 4px 8px;
      border-radius: 15px;
      font-size: 0.85em;
      font-weight: 600;
    }
    
    .overall-status {
      text-align: center;
      padding: 15px;
      border-radius: 8px;
      margin: 15px 0;
      font-weight: 700;
      font-size: 1.1em;
    }
    
    .status-passed {
      background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
      color: #155724;
      border: 2px solid #28a745;
    }
    
    .status-failed {
      background: linear-gradient(135deg, #f8d7da 0%, #f1b0b7 100%);
      color: #721c24;
      border: 2px solid #dc3545;
    }
    
    .status-review {
      background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
      color: #856404;
      border: 2px solid #ffc107;
    }
    
    .grades-summary {
      display: flex;
      justify-content: space-around;
      margin: 15px 0;
      padding: 15px;
      background: rgba(0,123,255,0.1);
      border-radius: 8px;
    }
    
    .summary-item {
      text-align: center;
    }
    
    .summary-label {
      display: block;
      font-size: 0.9em;
      color: #6c757d;
      margin-bottom: 5px;
    }
    
    .summary-value {
      display: block;
      font-size: 1.5em;
      font-weight: 700;
      color: #007bff;
    }
    
    .extracted-grades h6 {
      color: #495057;
      border-bottom: 2px solid #007bff;
      padding-bottom: 5px;
      margin-bottom: 15px;
    }
    
    .grade-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px;
      margin-bottom: 8px;
      border-radius: 6px;
      border: 1px solid #dee2e6;
    }
    
    .grade-pass {
      background: linear-gradient(90deg, #d4edda 0%, #ffffff 100%);
      border-left: 4px solid #28a745;
    }
    
    .grade-fail {
      background: linear-gradient(90deg, #f8d7da 0%, #ffffff 100%);
      border-left: 4px solid #dc3545;
    }
    
    .subject-name {
      font-weight: 600;
      color: #495057;
      flex: 1;
    }
    
    .grade-value {
      font-weight: 700;
      color: #007bff;
      margin: 0 15px;
    }
    
    .grade-equivalent {
      font-size: 0.9em;
      color: #6c757d;
    }
    
    .grade-status {
      font-size: 1.2em;
    }
    
    .processing-indicator {
      text-align: center;
      padding: 20px;
      background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
      border-radius: 8px;
      margin-top: 15px;
    }
    
    .processing-indicator .spinner-border {
      color: #007bff;
      margin-bottom: 10px;
    }
    
    .gpa-indicator {
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      padding: 10px;
      margin-top: 10px;
      text-align: center;
    }
  </style>
</head>
<body>
  <div id="wrapper">
    <!-- Sidebar -->
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
    <!-- Main Content Area -->
    <section class="home-section upload-container with-sidebar" id="page-content-wrapper">
      <nav class="px-4 py-3 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
          <i class="bi bi-list" id="menu-toggle"></i>
          
        </div>
      
      </nav>
      
      <div class="container py-4">
        <div class="upload-card">
          <!-- Header Section -->
          <div class="upload-header">
            <h1>
              <i class="bi bi-cloud-upload-fill me-3"></i>
              Upload Documents
            </h1>
            <p>Complete your application by uploading all required documents</p>
          </div>

          <!-- Flash Messages -->
          <?php if (!empty($flash_success)): ?>
            <div class="alert alert-success mx-4 mt-4 success-animation">
              <i class="bi bi-check-circle-fill me-2"></i>
              <strong>Success!</strong> Documents uploaded successfully.
            </div>
          <?php elseif (!empty($flash_fail)): ?>
            <div class="alert alert-danger mx-4 mt-4">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>
              <strong>Error!</strong> Failed to upload documents. Please try again.
            </div>
          <?php endif; ?>

          <!-- Progress Section -->
          <div class="progress-section">
            <h3 class="progress-title">Upload Progress</h3>
            
            <div class="document-progress">
              <div class="progress-item">
                <div class="progress-icon <?php echo $row['total_uploaded'] >= 1 ? 'completed' : 'pending'; ?>">
                  <i class="bi bi-person-badge-fill"></i>
                </div>
                <div class="progress-label">ID Picture</div>
                <div class="progress-status">
                  <?php echo $row['total_uploaded'] >= 1 ? 'Uploaded' : 'Required'; ?>
                </div>
              </div>
              
              <div class="progress-item">
                <div class="progress-icon <?php echo $row['total_uploaded'] >= 2 ? 'completed' : 'pending'; ?>">
                  <i class="bi bi-file-text-fill"></i>
                </div>
                <div class="progress-label">Letter to Mayor</div>
                <div class="progress-status">
                  <?php echo $row['total_uploaded'] >= 2 ? 'Uploaded' : 'Required'; ?>
                </div>
              </div>
              
              <div class="progress-item">
                <div class="progress-icon <?php echo $row['total_uploaded'] >= 3 ? 'completed' : 'pending'; ?>">
                  <i class="bi bi-award-fill"></i>
                </div>
                <div class="progress-label">Certificate of Indigency</div>
                <div class="progress-status">
                  <?php echo $row['total_uploaded'] >= 3 ? 'Uploaded' : 'Required'; ?>
                </div>
              </div>
              
              <div class="progress-item">
                <div class="progress-icon <?php echo $grades_row['grades_uploaded'] > 0 ? 'completed' : 'pending'; ?>">
                  <i class="bi bi-file-earmark-text-fill"></i>
                </div>
                <div class="progress-label">Academic Grades</div>
                <div class="progress-status">
                  <?php echo $grades_row['grades_uploaded'] > 0 ? 'Uploaded' : 'Required'; ?>
                </div>
              </div>
            </div>

            <!-- Overall Progress Bar -->
            <div class="overall-progress">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold">Overall Progress</span>
                <span class="text-muted"><?php echo ($row['total_uploaded'] + ($grades_row['grades_uploaded'] > 0 ? 1 : 0)); ?> of 4 completed</span>
              </div>
              <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: <?php echo (($row['total_uploaded'] + ($grades_row['grades_uploaded'] > 0 ? 1 : 0)) / 4) * 100; ?>%"></div>
              </div>
            </div>
          </div>

          <?php if ($allDocumentsUploaded): ?>
            <!-- Completion State -->
            <div class="completion-state">
              <div class="completion-icon">
                <i class="bi bi-check-circle-fill"></i>
              </div>
              <h3>All Documents Uploaded!</h3>
              <p>
                Congratulations! You have successfully uploaded all required documents including your academic grades. 
                Your application is now complete and under review by our administration team.
                <br><br>
                <strong>Next Steps:</strong><br>
                • Wait for admin review<br>
                • Check your notifications for updates<br>
                • You can re-upload if admin requests changes
              </p>
              
              <?php if ($latest_grades): ?>
                <div class="grades-analysis-card">
                  <div class="analysis-header">
                    <h5><i class="bi bi-graph-up me-2"></i>Your Grades Status</h5>
                    <?php if ($latest_grades['ocr_confidence']): ?>
                      <span class="confidence-badge">OCR Confidence: <?= round($latest_grades['ocr_confidence'], 1) ?>%</span>
                    <?php endif; ?>
                  </div>
                  
                  <div class="overall-status <?php 
                    echo $latest_grades['validation_status'] === 'passed' ? 'status-passed' : 
                         ($latest_grades['validation_status'] === 'failed' ? 'status-failed' : 'status-review'); 
                  ?>">
                    <i class="bi <?php 
                      echo $latest_grades['validation_status'] === 'passed' ? 'bi-check-circle' : 
                           ($latest_grades['validation_status'] === 'failed' ? 'bi-x-circle' : 'bi-clock'); 
                    ?> me-2"></i>
                    <?php 
                      echo $latest_grades['validation_status'] === 'passed' ? 'GRADES MEET REQUIREMENTS' : 
                           ($latest_grades['validation_status'] === 'failed' ? 'GRADES BELOW MINIMUM (75% / 3.00)' : 
                            ($latest_grades['validation_status'] === 'manual_review' ? 'UNDER MANUAL REVIEW' : 'PENDING PROCESSING')); 
                    ?>
                  </div>
                  
                  <?php if ($latest_grades['admin_reviewed'] && $latest_grades['admin_notes']): ?>
                    <div class="alert alert-info">
                      <strong>Admin Notes:</strong> <?= htmlspecialchars($latest_grades['admin_notes']) ?>
                    </div>
                  <?php endif; ?>
                  
                  <div class="text-center mt-3">
                    <small class="text-muted">
                      <i class="bi bi-info-circle me-1"></i>
                      Philippine grading system: 1.00-3.00 (Passing) | 75%-100% (Passing)
                    </small>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <!-- Upload Form -->
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
              <div class="upload-form-section">
                <!-- ID Picture -->
                <div class="upload-form-item" data-document="id_picture">
                  <div class="upload-item-header">
                    <div class="upload-item-icon">
                      <i class="bi bi-person-badge-fill"></i>
                    </div>
                    <div class="upload-item-info">
                      <h4>ID Picture</h4>
                      <p>Upload a clear photo of your government-issued ID</p>
                    </div>
                  </div>
                  
                  <div class="custom-file-input">
                    <input type="file" name="documents[]" id="id_picture_input" accept=".pdf,.jpg,.jpeg,.png" required>
                    <input type="hidden" name="document_type[]" value="id_picture">
                    <div class="file-input-label">
                      <i class="bi bi-cloud-upload"></i>
                      <span>Choose file or drag and drop</span>
                    </div>
                  </div>
                  
                  <div class="file-preview" id="preview_id_picture"></div>
                </div>

                <!-- Letter to Mayor -->
                <div class="upload-form-item" data-document="letter_to_mayor">
                  <div class="upload-item-header">
                    <div class="upload-item-icon">
                      <i class="bi bi-file-text-fill"></i>
                    </div>
                    <div class="upload-item-info">
                      <h4>Letter to Mayor</h4>
                      <p>Official letter addressed to the mayor</p>
                    </div>
                  </div>
                  
                  <div class="custom-file-input">
                    <input type="file" name="documents[]" id="letter_to_mayor_input" accept="image/*,.pdf" required>
                    <input type="hidden" name="document_type[]" value="letter_to_mayor">
                    <div class="file-input-label">
                      <i class="bi bi-cloud-upload"></i>
                      <span>Choose file or drag and drop</span>
                    </div>
                  </div>
                  
                  <div class="file-preview" id="preview_letter_to_mayor"></div>
                </div>

                <!-- Certificate of Indigency -->
                <div class="upload-form-item" data-document="certificate_of_indigency">
                  <div class="upload-item-header">
                    <div class="upload-item-icon">
                      <i class="bi bi-award-fill"></i>
                    </div>
                    <div class="upload-item-info">
                      <h4>Certificate of Indigency</h4>
                      <p>Official certificate from your barangay</p>
                    </div>
                  </div>
                  
                  <div class="custom-file-input">
                    <input type="file" name="documents[]" id="certificate_of_indigency_input_unique" accept=".pdf,.jpg,.jpeg,.png" required>
                    <input type="hidden" name="document_type[]" value="certificate_of_indigency">
                    <div class="file-input-label">
                      <i class="bi bi-cloud-upload"></i>
                      <span>Choose file or drag and drop</span>
                    </div>
                  </div>
                  
                  <div class="file-preview" id="preview_certificate_of_indigency_unique"></div>
                </div>

                <!-- Academic Grades Upload Section -->
                <div class="upload-form-item" data-document="grades">
                  <div class="upload-item-header">
                    <div class="upload-item-icon">
                      <i class="bi bi-file-earmark-text-fill"></i>
                    </div>
                    <div class="upload-item-info">
                      <h4>Academic Grades</h4>
                      <p>Upload your latest report card or transcript (PDF or Image)</p>
                      <small class="text-muted">
                        <strong>Philippine Grading Requirements:</strong><br>
                        • 1.00 - 3.00 GPA (Passing) | 75% - 100% (Passing)<br>
                        • System supports both university scales
                      </small>
                    </div>
                  </div>
                  
                  <div class="custom-file-input">
                    <input type="file" name="grades_file" id="grades_input" accept=".pdf,.jpg,.jpeg,.png" onchange="uploadGrades(this)" required>
                    <div class="file-input-label">
                      <i class="bi bi-cloud-upload"></i>
                      <span>Choose grades file</span>
                    </div>
                  </div>
                  
                  <div id="grades_processing" style="display: none;" class="processing-indicator">
                    <div class="spinner-border" role="status"></div>
                    <div><strong>Processing grades with OCR...</strong></div>
                    <small class="text-muted">Analyzing Philippine grading system formats</small>
                  </div>
                  
                  <div id="grades_results" style="display: none;"></div>
                </div>
              </div>

              <!-- Submit Section -->
              <div class="submit-section">
                <button type="submit" class="submit-btn" id="submit-documents">
                    <i class="bi bi-cloud-upload me-2"></i>
                    Submit All Documents
                </button>
                <p class="mt-2 mb-0 text-muted small">
                    Please ensure all required documents including academic grades are uploaded before submitting.
                </p>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>

  <!-- Enhanced Modal -->
  <div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="previewModalLabel">
            <i class="bi bi-eye-fill me-2"></i>
            Document Preview
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="previewContent">
          <div class="text-center">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Loading preview...</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/homepage.js"></script>
  <script src="../../assets/js/student/upload.js"></script>
  
  <script>
    // Enhanced grades upload functionality for Philippine grading system
    async function uploadGrades(input) {
        const file = input.files[0];
        if (!file) return;
        
        // Validate file type
        const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
        if (!allowedTypes.includes(file.type)) {
            alert('Please upload a PDF or image file (JPG, PNG).');
            input.value = '';
            return;
        }
        
        // Validate file size (max 10MB)
        if (file.size > 10 * 1024 * 1024) {
            alert('File size must be less than 10MB.');
            input.value = '';
            return;
        }
        
        // Show processing indicator
        document.getElementById('grades_processing').style.display = 'block';
        document.getElementById('grades_results').style.display = 'none';
        
        try {
            // First upload file to server
            const formData = new FormData();
            formData.append('grades_file', file);
            formData.append('student_id', '<?= $_SESSION['student_id'] ?>');
            
            const uploadResponse = await fetch('process_grades_upload.php', {
                method: 'POST',
                body: formData
            });
            
            const uploadResult = await uploadResponse.json();
            
            if (uploadResult.success) {
                // Send to OCR processing
                const ocrResponse = await fetch('process_ocr_grades.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        upload_id: uploadResult.upload_id
                    })
                });
                
                const ocrResult = await ocrResponse.json();
                
                if (ocrResult.success) {
                    // Display results
                    displayGradesResults(ocrResult.ocr_result);
                } else {
                    throw new Error(ocrResult.message || 'OCR processing failed');
                }
                
            } else {
                throw new Error(uploadResult.message || 'Upload failed');
            }
            
        } catch (error) {
            console.error('Error processing grades:', error);
            document.getElementById('grades_results').innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Error processing grades: ${error.message}. Please try again or contact support.
                </div>
            `;
            document.getElementById('grades_results').style.display = 'block';
        } finally {
            document.getElementById('grades_processing').style.display = 'none';
        }
    }
    
    function displayGradesResults(result) {
        const resultsDiv = document.getElementById('grades_results');
        
        let html = `
            <div class="grades-analysis-card">
                <div class="analysis-header">
                    <h5><i class="bi bi-graph-up me-2"></i>Grades Analysis</h5>
                    <span class="confidence-badge">OCR Confidence: ${result.confidence}%</span>
                </div>
                
                <div class="overall-status ${result.status === 'passed' ? 'status-passed' : result.status === 'failed' ? 'status-failed' : 'status-review'}">
                    <i class="bi ${result.status === 'passed' ? 'bi-check-circle' : result.status === 'failed' ? 'bi-x-circle' : 'bi-clock'} me-2"></i>
                    ${result.status === 'passed' ? 'GRADES MEET REQUIREMENTS' : result.status === 'failed' ? 'GRADES BELOW MINIMUM (75% / 3.00)' : 'NEEDS MANUAL REVIEW'}
                </div>
                
                <div class="grades-summary">
                    <div class="summary-item">
                        <span class="summary-label">Total Subjects:</span>
                        <span class="summary-value">${result.total_subjects}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Passing Grades:</span>
                        <span class="summary-value">${result.passing_count}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">GPA:</span>
                        <span class="summary-value">${result.gpa_equivalent}</span>
                    </div>
                </div>
                
                <div class="gpa-indicator">
                    <strong>Philippine Grading System</strong><br>
                    <small class="text-muted">1.00-3.00 (Passing) • 75%-100% (Passing) • ${result.passing_percentage}% of subjects passing</small>
                </div>
                
                <div class="extracted-grades">
                    <h6>Extracted Grades:</h6>
                    ${result.grades.map(grade => `
                        <div class="grade-item ${grade.is_passing ? 'grade-pass' : 'grade-fail'}">
                            <span class="subject-name">${grade.subject}</span>
                            <div class="grade-details">
                                <span class="grade-value">${grade.original_grade}</span>
                                <div class="grade-equivalent">
                                    ${grade.numeric_grade} GPA • ${grade.percentage_grade}%
                                </div>
                            </div>
                            <span class="grade-status">
                                <i class="bi ${grade.is_passing ? 'bi-check-circle text-success' : 'bi-x-circle text-danger'}"></i>
                            </span>
                        </div>
                    `).join('')}
                </div>
                
                ${result.status === 'manual_review' ? `
                    <div class="alert alert-warning mt-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This document requires manual review by an administrator due to low OCR confidence or unclear grade formats.
                    </div>
                ` : ''}
                
                ${result.status === 'failed' ? `
                    <div class="alert alert-danger mt-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Minimum Requirements Not Met:</strong> You need at least 75% average or 3.00 GPA to qualify for the scholarship program.
                    </div>
                ` : ''}
            </div>
        `;
        
        resultsDiv.innerHTML = html;
        resultsDiv.style.display = 'block';
    }
  </script>
</body>
</html>