<?php
/**
 * Debug script to check student_id in database
 * Run this to verify student_id values
 */
include 'config/database.php';

echo "=== Student ID Debug ===\n\n";

// Check first few students
$query = "SELECT student_id, school_student_id, email, first_name, last_name FROM students LIMIT 5";
$result = pg_query($connection, $query);

echo "Students in database:\n";
echo "--------------------\n";
while ($row = pg_fetch_assoc($result)) {
    echo "student_id (PK): " . $row['student_id'] . " (" . gettype($row['student_id']) . ")\n";
    echo "school_student_id: " . ($row['school_student_id'] ?? 'NULL') . "\n";
    echo "Email: " . $row['email'] . "\n";
    echo "Name: " . $row['first_name'] . " " . $row['last_name'] . "\n";
    echo "--------------------\n";
}

// Check if there's a student with student_id that looks like '2025-3-481217'
echo "\nSearching for student with potential ID mismatch...\n";
$search = pg_query($connection, "SELECT student_id, school_student_id, email FROM students WHERE school_student_id LIKE '2025-3-%'");
if (pg_num_rows($search) > 0) {
    echo "Found students with school_student_id matching pattern '2025-3-%':\n";
    while ($row = pg_fetch_assoc($search)) {
        echo "student_id: " . $row['student_id'] . ", school_student_id: " . $row['school_student_id'] . ", email: " . $row['email'] . "\n";
    }
} else {
    echo "No students found with that pattern.\n";
}

pg_close($connection);
?>
