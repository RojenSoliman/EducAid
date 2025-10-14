<?php
include 'config/database.php';

$student_id = 'TRIAS-0AA90A'; // The student to reset

echo "Clearing upload_document.php uploads for student: $student_id\n\n";

// Show what will be deleted
$query = "SELECT type, file_path, upload_date FROM documents 
          WHERE student_id = $1 
          AND file_path LIKE '%/assets/uploads/students/%'";
$result = pg_query_params($connection, $query, [$student_id]);

echo "Documents to be cleared (uploaded through upload_document.php):\n";
echo "=============================================================\n";
$count = 0;
while ($doc = pg_fetch_assoc($result)) {
    echo "- " . $doc['type'] . " (" . $doc['upload_date'] . ")\n";
    echo "  Path: " . $doc['file_path'] . "\n";
    $count++;
}

if ($count == 0) {
    echo "No documents found that were uploaded through upload_document.php\n";
} else {
    echo "\nFound $count document(s) to clear.\n";
    
    // Uncomment the lines below to actually delete
    echo "\nTo actually clear these documents, uncomment the delete query below:\n";
    echo "// \$delete_query = \"DELETE FROM documents WHERE student_id = \$1 AND file_path LIKE '%/assets/uploads/students/%'\";\n";
    echo "// \$delete_result = pg_query_params(\$connection, \$delete_query, [\$student_id]);\n";
    
    // DELETE QUERY - ACTIVATED
    $delete_query = "DELETE FROM documents WHERE student_id = $1 AND file_path LIKE '%/assets/uploads/students/%'";
    $delete_result = pg_query_params($connection, $delete_query, [$student_id]);
    if ($delete_result) {
        echo "✅ Documents cleared successfully!\n";
        echo "The upload progress should now show 'Required' for both documents.\n";
    } else {
        echo "❌ Error clearing documents.\n";
    }
}

pg_close($connection);
?>