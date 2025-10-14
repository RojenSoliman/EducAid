<?php
include 'config/database.php';

$student_id = 'TRIAS-0AA90A'; // The student with existing documents

echo "Clearing documents for student: $student_id\n\n";

// First, show current documents
$query = "SELECT type, file_path, upload_date FROM documents WHERE student_id = $1";
$result = pg_query_params($connection, $query, [$student_id]);

echo "Current documents:\n";
while ($doc = pg_fetch_assoc($result)) {
    echo "- " . $doc['type'] . " (" . $doc['upload_date'] . ")\n";
}

// Delete the documents (uncomment the line below to actually delete)
// $delete_query = "DELETE FROM documents WHERE student_id = $1";
// $delete_result = pg_query_params($connection, $delete_query, [$student_id]);

echo "\nTo clear documents, uncomment the delete lines in this script.\n";
echo "This will reset the upload status to show 'Required' instead of 'Uploaded'.\n";

pg_close($connection);
?>