-- ================================================================================
-- MIGRATE DISTRIBUTION SNAPSHOT SYSTEM TO USE DISTRIBUTION_ID AS PRIMARY KEY
-- ================================================================================
-- Changes snapshot_id from auto-incrementing integer to descriptive TEXT
-- This makes snapshots more trackable and human-readable
-- ================================================================================

BEGIN;

-- ================================================================================
-- STEP 1: Create new tables with distribution_id as primary key
-- ================================================================================

-- New distribution_student_snapshot with TEXT distribution_id
CREATE TABLE IF NOT EXISTS distribution_student_snapshot_v2 (
    student_snapshot_id SERIAL,
    distribution_id TEXT NOT NULL,  -- e.g., 'GENERALTRIAS-DISTR-2025-10-28-114253'
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
);

CREATE INDEX idx_dss_v2_distribution ON distribution_student_snapshot_v2(distribution_id);
CREATE INDEX idx_dss_v2_student ON distribution_student_snapshot_v2(student_id);
CREATE INDEX idx_dss_v2_dist_student ON distribution_student_snapshot_v2(distribution_id, student_id);

COMMENT ON TABLE distribution_student_snapshot_v2 IS 
    'Historical snapshot of student profiles at distribution time - uses human-readable distribution_id';

-- ================================================================================
-- STEP 2: Migrate existing data (if any)
-- ================================================================================

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
ON CONFLICT (distribution_id, student_id) DO NOTHING;

-- ================================================================================
-- STEP 3: Drop old table and rename new one
-- ================================================================================

DROP TABLE IF EXISTS distribution_student_snapshot CASCADE;
ALTER TABLE distribution_student_snapshot_v2 RENAME TO distribution_student_snapshot;

-- Rename constraints and indexes for consistency
ALTER TABLE distribution_student_snapshot 
    RENAME CONSTRAINT distribution_student_snapshot_v2_unique 
    TO distribution_student_snapshot_unique;

ALTER INDEX idx_dss_v2_distribution RENAME TO idx_dss_distribution;
ALTER INDEX idx_dss_v2_student RENAME TO idx_dss_student;
ALTER INDEX idx_dss_v2_dist_student RENAME TO idx_dss_dist_student;

-- ================================================================================
-- STEP 4: Update distribution_student_records to use distribution_id too
-- ================================================================================

-- Add distribution_id column
ALTER TABLE distribution_student_records 
ADD COLUMN IF NOT EXISTS distribution_id TEXT;

-- Populate from snapshot_id
UPDATE distribution_student_records dsr
SET distribution_id = ds.distribution_id
FROM distribution_snapshots ds
WHERE dsr.snapshot_id = ds.snapshot_id
AND dsr.distribution_id IS NULL;

-- Add foreign key constraint
ALTER TABLE distribution_student_records
DROP CONSTRAINT IF EXISTS fk_dsr_distribution_id;

ALTER TABLE distribution_student_records
ADD CONSTRAINT fk_dsr_distribution_id 
FOREIGN KEY (distribution_id) REFERENCES distribution_snapshots(distribution_id) ON DELETE CASCADE;

-- Create index
CREATE INDEX IF NOT EXISTS idx_dsr_distribution_id ON distribution_student_records(distribution_id);

COMMIT;

-- ================================================================================
-- SUCCESS MESSAGE
-- ================================================================================
SELECT 
    'Migration complete! distribution_student_snapshot now uses distribution_id' as status,
    COUNT(*) as migrated_records
FROM distribution_student_snapshot;
