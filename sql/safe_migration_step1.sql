-- ===========================================
-- SAFE MIGRATION: Replace student_id with unique_student_id
-- ===========================================
-- This is a safer version that handles each step separately to avoid transaction aborts

-- Step 1: Ensure all students have unique_student_id values
DO $$
DECLARE
    student_record RECORD;
    new_unique_id TEXT;
BEGIN
    RAISE NOTICE 'Step 1: Ensuring all students have unique_student_id values...';
    
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
    
    RAISE NOTICE 'Step 1 complete.';
END $$;