<?php
include __DIR__ . '/../../config/database.php';

echo "<h3>Current Students and File Status</h3>";

// Get all students
$query = "SELECT student_id, first_name, last_name, status FROM students ORDER BY student_id LIMIT 10";
$result = pg_query($connection, $query);

if (pg_num_rows($result) == 0) {
    echo "<p>No students found in database</p>";
} else {
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>";
    echo "<tr><th>Student ID</th><th>Name</th><th>Status</th><th>Files Found</th></tr>";
    
    while ($student = pg_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>{$student['student_id']}</td>";
        echo "<td>{$student['first_name']} {$student['last_name']}</td>";
        echo "<td>{$student['status']}</td>";
        
        // Check for files in both temp and student directories
        $student_id = $student['student_id'];
        $file_locations = [];
        
        // Check temp directories
        $temp_dirs = [
            'temp/enrollment_forms' => __DIR__ . '/../../assets/uploads/temp/enrollment_forms/',
            'temp/letter_mayor' => __DIR__ . '/../../assets/uploads/temp/letter_mayor/',
            'temp/indigency' => __DIR__ . '/../../assets/uploads/temp/indigency/',
        ];
        
        foreach ($temp_dirs as $label => $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . $student_id . '_*');
                if (!empty($files)) {
                    $file_locations[] = "$label: " . count($files) . " files";
                }
            }
        }
        
        // Check student directories
        $student_dirs = [
            'student/enrollment_forms' => __DIR__ . '/../../assets/uploads/student/enrollment_forms/',
            'student/letter_to_mayor' => __DIR__ . '/../../assets/uploads/student/letter_to_mayor/',
            'student/indigency' => __DIR__ . '/../../assets/uploads/student/indigency/'
        ];
        
        foreach ($student_dirs as $label => $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . $student_id . '_*');
                if (!empty($files)) {
                    $file_locations[] = "$label: " . count($files) . " files";
                }
            }
        }
        
        echo "<td>" . (empty($file_locations) ? "No files" : implode('<br>', $file_locations)) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Check database entries
echo "<h4>Document Database Entries (Last 10)</h4>";
$doc_query = "SELECT student_id, type, file_path, is_valid FROM documents ORDER BY document_id DESC LIMIT 10";
$doc_result = pg_query($connection, $doc_query);

if (pg_num_rows($doc_result) > 0) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Student ID</th><th>Type</th><th>File Path</th><th>File Exists?</th><th>Valid</th></tr>";
    
    while ($doc = pg_fetch_assoc($doc_result)) {
        $file_exists = file_exists($doc['file_path']) ? "✅" : "❌";
        echo "<tr>";
        echo "<td>{$doc['student_id']}</td>";
        echo "<td>{$doc['type']}</td>";
        echo "<td>{$doc['file_path']}</td>";
        echo "<td>$file_exists</td>";
        echo "<td>" . ($doc['is_valid'] ? '✅' : '❌') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No documents found in database</p>";
}

pg_close($connection);
?>