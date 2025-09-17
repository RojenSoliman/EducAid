-- ===========================================
-- SAFE MIGRATION STEP 2: Update foreign key columns
-- ===========================================

-- Create mapping table
CREATE TEMP TABLE student_id_mapping AS
SELECT student_id, unique_student_id FROM students;

-- Add new columns and populate them
DO $$
DECLARE
    table_name TEXT;
    tables TEXT[] := ARRAY['applications', 'documents', 'enrollment_forms', 'distributions', 'qr_logs', 'schedules', 'grade_uploads', 'notifications'];
BEGIN
    RAISE NOTICE 'Step 2: Adding and populating new student_id columns...';
    
    FOREACH table_name IN ARRAY tables
    LOOP
        -- Add new column
        EXECUTE 'ALTER TABLE ' || table_name || ' ADD COLUMN IF NOT EXISTS new_student_id TEXT';
        RAISE NOTICE 'Added new_student_id column to %', table_name;
        
        -- Populate new column
        EXECUTE 'UPDATE ' || table_name || ' SET new_student_id = (
            SELECT unique_student_id FROM student_id_mapping 
            WHERE student_id_mapping.student_id = ' || table_name || '.student_id
        )';
        RAISE NOTICE 'Populated new_student_id column in %', table_name;
    END LOOP;
    
    RAISE NOTICE 'Step 2 complete.';
END $$;