<?php
/**
 * ============================================================================
 * PHASE 2 MIGRATION RUNNER
 * ============================================================================
 * Purpose: Add year level management columns to students table
 * Date: October 31, 2025
 * 
 * This script runs:
 * - 003: Add year level management columns to students table
 * ============================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../config/database.php';

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════════╗\n";
echo "║                   PHASE 2 MIGRATION RUNNER                            ║\n";
echo "║           Add Year Level Management Columns to Students              ║\n";
echo "╚═══════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$migrationFile = '003_add_year_level_columns_to_students.sql';
$description = 'Add year level management columns to students table';

echo "──────────────────────────────────────────────────────────────────────\n";
echo "Running Migration: $migrationFile\n";
echo "Description: $description\n";
echo "──────────────────────────────────────────────────────────────────────\n";

$sqlFile = __DIR__ . '/' . $migrationFile;

if (!file_exists($sqlFile)) {
    echo "✗ ERROR: Migration file not found: $migrationFile\n\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);

if ($sql === false) {
    echo "✗ ERROR: Could not read file: $migrationFile\n\n";
    exit(1);
}

try {
    $result = pg_query($connection, $sql);
    
    if ($result === false) {
        throw new Exception(pg_last_error($connection));
    }
    
    echo "✓ Migration completed successfully!\n\n";
    
} catch (Exception $e) {
    echo "✗ Migration FAILED!\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    exit(1);
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════════╗\n";
echo "║                       VERIFICATION                                    ║\n";
echo "╚═══════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Verify columns were added
$columnCheck = pg_query($connection, "
    SELECT column_name, data_type, is_nullable, column_default
    FROM information_schema.columns
    WHERE table_name = 'students'
    AND column_name IN (
        'first_registered_academic_year',
        'current_academic_year',
        'year_level_history',
        'last_year_level_update',
        'course',
        'course_verified',
        'expected_graduation_year'
    )
    ORDER BY column_name
");

if ($columnCheck) {
    $columnCount = pg_num_rows($columnCheck);
    echo "Columns added to students table:\n\n";
    
    while ($col = pg_fetch_assoc($columnCheck)) {
        $nullable = $col['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $col['column_default'] ? " DEFAULT {$col['column_default']}" : '';
        echo "  ✓ {$col['column_name']} ({$col['data_type']}, {$nullable}{$default})\n";
    }
    
    echo "\n";
    echo "Total new columns: $columnCount/7\n";
    
    if ($columnCount === 7) {
        echo "\n";
        echo "╔═══════════════════════════════════════════════════════════════════════╗\n";
        echo "║                   ✓ ALL COLUMNS ADDED SUCCESSFULLY                    ║\n";
        echo "╚═══════════════════════════════════════════════════════════════════════╝\n";
    }
}

// Check triggers
echo "\n";
$triggerCheck = pg_query($connection, "
    SELECT trigger_name 
    FROM information_schema.triggers 
    WHERE event_object_table = 'students'
    AND trigger_name IN (
        'trigger_initialize_year_level_history',
        'trigger_calculate_graduation_year'
    )
");

if ($triggerCheck) {
    $triggerCount = pg_num_rows($triggerCheck);
    echo "Triggers created:\n\n";
    
    while ($trigger = pg_fetch_assoc($triggerCheck)) {
        echo "  ✓ {$trigger['trigger_name']}\n";
    }
    
    echo "\n";
    echo "Total triggers: $triggerCount/2\n";
}

// Check indexes
echo "\n";
$indexCheck = pg_query($connection, "
    SELECT indexname 
    FROM pg_indexes 
    WHERE tablename = 'students'
    AND indexname LIKE 'idx_students_%academic_year%'
       OR indexname LIKE 'idx_students_course%'
       OR indexname LIKE 'idx_students_expected%'
       OR indexname LIKE 'idx_students_year_level_history%'
");

if ($indexCheck) {
    $indexCount = pg_num_rows($indexCheck);
    echo "Indexes created:\n\n";
    
    while ($index = pg_fetch_assoc($indexCheck)) {
        echo "  ✓ {$index['indexname']}\n";
    }
    
    echo "\n";
    echo "Total new indexes: $indexCount\n";
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════════╗\n";
echo "║                   PHASE 2 MIGRATION COMPLETE                          ║\n";
echo "║                                                                       ║\n";
echo "║  Students table now has year level management capabilities!          ║\n";
echo "║  Ready to proceed with application logic updates.                    ║\n";
echo "╚═══════════════════════════════════════════════════════════════════════╝\n";

pg_close($connection);
echo "\n";
?>
