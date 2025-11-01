<?php
/**
 * Run the student_id type fix migration
 */
include 'config/database.php';

echo "=== Fixing student_id Type Mismatch ===\n\n";

// Read the SQL file
$sql = file_get_contents('fix_student_id_type_mismatch.sql');

// Execute the migration
$result = pg_query($connection, $sql);

if ($result) {
    echo "✓ Migration executed successfully!\n\n";
    
    // Show the verification results
    echo "Verification Results:\n";
    echo "--------------------\n";
    while ($row = pg_fetch_assoc($result)) {
        echo "Table: " . $row['table_name'] . "\n";
        echo "Column: " . $row['column_name'] . "\n";
        echo "Data Type: " . $row['data_type'];
        if ($row['character_maximum_length']) {
            echo "(" . $row['character_maximum_length'] . ")";
        }
        echo "\n--------------------\n";
    }
} else {
    echo "✗ Migration failed!\n";
    echo "Error: " . pg_last_error($connection) . "\n";
}

pg_close($connection);
?>
