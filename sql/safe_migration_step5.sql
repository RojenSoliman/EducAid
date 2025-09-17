-- ===========================================
-- SAFE MIGRATION STEP 5: Add foreign key constraints back
-- ===========================================

DO $$
DECLARE
    table_name TEXT;
    tables TEXT[] := ARRAY['applications', 'documents', 'enrollment_forms', 'distributions', 'qr_logs', 'schedules', 'grade_uploads', 'notifications'];
BEGIN
    RAISE NOTICE 'Step 5: Adding foreign key constraints...';
    
    FOREACH table_name IN ARRAY tables
    LOOP
        BEGIN
            -- Add foreign key constraint
            EXECUTE 'ALTER TABLE ' || table_name || ' ADD CONSTRAINT ' || table_name || '_student_id_fkey 
                FOREIGN KEY (student_id) REFERENCES students(student_id)';
            RAISE NOTICE 'Added foreign key constraint to %', table_name;
        EXCEPTION WHEN OTHERS THEN
            RAISE NOTICE 'Warning: Could not add foreign key constraint to %. Error: %', table_name, SQLERRM;
        END;
    END LOOP;
    
    RAISE NOTICE 'Step 5 complete.';
END $$;

-- Handle qr_codes table separately
DO $$
BEGIN
    -- Check if qr_codes table exists and has student_unique_id column
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'qr_codes') THEN
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'qr_codes' AND column_name = 'student_unique_id') THEN
            ALTER TABLE qr_codes DROP CONSTRAINT IF EXISTS qr_codes_student_unique_id_fkey;
            ALTER TABLE qr_codes RENAME COLUMN student_unique_id TO student_id;
            ALTER TABLE qr_codes ADD CONSTRAINT qr_codes_student_id_fkey 
                FOREIGN KEY (student_id) REFERENCES students(student_id);
            RAISE NOTICE 'Updated qr_codes table';
        END IF;
    END IF;
END $$;