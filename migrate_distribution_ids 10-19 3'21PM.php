<?php
/**
 * Migration Script: Convert distribution_id from INTEGER to TEXT
 * This enables identifiable distribution IDs like GENERALTRIAS-DISTR-2025-10-19-001
 */

require_once __DIR__ . '/config/database.php';

echo "=== Distribution ID Migration ===\n\n";

// Check current state
echo "STEP 1: Checking current table structure...\n";
$checkQuery = "SELECT column_name, data_type FROM information_schema.columns 
               WHERE table_name = 'distributions' AND column_name = 'distribution_id'";
$checkResult = pg_query($connection, $checkQuery);
$currentType = pg_fetch_assoc($checkResult);

echo "Current distribution_id type: {$currentType['data_type']}\n\n";

if ($currentType['data_type'] === 'text') {
    echo "✓ Distribution ID is already TEXT type. No migration needed!\n";
    exit(0);
}

// Count existing records
$countQuery = "SELECT COUNT(*) as count FROM distributions";
$countResult = pg_query($connection, $countQuery);
$count = pg_fetch_assoc($countResult)['count'];

echo "STEP 2: Found $count existing distribution records\n";

if ($count > 0) {
    echo "⚠ WARNING: Existing records will be migrated to new ID format\n";
    echo "Current records:\n";
    $existingQuery = "SELECT distribution_id, student_id, date_given FROM distributions ORDER BY distribution_id";
    $existingResult = pg_query($connection, $existingQuery);
    while ($row = pg_fetch_assoc($existingResult)) {
        echo "  - ID: {$row['distribution_id']}, Student: {$row['student_id']}, Date: {$row['date_given']}\n";
    }
    echo "\n";
}

echo "STEP 3: Starting migration...\n";

pg_query($connection, "BEGIN");

try {
    // Drop sequence if exists
    echo "  - Dropping old sequence...\n";
    pg_query($connection, "DROP SEQUENCE IF EXISTS distributions_distribution_id_seq CASCADE");
    
    // Add temporary column
    echo "  - Adding temporary column...\n";
    pg_query($connection, "ALTER TABLE distributions ADD COLUMN new_distribution_id TEXT");
    
    // Generate new IDs for existing records
    if ($count > 0) {
        echo "  - Generating new IDs for existing records...\n";
        $existingQuery = "SELECT distribution_id, date_given FROM distributions ORDER BY distribution_id";
        $existingResult = pg_query($connection, $existingQuery);
        $counter = 1;
        
        while ($row = pg_fetch_assoc($existingResult)) {
            $date = $row['date_given'] ?: date('Y-m-d');
            $newId = "GENERALTRIAS-DISTR-{$date}-" . str_pad($counter, 3, '0', STR_PAD_LEFT);
            
            $updateQuery = "UPDATE distributions SET new_distribution_id = $1 WHERE distribution_id = $2";
            pg_query_params($connection, $updateQuery, [$newId, $row['distribution_id']]);
            
            echo "    {$row['distribution_id']} → $newId\n";
            $counter++;
        }
    }
    
    // Drop foreign key constraint first if it exists
    echo "  - Checking for foreign key constraints...\n";
    $fkCheck = pg_query($connection, "
        SELECT constraint_name 
        FROM information_schema.table_constraints 
        WHERE table_name = 'distribution_files' 
        AND constraint_type = 'FOREIGN KEY'
        AND constraint_name LIKE '%distribution%'
    ");
    
    if ($fkCheck && pg_num_rows($fkCheck) > 0) {
        while ($fkRow = pg_fetch_assoc($fkCheck)) {
            echo "    - Dropping constraint: {$fkRow['constraint_name']}\n";
            pg_query($connection, "ALTER TABLE distribution_files DROP CONSTRAINT {$fkRow['constraint_name']}");
        }
    }
    
    // Drop old column with CASCADE
    echo "  - Dropping old distribution_id column...\n";
    $dropResult = pg_query($connection, "ALTER TABLE distributions DROP COLUMN distribution_id CASCADE");
    if (!$dropResult) {
        throw new Exception("Failed to drop old distribution_id column: " . pg_last_error($connection));
    }
    
    // Rename new column
    echo "  - Renaming new column...\n";
    $renameResult = pg_query($connection, "ALTER TABLE distributions RENAME COLUMN new_distribution_id TO distribution_id");
    if (!$renameResult) {
        throw new Exception("Failed to rename column: " . pg_last_error($connection));
    }
    
    // Set as primary key
    echo "  - Setting as PRIMARY KEY...\n";
    pg_query($connection, "ALTER TABLE distributions ADD PRIMARY KEY (distribution_id)");
    
    // Check if distribution_files table exists and update it
    $filesTableCheck = pg_query($connection, "SELECT 1 FROM information_schema.tables WHERE table_name = 'distribution_files'");
    if (pg_num_rows($filesTableCheck) > 0) {
        echo "  - Updating distribution_files table...\n";
        $filesColCheck = pg_query($connection, "SELECT column_name FROM information_schema.columns WHERE table_name = 'distribution_files' AND column_name = 'distribution_id'");
        if (pg_num_rows($filesColCheck) > 0) {
            pg_query($connection, "ALTER TABLE distribution_files ALTER COLUMN distribution_id TYPE TEXT");
            echo "    ✓ distribution_files.distribution_id updated to TEXT\n";
        }
    }
    
    pg_query($connection, "COMMIT");
    
    echo "\n✓✓✓ MIGRATION SUCCESSFUL! ✓✓✓\n\n";
    
    // Verify
    echo "STEP 4: Verifying migration...\n";
    $verifyQuery = "SELECT column_name, data_type FROM information_schema.columns 
                    WHERE table_name = 'distributions' AND column_name = 'distribution_id'";
    $verifyResult = pg_query($connection, $verifyQuery);
    $newType = pg_fetch_assoc($verifyResult);
    echo "New distribution_id type: {$newType['data_type']}\n\n";
    
    if ($count > 0) {
        echo "Migrated records:\n";
        $finalQuery = "SELECT distribution_id, student_id FROM distributions ORDER BY distribution_id";
        $finalResult = pg_query($connection, $finalQuery);
        while ($row = pg_fetch_assoc($finalResult)) {
            echo "  - {$row['distribution_id']} (Student: {$row['student_id']})\n";
        }
    }
    
    echo "\n✓ Migration complete! You can now use identifiable distribution IDs.\n";
    
} catch (Exception $e) {
    pg_query($connection, "ROLLBACK");
    echo "\n✗✗✗ MIGRATION FAILED! ✗✗✗\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nDatabase rolled back to previous state.\n";
    exit(1);
}
?>
