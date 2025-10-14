<?php
session_start();
echo "<h1>Upload Debug Test</h1>";

echo "<h3>POST Data:</h3>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

echo "<h3>FILES Data:</h3>";
echo "<pre>";
print_r($_FILES);
echo "</pre>";

echo "<h3>Session Data:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    echo "<h3>Upload Processing Test:</h3>";
    
    if (isset($_FILES['grades_file'])) {
        echo "<p>✅ grades_file found in FILES array</p>";
        echo "<p>File name: " . $_FILES['grades_file']['name'] . "</p>";
        echo "<p>File size: " . $_FILES['grades_file']['size'] . " bytes</p>";
        echo "<p>File type: " . $_FILES['grades_file']['type'] . "</p>";
        echo "<p>Upload error code: " . $_FILES['grades_file']['error'] . "</p>";
        
        if ($_FILES['grades_file']['error'] === UPLOAD_ERR_OK) {
            echo "<p>✅ No upload errors detected</p>";
        } else {
            echo "<p>❌ Upload error detected: " . $_FILES['grades_file']['error'] . "</p>";
        }
    } else {
        echo "<p>❌ grades_file NOT found in FILES array</p>";
    }
    
    if (isset($_FILES['documents'])) {
        echo "<p>✅ documents found in FILES array</p>";
    } else {
        echo "<p>ℹ️ documents NOT found in FILES array (expected for grades-only upload)</p>";
    }
    
    echo "<p><strong>Condition Check:</strong></p>";
    $condition1 = ($_SERVER["REQUEST_METHOD"] === "POST");
    $condition2 = (isset($_FILES['documents']) || isset($_FILES['grades_file']));
    
    echo "<p>REQUEST_METHOD === POST: " . ($condition1 ? "✅ True" : "❌ False") . "</p>";
    echo "<p>documents OR grades_file set: " . ($condition2 ? "✅ True" : "❌ False") . "</p>";
    echo "<p>Overall condition: " . ($condition1 && $condition2 ? "✅ SHOULD PROCESS" : "❌ Will not process") . "</p>";
} else {
    echo "<h3>Test Form:</h3>";
    echo '<form method="POST" enctype="multipart/form-data">';
    echo '<p><input type="file" name="grades_file" accept=".pdf,.jpg,.jpeg,.png" required></p>';
    echo '<p><button type="submit">Test Upload</button></p>';
    echo '</form>';
}
?>