-- =====================================================
-- DISTRIBUTION SYSTEM CLEANUP SCRIPT
-- Removes redundant tables and consolidates to new schema
-- Date: 2025-10-24
-- =====================================================

-- ANALYSIS SUMMARY:
-- The old system had:
--   1. 'distributions' table - Per-student distribution records (OLD APPROACH)
--   2. 'distribution_files' table - File tracking (EMPTY, NEVER USED)
--
-- The new optimized system uses:
--   1. 'distribution_snapshots' - One record per distribution cycle
--   2. 'distribution_file_manifest' - Detailed file tracking with hashes
--   3. 'distribution_student_snapshot' - Historical student records
--
-- REDUNDANT TABLES TO DROP:
--   - distributions (conflicts with new snapshot approach)
--   - distribution_files (never used, replaced by file_manifest)

BEGIN;

-- =====================================================
-- STEP 1: Backup any existing data (just in case)
-- =====================================================
DO $$
BEGIN
    -- Check if distributions table has any data
    IF EXISTS (SELECT 1 FROM distributions LIMIT 1) THEN
        RAISE WARNING 'distributions table contains data! Please review before cleanup.';
        -- Create backup table
        CREATE TABLE IF NOT EXISTS distributions_backup_20251024 AS 
        SELECT * FROM distributions;
        RAISE NOTICE 'Created backup: distributions_backup_20251024';
    ELSE
        RAISE NOTICE 'distributions table is empty - safe to drop';
    END IF;

    -- Check if distribution_files table has any data
    IF EXISTS (SELECT 1 FROM distribution_files LIMIT 1) THEN
        RAISE WARNING 'distribution_files table contains data! Please review before cleanup.';
        CREATE TABLE IF NOT EXISTS distribution_files_backup_20251024 AS 
        SELECT * FROM distribution_files;
        RAISE NOTICE 'Created backup: distribution_files_backup_20251024';
    ELSE
        RAISE NOTICE 'distribution_files table is empty - safe to drop';
    END IF;
END $$;

-- =====================================================
-- STEP 2: Drop old redundant tables
-- =====================================================
DROP TABLE IF EXISTS distributions CASCADE;
DROP TABLE IF EXISTS distribution_files CASCADE;

RAISE NOTICE 'Dropped redundant tables: distributions, distribution_files';

-- =====================================================
-- STEP 3: Verify distribution_snapshots has all needed columns
-- =====================================================
-- The distribution_snapshot_enhancement.sql should add these
-- This is a verification step

DO $$
DECLARE
    missing_columns TEXT[] := ARRAY[]::TEXT[];
    required_columns TEXT[] := ARRAY[
        'original_total_size',
        'compressed_size',
        'compression_ratio',
        'space_saved',
        'total_files_count',
        'archive_path',
        'municipality_id',
        'metadata'
    ];
    col TEXT;
BEGIN
    -- Check for missing columns
    FOREACH col IN ARRAY required_columns
    LOOP
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns 
            WHERE table_name = 'distribution_snapshots' 
            AND column_name = col
        ) THEN
            missing_columns := array_append(missing_columns, col);
        END IF;
    END LOOP;
    
    IF array_length(missing_columns, 1) > 0 THEN
        RAISE WARNING 'distribution_snapshots is missing columns: %. Run distribution_snapshot_enhancement.sql first!', 
            array_to_string(missing_columns, ', ');
    ELSE
        RAISE NOTICE 'distribution_snapshots has all required columns';
    END IF;
END $$;

-- =====================================================
-- STEP 4: Verify new tables exist
-- =====================================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'distribution_file_manifest'
    ) THEN
        RAISE WARNING 'distribution_file_manifest table does not exist! Run distribution_snapshot_enhancement.sql first!';
    ELSE
        RAISE NOTICE 'distribution_file_manifest table exists';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'distribution_student_snapshot'
    ) THEN
        RAISE WARNING 'distribution_student_snapshot table does not exist! Run distribution_snapshot_enhancement.sql first!';
    ELSE
        RAISE NOTICE 'distribution_student_snapshot table exists';
    END IF;
END $$;

-- =====================================================
-- STEP 5: Clean up any old migration-related config
-- =====================================================
-- Remove obsolete config keys if any exist
DELETE FROM config WHERE key IN (
    'slots_open',  -- Old slot-based system
    'last_distribution_id'  -- Old counter system
);

RAISE NOTICE 'Cleaned up obsolete config keys';

-- =====================================================
-- STEP 6: Display final table structure
-- =====================================================
SELECT 
    'CLEANUP COMPLETE' as status,
    COUNT(*) FILTER (WHERE table_name LIKE '%distribution%') as distribution_tables
FROM information_schema.tables 
WHERE table_schema = 'public';

COMMIT;

-- =====================================================
-- VERIFICATION QUERIES
-- =====================================================
-- Run these after cleanup to verify:

-- Check remaining distribution tables:
-- SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename LIKE '%distribution%' ORDER BY tablename;

-- Should show only:
--   - distribution_file_manifest
--   - distribution_snapshots
--   - distribution_student_snapshot

-- Check snapshots structure:
-- SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'distribution_snapshots' ORDER BY ordinal_position;
