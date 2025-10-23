-- ======================================================================
-- COMPLETE DISTRIBUTION TRACKING SYSTEM SETUP
-- ======================================================================
-- This script sets up all tables needed for comprehensive distribution
-- tracking, including snapshots, student records, and file manifests.
--
-- Run this ONCE to initialize the complete distribution system.
-- ======================================================================

BEGIN;

-- ======================================================================
-- 1. DISTRIBUTION SNAPSHOTS (Main distribution cycle records)
-- ======================================================================

-- Ensure distribution_snapshots has all required columns
ALTER TABLE distribution_snapshots 
ADD COLUMN IF NOT EXISTS distribution_id TEXT,
ADD COLUMN IF NOT EXISTS academic_year TEXT,
ADD COLUMN IF NOT EXISTS semester TEXT,
ADD COLUMN IF NOT EXISTS distribution_date DATE DEFAULT CURRENT_DATE,
ADD COLUMN IF NOT EXISTS finalized_at TIMESTAMP,
ADD COLUMN IF NOT EXISTS finalized_by INTEGER REFERENCES admins(admin_id),
ADD COLUMN IF NOT EXISTS total_students_count INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS files_compressed BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS compression_date TIMESTAMP,
ADD COLUMN IF NOT EXISTS original_total_size BIGINT DEFAULT 0,
ADD COLUMN IF NOT EXISTS compressed_size BIGINT DEFAULT 0,
ADD COLUMN IF NOT EXISTS compression_ratio NUMERIC(5,2),
ADD COLUMN IF NOT EXISTS space_saved BIGINT DEFAULT 0,
ADD COLUMN IF NOT EXISTS total_files_count INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS archive_filename TEXT,
ADD COLUMN IF NOT EXISTS archive_path TEXT,
ADD COLUMN IF NOT EXISTS location TEXT,
ADD COLUMN IF NOT EXISTS notes TEXT,
ADD COLUMN IF NOT EXISTS municipality_id INTEGER REFERENCES municipalities(municipality_id),
ADD COLUMN IF NOT EXISTS metadata JSONB;

-- Make distribution_id unique if not already
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'distribution_snapshots_distribution_id_key'
    ) THEN
        ALTER TABLE distribution_snapshots 
        ADD CONSTRAINT distribution_snapshots_distribution_id_key UNIQUE (distribution_id);
    END IF;
END $$;

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_distribution_snapshots_dist_id 
    ON distribution_snapshots(distribution_id);
CREATE INDEX IF NOT EXISTS idx_distribution_snapshots_finalized_at 
    ON distribution_snapshots(finalized_at DESC NULLS LAST);
CREATE INDEX IF NOT EXISTS idx_distribution_snapshots_municipality 
    ON distribution_snapshots(municipality_id);
CREATE INDEX IF NOT EXISTS idx_distribution_snapshots_academic 
    ON distribution_snapshots(academic_year, semester);

COMMENT ON TABLE distribution_snapshots IS 
    'Master record of each distribution cycle with metadata and compression statistics';

-- ======================================================================
-- 2. DISTRIBUTION STUDENT RECORDS (Individual student tracking)
-- ======================================================================

CREATE TABLE IF NOT EXISTS distribution_student_records (
    record_id SERIAL PRIMARY KEY,
    snapshot_id INTEGER NOT NULL REFERENCES distribution_snapshots(snapshot_id) ON DELETE CASCADE,
    student_id TEXT NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    qr_code_used TEXT,
    scanned_at TIMESTAMP DEFAULT NOW(),
    scanned_by INTEGER REFERENCES admins(admin_id),
    verification_method TEXT DEFAULT 'qr_scan' CHECK (verification_method IN ('qr_scan', 'manual', 'batch')),
    notes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),

    CONSTRAINT distribution_student_records_unique_student_snapshot 
        UNIQUE (snapshot_id, student_id)
);

-- Indexes for fast lookups
CREATE INDEX IF NOT EXISTS idx_dsr_snapshot 
    ON distribution_student_records(snapshot_id);
CREATE INDEX IF NOT EXISTS idx_dsr_student 
    ON distribution_student_records(student_id);
CREATE INDEX IF NOT EXISTS idx_dsr_scanned_at 
    ON distribution_student_records(scanned_at DESC);
CREATE INDEX IF NOT EXISTS idx_dsr_scanned_by 
    ON distribution_student_records(scanned_by);

COMMENT ON TABLE distribution_student_records IS 
    'Tracks individual students who received aid in each distribution cycle - the link between students and snapshots';

-- ======================================================================
-- 3. DISTRIBUTION FILE MANIFEST (Detailed file tracking)
-- ======================================================================

CREATE TABLE IF NOT EXISTS distribution_file_manifest (
    manifest_id SERIAL PRIMARY KEY,
    snapshot_id INTEGER NOT NULL REFERENCES distribution_snapshots(snapshot_id) ON DELETE CASCADE,
    student_id TEXT NOT NULL,
    document_type_code TEXT,
    original_file_path TEXT,
    file_size BIGINT,
    file_hash TEXT, -- MD5 or SHA256 for integrity verification
    archived_path TEXT, -- Path within ZIP file
    archived_at TIMESTAMP DEFAULT NOW(),

    CONSTRAINT fk_manifest_student FOREIGN KEY (student_id) 
        REFERENCES students(student_id) ON DELETE CASCADE
);

-- Indexes for queries
CREATE INDEX IF NOT EXISTS idx_manifest_snapshot 
    ON distribution_file_manifest(snapshot_id);
CREATE INDEX IF NOT EXISTS idx_manifest_student 
    ON distribution_file_manifest(student_id);
CREATE INDEX IF NOT EXISTS idx_manifest_doc_type 
    ON distribution_file_manifest(document_type_code);
CREATE INDEX IF NOT EXISTS idx_manifest_hash 
    ON distribution_file_manifest(file_hash);

COMMENT ON TABLE distribution_file_manifest IS 
    'Detailed manifest of every file archived in distribution ZIPs - for integrity verification and file recovery';

-- ======================================================================
-- 4. DISTRIBUTION STUDENT SNAPSHOT (Historical student data)
-- ======================================================================

CREATE TABLE IF NOT EXISTS distribution_student_snapshot (
    student_snapshot_id SERIAL PRIMARY KEY,
    snapshot_id INTEGER NOT NULL REFERENCES distribution_snapshots(snapshot_id) ON DELETE CASCADE,
    student_id TEXT NOT NULL,
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

    CONSTRAINT distribution_student_snapshot_unique 
        UNIQUE (snapshot_id, student_id)
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_student_snapshot_snapshot 
    ON distribution_student_snapshot(snapshot_id);
CREATE INDEX IF NOT EXISTS idx_student_snapshot_student 
    ON distribution_student_snapshot(student_id);
CREATE INDEX IF NOT EXISTS idx_student_snapshot_payroll 
    ON distribution_student_snapshot(payroll_number);

COMMENT ON TABLE distribution_student_snapshot IS 
    'Frozen historical snapshot of student data at time of distribution - preserves student info even after resets';

-- ======================================================================
-- 5. VERIFICATION QUERIES
-- ======================================================================

-- Create a view for easy distribution history querying
CREATE OR REPLACE VIEW v_distribution_history AS
SELECT 
    ds.snapshot_id,
    ds.distribution_id,
    ds.academic_year,
    ds.semester,
    ds.distribution_date,
    ds.location,
    ds.finalized_at,
    ds.finalized_by,
    a.username as finalized_by_username,
    ds.total_students_count,
    COUNT(DISTINCT dsr.student_id) as actual_students_distributed,
    ds.files_compressed,
    ds.compression_date,
    ds.total_files_count,
    ds.compressed_size,
    ds.compression_ratio,
    ds.archive_filename,
    ds.notes
FROM distribution_snapshots ds
LEFT JOIN distribution_student_records dsr ON ds.snapshot_id = dsr.snapshot_id
LEFT JOIN admins a ON ds.finalized_by = a.admin_id
GROUP BY ds.snapshot_id, a.username
ORDER BY ds.finalized_at DESC NULLS LAST;

COMMENT ON VIEW v_distribution_history IS 
    'Convenient view showing distribution history with student counts and compression stats';

-- ======================================================================
-- 6. HELPER FUNCTIONS
-- ======================================================================

-- Function to get current active snapshot
CREATE OR REPLACE FUNCTION get_active_distribution_snapshot()
RETURNS TABLE (
    snapshot_id INTEGER,
    distribution_id TEXT,
    academic_year TEXT,
    semester TEXT
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        ds.snapshot_id,
        ds.distribution_id,
        ds.academic_year,
        ds.semester
    FROM distribution_snapshots ds
    WHERE ds.finalized_at IS NULL 
       OR ds.finalized_at >= CURRENT_DATE - INTERVAL '7 days'
    ORDER BY ds.finalized_at DESC NULLS FIRST
    LIMIT 1;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION get_active_distribution_snapshot() IS 
    'Returns the currently active distribution snapshot (unfinalzed or recently finalized)';

-- ======================================================================
-- SUCCESS MESSAGE
-- ======================================================================

DO $$ 
BEGIN
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Distribution Tracking System Setup Complete!';
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Tables created/updated:';
    RAISE NOTICE '  ✓ distribution_snapshots (enhanced)';
    RAISE NOTICE '  ✓ distribution_student_records';
    RAISE NOTICE '  ✓ distribution_file_manifest';
    RAISE NOTICE '  ✓ distribution_student_snapshot';
    RAISE NOTICE '';
    RAISE NOTICE 'Views created:';
    RAISE NOTICE '  ✓ v_distribution_history';
    RAISE NOTICE '';
    RAISE NOTICE 'Functions created:';
    RAISE NOTICE '  ✓ get_active_distribution_snapshot()';
    RAISE NOTICE '========================================';
END $$;

COMMIT;

-- ======================================================================
-- VERIFICATION QUERY (Optional - run separately to test)
-- ======================================================================

-- Uncomment to verify setup:
-- SELECT * FROM v_distribution_history LIMIT 5;
-- SELECT * FROM get_active_distribution_snapshot();
