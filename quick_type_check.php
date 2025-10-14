<?php
include 'config/database.php';

// Simple query to see existing document types
$query = "SELECT DISTINCT type FROM documents ORDER BY type;";
$result = pg_query($connection, $query);

echo "Existing document types in database:\n";
if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        echo "- " . $row['type'] . "\n";
    }
} else {
    echo "Error: " . pg_last_error($connection) . "\n";
}

// Test a simple insert to see the exact constraint
echo "\nTesting constraint:\n";
$test_query = "BEGIN; INSERT INTO documents (student_id, type, file_path) VALUES (1, 'academic_grades', '/test'); ROLLBACK;";
$test_result = pg_query($connection, $test_query);

if (!$test_result) {
    echo "Error message: " . pg_last_error($connection) . "\n";
} else {
    echo "Insert would succeed\n";
}
?>