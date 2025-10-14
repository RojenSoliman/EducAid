<?php
session_start();
include 'config/database.php';

echo "<h1>Document Status Debug</h1>";

if (!isset($_SESSION['student_id'])) {
    echo "<p>❌ No student logged in</p>";
    echo "<p>Session data:</p><pre>";
    print_r($_SESSION);
    echo "</pre>";
    exit;
}

$student_id = $_SESSION['student_id'];
echo "<h3>Student ID: " . htmlspecialchars($student_id) . "</h3>";

// Check current documents for this student
$query = "SELECT * FROM documents WHERE student_id = $1 ORDER BY upload_date DESC";
$result = pg_query_params($connection, $query, [$student_id]);

echo "<h3>All Documents for this Student:</h3>";
if (pg_num_rows($result) == 0) {
    echo "<p>🎯 No documents found - this should show 'Required' status</p>";
} else {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'><th>Type</th><th>File Path</th><th>Upload Date</th><th>Valid</th></tr>";
    
    while ($doc = pg_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($doc['type']) . "</td>";
        echo "<td>" . htmlspecialchars($doc['file_path']) . "</td>";
        echo "<td>" . htmlspecialchars($doc['upload_date']) . "</td>";
        echo "<td>" . ($doc['is_valid'] ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Check the specific queries used in upload_document.php
echo "<h3>Query Results (same as upload page):</h3>";

// Old query (counts all documents)
$id_query_old = "SELECT COUNT(*) AS total_uploaded FROM documents WHERE student_id = $1 AND type IN ('id_picture')";
$id_result_old = pg_query_params($connection, $id_query_old, [$student_id]);
$id_row_old = pg_fetch_assoc($id_result_old);

// New query (only upload_document.php uploads)
$id_query = "SELECT COUNT(*) AS total_uploaded FROM documents 
            WHERE student_id = $1 AND type IN ('id_picture') 
            AND file_path LIKE '%/assets/uploads/students/%'";
$id_result = pg_query_params($connection, $id_query, [$student_id]);
$id_row = pg_fetch_assoc($id_result);

$grades_query = "SELECT COUNT(*) AS grades_uploaded FROM documents 
                WHERE student_id = $1 AND type = 'academic_grades'
                AND file_path LIKE '%/assets/uploads/students/%'";
$grades_result = pg_query_params($connection, $grades_query, [$student_id]);
$grades_row = pg_fetch_assoc($grades_result);

echo "<p><strong>ID Picture (OLD logic - all sources):</strong> " . $id_row_old['total_uploaded'] . "</p>";
echo "<p><strong>ID Picture (NEW logic - only upload_document.php):</strong> " . $id_row['total_uploaded'] . "</p>";

echo "<p><strong>Academic Grades (NEW logic):</strong> " . $grades_row['grades_uploaded'] . " (shows 'Uploaded' if > 0)</p>";

echo "<h3>Expected Status with NEW Logic:</h3>";
echo "<p>ID Picture: " . ($id_row['total_uploaded'] >= 1 ? '✅ Uploaded' : '❌ Required') . "</p>";
echo "<p>Academic Grades: " . ($grades_row['grades_uploaded'] > 0 ? '✅ Uploaded' : '❌ Required') . "</p>";

echo "<h3>File Path Analysis:</h3>";
$path_query = "SELECT type, file_path FROM documents WHERE student_id = $1";
$path_result = pg_query_params($connection, $path_query, [$student_id]);
while ($path_doc = pg_fetch_assoc($path_result)) {
    $is_upload_page = (strpos($path_doc['file_path'], '/assets/uploads/students/') !== false);
    echo "<p><strong>" . $path_doc['type'] . ":</strong> " . htmlspecialchars($path_doc['file_path']) . " " . ($is_upload_page ? "✅ (upload_document.php)" : "❌ (other source)") . "</p>";
}

pg_close($connection);
?>