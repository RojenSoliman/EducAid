<?php
include '../../config/database.php';
// Check if student is logged in
session_start();
// Redirect if not logged in
if (!isset($_SESSION['student_username'])) {
    header("Location: student_login.php");
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
  <title>Document Upload</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/homepage.css" />
  <style>
  body:not(.js-ready) .sidebar { visibility: hidden; transition: none !important; }
  </style>
</head>
<body>
  <div id="wrapper">
    <!-- Sidebar -->
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
    <!-- Main Content Area -->
    <section class="home-section" id="page-content-wrapper">
      <nav>
        <div class="sidebar-toggle px-4 py-3">
          <i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i>
        </div>
      </nav>
      <div class="container py-5">
        <h2 class="text-center">Upload Required Documents</h2>

        <?php if (!empty($flash_success)): ?>
          <div class="alert alert-success text-center">
            Document uploaded successfully.
          </div>
        <?php elseif (!empty($flash_fail)): ?>
          <div class="alert alert-danger text-center">
            Failed to upload document.
          </div>
        <?php endif; ?>

        <?php if ($allDocumentsUploaded): ?>
          <div class="alert alert-success text-center">
            <strong>All documents have been uploaded!</strong> You cannot upload documents anymore unless the admin denies your submission.
          </div>
        <?php else: ?>
          <form method="POST" enctype="multipart/form-data">
            <div class="mb-3">
              <label for="id_picture" class="form-label">ID Picture</label>
              <input type="file" class="form-control" name="documents[]" id="id_picture" required />
              <input type="hidden" name="document_type[]" value="id_picture" />
            </div>
            <div class="mb-3">
              <label for="letter_to_mayor" class="form-label">Letter to Mayor</label>
              <input type="file" class="form-control" name="documents[]" id="letter_to_mayor" required />
              <input type="hidden" name="document_type[]" value="letter_to_mayor" />
            </div>
            <div class="mb-3">
              <label for="certificate_of_indigency" class="form-label">Certificate of Indigency</label>
              <input type="file" class="form-control" name="documents[]" id="certificate_of_indigency" required />
              <input type="hidden" name="document_type[]" value="certificate_of_indigency" />
            </div>
            <button type="submit" class="btn btn-success w-100">Upload Documents</button>
          </form>
        <?php endif; ?>
      </div>
    </section>
  </div>
  <script src="../../assets/js/homepage.js"></script>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/homepage.js"></script>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script></body></html>