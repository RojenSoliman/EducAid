<?php
/**
 * Migrate distribution_student_snapshot to use distribution_id instead of snapshot_id
 * This makes snapshots more trackable with human-readable IDs
 */

require 'config/database.php';

echo "Migrating distribution_student_snapshot to use distribution_id...\n";
echo str_repeat("=", 80) . "\n\n";

pg_query($connection, "BEGIN");

try {
    // STEP 1: Create new table structure
    echo "STEP 1: Creating new table structure...\n";
    
    $create_table = "
        CREATE TABLE IF NOT EXISTS distribution_student_snapshot_v2 (
            student_snapshot_id SERIAL,
            distribution_id TEXT NOT NULL,
            student_id TEXT NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
            first_name TEXT,
            last_name TEXT,
            middle_name TEXT,
            email TEXT,
            mobile TEXT,
            year_level_name TEXT,
            university_name TEXT,
            barangay_name TEXT,
            payroll_number TEXT,
            amount_received NUMERIC(10,2),
            distribution_date DATE,
            created_at TIMESTAMP DEFAULT NOW(),

            PRIMARY KEY (student_snapshot_id),
            CONSTRAINT distribution_student_snapshot_v2_unique UNIQUE (distribution_id, student_id),
            CONSTRAINT fk_distribution_id FOREIGN KEY (distribution_id) 
                REFERENCES distribution_snapshots(distribution_id) ON DELETE CASCADE
        )
    ";
    
    if (!pg_query($connection, $create_table)) {
        throw new Exception("Failed to create new table: " . pg_last_error($connection));
    }
    echo "✓ Created distribution_student_snapshot_v2 table\n";
    
    // Create indexes
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_dss_v2_distribution ON distribution_student_snapshot_v2(distribution_id)",
        "CREATE INDEX IF NOT EXISTS idx_dss_v2_student ON distribution_student_snapshot_v2(student_id)",
        "CREATE INDEX IF NOT EXISTS idx_dss_v2_dist_student ON distribution_student_snapshot_v2(distribution_id, student_id)"
    ];
    
    foreach ($indexes as $index_sql) {
        if (!pg_query($connection, $index_sql)) {
            throw new Exception("Failed to create index: " . pg_last_error($connection));
        }
    }
    echo "✓ Created performance indexes\n\n";
    
    // STEP 2: Migrate existing data
    echo "STEP 2: Migrating existing data...\n";
    
    $migrate_data = "
        INSERT INTO distribution_student_snapshot_v2 
            (distribution_id, student_id, first_name, last_name, middle_name, email, mobile,
             year_level_name, university_name, barangay_name, payroll_number, 
             amount_received, distribution_date, created_at)
        SELECT 
            ds.distribution_id,
            dss.student_id,
            dss.first_name,
            dss.last_name,
            dss.middle_name,
            dss.email,
            dss.mobile,
            dss.year_level_name,
            dss.university_name,
            dss.barangay_name,
            dss.payroll_number,
            dss.amount_received,
            dss.distribution_date,
            dss.created_at
        FROM distribution_student_snapshot dss
        JOIN distribution_snapshots ds ON dss.snapshot_id = ds.snapshot_id
        WHERE ds.distribution_id IS NOT NULL
        ON CONFLICT (distribution_id, student_id) DO NOTHING
    ";
    
    $migrate_result = pg_query($connection, $migrate_data);
    if (!$migrate_result) {
        throw new Exception("Failed to migrate data: " . pg_last_error($connection));
    }
    
    $migrated_count = pg_affected_rows($migrate_result);
    echo "✓ Migrated $migrated_count student snapshot record(s)\n\n";
    
    // STEP 3: Drop old table and rename new one
    echo "STEP 3: Replacing old table...\n";
    
    if (!pg_query($connection, "DROP TABLE IF EXISTS distribution_student_snapshot CASCADE")) {
        throw new Exception("Failed to drop old table: " . pg_last_error($connection));
    }
    echo "✓ Dropped old distribution_student_snapshot table\n";
    
    if (!pg_query($connection, "ALTER TABLE distribution_student_snapshot_v2 RENAME TO distribution_student_snapshot")) {
        throw new Exception("Failed to rename table: " . pg_last_error($connection));
    }
    echo "✓ Renamed new table to distribution_student_snapshot\n";
    
    // Rename constraint
    if (!pg_query($connection, "ALTER TABLE distribution_student_snapshot RENAME CONSTRAINT distribution_student_snapshot_v2_unique TO distribution_student_snapshot_unique")) {
        throw new Exception("Failed to rename constraint: " . pg_last_error($connection));
    }
    echo "✓ Renamed constraints\n";
    
    // Rename indexes
    $rename_indexes = [
        "ALTER INDEX idx_dss_v2_distribution RENAME TO idx_dss_distribution",
        "ALTER INDEX idx_dss_v2_student RENAME TO idx_dss_student",
        "ALTER INDEX idx_dss_v2_dist_student RENAME TO idx_dss_dist_student"
    ];
    
    foreach ($rename_indexes as $rename_sql) {
        if (!pg_query($connection, $rename_sql)) {
            throw new Exception("Failed to rename index: " . pg_last_error($connection));
        }
    }
    echo "✓ Renamed indexes\n\n";
    
    // STEP 4: Update distribution_student_records
    echo "STEP 4: Updating distribution_student_records...\n";
    
    // Add distribution_id column
    pg_query($connection, "ALTER TABLE distribution_student_records ADD COLUMN IF NOT EXISTS distribution_id TEXT");
    
    // Populate from snapshot_id
    $populate_dsr = "
        UPDATE distribution_student_records dsr
        SET distribution_id = ds.distribution_id
        FROM distribution_snapshots ds
        WHERE dsr.snapshot_id = ds.snapshot_id
        AND dsr.distribution_id IS NULL
    ";
    
    $populate_result = pg_query($connection, $populate_dsr);
    if ($populate_result) {
        $updated_dsr = pg_affected_rows($populate_result);
        echo "✓ Updated $updated_dsr distribution_student_records with distribution_id\n";
    }
    
    // Add foreign key
    pg_query($connection, "ALTER TABLE distribution_student_records DROP CONSTRAINT IF EXISTS fk_dsr_distribution_id");
    pg_query($connection, "ALTER TABLE distribution_student_records ADD CONSTRAINT fk_dsr_distribution_id FOREIGN KEY (distribution_id) REFERENCES distribution_snapshots(distribution_id) ON DELETE CASCADE");
    pg_query($connection, "CREATE INDEX IF NOT EXISTS idx_dsr_distribution_id ON distribution_student_records(distribution_id)");
    
    echo "✓ Added distribution_id foreign key and index\n\n";
    
    // Commit
    pg_query($connection, "COMMIT");
    
    echo str_repeat("=", 80) . "\n";
    echo "SUCCESS! Migration complete.\n\n";
    echo "Benefits:\n";
    echo "  ✅ Snapshot IDs are now human-readable (e.g., GENERALTRIAS-DISTR-2025-10-28-114253)\n";
    echo "  ✅ No more ambiguous auto-incrementing integers\n";
    echo "  ✅ Easier to track and audit distribution cycles\n";
    echo "  ✅ Logs are more informative\n";
    echo str_repeat("=", 80) . "\n";
    
} catch (Exception $e) {
    pg_query($connection, "ROLLBACK");
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Migration rolled back. No changes were made.\n";
    exit(1);
}
