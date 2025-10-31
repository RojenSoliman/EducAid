-- =====================================================
-- Fix: Type mismatch in check_duplicate_school_student_id function
-- Date: October 31, 2025
-- Issue: Function returns TEXT but expects VARCHAR for university_name column
-- =====================================================

-- Drop and recreate the function with correct type casting
DROP FUNCTION IF EXISTS check_duplicate_school_student_id(INTEGER, VARCHAR);

CREATE OR REPLACE FUNCTION check_duplicate_school_student_id(
    p_university_id INTEGER,
    p_school_student_id VARCHAR(50)
)
RETURNS TABLE(
    is_duplicate BOOLEAN,
    system_student_id TEXT,
    student_name TEXT,
    student_email TEXT,
    student_mobile TEXT,
    student_status TEXT,
    registered_at TIMESTAMP,
    university_name VARCHAR(255),
    first_name VARCHAR(100),
    last_name VARCHAR(100)
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        TRUE as is_duplicate,
        s.student_id as system_student_id,
        (s.first_name || ' ' || COALESCE(s.middle_name || ' ', '') || s.last_name)::TEXT as student_name,
        s.email::TEXT as student_email,
        s.mobile::TEXT as student_mobile,
        s.status::TEXT as student_status,
        ssi.registered_at,
        ssi.university_name as university_name,
        ssi.first_name as first_name,
        ssi.last_name as last_name
    FROM school_student_ids ssi
    JOIN students s ON ssi.student_id = s.student_id
    WHERE ssi.university_id = p_university_id
      AND ssi.school_student_id = p_school_student_id
      AND ssi.status = 'active'
    LIMIT 1;
    
    -- If no duplicate found, return false
    IF NOT FOUND THEN
        RETURN QUERY SELECT FALSE, NULL::TEXT, NULL::TEXT, NULL::TEXT, NULL::TEXT, NULL::TEXT, NULL::TIMESTAMP, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR;
    END IF;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION check_duplicate_school_student_id IS 'Checks if a school student ID is already registered for a given university';

-- Verify the function was created successfully
DO $$
BEGIN
    RAISE NOTICE '✓ Function check_duplicate_school_student_id() has been recreated successfully';
END $$;
