<?php
/**
 * ============================================================================
 * PHASE 1: File Management System - Complete Setup
 * ============================================================================
 * Purpose: Database migration + Settings update + Folder creation
 * Date: 2025-10-19
 * ============================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300); // 5 minutes

require_once __DIR__ . '/../config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head>";
echo "<title>Phase 1 Setup - File Management System</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .success { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 10px 0; border-radius: 4px; }
    .error { background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 10px 0; border-radius: 4px; }
    .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 10px 0; border-radius: 4px; }
    .info { background: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 10px 0; border-radius: 4px; }
    .step { background: #f0f0f0; padding: 10px; margin: 10px 0; border-left: 4px solid #3498db; }
    h1 { color: #2c3e50; }
    h2 { color: #34495e; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
    .summary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
    .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px; }
    .btn:hover { background: #0056b3; }
</style>";
echo "</head><body>";
echo "<div class='container'>";

echo "<h1>🚀 Phase 1: File Management System Setup</h1>";
echo "<p>Complete database migration, settings update, and folder structure creation</p>";
echo "<hr>";

$results = [
    'success' => [],
    'failed' => [],
    'skipped' => []
];

/**
 * Execute SQL with error handling
 */
function executeSql($connection, $sql, $description) {
    global $results;
    
    echo "<div class='step'>";
    echo "<strong>$description</strong><br>";
    
    $result = @pg_query($connection, $sql);
    
    if ($result) {
        echo "✅ <span style='color: green;'>SUCCESS</span>";
        $results['success'][] = $description;
    } else {
        $error = pg_last_error($connection);
        
        if (stripos($error, 'already exists') !== false) {
            echo "⚠️ <span style='color: orange;'>SKIPPED (already exists)</span>";
            $results['skipped'][] = $description;
        } else {
            echo "❌ <span style='color: red;'>FAILED</span><br>";
            echo "<code style='color: red; font-size: 12px;'>" . htmlspecialchars($error) . "</code>";
            $results['failed'][] = $description;
        }
    }
    
    echo "</div>";
    
    return $result !== false;
}

// Reset any aborted transactions
@pg_query($connection, "ROLLBACK");

// ============================================================================
// PART 1: DATABASE SETUP
// ============================================================================

echo "<h2>📊 Part 1: Database Setup</h2>";

echo "<h3>Step 1.1: Creating/Updating distribution_files Table</h3>";

// Check if table exists
$checkTable = pg_query($connection, "
    SELECT table_name FROM information_schema.tables 
    WHERE table_name = 'distribution_files'
");

if (pg_num_rows($checkTable) == 0) {
    // Create new table
    $createTable = "
    CREATE TABLE distribution_files (
        file_id SERIAL PRIMARY KEY,
        student_id TEXT NOT NULL,
        distribution_id INTEGER,
        academic_year TEXT NOT NULL,
        original_filename TEXT NOT NULL,
        stored_filename TEXT NOT NULL,
        file_path TEXT NOT NULL,
        file_size BIGINT NOT NULL,
        file_type TEXT,
        file_category TEXT,
        is_compressed BOOLEAN DEFAULT FALSE,
        is_archived BOOLEAN DEFAULT FALSE,
        compression_date TIMESTAMP WITH TIME ZONE,
        compression_ratio NUMERIC(5,2),
        uploaded_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
        archived_at TIMESTAMP WITH TIME ZONE,
        checksum TEXT,
        uploaded_by INTEGER,
        notes TEXT,
        CONSTRAINT fk_student FOREIGN KEY (student_id) 
            REFERENCES students(student_id) ON DELETE CASCADE,
        CONSTRAINT fk_distribution FOREIGN KEY (distribution_id) 
            REFERENCES distributions(distribution_id) ON DELETE SET NULL
    )";
    
    executeSql($connection, $createTable, "Create distribution_files table");
} else {
    echo "<div class='info'>ℹ️ Table exists, checking for missing columns...</div>";
    
    // Add missing columns
    $columns = [
        'file_category' => 'TEXT',
        'is_compressed' => 'BOOLEAN DEFAULT FALSE',
        'is_archived' => 'BOOLEAN DEFAULT FALSE',
        'compression_date' => 'TIMESTAMP WITH TIME ZONE',
        'compression_ratio' => 'NUMERIC(5,2)',
        'archived_at' => 'TIMESTAMP WITH TIME ZONE',
        'checksum' => 'TEXT',
        'uploaded_by' => 'INTEGER',
        'notes' => 'TEXT'
    ];
    
    foreach ($columns as $col => $type) {
        $check = pg_query($connection, "
            SELECT column_name FROM information_schema.columns 
            WHERE table_name = 'distribution_files' AND column_name = '$col'
        ");
        
        if (pg_num_rows($check) == 0) {
            executeSql($connection, 
                "ALTER TABLE distribution_files ADD COLUMN $col $type",
                "Add column: $col"
            );
        }
    }
}

echo "<h3>Step 1.2: Creating file_archive_log Table</h3>";

$createLog = "
CREATE TABLE IF NOT EXISTS file_archive_log (
    log_id SERIAL PRIMARY KEY,
    student_id TEXT,
    operation TEXT NOT NULL,
    file_count INTEGER DEFAULT 0,
    total_size_before BIGINT,
    total_size_after BIGINT,
    space_saved BIGINT,
    operation_status TEXT,
    error_message TEXT,
    performed_by INTEGER,
    performed_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_student_archive_log FOREIGN KEY (student_id) 
        REFERENCES students(student_id) ON DELETE SET NULL,
    CONSTRAINT fk_admin_archive_log FOREIGN KEY (performed_by) 
        REFERENCES admins(admin_id) ON DELETE SET NULL
)";

executeSql($connection, $createLog, "Create file_archive_log table");

echo "<h3>Step 1.3: Creating Indexes</h3>";

$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_distribution_files_student ON distribution_files(student_id)",
    "CREATE INDEX IF NOT EXISTS idx_distribution_files_distribution ON distribution_files(distribution_id)",
    "CREATE INDEX IF NOT EXISTS idx_distribution_files_academic_year ON distribution_files(academic_year)",
    "CREATE INDEX IF NOT EXISTS idx_distribution_files_archived ON distribution_files(is_archived)",
    "CREATE INDEX IF NOT EXISTS idx_distribution_files_compressed ON distribution_files(is_compressed)",
    "CREATE INDEX IF NOT EXISTS idx_file_archive_log_student ON file_archive_log(student_id)",
    "CREATE INDEX IF NOT EXISTS idx_file_archive_log_operation ON file_archive_log(operation)",
    "CREATE INDEX IF NOT EXISTS idx_file_archive_log_date ON file_archive_log(performed_at)"
];

foreach ($indexes as $idx => $sql) {
    executeSql($connection, $sql, "Create index " . ($idx + 1));
}

echo "<h3>Step 1.4: Adding Columns to distributions Table</h3>";

$distColumns = [
    'status' => "TEXT DEFAULT 'active'",
    'ended_at' => 'TIMESTAMP WITH TIME ZONE',
    'files_compressed' => 'BOOLEAN DEFAULT FALSE',
    'compression_date' => 'TIMESTAMP WITH TIME ZONE'
];

foreach ($distColumns as $col => $type) {
    $check = pg_query($connection, "
        SELECT column_name FROM information_schema.columns 
        WHERE table_name = 'distributions' AND column_name = '$col'
    ");
    
    if (pg_num_rows($check) == 0) {
        executeSql($connection, 
            "ALTER TABLE distributions ADD COLUMN $col $type",
            "Add distributions.$col"
        );
    } else {
        echo "<div class='step'><strong>Add distributions.$col</strong><br>⚠️ <span style='color: orange;'>SKIPPED (exists)</span></div>";
        $results['skipped'][] = "Column distributions.$col";
    }
}

echo "<h3>Step 1.5: Creating Storage Statistics View</h3>";

$createView = "
CREATE OR REPLACE VIEW storage_statistics AS
SELECT 
    'Active Students' as category,
    COALESCE(COUNT(DISTINCT df.student_id), 0) as student_count,
    COALESCE(COUNT(df.file_id), 0) as file_count,
    COALESCE(SUM(CASE WHEN df.is_compressed THEN df.file_size ELSE 0 END), 0) as compressed_size,
    COALESCE(SUM(CASE WHEN NOT df.is_compressed THEN df.file_size ELSE 0 END), 0) as uncompressed_size,
    COALESCE(SUM(df.file_size), 0) as total_size
FROM distribution_files df
INNER JOIN students s ON df.student_id = s.student_id
WHERE s.is_archived = FALSE
UNION ALL
SELECT 
    'Archived Students' as category,
    COALESCE(COUNT(DISTINCT df.student_id), 0) as student_count,
    COALESCE(COUNT(df.file_id), 0) as file_count,
    COALESCE(SUM(CASE WHEN df.is_compressed THEN df.file_size ELSE 0 END), 0) as compressed_size,
    COALESCE(SUM(CASE WHEN NOT df.is_compressed THEN df.file_size ELSE 0 END), 0) as uncompressed_size,
    COALESCE(SUM(df.file_size), 0) as total_size
FROM distribution_files df
INNER JOIN students s ON df.student_id = s.student_id
WHERE s.is_archived = TRUE
UNION ALL
SELECT 
    'Total' as category,
    COALESCE(COUNT(DISTINCT df.student_id), 0) as student_count,
    COALESCE(COUNT(df.file_id), 0) as file_count,
    COALESCE(SUM(CASE WHEN df.is_compressed THEN df.file_size ELSE 0 END), 0) as compressed_size,
    COALESCE(SUM(CASE WHEN NOT df.is_compressed THEN df.file_size ELSE 0 END), 0) as uncompressed_size,
    COALESCE(SUM(df.file_size), 0) as total_size
FROM distribution_files df
";

executeSql($connection, $createView, "Create storage_statistics view");

// ============================================================================
// PART 2: UPDATE SETTINGS JSON
// ============================================================================

echo "<h2>⚙️ Part 2: Update Settings File</h2>";

$settingsPath = __DIR__ . '/../data/municipal_settings.json';

if (file_exists($settingsPath)) {
    $settings = json_decode(file_get_contents($settingsPath), true);
    
    $newSettings = [
        'archive_file_retention_years' => 5,
        'auto_compress_distributions' => true,
        'compress_after_days' => 30,
        'max_storage_gb' => 100,
        'enable_file_archiving' => true
    ];
    
    $added = [];
    foreach ($newSettings as $key => $value) {
        if (!isset($settings[$key])) {
            $settings[$key] = $value;
            $added[] = "$key = " . json_encode($value);
        }
    }
    
    if (!empty($added)) {
        file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
        echo "<div class='success'>";
        echo "<strong>✅ Updated municipal_settings.json</strong><br>";
        echo "Added: " . implode(', ', $added);
        echo "</div>";
        $results['success'][] = "Updated settings JSON";
    } else {
        echo "<div class='warning'>⚠️ All settings already exist in JSON file</div>";
        $results['skipped'][] = "Settings JSON (up to date)";
    }
} else {
    echo "<div class='error'>❌ Settings file not found: $settingsPath</div>";
    $results['failed'][] = "Settings JSON file missing";
}

// ============================================================================
// PART 3: CREATE FOLDER STRUCTURE
// ============================================================================

echo "<h2>📁 Part 3: Create Folder Structure</h2>";

$baseDir = __DIR__ . '/../uploads';
$folders = [
    'students' => 'Active student files',
    'distributions' => 'Distribution archives by academic year',
    'archived_students' => 'Archived student files by archive year',
    'temp' => 'Temporary upload storage'
];

foreach ($folders as $folder => $desc) {
    $path = $baseDir . '/' . $folder;
    
    if (!file_exists($path)) {
        if (mkdir($path, 0755, true)) {
            file_put_contents($path . '/.gitkeep', '# Keep directory in version control');
            echo "<div class='success'>✅ Created: <code>$folder/</code> - $desc</div>";
            $results['success'][] = "Created folder: $folder";
        } else {
            echo "<div class='error'>❌ Failed to create: <code>$folder/</code></div>";
            $results['failed'][] = "Create folder: $folder";
        }
    } else {
        echo "<div class='warning'>⚠️ Exists: <code>$folder/</code></div>";
        $results['skipped'][] = "Folder: $folder";
    }
}

// ============================================================================
// SUMMARY
// ============================================================================

echo "<hr>";
echo "<div class='summary'>";
echo "<h2 style='margin-top:0; color: white;'>📊 Setup Summary</h2>";

echo "<h3 style='color: white;'>✅ Successful: " . count($results['success']) . "</h3>";
if (!empty($results['success'])) {
    echo "<ul style='color: white;'>";
    foreach ($results['success'] as $item) {
        echo "<li>$item</li>";
    }
    echo "</ul>";
}

if (!empty($results['skipped'])) {
    echo "<h3 style='color: white;'>⚠️ Skipped: " . count($results['skipped']) . "</h3>";
    echo "<ul style='color: white;'>";
    foreach ($results['skipped'] as $item) {
        echo "<li>$item</li>";
    }
    echo "</ul>";
}

if (!empty($results['failed'])) {
    echo "<h3 style='color: white;'>❌ Failed: " . count($results['failed']) . "</h3>";
    echo "<ul style='color: white;'>";
    foreach ($results['failed'] as $item) {
        echo "<li>$item</li>";
    }
    echo "</ul>";
}

echo "</div>";

// Final verdict
if (empty($results['failed'])) {
    echo "<div class='success' style='text-align: center; padding: 30px;'>";
    echo "<h1 style='margin: 0; color: #155724;'>🎉 Phase 1 Complete!</h1>";
    echo "<p style='font-size: 18px; margin: 20px 0;'>File management system is ready to use.</p>";
    echo "<a href='verify_phase1.php' class='btn'>→ Verify Installation</a>";
    echo "<a href='../modules/admin/archived_students.php' class='btn'>→ View Archived Students</a>";
    echo "</div>";
    
    echo "<div class='info'>";
    echo "<h3>✨ What's Next?</h3>";
    echo "<p><strong>Phase 2:</strong> Distribution Auto-Archive System</p>";
    echo "<ul>";
    echo "<li>Compression service when distributions end</li>";
    echo "<li>File moving utilities for archiving/unarchiving</li>";
    echo "<li>Admin controls for manual compression</li>";
    echo "<li>Storage management dashboard</li>";
    echo "</ul>";
    echo "</div>";
} else {
    echo "<div class='error' style='text-align: center; padding: 30px;'>";
    echo "<h2 style='margin: 0;'>⚠️ Setup Had Errors</h2>";
    echo "<p>Some operations failed. Please review the failed items above.</p>";
    echo "<a href='?retry=1' class='btn'>↻ Retry Setup</a>";
    echo "</div>";
}

echo "</div>"; // container
echo "</body></html>";
?>
