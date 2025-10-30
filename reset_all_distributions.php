<?php
/**
 * ============================================================================
 * RESET ALL DISTRIBUTIONS - Complete Distribution System Reset
 * ============================================================================
 * 
 * This script performs a complete reset of the distribution system:
 * 1. Deletes all compressed distribution ZIP files
 * 2. Clears all distribution snapshots from database
 * 3. Removes all student snapshot records
 * 4. Cleans up distribution file manifests
 * 5. Removes distribution student records
 * 
 * ⚠️ WARNING: This is a DESTRUCTIVE operation!
 * - All distribution history will be permanently deleted
 * - All compressed archives will be removed
 * - This action cannot be undone
 * 
 * Use this when starting a new school year to clean slate.
 * 
 * Date: October 30, 2025
 * ============================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300); // 5 minutes max

require_once __DIR__ . '/config/database.php';

// HTML header
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset All Distributions - EducAid</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #333;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        .header .subtitle {
            font-size: 1.1em;
            opacity: 0.95;
        }
        .content {
            padding: 40px;
        }
        .warning-box {
            background: #fff3cd;
            border: 3px solid #ffc107;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);
        }
        .warning-box h2 {
            color: #856404;
            margin-bottom: 15px;
            font-size: 1.5em;
        }
        .warning-box ul {
            margin-left: 25px;
            color: #856404;
        }
        .warning-box li {
            margin: 8px 0;
        }
        .section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            border-left: 5px solid #667eea;
        }
        .section h3 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 1.3em;
        }
        .step {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
            border-left: 4px solid #28a745;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .step.error {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        .step.warning {
            border-left-color: #ffc107;
            background: #fffef5;
        }
        .step-title {
            font-weight: bold;
            font-size: 1.1em;
            margin-bottom: 10px;
            color: #333;
        }
        .step-content {
            color: #666;
            margin-left: 20px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .stat-box {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            color: #666;
            margin-top: 5px;
            font-size: 0.9em;
        }
        .success {
            color: #28a745;
            font-weight: bold;
        }
        .error {
            color: #dc3545;
            font-weight: bold;
        }
        .info {
            color: #17a2b8;
        }
        .code {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            overflow-x: auto;
            margin: 10px 0;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
            border-top: 1px solid #dee2e6;
        }
        .icon {
            display: inline-block;
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><span class="icon">🔄</span>Distribution System Reset</h1>
            <p class="subtitle">Complete cleanup of all distribution records and archives</p>
        </div>
        <div class="content">
<?php

// ============================================================================
// STEP 0: Display Warning and Current Status
// ============================================================================

echo '<div class="warning-box">';
echo '<h2><span class="icon">⚠️</span>CRITICAL WARNING</h2>';
echo '<p>This script will permanently delete:</p>';
echo '<ul>';
echo '<li>All compressed distribution ZIP files</li>';
echo '<li>All distribution snapshot records</li>';
echo '<li>All student snapshot records</li>';
echo '<li>All distribution file manifests</li>';
echo '<li>All distribution student records</li>';
echo '<li>Historical distribution data</li>';
echo '</ul>';
echo '<p style="margin-top: 15px;"><strong>This action CANNOT be undone!</strong></p>';
echo '</div>';

// Check current status before deletion
echo '<div class="section">';
echo '<h3><span class="icon">📊</span>Current Status (Before Reset)</h3>';

$stats = [
    'zip_files' => 0,
    'zip_size' => 0,
    'snapshots' => 0,
    'student_snapshots' => 0,
    'file_manifests' => 0,
    'student_records' => 0
];

// Count ZIP files
$distributionsPath = __DIR__ . '/assets/uploads/distributions';
if (is_dir($distributionsPath)) {
    $zipFiles = glob($distributionsPath . '/*.zip');
    $stats['zip_files'] = count($zipFiles);
    foreach ($zipFiles as $zipFile) {
        $stats['zip_size'] += filesize($zipFile);
    }
}

// Count database records
$queries = [
    'snapshots' => "SELECT COUNT(*) as count FROM distribution_snapshots",
    'student_snapshots' => "SELECT COUNT(*) as count FROM distribution_student_snapshot",
    'file_manifests' => "SELECT COUNT(*) as count FROM distribution_file_manifest",
    'student_records' => "SELECT COUNT(*) as count FROM distribution_student_records"
];

foreach ($queries as $key => $query) {
    $result = @pg_query($connection, $query);
    if ($result) {
        $row = pg_fetch_assoc($result);
        $stats[$key] = $row['count'] ?? 0;
    }
}

echo '<div class="stats">';
echo '<div class="stat-box">';
echo '<div class="stat-number">' . $stats['zip_files'] . '</div>';
echo '<div class="stat-label">ZIP Archives</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . number_format($stats['zip_size'] / 1024 / 1024, 2) . ' MB</div>';
echo '<div class="stat-label">Total Archive Size</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . $stats['snapshots'] . '</div>';
echo '<div class="stat-label">Distribution Snapshots</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . $stats['student_snapshots'] . '</div>';
echo '<div class="stat-label">Student Snapshots</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . $stats['file_manifests'] . '</div>';
echo '<div class="stat-label">File Manifests</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . $stats['student_records'] . '</div>';
echo '<div class="stat-label">Student Records</div>';
echo '</div>';
echo '</div>';

echo '</div>';

// ============================================================================
// STEP 1: Delete All ZIP Archives
// ============================================================================

echo '<div class="section">';
echo '<h3><span class="icon">📦</span>Step 1: Delete Distribution ZIP Archives</h3>';

$deletedFiles = [];
$deletedSize = 0;
$deleteErrors = [];

if (is_dir($distributionsPath)) {
    $zipFiles = glob($distributionsPath . '/*.zip');
    
    if (empty($zipFiles)) {
        echo '<div class="step warning">';
        echo '<div class="step-title">No ZIP files found</div>';
        echo '<div class="step-content">The distributions folder is empty or contains no ZIP files.</div>';
        echo '</div>';
    } else {
        foreach ($zipFiles as $zipFile) {
            $filename = basename($zipFile);
            $filesize = filesize($zipFile);
            
            if (unlink($zipFile)) {
                $deletedFiles[] = $filename;
                $deletedSize += $filesize;
                
                echo '<div class="step">';
                echo '<div class="step-title"><span class="success">✓</span> Deleted: ' . htmlspecialchars($filename) . '</div>';
                echo '<div class="step-content">Size: ' . number_format($filesize / 1024 / 1024, 2) . ' MB</div>';
                echo '</div>';
            } else {
                $deleteErrors[] = $filename;
                
                echo '<div class="step error">';
                echo '<div class="step-title"><span class="error">✗</span> Failed to delete: ' . htmlspecialchars($filename) . '</div>';
                echo '<div class="step-content">Check file permissions</div>';
                echo '</div>';
            }
        }
        
        echo '<div class="step">';
        echo '<div class="step-title"><span class="success">✓</span> ZIP Deletion Summary</div>';
        echo '<div class="step-content">';
        echo 'Files deleted: <strong>' . count($deletedFiles) . '</strong><br>';
        echo 'Space freed: <strong>' . number_format($deletedSize / 1024 / 1024, 2) . ' MB</strong><br>';
        if (!empty($deleteErrors)) {
            echo '<span class="error">Failed deletions: ' . count($deleteErrors) . '</span>';
        }
        echo '</div>';
        echo '</div>';
    }
} else {
    echo '<div class="step warning">';
    echo '<div class="step-title">Distributions folder not found</div>';
    echo '<div class="step-content">Path: ' . htmlspecialchars($distributionsPath) . '</div>';
    echo '</div>';
}

echo '</div>';

// ============================================================================
// STEP 2: Clear Database Tables
// ============================================================================

echo '<div class="section">';
echo '<h3><span class="icon">🗄️</span>Step 2: Clear Distribution Database Tables</h3>';

pg_query($connection, "BEGIN");

try {
    $deletionQueries = [
        [
            'name' => 'Distribution Student Records',
            'query' => 'DELETE FROM distribution_student_records',
            'description' => 'Individual student distribution tracking records'
        ],
        [
            'name' => 'Distribution File Manifest',
            'query' => 'DELETE FROM distribution_file_manifest',
            'description' => 'File tracking and manifest entries'
        ],
        [
            'name' => 'Distribution Student Snapshots',
            'query' => 'DELETE FROM distribution_student_snapshot',
            'description' => 'Historical student data snapshots'
        ],
        [
            'name' => 'Distribution Snapshots',
            'query' => 'DELETE FROM distribution_snapshots',
            'description' => 'Main distribution cycle records'
        ]
    ];
    
    foreach ($deletionQueries as $deletion) {
        $result = @pg_query($connection, $deletion['query']);
        
        if ($result) {
            $affected = pg_affected_rows($result);
            
            echo '<div class="step">';
            echo '<div class="step-title"><span class="success">✓</span> Cleared: ' . $deletion['name'] . '</div>';
            echo '<div class="step-content">';
            echo 'Records deleted: <strong>' . $affected . '</strong><br>';
            echo 'Description: ' . $deletion['description'];
            echo '</div>';
            echo '</div>';
        } else {
            echo '<div class="step error">';
            echo '<div class="step-title"><span class="error">✗</span> Failed: ' . $deletion['name'] . '</div>';
            echo '<div class="step-content">';
            echo 'Error: ' . pg_last_error($connection);
            echo '</div>';
            echo '</div>';
        }
    }
    
    // Reset sequences to start fresh
    echo '<div class="step">';
    echo '<div class="step-title"><span class="info">↻</span> Resetting Auto-Increment Sequences</div>';
    echo '<div class="step-content">';
    
    $sequences = [
        'distribution_snapshots_snapshot_id_seq',
        'distribution_student_snapshot_student_snapshot_id_seq',
        'distribution_file_manifest_manifest_id_seq',
        'distribution_student_records_record_id_seq'
    ];
    
    foreach ($sequences as $sequence) {
        $resetQuery = "ALTER SEQUENCE IF EXISTS $sequence RESTART WITH 1";
        if (@pg_query($connection, $resetQuery)) {
            echo '✓ Reset sequence: <code>' . $sequence . '</code><br>';
        }
    }
    
    echo '</div>';
    echo '</div>';
    
    pg_query($connection, "COMMIT");
    
    echo '<div class="step">';
    echo '<div class="step-title"><span class="success">✓</span> Database Reset Complete</div>';
    echo '<div class="step-content">All distribution tables have been cleared and sequences reset.</div>';
    echo '</div>';
    
} catch (Exception $e) {
    pg_query($connection, "ROLLBACK");
    
    echo '<div class="step error">';
    echo '<div class="step-title"><span class="error">✗</span> Database Reset Failed</div>';
    echo '<div class="step-content">';
    echo 'Error: ' . htmlspecialchars($e->getMessage()) . '<br>';
    echo 'All changes have been rolled back.';
    echo '</div>';
    echo '</div>';
}

echo '</div>';

// ============================================================================
// STEP 3: Verify Clean State
// ============================================================================

echo '<div class="section">';
echo '<h3><span class="icon">✅</span>Step 3: Verification - Final Status</h3>';

// Re-check everything
$finalStats = [
    'zip_files' => 0,
    'zip_size' => 0,
    'snapshots' => 0,
    'student_snapshots' => 0,
    'file_manifests' => 0,
    'student_records' => 0
];

// Count ZIP files
if (is_dir($distributionsPath)) {
    $zipFiles = glob($distributionsPath . '/*.zip');
    $finalStats['zip_files'] = count($zipFiles);
    foreach ($zipFiles as $zipFile) {
        $finalStats['zip_size'] += filesize($zipFile);
    }
}

// Count database records
foreach ($queries as $key => $query) {
    $result = @pg_query($connection, $query);
    if ($result) {
        $row = pg_fetch_assoc($result);
        $finalStats[$key] = $row['count'] ?? 0;
    }
}

$allClear = ($finalStats['zip_files'] === 0 && 
             $finalStats['snapshots'] === 0 && 
             $finalStats['student_snapshots'] === 0 && 
             $finalStats['file_manifests'] === 0 && 
             $finalStats['student_records'] === 0);

if ($allClear) {
    echo '<div class="step">';
    echo '<div class="step-title"><span class="success">✓✓✓ VERIFICATION PASSED</span></div>';
    echo '<div class="step-content">';
    echo 'All distribution data has been successfully removed!<br>';
    echo 'The system is now ready for a fresh start.';
    echo '</div>';
    echo '</div>';
} else {
    echo '<div class="step warning">';
    echo '<div class="step-title"><span class="error">⚠️ VERIFICATION WARNING</span></div>';
    echo '<div class="step-content">';
    echo 'Some data still remains:<br>';
    if ($finalStats['zip_files'] > 0) echo '- ZIP Files: ' . $finalStats['zip_files'] . '<br>';
    if ($finalStats['snapshots'] > 0) echo '- Distribution Snapshots: ' . $finalStats['snapshots'] . '<br>';
    if ($finalStats['student_snapshots'] > 0) echo '- Student Snapshots: ' . $finalStats['student_snapshots'] . '<br>';
    if ($finalStats['file_manifests'] > 0) echo '- File Manifests: ' . $finalStats['file_manifests'] . '<br>';
    if ($finalStats['student_records'] > 0) echo '- Student Records: ' . $finalStats['student_records'] . '<br>';
    echo '</div>';
    echo '</div>';
}

echo '<div class="stats">';
echo '<div class="stat-box">';
echo '<div class="stat-number">' . $finalStats['zip_files'] . '</div>';
echo '<div class="stat-label">ZIP Archives</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . number_format($finalStats['zip_size'] / 1024 / 1024, 2) . ' MB</div>';
echo '<div class="stat-label">Total Archive Size</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . $finalStats['snapshots'] . '</div>';
echo '<div class="stat-label">Distribution Snapshots</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . $finalStats['student_snapshots'] . '</div>';
echo '<div class="stat-label">Student Snapshots</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . $finalStats['file_manifests'] . '</div>';
echo '<div class="stat-label">File Manifests</div>';
echo '</div>';

echo '<div class="stat-box">';
echo '<div class="stat-number">' . $finalStats['student_records'] . '</div>';
echo '<div class="stat-label">Student Records</div>';
echo '</div>';
echo '</div>';

echo '</div>';

// ============================================================================
// STEP 4: Recommendations
// ============================================================================

echo '<div class="section">';
echo '<h3><span class="icon">💡</span>Next Steps & Recommendations</h3>';

echo '<div class="step">';
echo '<div class="step-title">What you can do now:</div>';
echo '<div class="step-content">';
echo '<ol style="margin-left: 20px;">';
echo '<li><strong>Start Fresh:</strong> You can now create distributions for the 2025-2026 school year</li>';
echo '<li><strong>Configure Academic Year:</strong> Update the current academic year in system settings</li>';
echo '<li><strong>Update Semester:</strong> Set the current semester (1st or 2nd)</li>';
echo '<li><strong>Clean Uploads:</strong> Consider cleaning up old student document uploads if needed</li>';
echo '<li><strong>Backup:</strong> Always maintain database backups before major operations</li>';
echo '</ol>';
echo '</div>';
echo '</div>';

echo '<div class="step warning">';
echo '<div class="step-title">Important Notes:</div>';
echo '<div class="step-content">';
echo '⚠️ This script does NOT delete:<br>';
echo '<ul style="margin-left: 20px;">';
echo '<li>Student records (students table)</li>';
echo '<li>Admin accounts</li>';
echo '<li>System configuration</li>';
echo '<li>Municipality data</li>';
echo '<li>Individual student uploaded documents</li>';
echo '</ul>';
echo '<p style="margin-top: 10px;">Only distribution-related data and archives are removed.</p>';
echo '</div>';
echo '</div>';

echo '</div>';

// ============================================================================
// Summary Report
// ============================================================================

echo '<div class="section" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">';
echo '<h3 style="color: white;"><span class="icon">📋</span>Execution Summary</h3>';

$summary = [
    'ZIP files deleted' => count($deletedFiles),
    'Space freed' => number_format($deletedSize / 1024 / 1024, 2) . ' MB',
    'Database records removed' => ($stats['snapshots'] + $stats['student_snapshots'] + $stats['file_manifests'] + $stats['student_records']),
    'Status' => $allClear ? '✓ Clean State Achieved' : '⚠️ Some Data Remains'
];

echo '<div style="background: rgba(255,255,255,0.1); padding: 20px; border-radius: 8px; margin-top: 15px;">';
foreach ($summary as $key => $value) {
    echo '<div style="margin: 10px 0; font-size: 1.1em;">';
    echo '<strong>' . $key . ':</strong> ' . $value;
    echo '</div>';
}
echo '</div>';

echo '</div>';

?>
        </div>
        <div class="footer">
            <p>Distribution Reset Script - EducAid System</p>
            <p style="margin-top: 5px; font-size: 0.9em;">Date: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
</body>
</html>
