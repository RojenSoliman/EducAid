-- Archive Student Function
-- Archives a student and sets all necessary fields
CREATE OR REPLACE FUNCTION archive_student(
    p_student_id TEXT,
    p_admin_id INTEGER,
    p_archive_reason TEXT
)
RETURNS BOOLEAN AS $$
BEGIN
    -- Update student record
    UPDATE students
    SET 
        is_archived = TRUE,
        archived_at = NOW(),
        archived_by = p_admin_id,
        archive_reason = p_archive_reason,
        status = 'archived'
    WHERE student_id = p_student_id
    AND is_archived = FALSE; -- Only archive if not already archived
    
    -- Return true if a row was updated
    RETURN FOUND;
END;
$$ LANGUAGE plpgsql;

-- Unarchive Student Function
-- Restores an archived student back to applicant status
CREATE OR REPLACE FUNCTION unarchive_student(
    p_student_id TEXT,
    p_admin_id INTEGER
)
RETURNS BOOLEAN AS $$
BEGIN
    -- Update student record
    UPDATE students
    SET 
        is_archived = FALSE,
        archived_at = NULL,
        archived_by = NULL,
        archive_reason = NULL,
        status = 'applicant' -- Set to applicant status (requires re-verification)
    WHERE student_id = p_student_id
    AND is_archived = TRUE; -- Only unarchive if currently archived
    
    -- Return true if a row was updated
    RETURN FOUND;
END;
$$ LANGUAGE plpgsql;

-- Get Archived Students with Details
CREATE OR REPLACE FUNCTION get_archived_students(
    p_municipality_id INTEGER DEFAULT NULL,
    p_limit INTEGER DEFAULT 50,
    p_offset INTEGER DEFAULT 0
)
RETURNS TABLE (
    student_id TEXT,
    first_name TEXT,
    middle_name TEXT,
    last_name TEXT,
    extension_name TEXT,
    email TEXT,
    mobile TEXT,
    year_level_name TEXT,
    university_name TEXT,
    archived_at TIMESTAMP,
    archived_by_name TEXT,
    archive_reason TEXT,
    archive_type TEXT
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        s.student_id,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.extension_name,
        s.email,
        s.mobile,
        yl.name as year_level_name,
        u.name as university_name,
        s.archived_at,
        CONCAT(a.first_name, ' ', a.last_name) as archived_by_name,
        s.archive_reason,
        CASE 
            WHEN s.archived_by IS NULL THEN 'Automatic'
            ELSE 'Manual'
        END as archive_type
    FROM students s
    LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
    LEFT JOIN universities u ON s.university_id = u.university_id
    LEFT JOIN admins a ON s.archived_by = a.admin_id
    WHERE s.is_archived = TRUE
    AND (p_municipality_id IS NULL OR s.municipality_id = p_municipality_id)
    ORDER BY s.archived_at DESC
    LIMIT p_limit OFFSET p_offset;
END;
$$ LANGUAGE plpgsql;

-- Check if student has archive files
CREATE OR REPLACE FUNCTION has_archived_files(p_student_id TEXT)
RETURNS BOOLEAN AS $$
DECLARE
    v_zip_exists BOOLEAN;
BEGIN
    -- This would need to be implemented with a custom function that checks the filesystem
    -- For now, we'll return TRUE if the student is archived
    SELECT is_archived INTO v_zip_exists
    FROM students
    WHERE student_id = p_student_id;
    
    RETURN COALESCE(v_zip_exists, FALSE);
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION archive_student IS 'Archives a student by setting is_archived flag and related metadata';
COMMENT ON FUNCTION unarchive_student IS 'Unarchives a student and restores active status';
COMMENT ON FUNCTION get_archived_students IS 'Retrieves paginated list of archived students with related details';
