<?php
/**
 * Cleanup orphaned temporary files
 * 
 * This script removes files from the temp folder that should have been moved
 * to permanent storage but were left behind due to the previous bug.
 * 
 * Run this once to clean up existing orphaned files.
 */

$baseDir = __DIR__ . '/assets/uploads/temp/';

$folders = [
    'id_pictures',
    'enrollment_forms',
    'grades',
    'letter_mayor',
    'indigency'
];

$totalDeleted = 0;
$totalFailed = 0;
$deletedFiles = [];
$failedFiles = [];

echo "=== Temporary File Cleanup ===\n\n";

foreach ($folders as $folder) {
    $folderPath = $baseDir . $folder . '/';
    
    if (!is_dir($folderPath)) {
        echo "Folder not found: $folder (skipping)\n";
        continue;
    }
    
    echo "Scanning: $folder/\n";
    
    $files = scandir($folderPath);
    $folderDeleted = 0;
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $filePath = $folderPath . $file;
        
        if (is_file($filePath)) {
            // Delete the file
            if (@unlink($filePath)) {
                $folderDeleted++;
                $totalDeleted++;
                $deletedFiles[] = "$folder/$file";
                echo "  ✓ Deleted: $file\n";
            } else {
                $totalFailed++;
                $failedFiles[] = "$folder/$file";
                echo "  ✗ Failed to delete: $file\n";
            }
        }
    }
    
    echo "  → Deleted $folderDeleted file(s) from $folder/\n\n";
}

echo "=== Cleanup Summary ===\n";
echo "Total deleted: $totalDeleted file(s)\n";
echo "Total failed: $totalFailed file(s)\n\n";

if (!empty($deletedFiles)) {
    echo "Deleted files:\n";
    foreach ($deletedFiles as $file) {
        echo "  - $file\n";
    }
    echo "\n";
}

if (!empty($failedFiles)) {
    echo "Failed to delete:\n";
    foreach ($failedFiles as $file) {
        echo "  - $file\n";
    }
    echo "\n";
}

echo "Cleanup complete!\n";
?>
