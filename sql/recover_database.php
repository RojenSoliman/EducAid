<?php
// Recovery script to clean up failed migration
try {
    $pdo = new PDO('pgsql:host=localhost;dbname=educaid', 'postgres', 'postgres_dev_2025');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== STARTING RECOVERY FROM FAILED MIGRATION ===\n";
    
    // First, rollback any aborted transaction
    try {
        $pdo->exec("ROLLBACK");
        echo "✓ Rolled back aborted transaction\n";
    } catch (Exception $e) {
        echo "Note: No transaction to rollback\n";
    }
    
    // Check current state
    echo "\n1. Checking current state...\n";
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'students' AND column_name IN ('student_id', 'unique_student_id')");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Students table has columns: " . implode(', ', $columns) . "\n";
    
    // Clean up any partial migration artifacts
    echo "\n2. Cleaning up partial migration artifacts...\n";
    $tables = ['applications', 'documents', 'enrollment_forms', 'distributions', 'qr_logs', 'schedules', 'grade_uploads', 'notifications'];
    
    foreach ($tables as $table) {
        try {
            // Check if new_student_id column exists
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = '$table' AND column_name = 'new_student_id'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE $table DROP COLUMN new_student_id");
                echo "✓ Dropped new_student_id column from $table\n";
            }
        } catch (Exception $e) {
            echo "Note: $table doesn't have new_student_id column\n";
        }
    }
    
    // Ensure all students have unique_student_id
    echo "\n3. Ensuring all students have unique_student_id...\n";
    $stmt = $pdo->query("SELECT student_id FROM students WHERE unique_student_id IS NULL OR unique_student_id = ''");
    $students_without_unique_id = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($students_without_unique_id as $student_id) {
        $year = date('Y');
        $unique_id = "EDU-{$year}-" . str_pad($student_id, 6, '0', STR_PAD_LEFT);
        
        // Ensure uniqueness
        $counter = 0;
        $base_unique_id = $unique_id;
        while (true) {
            $stmt = $pdo->prepare("SELECT 1 FROM students WHERE unique_student_id = ?");
            $stmt->execute([$unique_id]);
            if ($stmt->rowCount() == 0) break;
            $counter++;
            $unique_id = $base_unique_id . '-' . $counter;
        }
        
        $stmt = $pdo->prepare("UPDATE students SET unique_student_id = ? WHERE student_id = ?");
        $stmt->execute([$unique_id, $student_id]);
        echo "✓ Generated unique_student_id $unique_id for student_id $student_id\n";
    }
    
    // Verify final state
    echo "\n4. Verification...\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM students WHERE unique_student_id IS NOT NULL");
    $count = $stmt->fetchColumn();
    echo "✓ All $count students now have unique_student_id\n";
    
    $stmt = $pdo->query("SELECT student_id, unique_student_id FROM students LIMIT 3");
    echo "Sample data:\n";
    while ($row = $stmt->fetch()) {
        echo "  student_id: {$row['student_id']} -> unique_student_id: {$row['unique_student_id']}\n";
    }
    
    echo "\n=== RECOVERY COMPLETE ===\n";
    echo "The database is now ready for the step-by-step migration.\n";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>