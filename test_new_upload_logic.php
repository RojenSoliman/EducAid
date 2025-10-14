<?php
include 'config/database.php';

$student_id = 'TRIAS-0AA90A'; // Test with the student that has existing documents

echo "Testing NEW upload progress logic...\n\n";

echo "Student ID: $student_id\n\n";

// Check all documents for this student
$all_docs_query = "SELECT type, file_path, upload_date FROM documents WHERE student_id = $1 ORDER BY upload_date DESC";
$all_docs_result = pg_query_params($connection, $all_docs_query, [$student_id]);

echo "All documents for this student:\n";
echo "==============================\n";
while ($doc = pg_fetch_assoc($all_docs_result)) {
    $is_upload_page = (strpos($doc['file_path'], '/assets/uploads/students/') !== false);
    echo "Type: " . $doc['type'] . "\n";
    echo "Path: " . $doc['file_path'] . "\n";
    echo "Source: " . ($is_upload_page ? "upload_document.php ✅" : "other source ❌") . "\n";
    echo "Date: " . $doc['upload_date'] . "\n";
    echo "---\n";
}

// Test new queries
echo "\nNEW Query Results (only upload_document.php):\n";
echo "=============================================\n";

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

echo "ID Picture count: " . $id_row['total_uploaded'] . " → Status: " . ($id_row['total_uploaded'] >= 1 ? 'Uploaded' : 'Required') . "\n";
echo "Academic Grades count: " . $grades_row['grades_uploaded'] . " → Status: " . ($grades_row['grades_uploaded'] > 0 ? 'Uploaded' : 'Required') . "\n";

pg_close($connection);
?>