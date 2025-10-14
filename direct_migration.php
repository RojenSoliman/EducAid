<?php
include 'config/database.php';

echo "Direct SQL Migration for Academic Grades\n";
echo "=====================================\n\n";

// Try the migration step by step
echo "Step 1: Dropping existing constraint...\n";
$drop_sql = "ALTER TABLE documents DROP CONSTRAINT documents_type_check";
$drop_result = pg_query($connection, $drop_sql);

if ($drop_result) {
    echo "✅ Constraint dropped successfully\n";
    
    echo "Step 2: Adding new constraint with academic_grades...\n";
    $add_sql = "ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type = ANY (ARRAY['school_id'::text, 'eaf'::text, 'certificate_of_indigency'::text, 'letter_to_mayor'::text, 'id_picture'::text, 'academic_grades'::text]))";
    $add_result = pg_query($connection, $add_sql);
    
    if ($add_result) {
        echo "✅ New constraint added successfully\n";
        
        echo "Step 3: Testing academic_grades insertion...\n";
        $test_sql = "INSERT INTO documents (student_id, type, file_path, upload_date) VALUES (999, 'academic_grades', '/test/path', NOW())";
        $test_result = pg_query($connection, $test_sql);
        
        if ($test_result) {
            echo "✅ Test insert successful\n";
            
            // Clean up test record
            $cleanup_sql = "DELETE FROM documents WHERE student_id = 999 AND type = 'academic_grades'";
            pg_query($connection, $cleanup_sql);
            echo "✅ Test record cleaned up\n";
            
            echo "\n🎉 SUCCESS: academic_grades is now a valid document type!\n";
        } else {
            echo "❌ Test insert failed: " . pg_last_error($connection) . "\n";
        }
    } else {
        echo "❌ Failed to add new constraint: " . pg_last_error($connection) . "\n";
    }
} else {
    echo "❌ Failed to drop constraint: " . pg_last_error($connection) . "\n";
}

echo "\nFinal constraint check:\n";
$final_check = "SELECT pg_get_constraintdef(oid) as definition FROM pg_constraint WHERE conname = 'documents_type_check'";
$final_result = pg_query($connection, $final_check);
if ($final_result) {
    $row = pg_fetch_assoc($final_result);
    echo $row['definition'] . "\n";
}
?>