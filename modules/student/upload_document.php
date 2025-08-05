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

if ($row['total_uploaded'] == 3) {
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
            </div>

            <!-- Overall Progress Bar -->
            <div class="overall-progress">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold">Overall Progress</span>
                <span class="text-muted"><?php echo $row['total_uploaded']; ?> of 3 completed</span>
              </div>
              <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: <?php echo ($row['total_uploaded'] / 3) * 100; ?>%"></div>
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
                Congratulations! You have successfully uploaded all required documents. 
                Your application is now complete and under review by our administration team.
                <br><br>
                <strong>Next Steps:</strong><br>
                • Wait for admin review<br>
                • Check your notifications for updates<br>
                • You can re-upload if admin requests changes
              </p>
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
              </div>

              <!-- Submit Section -->
              <div class="submit-section">
                <button type="submit" class="submit-btn" id="submit-documents">
                    <i class="bi bi-cloud-upload me-2"></i>
                    Submit All Documents
                </button>
                <p class="mt-2 mb-0 text-muted small">
                    Please ensure all required documents are uploaded before submitting.
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
</body>
</html>