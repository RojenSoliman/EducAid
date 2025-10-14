<?php
// Test script to check grades resubmit functionality
include 'config/database.php';

session_start();

// Set test session (replace with actual student ID for testing)
$_SESSION['student_id'] = 1; // Change this to a valid student ID
$_SESSION['student_username'] = 'test_student';

echo "<h2>Testing Grades Resubmit Form</h2>";

// Check if student has grades uploaded
$student_id = $_SESSION['student_id'];
$grades_query = "SELECT * FROM documents WHERE student_id = $1 AND type = 'academic_grades' 
                AND file_path LIKE '%/assets/uploads/students/%' ORDER BY upload_date DESC LIMIT 1";
$grades_result = pg_query_params($connection, $grades_query, [$student_id]);
$uploaded_grades = pg_fetch_assoc($grades_result);

if ($uploaded_grades) {
    echo "<p><strong>✓ Student has uploaded grades:</strong></p>";
    echo "<ul>";
    echo "<li>File: " . htmlspecialchars(basename($uploaded_grades['file_path'])) . "</li>";
    echo "<li>Upload Date: " . $uploaded_grades['upload_date'] . "</li>";
    echo "<li>Type: " . $uploaded_grades['type'] . "</li>";
    echo "</ul>";
    
    echo "<p><strong>Test resubmit form HTML:</strong></p>";
    echo '<div class="resubmit-form" id="resubmit_grades" style="border: 1px solid #ccc; padding: 15px; background: #f9f9f9;">
            <form method="POST" enctype="multipart/form-data" id="gradesResubmitForm">
              <div class="custom-file-input">
                <input type="file" name="grades_file" id="grades_resubmit_input" accept=".pdf,.jpg,.jpeg,.png" required>
                <label for="grades_resubmit_input">Choose new grades file</label>
              </div>
              <div class="resubmit-actions" style="margin-top: 10px;">
                <button type="button" class="btn btn-secondary" onclick="alert(\'Cancel clicked\')">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload New Grades</button>
              </div>
            </form>
          </div>';
          
    echo "<p><strong>Form submission test:</strong></p>";
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES['grades_file'])) {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; color: #155724;'>";
        echo "✓ Form submitted successfully!<br>";
        echo "File name: " . htmlspecialchars($_FILES['grades_file']['name']) . "<br>";
        echo "File size: " . $_FILES['grades_file']['size'] . " bytes<br>";
        echo "File type: " . $_FILES['grades_file']['type'] . "<br>";
        echo "Error code: " . $_FILES['grades_file']['error'] . "<br>";
        echo "</div>";
    } else {
        echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; color: #856404;'>";
        echo "No file submitted yet. Use the form above to test.";
        echo "</div>";
    }
    
} else {
    echo "<p><strong>⚠ No grades found for student ID: $student_id</strong></p>";
    echo "<p>The student needs to upload grades first before testing resubmit functionality.</p>";
}

echo "<br><p><a href='modules/student/upload_document.php'>← Back to Upload Document</a></p>";
?>