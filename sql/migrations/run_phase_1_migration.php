<?php
/**
 * ============================================================================
 * PHASE 1 MIGRATION RUNNER
 * ============================================================================
 * Purpose: Create new independent tables for year level management
 * Date: October 31, 2025
 * 
 * This script runs both migrations:
 * - 001: Create academic_years table
 * - 002: Create courses_mapping table
 * ============================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../config/database.php';

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════════╗\n";
echo "║                   PHASE 1 MIGRATION RUNNER                            ║\n";
echo "║              Year Level Management System - Part 1                    ║\n";
echo "╚═══════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$migrations = [
    '001_create_academic_years_table.sql' => 'Create academic_years table',
    '002_create_courses_mapping_table.sql' => 'Create courses_mapping table'
];

$success = true;
$results = [];

foreach ($migrations as $file => $description) {
    echo "──────────────────────────────────────────────────────────────────────\n";
    echo "Running Migration: $file\n";
    echo "Description: $description\n";
    echo "──────────────────────────────────────────────────────────────────────\n";
    
    $sqlFile = __DIR__ . '/' . $file;
    
    if (!file_exists($sqlFile)) {
        echo "✗ ERROR: Migration file not found: $file\n\n";
        $success = false;
        $results[$file] = 'FAILED - File not found';
        continue;
    }
    
    $sql = file_get_contents($sqlFile);
    
    if ($sql === false) {
        echo "✗ ERROR: Could not read file: $file\n\n";
        $success = false;
        $results[$file] = 'FAILED - Could not read file';
        continue;
    }
    
    try {
        $result = pg_query($connection, $sql);
        
        if ($result === false) {
            throw new Exception(pg_last_error($connection));
        }
        
        echo "✓ Migration completed successfully!\n\n";
        $results[$file] = 'SUCCESS';
        
    } catch (Exception $e) {
        echo "✗ Migration FAILED!\n";
        echo "Error: " . $e->getMessage() . "\n\n";
        $success = false;
        $results[$file] = 'FAILED - ' . $e->getMessage();
    }
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════════╗\n";
echo "║                       MIGRATION SUMMARY                               ║\n";
echo "╚═══════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

foreach ($results as $file => $status) {
    $icon = (strpos($status, 'SUCCESS') !== false) ? '✓' : '✗';
    echo "$icon $file: $status\n";
}

echo "\n";

if ($success) {
    echo "╔═══════════════════════════════════════════════════════════════════════╗\n";
    echo "║                   ✓ ALL MIGRATIONS SUCCESSFUL                         ║\n";
    echo "╚═══════════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    
    // Verify tables were created
    echo "Verifying table creation...\n\n";
    
    // Check academic_years
    $check = pg_query($connection, "SELECT COUNT(*) as count FROM academic_years");
    if ($check) {
        $row = pg_fetch_assoc($check);
        echo "✓ academic_years: {$row['count']} records\n";
    }
    
    // Check courses_mapping
    $check = pg_query($connection, "SELECT COUNT(*) as count FROM courses_mapping");
    if ($check) {
        $row = pg_fetch_assoc($check);
        echo "✓ courses_mapping: {$row['count']} records\n";
    }
    
    echo "\n";
    echo "Phase 1 Complete! Ready for Phase 2.\n";
    
} else {
    echo "╔═══════════════════════════════════════════════════════════════════════╗\n";
    echo "║                   ✗ SOME MIGRATIONS FAILED                            ║\n";
    echo "║                Please check errors above                              ║\n";
    echo "╚═══════════════════════════════════════════════════════════════════════╝\n";
    exit(1);
}

pg_close($connection);
echo "\n";
?>
