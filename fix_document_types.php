<?php
include 'config/database.php';

echo "<h2>Database Migration: Add Academic Grades Document Type</h2>";

// First, show current constraint
echo "<h3>Current Constraint:</h3>";
$current_constraint = "
    SELECT pg_get_constraintdef(oid) as definition
    FROM pg_constraint 
    WHERE conname = 'documents_type_check';
";
$result = pg_query($connection, $current_constraint);
if ($result) {
    $row = pg_fetch_assoc($result);
    echo "<p><code>" . htmlspecialchars($row['definition']) . "</code></p>";
}

// Drop the old constraint and create a new one that includes academic_grades
echo "<h3>Updating Constraint...</h3>";

try {
    // Start transaction
    pg_query($connection, "BEGIN;");
    
    // Drop existing constraint
    $drop_result = pg_query($connection, "ALTER TABLE documents DROP CONSTRAINT documents_type_check;");
    
    if ($drop_result) {
        echo "<p>✅ Old constraint dropped successfully</p>";
        
        // Add new constraint with academic_grades included
        $new_constraint = "ALTER TABLE documents ADD CONSTRAINT documents_type_check 
                          CHECK (type = ANY (ARRAY['school_id'::text, 'eaf'::text, 'certificate_of_indigency'::text, 'letter_to_mayor'::text, 'id_picture'::text, 'academic_grades'::text]));";
        
        $add_result = pg_query($connection, $new_constraint);
        
        if ($add_result) {
            echo "<p>✅ New constraint added successfully</p>";
            
            // Test the new constraint
            $test_query = "BEGIN; INSERT INTO documents (student_id, type, file_path) VALUES (999, 'academic_grades', '/test/path'); ROLLBACK;";
            $test_result = pg_query($connection, $test_query);
            
            if ($test_result) {
                echo "<p>✅ Test insert with 'academic_grades' successful</p>";
                
                // Commit the changes
                pg_query($connection, "COMMIT;");
                echo "<p><strong>🎉 Migration completed successfully!</strong></p>";
                echo "<p>You can now use 'academic_grades' as a document type.</p>";
                
            } else {
                echo "<p>❌ Test insert failed: " . pg_last_error($connection) . "</p>";
                pg_query($connection, "ROLLBACK;");
            }
        } else {
            echo "<p>❌ Failed to add new constraint: " . pg_last_error($connection) . "</p>";
            pg_query($connection, "ROLLBACK;");
        }
    } else {
        echo "<p>❌ Failed to drop old constraint: " . pg_last_error($connection) . "</p>";
        pg_query($connection, "ROLLBACK;");
    }
    
} catch (Exception $e) {
    echo "<p>❌ Exception: " . $e->getMessage() . "</p>";
    pg_query($connection, "ROLLBACK;");
}

// Show final constraint
echo "<h3>Final Constraint:</h3>";
$final_constraint = "
    SELECT pg_get_constraintdef(oid) as definition
    FROM pg_constraint 
    WHERE conname = 'documents_type_check';
";
$final_result = pg_query($connection, $final_constraint);
if ($final_result) {
    $row = pg_fetch_assoc($final_result);
    echo "<p><code>" . htmlspecialchars($row['definition']) . "</code></p>";
}
?>