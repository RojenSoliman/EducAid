<?php
include '../../config/database.php';
// Check if student is logged in
session_start();
if (!isset($_SESSION['student_username'])) {
    header("Location: student_login.php");
    exit;
}

// Handle the file uploads
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES['documents'])) {
    $student_id = $_SESSION['student_id']; // Assuming student_id is stored in the session

    // Create a folder for the student if it doesn't exist
    $uploadDir = "../../assets/uploads/{$student_id}/";
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Process the uploaded files
    foreach ($_FILES['documents']['name'] as $index => $fileName) {
        $fileTmpName = $_FILES['documents']['tmp_name'][$index];
        $fileType = $_POST['document_type'][$index]; // Document type (e.g., 'certificate_of_indigency')
        
        // Validate the document type
        if (!in_array($fileType, ['id_picture', 'certificate_of_indigency', 'letter_to_mayor'])) {
            echo "<script>alert('Invalid document type.');</script>";
            continue;
        }

        // Move the uploaded file to the student’s folder
        $filePath = $uploadDir . basename($fileName);
        if (move_uploaded_file($fileTmpName, $filePath)) {
            // Insert record into the documents table
            $query = "INSERT INTO documents (student_id, type, file_path) VALUES ($1, $2, $3)";
            pg_query_params($connection, $query, [$student_id, $fileType, $filePath]);

            echo "<script>alert('Document uploaded successfully.');</script>";
        } else {
            echo "<script>alert('Failed to upload document.');</script>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>EducAid Dashboard</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/homepage.css" />
</head>
<body>
  <div class="container-fluid">
    <div class="row">
      <!-- Include Sidebar -->
      <?php include '../../includes/student/student_sidebar.php' ?>
      
      <!-- Main Content Area -->
      <section class="home-section" id="page-content-wrapper">
        <nav>
            <div class="sidebar-toggle px-4 py-3">
            <i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i>
            </div>
        </nav>
        <div class="container py-5">
            <h2 class="text-center">Upload Required Documents</h2>
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
        </div> 
        </section>
    </div>
  </div>
    <script src="../../assets/js/homepage.js"></script>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>