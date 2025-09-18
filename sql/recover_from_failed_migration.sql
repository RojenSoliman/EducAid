-- ===========================================
-- RECOVERY SCRIPT: Clean up failed migration
-- ===========================================
-- This script recovers from a failed migration attempt

-- First, rollback any aborted transaction
ROLLBACK;

-- Start fresh
BEGIN;

-- Check current state
DO $$
BEGIN
    RAISE NOTICE 'Starting recovery from failed migration...';
    RAISE NOTICE 'Current students table has both student_id (integer) and unique_student_id (text)';
END $$;

-- Step 1: Clean up any partial migration artifacts
-- Check if any tables have 'new_student_id' columns from partial migration
DO $$
DECLARE
    table_name TEXT;
    tables TEXT[] := ARRAY['applications', 'documents', 'enrollment_forms', 'distributions', 'qr_logs', 'schedules', 'grade_uploads', 'notifications'];
BEGIN
    FOREACH table_name IN ARRAY tables
    LOOP
        -- Check if new_student_id column exists and drop it
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = table_name AND column_name = 'new_student_id') THEN
            EXECUTE 'ALTER TABLE ' || table_name || ' DROP COLUMN new_student_id';
            RAISE NOTICE 'Dropped new_student_id column from %', table_name;
        END IF;
    END LOOP;
END $$;

-- Step 2: Ensure all students have unique_student_id values
DO $$
DECLARE
    student_record RECORD;
    new_unique_id TEXT;
BEGIN
    FOR student_record IN 
        SELECT student_id FROM students WHERE unique_student_id IS NULL OR unique_student_id = ''
    LOOP
        -- Generate a unique ID
        new_unique_id := 'EDU-' || EXTRACT(YEAR FROM CURRENT_DATE) || '-' || LPAD(student_record.student_id::TEXT, 6, '0');
        
        -- Ensure uniqueness
        WHILE EXISTS (SELECT 1 FROM students WHERE unique_student_id = new_unique_id) LOOP
            new_unique_id := new_unique_id || '-' || floor(random() * 1000)::TEXT;
        END LOOP;
        
        UPDATE students SET unique_student_id = new_unique_id WHERE student_id = student_record.student_id;
        RAISE NOTICE 'Generated unique_student_id % for student_id %', new_unique_id, student_record.student_id;
    END LOOP;
END $$;

COMMIT;

-- Verification
SELECT 'Recovery complete. Current state:' as status;
SELECT 'Students with unique_student_id:', COUNT(*) FROM students WHERE unique_student_id IS NOT NULL;
SELECT 'Sample data:', student_id, unique_student_id FROM students LIMIT 3;