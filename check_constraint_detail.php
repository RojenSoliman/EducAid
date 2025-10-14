<?php
include 'config/database.php';

// Get the exact check constraint definition
$constraint_query = "
    SELECT 
        conname as constraint_name,
        pg_get_constraintdef(oid) as constraint_definition
    FROM pg_constraint 
    WHERE conname LIKE '%type_check%' 
       OR conname LIKE '%documents%';
";

$result = pg_query($connection, $constraint_query);

echo "Check constraints:\n";
if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        echo "Constraint: " . $row['constraint_name'] . "\n";
        echo "Definition: " . $row['constraint_definition'] . "\n\n";
    }
} else {
    echo "Error: " . pg_last_error($connection) . "\n";
}

// Also check enum types
$enum_query = "
    SELECT 
        t.typname,
        e.enumlabel,
        e.enumsortorder
    FROM pg_type t 
    JOIN pg_enum e ON t.oid = e.enumtypid 
    WHERE t.typname LIKE '%document%'
    ORDER BY t.typname, e.enumsortorder;
";

$enum_result = pg_query($connection, $enum_query);

echo "Enum types for documents:\n";
if ($enum_result && pg_num_rows($enum_result) > 0) {
    $current_type = '';
    while ($row = pg_fetch_assoc($enum_result)) {
        if ($current_type != $row['typname']) {
            if ($current_type != '') echo "\n";
            echo "Enum type: " . $row['typname'] . "\n";
            $current_type = $row['typname'];
        }
        echo "  - " . $row['enumlabel'] . "\n";
    }
} else {
    echo "No enum types found\n";
}
?>