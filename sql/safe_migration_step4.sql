-- ===========================================
-- SAFE MIGRATION STEP 4: Replace columns and update students table
-- ===========================================

DO $$
DECLARE
    table_name TEXT;
    tables TEXT[] := ARRAY['applications', 'documents', 'enrollment_forms', 'distributions', 'qr_logs', 'schedules', 'grade_uploads', 'notifications'];
BEGIN
    RAISE NOTICE 'Step 4: Replacing student_id columns...';
    
    FOREACH table_name IN ARRAY tables
    LOOP
        -- Drop old column and rename new one
        EXECUTE 'ALTER TABLE ' || table_name || ' DROP COLUMN student_id';
        EXECUTE 'ALTER TABLE ' || table_name || ' RENAME COLUMN new_student_id TO student_id';
        RAISE NOTICE 'Replaced student_id column in %', table_name;
    END LOOP;
    
    RAISE NOTICE 'Step 4 complete.';
END $$;

-- Update students table
ALTER TABLE students DROP CONSTRAINT students_pkey;
ALTER TABLE students DROP COLUMN student_id;
ALTER TABLE students RENAME COLUMN unique_student_id TO student_id;
ALTER TABLE students ADD PRIMARY KEY (student_id);