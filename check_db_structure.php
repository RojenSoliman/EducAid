<?php
include 'config/database.php';

// Check the documents table structure and constraints
echo "<h2>Documents Table Structure</h2>";

// Get table structure
$structure_query = "
    SELECT 
        column_name, 
        data_type, 
        is_nullable, 
        column_default
    FROM information_schema.columns 
    WHERE table_name = 'documents' 
    ORDER BY ordinal_position;
";

$result = pg_query($connection, $structure_query);
if ($result) {
    echo "<h3>Columns:</h3>";
    echo "<table border='1'>";
    echo "<tr><th>Column</th><th>Type</th><th>Nullable</th><th>Default</th></tr>";
    while ($row = pg_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>{$row['column_name']}</td>";
        echo "<td>{$row['data_type']}</td>";
        echo "<td>{$row['is_nullable']}</td>";
        echo "<td>{$row['column_default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Get check constraints
echo "<h3>Check Constraints:</h3>";
$constraints_query = "
    SELECT 
        constraint_name,
        check_clause
    FROM information_schema.check_constraints 
    WHERE constraint_name LIKE '%documents%';
";

$constraints_result = pg_query($connection, $constraints_query);
if ($constraints_result) {
    while ($constraint = pg_fetch_assoc($constraints_result)) {
        echo "<p><strong>{$constraint['constraint_name']}:</strong><br>";
        echo "<code>{$constraint['check_clause']}</code></p>";
    }
}

// Test what document types exist in the database
echo "<h3>Existing Document Types:</h3>";
$types_query = "SELECT DISTINCT type FROM documents ORDER BY type;";
$types_result = pg_query($connection, $types_query);
if ($types_result) {
    echo "<ul>";
    while ($type_row = pg_fetch_assoc($types_result)) {
        echo "<li>{$type_row['type']}</li>";
    }
    echo "</ul>";
}

// Check if there's an enum type for document_type
echo "<h3>Enum Types:</h3>";
$enum_query = "SELECT typname, enumlabel FROM pg_type t JOIN pg_enum e ON t.oid = e.enumtypid WHERE typname LIKE '%document%' ORDER BY enumsortorder;";
$enum_result = pg_query($connection, $enum_query);
if ($enum_result && pg_num_rows($enum_result) > 0) {
    echo "<table border='1'>";
    echo "<tr><th>Enum Type</th><th>Allowed Value</th></tr>";
    while ($enum_row = pg_fetch_assoc($enum_result)) {
        echo "<tr><td>{$enum_row['typname']}</td><td>{$enum_row['enumlabel']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No document-related enum types found</p>";
}
?>