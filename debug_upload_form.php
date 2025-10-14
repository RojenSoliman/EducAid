<?php
// Debug page to test form submission
echo "<h2>DEBUG: Form Submission Test</h2>";

echo "<h3>POST Data:</h3>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

echo "<h3>FILES Data:</h3>";
echo "<pre>";
print_r($_FILES);
echo "</pre>";

echo "<h3>REQUEST METHOD: " . $_SERVER['REQUEST_METHOD'] . "</h3>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>Form Processing Test:</h3>";
    
    echo "<p><strong>documents check:</strong> " . (isset($_FILES['documents']) ? "YES" : "NO") . "</p>";
    echo "<p><strong>grades_file check:</strong> " . (isset($_FILES['grades_file']) ? "YES" : "NO") . "</p>";
    
    if (isset($_FILES['grades_file'])) {
        echo "<p><strong>grades_file error code:</strong> " . $_FILES['grades_file']['error'] . "</p>";
        echo "<p><strong>grades_file name:</strong> " . $_FILES['grades_file']['name'] . "</p>";
        echo "<p><strong>grades_file size:</strong> " . $_FILES['grades_file']['size'] . "</p>";
    }
    
    if (isset($_FILES['documents'])) {
        echo "<p><strong>documents is array:</strong> " . (is_array($_FILES['documents']['name']) ? "YES" : "NO") . "</p>";
        if (is_array($_FILES['documents']['name'])) {
            echo "<p><strong>documents count:</strong> " . count($_FILES['documents']['name']) . "</p>";
        }
    }
}
?>

<form method="POST" enctype="multipart/form-data">
    <h3>Test Form</h3>
    
    <div>
        <label>ID Picture:</label>
        <input type="file" name="documents[]" accept=".pdf,.jpg,.jpeg,.png">
        <input type="hidden" name="document_type[]" value="id_picture">
    </div>
    
    <div>
        <label>Grades File:</label>
        <input type="file" name="grades_file" accept=".pdf,.jpg,.jpeg,.png">
    </div>
    
    <button type="submit">Test Submit</button>
</form>