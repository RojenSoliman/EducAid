-- ===========================================
-- SAFE MIGRATION STEP 3: Drop foreign key constraints
-- ===========================================

DO $$
DECLARE
    constraint_name TEXT;
    table_name TEXT;
    tables TEXT[] := ARRAY['applications', 'documents', 'enrollment_forms', 'distributions', 'qr_logs', 'schedules', 'grade_uploads', 'notifications'];
BEGIN
    RAISE NOTICE 'Step 3: Dropping foreign key constraints...';
    
    FOREACH table_name IN ARRAY tables
    LOOP
        -- Drop foreign key constraint if it exists
        EXECUTE 'ALTER TABLE ' || table_name || ' DROP CONSTRAINT IF EXISTS ' || table_name || '_student_id_fkey';
        RAISE NOTICE 'Dropped foreign key constraint from %', table_name;
    END LOOP;
    
    RAISE NOTICE 'Step 3 complete.';
END $$;