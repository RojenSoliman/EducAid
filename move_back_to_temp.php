<?php
include __DIR__ . '/config/database.php';

$student_id = 'GENERALTRIAS-2025-3-P6BE0U';

echo "=== MOVING FILES BACK TO TEMP ===\n";

// Define folder mappings
$folderMap = [
    'enrollment_forms' => ['perm' => 'enrollment_forms', 'temp' => 'enrollment_forms', 'code' => '00'],
    'grades' => ['perm' => 'grades', 'temp' => 'grades', 'code' => '01'],
    'letter_mayor' => ['perm' => 'letter_mayor', 'temp' => 'letter_mayor', 'code' => '02'],
    'indigency' => ['perm' => 'indigency', 'temp' => 'indigency', 'code' => '03'],
    'id_pictures' => ['perm' => 'id_pictures', 'temp' => 'id_pictures', 'code' => '04']
];

foreach ($folderMap as $key => $folders) {
    $permDir = __DIR__ . '/assets/uploads/student/' . $folders['perm'] . '/' . $student_id . '/';
    $tempDir = __DIR__ . '/assets/uploads/temp/' . $folders['temp'] . '/';
    
    if (!is_dir($permDir)) {
        echo "Skipping $key - permanent directory doesn't exist\n";
        continue;
    }
    
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    $files = array_diff(scandir($permDir), ['.', '..']);
    foreach ($files as $filename) {
        $oldPath = $permDir . $filename;
        
        // Remove timestamp from filename to get original name
        // Pattern: studentid_name_type_TIMESTAMP.ext -> studentid_name_type.ext
        $newFilename = preg_replace('/_(\d{14})\./', '.', $filename);
        $newPath = $tempDir . $newFilename;
        
        echo "Moving: $filename\n";
        echo "  From: $oldPath\n";
        echo "  To: $newPath\n";
        
        if (rename($oldPath, $newPath)) {
            echo "  ✓ Success\n";
        } else {
            echo "  ✗ Failed\n";
        }
    }
    
    // Remove student directory
    if (is_dir($permDir)) {
        rmdir($permDir);
        echo "Removed directory: $permDir\n";
    }
}

echo "\n=== UPDATING DOCUMENTS TABLE ===\n";
pg_query($connection, "BEGIN");

// Update all document paths to temp format
$docTypes = [
    '00' => ['folder' => 'enrollment_forms', 'pattern' => '_eaf_'],
    '01' => ['folder' => 'grades', 'pattern' => '_grades_'],
    '02' => ['folder' => 'letter_mayor', 'pattern' => '_lettertomayor_'],
    '03' => ['folder' => 'indigency', 'pattern' => '_indigency_'],
    '04' => ['folder' => 'id_pictures', 'pattern' => '_id_']
];

foreach ($docTypes as $code => $info) {
    $query = "UPDATE documents 
              SET file_path = regexp_replace(
                  file_path, 
                  'assets/uploads/student/" . $info['folder'] . "/" . $student_id . "/(.*?)_\\d{14}\\.', 
                  'assets/uploads/temp/" . $info['folder'] . "/\\1.', 
                  'g'
              )
              WHERE student_id = $1 AND document_type_code = $2";
    
    $result = pg_query_params($connection, $query, [$student_id, $code]);
    if ($result) {
        echo "✓ Updated document type $code\n";
    } else {
        echo "✗ Failed to update document type $code: " . pg_last_error($connection) . "\n";
    }
}

echo "\n=== RESETTING STUDENT STATUS ===\n";
$query = "UPDATE students 
          SET status = 'under_registration',
              year_level_id = NULL,
              expected_graduation_year = NULL
          WHERE student_id = $1";

$result = pg_query_params($connection, $query, [$student_id]);
if ($result) {
    echo "✓ Student reset to under_registration\n";
    echo "✓ Year level cleared\n";
    echo "✓ Expected graduation year cleared\n";
} else {
    echo "✗ Failed to update student: " . pg_last_error($connection) . "\n";
}

pg_query($connection, "COMMIT");

echo "\n=== VERIFICATION ===\n";
$result = pg_query_params($connection, "SELECT status, year_level_id, expected_graduation_year FROM students WHERE student_id = $1", [$student_id]);
$student = pg_fetch_assoc($result);
echo "Status: {$student['status']}\n";
echo "Year Level: " . ($student['year_level_id'] ?? 'NULL') . "\n";
echo "Expected Graduation: " . ($student['expected_graduation_year'] ?? 'NULL') . "\n";

echo "\nDocument paths:\n";
$result = pg_query_params($connection, "SELECT document_type_code, file_path FROM documents WHERE student_id = $1 ORDER BY document_type_code", [$student_id]);
while ($row = pg_fetch_assoc($result)) {
    echo "  [{$row['document_type_code']}] {$row['file_path']}\n";
}

echo "\n✓ DONE!\n";
