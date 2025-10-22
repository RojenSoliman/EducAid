-- =====================================================
-- Migration: Add student information columns to school_student_ids
-- Run this if you already installed the original schema
-- =====================================================

DO $$
BEGIN
    RAISE NOTICE '';
    RAISE NOTICE '=========================================';
    RAISE NOTICE ' Adding Student Information Columns';
    RAISE NOTICE '=========================================';
    RAISE NOTICE '';
END $$;

-- Add columns if they don't exist
ALTER TABLE school_student_ids 
ADD COLUMN IF NOT EXISTS university_name VARCHAR(255),
ADD COLUMN IF NOT EXISTS first_name VARCHAR(100),
ADD COLUMN IF NOT EXISTS last_name VARCHAR(100);

-- Update existing records with student information
DO $$
DECLARE
    updated_count INTEGER;
BEGIN
    UPDATE school_student_ids ssi
    SET 
        university_name = u.name,
        first_name = s.first_name,
        last_name = s.last_name
    FROM students s
    JOIN universities u ON s.university_id = u.university_id
    WHERE ssi.student_id = s.student_id
      AND (ssi.university_name IS NULL OR ssi.first_name IS NULL OR ssi.last_name IS NULL);
    
    GET DIAGNOSTICS updated_count = ROW_COUNT;
    RAISE NOTICE '✓ Updated % existing records with student information', updated_count;
END $$;

-- Show sample of updated records
DO $$
BEGIN
    RAISE NOTICE '';
    RAISE NOTICE 'Sample of updated records:';
    RAISE NOTICE '-------------------------------------------';
END $$;

SELECT 
    school_student_id,
    university_name,
    first_name || ' ' || last_name as student_name,
    status,
    registered_at
FROM school_student_ids
ORDER BY registered_at DESC
LIMIT 10;

DO $$
BEGIN
    RAISE NOTICE '';
    RAISE NOTICE '=========================================';
    RAISE NOTICE ' Migration Complete!';
    RAISE NOTICE '=========================================';
    RAISE NOTICE '';
    RAISE NOTICE 'All school_student_ids records now include:';
    RAISE NOTICE '- university_name';
    RAISE NOTICE '- first_name';
    RAISE NOTICE '- last_name';
    RAISE NOTICE '';
END $$;
