-- ============================================================================
-- PHASE 1: File Management System - Manual SQL Migration
-- ============================================================================
-- Purpose: Create tables and columns for comprehensive file management
-- Date: 2025-10-19
-- Compatible with: PostgreSQL
-- Settings: Stored in data/municipal_settings.json (updated separately)
-- ============================================================================

-- Start transaction
BEGIN;

-- ============================================================================
-- 1. CHECK AND UPDATE distribution_files TABLE
-- ============================================================================

-- Check if distribution_files exists, if not create it
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'distribution_files') THEN
        CREATE TABLE distribution_files (
            file_id SERIAL PRIMARY KEY,
            student_id TEXT NOT NULL,
            distribution_id INTEGER,
            academic_year TEXT NOT NULL,
            
            -- File information
            original_filename TEXT NOT NULL,
            stored_filename TEXT NOT NULL,
            file_path TEXT NOT NULL,
            file_size BIGINT NOT NULL,
            file_type TEXT,
            file_category TEXT,
            
            -- Compression & Archive status
            is_compressed BOOLEAN DEFAULT FALSE,
            is_archived BOOLEAN DEFAULT FALSE,
            compression_date TIMESTAMP WITH TIME ZONE,
            compression_ratio NUMERIC(5,2),
            
            -- Metadata
            uploaded_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            archived_at TIMESTAMP WITH TIME ZONE,
            checksum TEXT,
            uploaded_by INTEGER,
            notes TEXT,
            
            CONSTRAINT fk_student FOREIGN KEY (student_id) 
                REFERENCES students(student_id) ON DELETE CASCADE,
            CONSTRAINT fk_distribution FOREIGN KEY (distribution_id) 
                REFERENCES distributions(distribution_id) ON DELETE SET NULL
        );
        
        RAISE NOTICE '✓ Created distribution_files table';
    ELSE
        RAISE NOTICE '○ distribution_files table already exists';
    END IF;
END $$;

-- Add missing columns to distribution_files if they don't exist
DO $$ 
BEGIN
    -- file_category
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_files' AND column_name = 'file_category'
    ) THEN
        ALTER TABLE distribution_files ADD COLUMN file_category TEXT;
        RAISE NOTICE '✓ Added column: file_category';
    END IF;

    -- is_compressed
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_files' AND column_name = 'is_compressed'
    ) THEN
        ALTER TABLE distribution_files ADD COLUMN is_compressed BOOLEAN DEFAULT FALSE;
        RAISE NOTICE '✓ Added column: is_compressed';
    END IF;

    -- is_archived
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_files' AND column_name = 'is_archived'
    ) THEN
        ALTER TABLE distribution_files ADD COLUMN is_archived BOOLEAN DEFAULT FALSE;
        RAISE NOTICE '✓ Added column: is_archived';
    END IF;

    -- compression_date
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_files' AND column_name = 'compression_date'
    ) THEN
        ALTER TABLE distribution_files ADD COLUMN compression_date TIMESTAMP WITH TIME ZONE;
        RAISE NOTICE '✓ Added column: compression_date';
    END IF;

    -- compression_ratio
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_files' AND column_name = 'compression_ratio'
    ) THEN
        ALTER TABLE distribution_files ADD COLUMN compression_ratio NUMERIC(5,2);
        RAISE NOTICE '✓ Added column: compression_ratio';
    END IF;

    -- archived_at
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_files' AND column_name = 'archived_at'
    ) THEN
        ALTER TABLE distribution_files ADD COLUMN archived_at TIMESTAMP WITH TIME ZONE;
        RAISE NOTICE '✓ Added column: archived_at';
    END IF;

    -- checksum
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_files' AND column_name = 'checksum'
    ) THEN
        ALTER TABLE distribution_files ADD COLUMN checksum TEXT;
        RAISE NOTICE '✓ Added column: checksum';
    END IF;

    -- uploaded_by
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_files' AND column_name = 'uploaded_by'
    ) THEN
        ALTER TABLE distribution_files ADD COLUMN uploaded_by INTEGER;
        RAISE NOTICE '✓ Added column: uploaded_by';
    END IF;

    -- notes
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_files' AND column_name = 'notes'
    ) THEN
        ALTER TABLE distribution_files ADD COLUMN notes TEXT;
        RAISE NOTICE '✓ Added column: notes';
    END IF;
END $$;

-- ============================================================================
-- 2. CREATE file_archive_log TABLE
-- ============================================================================

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
);

-- ============================================================================
-- 3. CREATE INDEXES
-- ============================================================================

-- Indexes for distribution_files
CREATE INDEX IF NOT EXISTS idx_distribution_files_student 
    ON distribution_files(student_id);

CREATE INDEX IF NOT EXISTS idx_distribution_files_distribution 
    ON distribution_files(distribution_id);

CREATE INDEX IF NOT EXISTS idx_distribution_files_academic_year 
    ON distribution_files(academic_year);

CREATE INDEX IF NOT EXISTS idx_distribution_files_archived 
    ON distribution_files(is_archived);

CREATE INDEX IF NOT EXISTS idx_distribution_files_compressed 
    ON distribution_files(is_compressed);

-- Indexes for file_archive_log
CREATE INDEX IF NOT EXISTS idx_file_archive_log_student 
    ON file_archive_log(student_id);

CREATE INDEX IF NOT EXISTS idx_file_archive_log_operation 
    ON file_archive_log(operation);

CREATE INDEX IF NOT EXISTS idx_file_archive_log_date 
    ON file_archive_log(performed_at);

-- ============================================================================
-- 4. ADD COLUMNS TO distributions TABLE
-- ============================================================================

DO $$ 
BEGIN
    -- status
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distributions' AND column_name = 'status'
    ) THEN
        ALTER TABLE distributions ADD COLUMN status TEXT DEFAULT 'active';
        RAISE NOTICE '✓ Added column: distributions.status';
    END IF;

    -- ended_at
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distributions' AND column_name = 'ended_at'
    ) THEN
        ALTER TABLE distributions ADD COLUMN ended_at TIMESTAMP WITH TIME ZONE;
        RAISE NOTICE '✓ Added column: distributions.ended_at';
    END IF;

    -- files_compressed
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distributions' AND column_name = 'files_compressed'
    ) THEN
        ALTER TABLE distributions ADD COLUMN files_compressed BOOLEAN DEFAULT FALSE;
        RAISE NOTICE '✓ Added column: distributions.files_compressed';
    END IF;

    -- compression_date
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distributions' AND column_name = 'compression_date'
    ) THEN
        ALTER TABLE distributions ADD COLUMN compression_date TIMESTAMP WITH TIME ZONE;
        RAISE NOTICE '✓ Added column: distributions.compression_date';
    END IF;
END $$;

-- ============================================================================
-- 5. CREATE STORAGE STATISTICS VIEW
-- ============================================================================

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
FROM distribution_files df;

-- ============================================================================
-- 6. ADD COMMENTS FOR DOCUMENTATION
-- ============================================================================

COMMENT ON TABLE distribution_files IS 
    'Tracks all student file uploads across distributions with compression and archive status';

COMMENT ON TABLE file_archive_log IS 
    'Audit log for all file archiving, compression, restoration, and purge operations';

COMMENT ON VIEW storage_statistics IS 
    'Real-time storage usage statistics for active and archived students';

COMMENT ON COLUMN distribution_files.file_category IS 
    'Type of file: requirement, document, profile, registration';

COMMENT ON COLUMN distribution_files.is_compressed IS 
    'TRUE if file has been compressed to save space';

COMMENT ON COLUMN distribution_files.is_archived IS 
    'TRUE if student is archived (file moved to archived_students/ folder)';

COMMENT ON COLUMN distribution_files.compression_ratio IS 
    'Percentage of space saved by compression (0-100)';

COMMENT ON COLUMN distribution_files.checksum IS 
    'MD5 or SHA256 hash for file integrity verification';

COMMENT ON COLUMN distributions.status IS 
    'Distribution lifecycle: active, ended, archived, cancelled';

COMMENT ON COLUMN distributions.files_compressed IS 
    'TRUE if distribution files have been compressed';

-- Commit transaction
COMMIT;

-- ============================================================================
-- 7. VERIFICATION QUERIES
-- ============================================================================

-- Show created/updated tables
SELECT 
    'TABLES' as type,
    table_name,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_name = t.table_name) as column_count
FROM information_schema.tables t
WHERE table_name IN ('distribution_files', 'file_archive_log')
    AND table_schema = 'public'
ORDER BY table_name;

-- Show new columns in distributions
SELECT 
    'DISTRIBUTIONS COLUMNS' as type,
    column_name,
    data_type,
    column_default
FROM information_schema.columns
WHERE table_name = 'distributions'
    AND column_name IN ('status', 'ended_at', 'files_compressed', 'compression_date')
ORDER BY column_name;

-- Show indexes
SELECT 
    'INDEXES' as type,
    indexname,
    tablename
FROM pg_indexes
WHERE tablename IN ('distribution_files', 'file_archive_log')
    AND schemaname = 'public'
ORDER BY tablename, indexname;

-- Show view
SELECT 
    'VIEWS' as type,
    table_name as view_name,
    'storage_statistics' as description
FROM information_schema.views
WHERE table_name = 'storage_statistics'
    AND table_schema = 'public';

-- Test storage statistics view
SELECT * FROM storage_statistics;

-- ============================================================================
-- SETUP COMPLETE!
-- ============================================================================

SELECT '✅ Phase 1 Database Migration Complete!' as status;

-- Next steps reminder
SELECT 
    'NEXT STEPS' as reminder,
    '1. Update data/municipal_settings.json manually
     2. Run setup_folder_structure.php
     3. Run verify_phase1.php to confirm installation' as instructions;
