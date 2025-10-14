<?php
// Simple test to verify form submission works

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h2>Form Submitted Successfully!</h2>";
    echo "<h3>POST Data:</h3>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
    
    echo "<h3>FILES Data:</h3>";
    echo "<pre>";
    print_r($_FILES);
    echo "</pre>";
    
    // Test file processing
    if (isset($_FILES['documents']) && is_array($_FILES['documents']['name'])) {
        echo "<h3>Documents Processing:</h3>";
        foreach ($_FILES['documents']['name'] as $index => $fileName) {
            if (!empty($fileName)) {
                echo "<p>Document $index: $fileName (Size: {$_FILES['documents']['size'][$index]})</p>";
            } else {
                echo "<p>Document $index: Empty file</p>";
            }
        }
    }
    
    if (isset($_FILES['grades_file'])) {
        echo "<h3>Grades File:</h3>";
        echo "<p>Name: {$_FILES['grades_file']['name']}</p>";
        echo "<p>Size: {$_FILES['grades_file']['size']}</p>";
        echo "<p>Error: {$_FILES['grades_file']['error']}</p>";
    }
    
    exit;
}
?>

<form method="POST" enctype="multipart/form-data">
    <div>
        <label>ID Picture:</label><br>
        <input type="file" name="documents[]" required>
        <input type="hidden" name="document_type[]" value="id_picture">
    </div><br>
    
    <div>
        <label>Grades File:</label><br>
        <input type="file" name="grades_file" required>
    </div><br>
    
    <button type="submit">Test Submit</button>
</form>