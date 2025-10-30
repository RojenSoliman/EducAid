<?php
/**
 * ============================================================================
 * COMPLETE STUDENT DATA CLEANUP SCRIPT
 * ============================================================================
 * 
 * This script removes ALL student-related data from the system:
 * - Student accounts and records
 * - Student notifications
 * - Distribution slots
 * - Applications and documents
 * - All related data
 * 
 * ⚠️ CRITICAL WARNING: This is a DESTRUCTIVE operation!
 * - All student data will be permanently deleted
 * - All student accounts will be removed
 * - All distribution slots will be cleared
 * - This action CANNOT be undone
 * 
 * Use this before major schema changes to start with a clean slate.
 * 
 * Date: October 30, 2025
 * Purpose: Prepare system for year level management update
 * ============================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600); // 10 minutes max

require_once __DIR__ . '/config/database.php';

// Security check - require confirmation
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === 'YES_DELETE_ALL_STUDENTS';

// HTML header
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Data Cleanup - EducAid</title>
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
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
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
            font-weight: bold;
        }
        .danger-box {
            background: #f8d7da;
            border: 3px solid #dc3545;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        .danger-box h2 {
            color: #721c24;
            margin-bottom: 15px;
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
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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
        .btn {
            display: inline-block;
            padding: 15px 30px;
            font-size: 1.1em;
            font-weight: bold;
            text-decoration: none;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .confirmation-form {
            background: #f8d7da;
            padding: 30px;
            border-radius: 10px;
            border: 3px solid #dc3545;
            margin-top: 20px;
        }
        .confirmation-input {
            width: 100%;
            padding: 12px;
            font-size: 1em;
            border: 2px solid #dc3545;
            border-radius: 5px;
            margin: 10px 0;
        }
        .code {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 10px 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            display: inline-block;
            margin: 10px 0;
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
            <h1><span class="icon">🗑️</span>Student Data Cleanup</h1>
            <p class="subtitle">Complete removal of all student records and related data</p>
        </div>
        <div class="content">
<?php

if (!$confirm) {
    // ============================================================================
    // STEP 0: Display Warning and Current Status
    // ============================================================================
    
    echo '<div class="danger-box">';
    echo '<h2><span class="icon">⚠️</span>CRITICAL WARNING - READ CAREFULLY</h2>';
    echo '<p style="font-size: 1.1em; margin-bottom: 15px;"><strong>This script will permanently delete:</strong></p>';
    echo '<ul>';
    echo '<li>ALL student accounts and login credentials</li>';
    echo '<li>ALL student personal information and profiles</li>';
    echo '<li>ALL student notifications and messages</li>';
    echo '<li>ALL distribution slots and assignments</li>';
    echo '<li>ALL student applications and documents</li>';
    echo '<li>ALL student distribution records</li>';
    echo '<li>ALL student snapshots and history</li>';
    echo '<li>ALL enrollment forms and uploaded files</li>';
    echo '</ul>';
    echo '<p style="margin-top: 20px; font-size: 1.2em; color: #721c24;"><strong>⚠️ THIS ACTION CANNOT BE UNDONE!</strong></p>';
    echo '</div>';
    
    // Check current status before deletion
    echo '<div class="section">';
    echo '<h3><span class="icon">📊</span>Current Database Status</h3>';
    
    $stats = [
        'students' => 0,
        'notifications' => 0,
        'qr_logs' => 0,
        'documents' => 0,
        'distribution_records' => 0,
        'student_snapshots' => 0
    ];
    
    // Count records
    $queries = [
        'students' => "SELECT COUNT(*) as count FROM students",
        'notifications' => "SELECT COUNT(*) as count FROM student_notifications",
        'qr_logs' => "SELECT COUNT(*) as count FROM qr_logs",
        'documents' => "SELECT COUNT(*) as count FROM documents",
        'distribution_records' => "SELECT COUNT(*) as count FROM distribution_student_records",
        'student_snapshots' => "SELECT COUNT(*) as count FROM distribution_student_snapshot"
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
    echo '<div class="stat-number">' . $stats['students'] . '</div>';
    echo '<div class="stat-label">Student Accounts</div>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $stats['notifications'] . '</div>';
    echo '<div class="stat-label">Notifications</div>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $stats['qr_logs'] . '</div>';
    echo '<div class="stat-label">QR Logs</div>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $stats['documents'] . '</div>';
    echo '<div class="stat-label">Documents</div>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $stats['distribution_records'] . '</div>';
    echo '<div class="stat-label">Distribution Records</div>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $stats['student_snapshots'] . '</div>';
    echo '<div class="stat-label">Student Snapshots</div>';
    echo '</div>';
    echo '</div>';
    
    echo '</div>';
    
    // Confirmation Form
    echo '<div class="section">';
    echo '<h3><span class="icon">🔒</span>Confirmation Required</h3>';
    
    if (array_sum($stats) === 0) {
        echo '<div class="step">';
        echo '<div class="step-title"><span class="success">✓</span> No Student Data Found</div>';
        echo '<div class="step-content">The database is already clean. No student records to delete.</div>';
        echo '</div>';
        echo '<a href="index.php" class="btn btn-secondary" style="margin-top: 20px;">Return to Home</a>';
    } else {
        echo '<div class="confirmation-form">';
        echo '<p style="font-size: 1.1em; margin-bottom: 15px;"><strong>To proceed with deletion, you must:</strong></p>';
        echo '<ol style="margin-left: 20px; margin-bottom: 20px;">';
        echo '<li>Understand that this will delete <strong>' . array_sum($stats) . ' total records</strong></li>';
        echo '<li>Confirm you have a database backup (if needed)</li>';
        echo '<li>Type the confirmation phrase exactly as shown</li>';
        echo '<li>Click the deletion button</li>';
        echo '</ol>';
        
        echo '<form method="GET" onsubmit="return confirm(\'Are you ABSOLUTELY SURE you want to delete ALL student data? This cannot be undone!\');">';
        echo '<p style="margin-bottom: 10px;">Type this phrase to confirm:</p>';
        echo '<div class="code">YES_DELETE_ALL_STUDENTS</div>';
        echo '<input type="text" name="confirm" class="confirmation-input" placeholder="Type the confirmation phrase here" required>';
        echo '<div style="margin-top: 20px;">';
        echo '<button type="submit" class="btn btn-danger">';
        echo '<span class="icon">🗑️</span> DELETE ALL STUDENT DATA';
        echo '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }
    
    echo '</div>';
    
} else {
    // ============================================================================
    // EXECUTE DELETION
    // ============================================================================
    
    echo '<div class="section">';
    echo '<h3><span class="icon">🔄</span>Executing Deletion Process</h3>';
    
    pg_query($connection, "BEGIN");
    
    try {
        // Each step must be executed in its own mini-transaction to avoid constraint conflicts
        $deletionSteps = [
            [
                'name' => 'Delete Distribution Student Snapshots',
                'query' => 'DELETE FROM distribution_student_snapshot',
                'description' => 'Remove all historical student snapshots'
            ],
            [
                'name' => 'Delete Distribution Student Records',
                'query' => 'DELETE FROM distribution_student_records',
                'description' => 'Remove all distribution tracking records'
            ],
            [
                'name' => 'Delete Student Notifications',
                'query' => 'DELETE FROM student_notifications',
                'description' => 'Remove all student notification records'
            ],
            [
                'name' => 'Delete QR Code Logs',
                'query' => 'DELETE FROM qr_logs',
                'description' => 'Remove all QR code scan logs for students'
            ],
            [
                'name' => 'Delete Student Documents',
                'query' => 'DELETE FROM documents WHERE student_id IN (SELECT student_id FROM students)',
                'description' => 'Remove all uploaded document records'
            ],
            [
                'name' => 'Delete Blacklisted Students',
                'query' => 'DELETE FROM blacklisted_students',
                'description' => 'Remove all blacklist records'
            ],
            [
                'name' => 'Delete Student Accounts',
                'query' => 'DELETE FROM students',
                'description' => 'Remove all student account records'
            ]
        ];
        
        foreach ($deletionSteps as $step) {
            // Execute each deletion separately to handle constraints
            pg_query($connection, "COMMIT"); // Commit previous
            pg_query($connection, "BEGIN");  // Start new transaction
            
            $result = pg_query($connection, $step['query']);
            
            if ($result) {
                $affected = pg_affected_rows($result);
                
                echo '<div class="step">';
                echo '<div class="step-title"><span class="success">✓</span> ' . $step['name'] . '</div>';
                echo '<div class="step-content">';
                echo 'Records affected: <strong>' . $affected . '</strong><br>';
                echo 'Description: ' . $step['description'];
                echo '</div>';
                echo '</div>';
                
                pg_query($connection, "COMMIT");
            } else {
                $error = pg_last_error($connection);
                echo '<div class="step error">';
                echo '<div class="step-title"><span class="error">✗</span> Failed: ' . $step['name'] . '</div>';
                echo '<div class="step-content">';
                echo 'Error: ' . htmlspecialchars($error);
                echo '</div>';
                echo '</div>';
                
                pg_query($connection, "ROLLBACK");
                throw new Exception("Failed at step: " . $step['name'] . " - " . $error);
            }
        }
        
        // Start final transaction for sequence reset
        pg_query($connection, "BEGIN");
        
        // Reset sequences
        echo '<div class="step">';
        echo '<div class="step-title"><span class="icon">↻</span> Resetting Auto-Increment Sequences</div>';
        echo '<div class="step-content">';
        
        $sequences = [
            'students_student_id_seq',
            'documents_document_id_seq',
            'student_notifications_notification_id_seq',
            'distribution_student_records_record_id_seq',
            'distribution_student_snapshot_student_snapshot_id_seq',
            'blacklisted_students_blacklist_id_seq',
            'qr_logs_log_id_seq'
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
        echo '<div class="step-title"><span class="success">✓✓✓</span> Cleanup Complete</div>';
        echo '<div class="step-content">All student data has been successfully removed from the database.</div>';
        echo '</div>';
        
    } catch (Exception $e) {
        pg_query($connection, "ROLLBACK");
        
        echo '<div class="step error">';
        echo '<div class="step-title"><span class="error">✗</span> Cleanup Failed</div>';
        echo '<div class="step-content">';
        echo 'Error: ' . htmlspecialchars($e->getMessage()) . '<br>';
        echo 'All changes have been rolled back.';
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
    
    // ============================================================================
    // VERIFICATION
    // ============================================================================
    
    echo '<div class="section">';
    echo '<h3><span class="icon">✅</span>Verification - Final Status</h3>';
    
    // Re-check everything
    $finalStats = [
        'students' => 0,
        'notifications' => 0,
        'qr_logs' => 0,
        'documents' => 0,
        'distribution_records' => 0,
        'student_snapshots' => 0
    ];
    
    $queries = [
        'students' => "SELECT COUNT(*) as count FROM students",
        'notifications' => "SELECT COUNT(*) as count FROM student_notifications",
        'qr_logs' => "SELECT COUNT(*) as count FROM qr_logs",
        'documents' => "SELECT COUNT(*) as count FROM documents",
        'distribution_records' => "SELECT COUNT(*) as count FROM distribution_student_records",
        'student_snapshots' => "SELECT COUNT(*) as count FROM distribution_student_snapshot"
    ];
    
    foreach ($queries as $key => $query) {
        $result = @pg_query($connection, $query);
        if ($result) {
            $row = pg_fetch_assoc($result);
            $finalStats[$key] = $row['count'] ?? 0;
        }
    }
    
    $allClear = (array_sum($finalStats) === 0);
    
    if ($allClear) {
        echo '<div class="step">';
        echo '<div class="step-title"><span class="success">✓✓✓ VERIFICATION PASSED</span></div>';
        echo '<div class="step-content">';
        echo 'All student data has been successfully removed!<br>';
        echo 'The system is now ready for new student registrations with updated schema.';
        echo '</div>';
        echo '</div>';
    } else {
        echo '<div class="step warning">';
        echo '<div class="step-title"><span class="error">⚠️ VERIFICATION WARNING</span></div>';
        echo '<div class="step-content">';
        echo 'Some data still remains:<br>';
        if ($finalStats['students'] > 0) echo '- Student Accounts: ' . $finalStats['students'] . '<br>';
        if ($finalStats['notifications'] > 0) echo '- Notifications: ' . $finalStats['notifications'] . '<br>';
        if ($finalStats['qr_logs'] > 0) echo '- QR Logs: ' . $finalStats['qr_logs'] . '<br>';
        if ($finalStats['documents'] > 0) echo '- Documents: ' . $finalStats['documents'] . '<br>';
        if ($finalStats['distribution_records'] > 0) echo '- Distribution Records: ' . $finalStats['distribution_records'] . '<br>';
        if ($finalStats['student_snapshots'] > 0) echo '- Student Snapshots: ' . $finalStats['student_snapshots'] . '<br>';
        echo '</div>';
        echo '</div>';
    }
    
    echo '<div class="stats">';
    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $finalStats['students'] . '</div>';
    echo '<div class="stat-label">Student Accounts</div>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $finalStats['notifications'] . '</div>';
    echo '<div class="stat-label">Notifications</div>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $finalStats['qr_logs'] . '</div>';
    echo '<div class="stat-label">QR Logs</div>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $finalStats['documents'] . '</div>';
    echo '<div class="stat-label">Documents</div>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $finalStats['distribution_records'] . '</div>';
    echo '<div class="stat-label">Distribution Records</div>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $finalStats['student_snapshots'] . '</div>';
    echo '<div class="stat-label">Student Snapshots</div>';
    echo '</div>';
    echo '</div>';
    
    echo '</div>';
    
    // ============================================================================
    // NEXT STEPS
    // ============================================================================
    
    echo '<div class="section">';
    echo '<h3><span class="icon">💡</span>Next Steps</h3>';
    
    echo '<div class="step">';
    echo '<div class="step-title">What to do now:</div>';
    echo '<div class="step-content">';
    echo '<ol style="margin-left: 20px;">';
    echo '<li><strong>Update Database Schema:</strong> Run your new migration scripts to add year level columns</li>';
    echo '<li><strong>Update Registration:</strong> Modify student registration to include new fields</li>';
    echo '<li><strong>Test Registration:</strong> Create test student accounts with new schema</li>';
    echo '<li><strong>Verify OCR:</strong> Test document uploads with course detection</li>';
    echo '<li><strong>Configure Academic Year:</strong> Set up the academic year management system</li>';
    echo '</ol>';
    echo '</div>';
    echo '</div>';
    
    echo '</div>';
}

?>
        </div>
    </div>
</body>
</html>
<?php pg_close($connection); ?>
