<?php
// Simple test file to debug grades upload issue
session_start();

echo "<h2>Debug Grades Upload Test</h2>";

// Set test session (use a valid student ID from your database)
if (!isset($_SESSION['student_id'])) {
    $_SESSION['student_id'] = 1; // Change this to a valid student ID
    $_SESSION['student_username'] = 'test_student';
    echo "<p>Set test session: student_id = 1</p>";
}

echo "<p>Current session:</p>";
echo "<ul>";
echo "<li>student_id: " . ($_SESSION['student_id'] ?? 'not set') . "</li>";
echo "<li>student_username: " . ($_SESSION['student_username'] ?? 'not set') . "</li>";
echo "</ul>";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    echo "<h3>POST Request Received</h3>";
    echo "<p><strong>FILES data:</strong></p>";
    echo "<pre>" . print_r($_FILES, true) . "</pre>";
    echo "<p><strong>POST data:</strong></p>";
    echo "<pre>" . print_r($_POST, true) . "</pre>";
    
    if (isset($_FILES['grades_file'])) {
        $file = $_FILES['grades_file'];
        echo "<h4>File Analysis:</h4>";
        echo "<ul>";
        echo "<li>Name: " . $file['name'] . "</li>";
        echo "<li>Size: " . $file['size'] . " bytes</li>";
        echo "<li>Type: " . $file['type'] . "</li>";
        echo "<li>Error: " . $file['error'] . "</li>";
        echo "<li>Temp file: " . $file['tmp_name'] . "</li>";
        echo "<li>File exists: " . (file_exists($file['tmp_name']) ? 'YES' : 'NO') . "</li>";
        echo "</ul>";
        
        // Check upload errors
        $upload_errors = [
            UPLOAD_ERR_OK => 'No error',
            UPLOAD_ERR_INI_SIZE => 'File too large (php.ini)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (form)',
            UPLOAD_ERR_PARTIAL => 'Partial upload',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'No temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk',
            UPLOAD_ERR_EXTENSION => 'Extension stopped upload'
        ];
        
        echo "<p><strong>Upload Error:</strong> " . ($upload_errors[$file['error']] ?? 'Unknown error') . "</p>";
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            echo "<p style='color: green;'>✓ File upload looks good!</p>";
        } else {
            echo "<p style='color: red;'>✗ File upload failed with error: " . $file['error'] . "</p>";
        }
    } else {
        echo "<p style='color: red;'>No grades_file found in \$_FILES</p>";
    }
} else {
    echo "<p>No POST request received yet.</p>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Grade Upload Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-form { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; margin: 20px 0; }
        pre { background: #f0f0f0; padding: 10px; border: 1px solid #ccc; overflow-x: auto; }
    </style>
</head>
<body>

<div class="test-form">
    <h3>Test Grades Upload Form</h3>
    <form method="POST" enctype="multipart/form-data" action="">
        <p>
            <label for="grades_file">Select grades file:</label><br>
            <input type="file" name="grades_file" id="grades_file" accept=".pdf,.jpg,.jpeg,.png" required>
        </p>
        <p>
            <button type="submit">Test Upload</button>
        </p>
    </form>
</div>

<p><a href="modules/student/upload_document.php">← Back to Upload Document</a></p>

</body>
</html>