<?php
// Diagnostic script to check current database state
try {
    $pdo = new PDO('pgsql:host=localhost;dbname=educaid', 'postgres', 'postgres_dev_2025');
    
    echo "=== CURRENT DATABASE STATE DIAGNOSIS ===\n";
    
    // Check if students table exists and its structure
    echo "\n1. STUDENTS TABLE STRUCTURE:\n";
    try {
        $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'students' ORDER BY ordinal_position");
        while($row = $stmt->fetch()) {
            echo "  - " . $row['column_name'] . " (" . $row['data_type'] . ")\n";
        }
    } catch(Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
    
    // Check primary key constraint
    echo "\n2. PRIMARY KEY CONSTRAINTS:\n";
    try {
        $stmt = $pdo->query("SELECT constraint_name, column_name FROM information_schema.key_column_usage WHERE table_name = 'students' AND constraint_name LIKE '%pkey%'");
        while($row = $stmt->fetch()) {
            echo "  - " . $row['constraint_name'] . " on " . $row['column_name'] . "\n";
        }
    } catch(Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
    
    // Check foreign key constraints
    echo "\n3. FOREIGN KEY CONSTRAINTS:\n";
    $tables = ['applications', 'documents', 'enrollment_forms', 'distributions', 'qr_logs', 'schedules', 'grade_uploads', 'notifications'];
    foreach($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT constraint_name FROM information_schema.table_constraints WHERE table_name = '$table' AND constraint_type = 'FOREIGN KEY'");
            $constraints = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "  $table: " . (count($constraints) > 0 ? implode(', ', $constraints) : 'No constraints') . "\n";
        } catch(Exception $e) {
            echo "  $table: ERROR - " . $e->getMessage() . "\n";
        }
    }
    
    // Check if tables have the new columns
    echo "\n4. NEW COLUMN STATUS:\n";
    foreach($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = '$table' AND column_name IN ('student_id', 'new_student_id')");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "  $table: " . implode(', ', $columns) . "\n";
        } catch(Exception $e) {
            echo "  $table: ERROR - " . $e->getMessage() . "\n";
        }
    }
    
    // Check data in students table
    echo "\n5. SAMPLE STUDENTS DATA:\n";
    try {
        $stmt = $pdo->query("SELECT * FROM students LIMIT 3");
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($students as $student) {
            $keys = array_keys($student);
            echo "  Student columns: " . implode(', ', $keys) . "\n";
            break; // Just show structure once
        }
        echo "  Total students: " . count($students) . "\n";
    } catch(Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
    
    // Check for any active transactions
    echo "\n6. TRANSACTION STATUS:\n";
    try {
        $stmt = $pdo->query("SELECT state, query FROM pg_stat_activity WHERE datname = 'educaid' AND state != 'idle'");
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($activities) > 0) {
            foreach($activities as $activity) {
                echo "  Active: " . $activity['state'] . " - " . substr($activity['query'], 0, 100) . "\n";
            }
        } else {
            echo "  No active transactions\n";
        }
    } catch(Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
    
} catch(PDOException $e) {
    echo 'Database Error: ' . $e->getMessage() . "\n";
}
?>