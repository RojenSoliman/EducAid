<?php
// Script to delete uploaded documents for testing
include 'config/database.php';
session_start();

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    echo "<p>No student session found. Please log in first.</p>";
    exit;
}

$student_id = $_SESSION['student_id'];

echo "<h2>Delete Documents for Testing</h2>";
echo "<p>Student ID: $student_id</p>";

// Check current documents
echo "<h3>Current Documents:</h3>";
$check_query = "SELECT * FROM documents WHERE student_id = $1 ORDER BY upload_date DESC";
$check_result = pg_query_params($connection, $check_query, [$student_id]);

if (pg_num_rows($check_result) > 0) {
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>ID</th><th>Type</th><th>File Path</th><th>Upload Date</th></tr>";
    
    while ($doc = pg_fetch_assoc($check_result)) {
        echo "<tr>";
        echo "<td>" . $doc['document_id'] . "</td>";
        echo "<td>" . $doc['type'] . "</td>";
        echo "<td>" . htmlspecialchars($doc['file_path']) . "</td>";
        echo "<td>" . $doc['upload_date'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Add delete button
    echo "<form method='POST' style='margin: 20px 0;'>";
    echo "<button type='submit' name='delete_docs' style='background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>Delete All Documents</button>";
    echo "</form>";
} else {
    echo "<p>No documents found for this student.</p>";
}

// Handle deletion
if (isset($_POST['delete_docs'])) {
    echo "<h3>Deleting Documents...</h3>";
    
    // Get documents to delete files
    $files_query = "SELECT file_path FROM documents WHERE student_id = $1";
    $files_result = pg_query_params($connection, $files_query, [$student_id]);
    
    $deleted_files = 0;
    while ($file_row = pg_fetch_assoc($files_result)) {
        $file_path = $file_row['file_path'];
        if (file_exists($file_path)) {
            if (unlink($file_path)) {
                echo "<p style='color: green;'>✓ Deleted file: " . htmlspecialchars(basename($file_path)) . "</p>";
                $deleted_files++;
            } else {
                echo "<p style='color: red;'>✗ Failed to delete file: " . htmlspecialchars(basename($file_path)) . "</p>";
            }
        }
    }
    
    // Delete database records
    $delete_query = "DELETE FROM documents WHERE student_id = $1";
    $delete_result = pg_query_params($connection, $delete_query, [$student_id]);
    
    if ($delete_result) {
        $deleted_count = pg_affected_rows($delete_result);
        echo "<p style='color: green; font-weight: bold;'>✓ Deleted $deleted_count database records</p>";
        echo "<p style='color: green; font-weight: bold;'>✓ Deleted $deleted_files physical files</p>";
        echo "<p><a href='modules/student/upload_document.php'>→ Go to Upload Page</a></p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to delete database records</p>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Delete Documents - Testing</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; }
        th, td { padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
    <p><a href="modules/student/upload_document.php">← Back to Upload Page</a></p>
</body>
</html>