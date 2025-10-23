-- =====================================================
-- DROP REDUNDANT STUDENT FIELDS
-- Removes unused qr_code and has_received columns
-- Date: 2025-10-24
-- =====================================================

-- REASON FOR REMOVAL:
-- 1. students.qr_code: Always NULL, QR codes stored in qr_codes.unique_id
-- 2. students.has_received: Always FALSE, status tracked via students.status and qr_codes.status

BEGIN;

-- =====================================================
-- STEP 1: Verify fields are unused (safety check)
-- =====================================================
DO $$
DECLARE
    qr_code_used INTEGER;
    has_received_used INTEGER;
BEGIN
    -- Check if qr_code has any non-null/non-zero values
    SELECT COUNT(*) INTO qr_code_used
    FROM students 
    WHERE qr_code IS NOT NULL AND qr_code != '0' AND qr_code != '';
    
    -- Check if has_received is ever true
    SELECT COUNT(*) INTO has_received_used
    FROM students 
    WHERE has_received = true;
    
    -- Report findings
    RAISE NOTICE 'Students with qr_code populated: %', qr_code_used;
    RAISE NOTICE 'Students with has_received = true: %', has_received_used;
    
    -- Abort if data would be lost
    IF qr_code_used > 0 THEN
        RAISE EXCEPTION 'ABORT: % students have qr_code populated! Review before dropping.', qr_code_used;
    END IF;
    
    IF has_received_used > 0 THEN
        RAISE EXCEPTION 'ABORT: % students have has_received = true! Review before dropping.', has_received_used;
    END IF;
    
    RAISE NOTICE 'Safety check passed: Both fields are unused';
END $$;

-- =====================================================
-- STEP 2: Create backup (optional but recommended)
-- =====================================================
DO $$
BEGIN
    -- Check if backup already exists
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'students_backup_redundant_fields_20251024'
    ) THEN
        CREATE TABLE students_backup_redundant_fields_20251024 AS 
        SELECT student_id, qr_code, has_received 
        FROM students;
        
        RAISE NOTICE 'Created backup table: students_backup_redundant_fields_20251024';
    ELSE
        RAISE NOTICE 'Backup table already exists, skipping';
    END IF;
END $$;

-- =====================================================
-- STEP 3: Drop redundant columns
-- =====================================================
-- Drop qr_code column (always NULL, replaced by qr_codes.unique_id)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'students' AND column_name = 'qr_code'
    ) THEN
        ALTER TABLE students DROP COLUMN qr_code CASCADE;
        RAISE NOTICE 'Dropped column: students.qr_code';
    ELSE
        RAISE NOTICE 'Column students.qr_code does not exist, skipping';
    END IF;
END $$;

-- Drop has_received column (always FALSE, replaced by status field)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'students' AND column_name = 'has_received'
    ) THEN
        ALTER TABLE students DROP COLUMN has_received CASCADE;
        RAISE NOTICE 'Dropped column: students.has_received';
    ELSE
        RAISE NOTICE 'Column students.has_received does not exist, skipping';
    END IF;
END $$;

-- =====================================================
-- STEP 4: Verify cleanup
-- =====================================================
DO $$
DECLARE
    column_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO column_count
    FROM information_schema.columns 
    WHERE table_name = 'students' 
    AND column_name IN ('qr_code', 'has_received');
    
    IF column_count = 0 THEN
        RAISE NOTICE 'SUCCESS: Both redundant columns removed';
    ELSE
        RAISE WARNING 'WARNING: % redundant columns still exist', column_count;
    END IF;
    
    -- Show current column count
    SELECT COUNT(*) INTO column_count
    FROM information_schema.columns 
    WHERE table_name = 'students';
    
    RAISE NOTICE 'Students table now has % columns', column_count;
END $$;

COMMIT;

-- =====================================================
-- POST-CLEANUP VERIFICATION QUERIES
-- =====================================================
-- Run these manually to verify:

-- 1. Check columns no longer exist
-- SELECT column_name FROM information_schema.columns WHERE table_name = 'students' ORDER BY ordinal_position;

-- 2. Check backup was created
-- SELECT COUNT(*) FROM students_backup_redundant_fields_20251024;

-- 3. Verify students table structure
-- \d students

-- =====================================================
-- ROLLBACK INSTRUCTIONS (if needed)
-- =====================================================
-- If you need to restore the columns:
-- ALTER TABLE students ADD COLUMN qr_code TEXT DEFAULT NULL;
-- ALTER TABLE students ADD COLUMN has_received BOOLEAN DEFAULT false;
-- UPDATE students SET qr_code = b.qr_code, has_received = b.has_received 
-- FROM students_backup_redundant_fields_20251024 b 
-- WHERE students.student_id = b.student_id;
