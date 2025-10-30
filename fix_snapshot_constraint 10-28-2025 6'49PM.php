<?php
/**
 * Add missing UNIQUE constraint to distribution_student_snapshot
 * This fixes the "no unique or exclusion constraint matching" error
 */

require 'config/database.php';

echo "Adding UNIQUE constraint to distribution_student_snapshot...\n\n";

// Start transaction
pg_query($connection, "BEGIN");

try {
    // Check if constraint already exists
    $check_query = "SELECT 1 FROM pg_constraint 
                    WHERE conname = 'distribution_student_snapshot_unique'";
    $check_result = pg_query($connection, $check_query);
    
    if (pg_num_rows($check_result) > 0) {
        echo "✓ Constraint already exists!\n";
    } else {
        echo "Adding UNIQUE constraint...\n";
        
        // Add NOT NULL to snapshot_id first
        $not_null = pg_query($connection, "
            ALTER TABLE distribution_student_snapshot
            ALTER COLUMN snapshot_id SET NOT NULL
        ");
        
        if (!$not_null) {
            throw new Exception("Failed to set NOT NULL: " . pg_last_error($connection));
        }
        echo "✓ Set snapshot_id to NOT NULL\n";
        
        // Add unique constraint
        $add_constraint = pg_query($connection, "
            ALTER TABLE distribution_student_snapshot
            ADD CONSTRAINT distribution_student_snapshot_unique 
            UNIQUE (snapshot_id, student_id)
        ");
        
        if (!$add_constraint) {
            throw new Exception("Failed to add UNIQUE constraint: " . pg_last_error($connection));
        }
        echo "✓ Added UNIQUE constraint (snapshot_id, student_id)\n";
        
        // Add performance index
        $add_index = pg_query($connection, "
            CREATE INDEX IF NOT EXISTS idx_dss_snapshot_student 
            ON distribution_student_snapshot(snapshot_id, student_id)
        ");
        
        if (!$add_index) {
            throw new Exception("Failed to create index: " . pg_last_error($connection));
        }
        echo "✓ Created performance index\n";
    }
    
    // Commit transaction
    pg_query($connection, "COMMIT");
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "SUCCESS! The distribution_student_snapshot table is now ready.\n";
    echo "You can now complete distributions without errors.\n";
    echo str_repeat("=", 80) . "\n";
    
} catch (Exception $e) {
    pg_query($connection, "ROLLBACK");
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
