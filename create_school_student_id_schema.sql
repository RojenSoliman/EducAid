-- =====================================================
-- School Student ID Tracking Schema
-- Purpose: Track all school-issued student IDs per university
--          to prevent multiple accounts with same school ID
-- Note: 'student_id' = System's internal unique ID (e.g., STU-2024-001)
--       'school_student_id' = University/School-issued ID number (e.g., 2024-12345)
-- =====================================================

-- 1. Add school_student_id column to students table
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS school_student_id VARCHAR(50);

-- 2. Create index for faster lookups
CREATE INDEX IF NOT EXISTS idx_students_school_student_id 
ON students(school_student_id) 
WHERE school_student_id IS NOT NULL AND school_student_id != '';

-- 3. Create composite unique index (university + school_student_id combination)
-- This prevents the same school ID from being used twice in the same university
CREATE UNIQUE INDEX IF NOT EXISTS idx_unique_school_student_id 
ON students(university_id, school_student_id) 
WHERE school_student_id IS NOT NULL AND school_student_id != '';

-- 4. Create dedicated tracking table for school student IDs
CREATE TABLE IF NOT EXISTS school_student_ids (
    id SERIAL PRIMARY KEY,
    university_id INTEGER NOT NULL REFERENCES universities(university_id) ON DELETE CASCADE,
    student_id VARCHAR(50) NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    school_student_id VARCHAR(50) NOT NULL,
    university_name VARCHAR(255),
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    registered_at TIMESTAMP DEFAULT NOW(),
    status VARCHAR(50) DEFAULT 'active',
    notes TEXT,
    CONSTRAINT unique_school_student_per_university UNIQUE(university_id, school_student_id)
);

-- 5. Create indexes on tracking table
CREATE INDEX IF NOT EXISTS idx_school_student_ids_university 
ON school_student_ids(university_id);

CREATE INDEX IF NOT EXISTS idx_school_student_ids_lookup 
ON school_student_ids(university_id, school_student_id);

CREATE INDEX IF NOT EXISTS idx_school_student_ids_status 
ON school_student_ids(status);

-- 6. Create audit log table for tracking changes
CREATE TABLE IF NOT EXISTS school_student_id_audit (
    audit_id SERIAL PRIMARY KEY,
    university_id INTEGER NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    school_student_id VARCHAR(50) NOT NULL,
    action VARCHAR(50) NOT NULL, -- 'register', 'update', 'deactivate', 'reactivate'
    old_value TEXT,
    new_value TEXT,
    performed_by VARCHAR(100),
    performed_at TIMESTAMP DEFAULT NOW(),
    ip_address VARCHAR(50),
    notes TEXT
);

CREATE INDEX IF NOT EXISTS idx_audit_school_student_id 
ON school_student_id_audit(school_student_id);

CREATE INDEX IF NOT EXISTS idx_audit_performed_at 
ON school_student_id_audit(performed_at DESC);

-- 7. Create trigger to automatically populate tracking table
CREATE OR REPLACE FUNCTION track_school_student_id()
RETURNS TRIGGER AS $$
DECLARE
    v_university_name VARCHAR(255);
BEGIN
    -- Only track if school_student_id is provided
    IF NEW.school_student_id IS NOT NULL AND NEW.school_student_id != '' THEN
        -- Get university name
        SELECT name INTO v_university_name 
        FROM universities 
        WHERE university_id = NEW.university_id;
        
        -- Insert into tracking table with student information
        INSERT INTO school_student_ids (
            university_id,
            student_id,
            school_student_id,
            university_name,
            first_name,
            last_name,
            status
        ) VALUES (
            NEW.university_id,
            NEW.student_id,
            NEW.school_student_id,
            v_university_name,
            NEW.first_name,
            NEW.last_name,
            'active'
        )
        ON CONFLICT (university_id, school_student_id) DO NOTHING;
        
        -- Log the registration
        INSERT INTO school_student_id_audit (
            university_id,
            student_id,
            school_student_id,
            action,
            new_value,
            ip_address
        ) VALUES (
            NEW.university_id,
            NEW.student_id,
            NEW.school_student_id,
            'register',
            NEW.first_name || ' ' || NEW.last_name || ' (' || COALESCE(v_university_name, 'Unknown') || ')',
            inet_client_addr()::TEXT
        );
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Attach trigger to students table
DROP TRIGGER IF EXISTS trigger_track_school_student_id ON students;
CREATE TRIGGER trigger_track_school_student_id
    AFTER INSERT ON students
    FOR EACH ROW
    EXECUTE FUNCTION track_school_student_id();

-- 8. Create view for easy duplicate detection
CREATE OR REPLACE VIEW v_school_student_id_duplicates AS
SELECT 
    ssi.university_name,
    ssi.school_student_id,
    COUNT(*) as registration_count,
    array_agg(s.student_id) as system_student_ids,
    array_agg(ssi.first_name || ' ' || ssi.last_name) as student_names,
    array_agg(s.status) as statuses,
    MIN(ssi.registered_at) as first_registered,
    MAX(ssi.registered_at) as last_registered
FROM school_student_ids ssi
JOIN students s ON ssi.student_id = s.student_id
WHERE ssi.status = 'active'
GROUP BY ssi.university_name, ssi.school_student_id
HAVING COUNT(*) > 1
ORDER BY registration_count DESC, last_registered DESC;

-- 9. Create function to check for duplicate school student IDs
CREATE OR REPLACE FUNCTION check_duplicate_school_student_id(
    p_university_id INTEGER,
    p_school_student_id VARCHAR(50)
)
RETURNS TABLE(
    is_duplicate BOOLEAN,
    system_student_id VARCHAR(50),
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
        s.student_id::VARCHAR(50) as system_student_id,
        (s.first_name || ' ' || COALESCE(s.middle_name || ' ', '') || s.last_name)::TEXT as student_name,
        s.email::TEXT as student_email,
        s.mobile::TEXT as student_mobile,
        s.status::TEXT as student_status,
        ssi.registered_at,
        ssi.university_name::VARCHAR(255) as university_name,
        ssi.first_name::VARCHAR(100) as first_name,
        ssi.last_name::VARCHAR(100) as last_name
    FROM school_student_ids ssi
    JOIN students s ON ssi.student_id = s.student_id
    WHERE ssi.university_id = p_university_id
      AND ssi.school_student_id = p_school_student_id
      AND ssi.status = 'active'
    LIMIT 1;
    
    -- If no duplicate found, return false
    IF NOT FOUND THEN
        RETURN QUERY SELECT FALSE, NULL::VARCHAR, NULL::TEXT, NULL::TEXT, NULL::TEXT, NULL::TEXT, NULL::TIMESTAMP, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR;
    END IF;
END;
$$ LANGUAGE plpgsql;

-- 10. Create function to get all school student IDs for a university
CREATE OR REPLACE FUNCTION get_school_student_ids(p_university_id INTEGER)
RETURNS TABLE(
    school_student_id VARCHAR(50),
    system_student_id VARCHAR(50),
    student_name TEXT,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    university_name VARCHAR(255),
    status TEXT,
    registered_at TIMESTAMP
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        ssi.school_student_id,
        s.student_id as system_student_id,
        s.first_name || ' ' || COALESCE(s.middle_name || ' ', '') || s.last_name as student_name,
        ssi.first_name,
        ssi.last_name,
        ssi.university_name,
        ssi.status,
        ssi.registered_at
    FROM school_student_ids ssi
    JOIN students s ON ssi.student_id = s.student_id
    WHERE ssi.university_id = p_university_id
    ORDER BY ssi.registered_at DESC;
END;
$$ LANGUAGE plpgsql;

-- 11. Add comments for documentation
COMMENT ON TABLE school_student_ids IS 'Tracks all school-issued student IDs to prevent duplicate registrations';
COMMENT ON TABLE school_student_id_audit IS 'Audit log for all changes to school student ID records';
COMMENT ON COLUMN students.school_student_id IS 'Official student ID number from the school/university (e.g., 2024-12345). Different from system student_id.';
COMMENT ON FUNCTION check_duplicate_school_student_id IS 'Checks if a school student ID is already registered for a given university';

-- 12. Grant necessary permissions (adjust roles as needed)
GRANT SELECT, INSERT ON school_student_ids TO PUBLIC;
GRANT SELECT ON school_student_id_audit TO PUBLIC;
GRANT SELECT ON v_school_student_id_duplicates TO PUBLIC;

-- 13. Display completion message
DO $$
BEGIN
    RAISE NOTICE 'School Student ID schema created successfully!';
    RAISE NOTICE 'Tables created: school_student_ids, school_student_id_audit';
    RAISE NOTICE 'View created: v_school_student_id_duplicates';
    RAISE NOTICE 'Function created: check_duplicate_school_student_id()';
    RAISE NOTICE 'Trigger created: trigger_track_school_student_id';
END $$;
