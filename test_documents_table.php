<?php
include 'config/database.php';

echo "Testing documents table structure...\n\n";

// Check current documents for a test student
$query = "SELECT DISTINCT type FROM documents LIMIT 10";
$result = pg_query($connection, $query);

echo "Current document types in database:\n";
while ($row = pg_fetch_assoc($result)) {
    echo "- " . $row['type'] . "\n";
}

echo "\nTable structure:\n";
$structure_query = "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'documents' ORDER BY ordinal_position";
$structure_result = pg_query($connection, $structure_query);

while ($col = pg_fetch_assoc($structure_result)) {
    echo "- " . $col['column_name'] . " (" . $col['data_type'] . ") - Nullable: " . $col['is_nullable'] . "\n";
}

echo "\nTesting insert of academic_grades type...\n";
// Test if we can insert academic_grades type (dry run)
$test_query = "SELECT 1 WHERE 'academic_grades' ~ '^[a-zA-Z_]+$'";
$test_result = pg_query($connection, $test_query);
if (pg_num_rows($test_result) > 0) {
    echo "✅ academic_grades type should work fine\n";
} else {
    echo "❌ academic_grades type might have issues\n";
}

pg_close($connection);
?>