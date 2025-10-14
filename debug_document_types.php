<?php
include 'config/database.php';

echo "<h2>Database Investigation for Document Types</h2>";

// Check existing documents to see what types are currently used
echo "<h3>Currently Used Document Types:</h3>";
$existing_types_query = "SELECT DISTINCT type, COUNT(*) as count FROM documents GROUP BY type ORDER BY type;";
$result = pg_query($connection, $existing_types_query);

if ($result) {
    echo "<table border='1'>";
    echo "<tr><th>Document Type</th><th>Count</th></tr>";
    while ($row = pg_fetch_assoc($result)) {
        echo "<tr><td>{$row['type']}</td><td>{$row['count']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>Error: " . pg_last_error($connection) . "</p>";
}

// Check for enum types
echo "<h3>Document Type Enum (if exists):</h3>";
$enum_check = "SELECT enumlabel FROM pg_enum WHERE enumtypid = (SELECT oid FROM pg_type WHERE typname = 'document_type') ORDER BY enumsortorder;";
$enum_result = pg_query($connection, $enum_check);

if ($enum_result && pg_num_rows($enum_result) > 0) {
    echo "<ul>";
    while ($enum = pg_fetch_assoc($enum_result)) {
        echo "<li><code>{$enum['enumlabel']}</code></li>";
    }
    echo "</ul>";
} else {
    echo "<p>No document_type enum found or no values.</p>";
}

// Check constraints on documents table
echo "<h3>Table Constraints:</h3>";
$constraints_query = "
    SELECT 
        tc.constraint_name, 
        tc.constraint_type,
        cc.check_clause
    FROM information_schema.table_constraints tc
    LEFT JOIN information_schema.check_constraints cc ON tc.constraint_name = cc.constraint_name
    WHERE tc.table_name = 'documents'
    ORDER BY tc.constraint_type, tc.constraint_name;
";

$constraints_result = pg_query($connection, $constraints_query);
if ($constraints_result) {
    echo "<table border='1'>";
    echo "<tr><th>Constraint Name</th><th>Type</th><th>Check Clause</th></tr>";
    while ($constraint = pg_fetch_assoc($constraints_result)) {
        echo "<tr>";
        echo "<td>{$constraint['constraint_name']}</td>";
        echo "<td>{$constraint['constraint_type']}</td>";
        echo "<td>" . htmlspecialchars($constraint['check_clause']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Try different possible values for academic records
echo "<h3>Testing Document Type Values:</h3>";
$test_values = ['grades', 'academic_grades', 'transcript', 'academic_record', 'academic_transcript', 'school_records'];

foreach ($test_values as $test_type) {
    try {
        $test_insert = "INSERT INTO documents (student_id, type, file_path, upload_date) VALUES (999, '$test_type', '/test/path', NOW());";
        $test_result = pg_query($connection, "BEGIN; $test_insert ROLLBACK;");
        
        if ($test_result) {
            echo "<p>✅ <code>$test_type</code> - Valid</p>";
        } else {
            echo "<p>❌ <code>$test_type</code> - Invalid: " . pg_last_error($connection) . "</p>";
        }
    } catch (Exception $e) {
        echo "<p>❌ <code>$test_type</code> - Error: " . $e->getMessage() . "</p>";
    }
}
?>