<?php
include __DIR__ . '/config/database.php';

$student_id = 'GENERALTRIAS-2025-3-P6BE0U';

$query = "UPDATE students SET expected_graduation_year = NULL WHERE student_id = $1";
pg_query_params($connection, $query, [$student_id]);

$result = pg_query_params($connection, "SELECT status, expected_graduation_year FROM students WHERE student_id = $1", [$student_id]);
$student = pg_fetch_assoc($result);

echo "Status: {$student['status']}\n";
echo "Expected Graduation: " . ($student['expected_graduation_year'] ?? 'NULL') . "\n";
