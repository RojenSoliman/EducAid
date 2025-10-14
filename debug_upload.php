<?php
// Simple diagnostic page for upload testing
session_start();

echo "<h1>Upload Diagnostic Page</h1>";
echo "<h3>Session Information:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>POST Data:</h3>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

echo "<h3>FILES Data:</h3>";
echo "<pre>";
print_r($_FILES);
echo "</pre>";

echo "<h3>Server Info:</h3>";
echo "<pre>";
echo "REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "CONTENT_TYPE: " . (isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : 'Not set') . "\n";
echo "</pre>";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    echo "<h3>Upload Analysis:</h3>";
    
    if (isset($_FILES['grades_file'])) {
        echo "<p>✅ grades_file found in FILES</p>";
        echo "<p>File name: " . $_FILES['grades_file']['name'] . "</p>";
        echo "<p>File size: " . $_FILES['grades_file']['size'] . " bytes</p>";
        echo "<p>File type: " . $_FILES['grades_file']['type'] . "</p>";
        echo "<p>Upload error: " . $_FILES['grades_file']['error'] . "</p>";
    } else {
        echo "<p>❌ grades_file NOT found in FILES</p>";
    }
    
    if (isset($_POST['upload_grades'])) {
        echo "<p>✅ upload_grades button clicked</p>";
    } else {
        echo "<p>❌ upload_grades button NOT clicked</p>";
    }
    
    if (isset($_SESSION['student_username'])) {
        echo "<p>✅ Student logged in: " . $_SESSION['student_username'] . "</p>";
    } else {
        echo "<p>❌ Student NOT logged in</p>";
    }
} else {
    echo "<h3>Test Form:</h3>";
    echo '<form method="POST" enctype="multipart/form-data">';
    echo '<p><input type="file" name="grades_file" required></p>';
    echo '<p><button type="submit" name="upload_grades">Test Upload</button></p>';
    echo '</form>';
}
?>