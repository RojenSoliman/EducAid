<?php
require_once __DIR__ . '/../../config/database.php';

echo "Checking students table structure...\n\n";

$result = pg_query($connection, "
    SELECT column_name, data_type, is_nullable, column_default
    FROM information_schema.columns
    WHERE table_name = 'students'
    ORDER BY ordinal_position
");

if ($result) {
    echo "Current columns in students table:\n";
    echo "─────────────────────────────────────────────────────────────\n";
    
    while ($row = pg_fetch_assoc($result)) {
        $nullable = $row['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $row['column_default'] ? " = {$row['column_default']}" : '';
        echo sprintf("%-40s %-20s %s%s\n", 
            $row['column_name'], 
            $row['data_type'], 
            $nullable,
            $default
        );
    }
} else {
    echo "Error: " . pg_last_error($connection);
}

pg_close($connection);
?>
