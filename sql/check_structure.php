<?php
// Simple database structure checker
try {
    $pdo = new PDO('pgsql:host=localhost;dbname=educaid', 'postgres', 'postgres_dev_2025');
    
    echo "=== CURRENT STUDENTS TABLE STRUCTURE ===\n";
    $stmt = $pdo->query("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'students' ORDER BY ordinal_position");
    while($row = $stmt->fetch()) {
        echo $row['column_name'] . ' (' . $row['data_type'] . ') - nullable: ' . $row['is_nullable'] . "\n";
    }
    
    echo "\n=== SAMPLE DATA ===\n";
    $stmt = $pdo->query("SELECT student_id, unique_student_id, first_name, last_name FROM students LIMIT 5");
    while($row = $stmt->fetch()) {
        echo "ID: " . $row['student_id'] . " | Unique: " . $row['unique_student_id'] . " | Name: " . $row['first_name'] . " " . $row['last_name'] . "\n";
    }
    
    echo "\n=== FOREIGN KEY TABLES ===\n";
    $tables = ['applications', 'documents', 'enrollment_forms', 'distributions', 'qr_logs', 'schedules', 'grade_uploads', 'notifications'];
    foreach($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$table'");
        if($stmt->fetchColumn() > 0) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
            $count = $stmt->fetchColumn();
            echo "$table: $count records\n";
        }
    }
    
} catch(PDOException $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
?>