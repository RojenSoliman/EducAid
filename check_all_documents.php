<?php
include 'config/database.php';

echo "Checking all documents in database...\n\n";

// Check all documents in the database
$query = "SELECT student_id, type, file_path, upload_date FROM documents ORDER BY upload_date DESC LIMIT 20";
$result = pg_query($connection, $query);

echo "Recent documents in database:\n";
echo "============================\n";

if (pg_num_rows($result) == 0) {
    echo "No documents found in database.\n";
} else {
    while ($doc = pg_fetch_assoc($result)) {
        echo "Student: " . $doc['student_id'] . "\n";
        echo "Type: " . $doc['type'] . "\n";
        echo "File: " . $doc['file_path'] . "\n";
        echo "Date: " . $doc['upload_date'] . "\n";
        echo "---\n";
    }
}

// Check for id_picture specifically
$id_query = "SELECT student_id, COUNT(*) as count FROM documents WHERE type = 'id_picture' GROUP BY student_id";
$id_result = pg_query($connection, $id_query);

echo "\nStudents with ID Pictures:\n";
echo "=========================\n";
if (pg_num_rows($id_result) == 0) {
    echo "No ID pictures found.\n";
} else {
    while ($row = pg_fetch_assoc($id_result)) {
        echo "Student " . $row['student_id'] . ": " . $row['count'] . " ID picture(s)\n";
    }
}

pg_close($connection);
?>