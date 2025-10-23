-- ============================================
-- REMOVE APPLICATIONS TABLE MIGRATION
-- Date: October 23, 2025
-- ============================================
-- 
-- REASON FOR REMOVAL:
-- The applications table is redundant because:
-- 1. Students already have slot_id linking to signup_slots table
-- 2. signup_slots table already contains semester and academic_year
-- 3. Current data in applications table is empty (no useful information)
-- 4. This eliminates data duplication and simplifies queries
--
-- BEFORE RUNNING:
-- - Ensure all code changes have been deployed
-- - Verify that no new code references this table
-- - Test the application thoroughly
--
-- ============================================

-- Step 1: Create backup table (optional, for safety)
CREATE TABLE applications_backup_20251023 AS 
SELECT * FROM applications;

-- Verify backup
SELECT COUNT(*) as backup_count FROM applications_backup_20251023;
-- Expected: 2 records

-- Step 2: View current data before deletion
SELECT 
    a.application_id,
    a.student_id,
    a.semester,
    a.academic_year,
    s.first_name,
    s.last_name,
    ss.semester as slot_semester,
    ss.academic_year as slot_academic_year
FROM applications a
LEFT JOIN students s ON a.student_id = s.student_id
LEFT JOIN signup_slots ss ON s.slot_id = ss.slot_id;

-- Step 3: Drop the applications table
-- This will CASCADE delete foreign key constraints if any exist
DROP TABLE IF EXISTS applications CASCADE;

-- Step 4: Verify table is gone
SELECT tablename 
FROM pg_tables 
WHERE schemaname = 'public' 
AND tablename = 'applications';
-- Expected: 0 rows

-- ============================================
-- ROLLBACK INSTRUCTIONS (if needed)
-- ============================================
--
-- If you need to restore the table:
-- 
-- 1. Recreate the table structure:
/*
CREATE TABLE applications (
    application_id SERIAL PRIMARY KEY,
    semester text,
    academic_year text,
    is_valid boolean DEFAULT true,
    remarks text,
    student_id text REFERENCES students(student_id)
);
*/
--
-- 2. Restore data from backup:
/*
INSERT INTO applications 
SELECT * FROM applications_backup_20251023;
*/
--
-- 3. Verify restoration:
/*
SELECT COUNT(*) FROM applications;
*/
--
-- ============================================

-- Optional: Keep backup table or drop it after verification
-- DROP TABLE applications_backup_20251023;

-- ============================================
-- MIGRATION COMPLETE
-- ============================================
-- 
-- NEXT STEPS:
-- 1. Test student registration
-- 2. Test admin approval workflow
-- 3. Test student listing in manage_slots
-- 4. Test CSV export functionality
-- 5. Test student deletion
-- 6. Monitor application logs for any errors
--
-- ============================================
