<?php
/**
 * Quick verification script for Phase 1 setup
 */

require_once __DIR__ . '/../config/database.php';

echo "<h1>🔍 Phase 1 Setup Verification</h1>";
echo "<p>Checking if all components are properly installed...</p>";
echo "<hr>";

$checks = [
    'passed' => [],
    'failed' => [],
    'warnings' => []
];

// Check 1: distribution_files table
echo "<h2>1️⃣ Checking Tables...</h2>";
$tableCheck = pg_query($connection, "
    SELECT table_name 
    FROM information_schema.tables 
    WHERE table_schema = 'public' 
    AND table_name IN ('distribution_files', 'file_archive_log')
");

$tables = pg_fetch_all($tableCheck);
if ($tables && count($tables) == 2) {
    $checks['passed'][] = "Tables: distribution_files, file_archive_log exist";
    echo "✅ <strong>PASS:</strong> Both tables created<br>";
} else {
    $checks['failed'][] = "Required tables missing";
    echo "❌ <strong>FAIL:</strong> Tables not found<br>";
}

// Check 2: municipal_settings JSON file
echo "<h2>2️⃣ Checking Settings Columns...</h2>";
$settingsPath = __DIR__ . '/../data/municipal_settings.json';

if (file_exists($settingsPath)) {
    $settings = json_decode(file_get_contents($settingsPath), true);
    
    $requiredSettings = [
        'archive_file_retention_years',
        'auto_compress_distributions',
        'compress_after_days',
        'max_storage_gb',
        'enable_file_archiving'
    ];
    
    $missingSettings = [];
    foreach ($requiredSettings as $key) {
        if (!isset($settings[$key])) {
            $missingSettings[] = $key;
        }
    }
    
    if (empty($missingSettings)) {
        $checks['passed'][] = "Settings: All 5 archiving settings exist";
        echo "✅ <strong>PASS:</strong> All settings exist in JSON file<br>";
    } else {
        $checks['failed'][] = "Settings incomplete: " . implode(', ', $missingSettings);
        echo "❌ <strong>FAIL:</strong> Missing settings: " . implode(', ', $missingSettings) . "<br>";
    }
} else {
    $checks['failed'][] = "Settings file not found";
    echo "❌ <strong>FAIL:</strong> municipal_settings.json not found<br>";
}

// Check 3: distributions columns
echo "<h2>3️⃣ Checking Distributions Columns...</h2>";
$distCheck = pg_query($connection, "
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'distributions' 
    AND column_name IN ('status', 'ended_at', 'files_compressed', 'compression_date')
");

$distCols = pg_fetch_all($distCheck);
if ($distCols && count($distCols) == 4) {
    $checks['passed'][] = "Distributions: All 4 columns added";
    echo "✅ <strong>PASS:</strong> Distribution tracking columns exist<br>";
} else {
    $checks['failed'][] = "Distributions columns incomplete";
    echo "❌ <strong>FAIL:</strong> Missing distribution columns<br>";
}

// Check 4: Indexes
echo "<h2>4️⃣ Checking Indexes...</h2>";
$indexCheck = pg_query($connection, "
    SELECT COUNT(*) as index_count
    FROM pg_indexes 
    WHERE tablename IN ('distribution_files', 'file_archive_log')
    AND schemaname = 'public'
");

$indexCount = pg_fetch_assoc($indexCheck)['index_count'];
if ($indexCount >= 7) {
    $checks['passed'][] = "Indexes: $indexCount indexes created";
    echo "✅ <strong>PASS:</strong> All indexes created ($indexCount found)<br>";
} else {
    $checks['warnings'][] = "Only $indexCount indexes found (expected 7+)";
    echo "⚠️ <strong>WARNING:</strong> Only $indexCount indexes found<br>";
}

// Check 5: Storage statistics view
echo "<h2>5️⃣ Checking Views...</h2>";
$viewCheck = pg_query($connection, "
    SELECT table_name 
    FROM information_schema.views 
    WHERE table_name = 'storage_statistics' 
    AND table_schema = 'public'
");

if (pg_num_rows($viewCheck) > 0) {
    $checks['passed'][] = "Views: storage_statistics created";
    echo "✅ <strong>PASS:</strong> Storage statistics view exists<br>";
    
    // Test the view
    $statsTest = pg_query($connection, "SELECT * FROM storage_statistics");
    if ($statsTest) {
        echo "&nbsp;&nbsp;&nbsp;└─ View is functional<br>";
    }
} else {
    $checks['failed'][] = "Storage statistics view missing";
    echo "❌ <strong>FAIL:</strong> View not found<br>";
}

// Check 6: Folder structure
echo "<h2>6️⃣ Checking Folder Structure...</h2>";
$baseDir = __DIR__ . '/../uploads';
$requiredFolders = [
    'students',
    'distributions',
    'archived_students',
    'temp'
];

$foldersPassed = true;
foreach ($requiredFolders as $folder) {
    $path = $baseDir . '/' . $folder;
    if (is_dir($path)) {
        echo "✅ <code>$folder/</code> exists<br>";
    } else {
        echo "❌ <code>$folder/</code> missing<br>";
        $foldersPassed = false;
    }
}

if ($foldersPassed) {
    $checks['passed'][] = "Folders: All 4 directories created";
} else {
    $checks['failed'][] = "Some folders missing";
}

// Summary
echo "<hr>";
echo "<h2>📊 Summary</h2>";

if (!empty($checks['passed'])) {
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3 style='color: #155724; margin-top: 0;'>✅ Passed Checks (" . count($checks['passed']) . ")</h3>";
    echo "<ul>";
    foreach ($checks['passed'] as $check) {
        echo "<li>$check</li>";
    }
    echo "</ul>";
    echo "</div>";
}

if (!empty($checks['warnings'])) {
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3 style='color: #856404; margin-top: 0;'>⚠️ Warnings (" . count($checks['warnings']) . ")</h3>";
    echo "<ul>";
    foreach ($checks['warnings'] as $warning) {
        echo "<li>$warning</li>";
    }
    echo "</ul>";
    echo "</div>";
}

if (!empty($checks['failed'])) {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3 style='color: #721c24; margin-top: 0;'>❌ Failed Checks (" . count($checks['failed']) . ")</h3>";
    echo "<ul>";
    foreach ($checks['failed'] as $failed) {
        echo "<li>$failed</li>";
    }
    echo "</ul>";
    echo "</div>";
}

// Final verdict
echo "<hr>";
if (empty($checks['failed'])) {
    echo "<div style='background: #d4edda; border: 3px solid #28a745; padding: 20px; border-radius: 8px; text-align: center;'>";
    echo "<h2 style='color: #155724; margin: 0;'>🎉 Phase 1 Setup Complete!</h2>";
    echo "<p style='margin: 10px 0 0 0;'>All components are properly installed and ready to use.</p>";
    echo "<p><strong>Next Step:</strong> Proceed to Phase 2 (Distribution Auto-Archive)</p>";
    echo "</div>";
} else {
    echo "<div style='background: #f8d7da; border: 3px solid #dc3545; padding: 20px; border-radius: 8px; text-align: center;'>";
    echo "<h2 style='color: #721c24; margin: 0;'>⚠️ Setup Incomplete</h2>";
    echo "<p style='margin: 10px 0 0 0;'>Some components are missing. Please review the failed checks above.</p>";
    echo "<p><strong>Action Required:</strong> Re-run the SQL migration and folder setup script.</p>";
    echo "</div>";
}
?>
