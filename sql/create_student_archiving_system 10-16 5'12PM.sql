-- ============================================
-- STUDENT ARCHIVING SYSTEM
-- ============================================
-- Implements automatic and manual student archiving
-- for graduated students and inactive accounts
-- Created: 2025-10-16

BEGIN;

-- Step 1: Add archiving columns to students table
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS is_archived BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP NULL,
ADD COLUMN IF NOT EXISTS archived_by INTEGER REFERENCES admins(admin_id),
ADD COLUMN IF NOT EXISTS archive_reason TEXT,
ADD COLUMN IF NOT EXISTS expected_graduation_year INTEGER,
ADD COLUMN IF NOT EXISTS academic_year_registered TEXT;

-- Step 2: Update status constraint to include 'archived'
ALTER TABLE students DROP CONSTRAINT IF EXISTS students_status_check;

ALTER TABLE students ADD CONSTRAINT students_status_check 
CHECK (status IN ('under_registration', 'applicant', 'active', 'disabled', 'given', 'blacklisted', 'archived'));

-- Step 3: Create indexes for archiving queries
CREATE INDEX IF NOT EXISTS idx_students_is_archived ON students(is_archived);
CREATE INDEX IF NOT EXISTS idx_students_archived_at ON students(archived_at);
CREATE INDEX IF NOT EXISTS idx_students_graduation_year ON students(expected_graduation_year);
CREATE INDEX IF NOT EXISTS idx_students_year_level ON students(year_level_id);

-- Composite index for active student queries (frequently used)
CREATE INDEX IF NOT EXISTS idx_students_active_status ON students(is_archived, status) WHERE is_archived = FALSE;

-- Step 4: Add comments for documentation
COMMENT ON COLUMN students.is_archived IS 'Flag indicating if student account is archived (graduated/inactive)';
COMMENT ON COLUMN students.archived_at IS 'Timestamp when student was archived';
COMMENT ON COLUMN students.archived_by IS 'Admin ID who archived the student (NULL for automatic archiving)';
COMMENT ON COLUMN students.archive_reason IS 'Reason for archiving: graduated, inactive, manual, no_attendance, etc.';
COMMENT ON COLUMN students.expected_graduation_year IS 'Calculated graduation year based on year level at registration';
COMMENT ON COLUMN students.academic_year_registered IS 'Academic year when student first registered (e.g., 2024-2025)';

-- Step 5: Calculate expected graduation year for existing students
-- Formula: If student is 1st year in 2024-2025, graduation = 2028
-- If student is 2nd year in 2024-2025, graduation = 2027, etc.
UPDATE students s
SET 
    academic_year_registered = CASE 
        WHEN s.academic_year_registered IS NULL 
        THEN EXTRACT(YEAR FROM s.application_date)::TEXT || '-' || (EXTRACT(YEAR FROM s.application_date) + 1)::TEXT
        ELSE s.academic_year_registered
    END,
    expected_graduation_year = CASE 
        WHEN s.expected_graduation_year IS NULL AND s.year_level_id IS NOT NULL THEN
            CASE 
                WHEN yl.code = '1ST' THEN EXTRACT(YEAR FROM s.application_date)::INTEGER + 4
                WHEN yl.code = '2ND' THEN EXTRACT(YEAR FROM s.application_date)::INTEGER + 3
                WHEN yl.code = '3RD' THEN EXTRACT(YEAR FROM s.application_date)::INTEGER + 2
                WHEN yl.code = '4TH' THEN EXTRACT(YEAR FROM s.application_date)::INTEGER + 1
                WHEN yl.code = '5TH' THEN EXTRACT(YEAR FROM s.application_date)::INTEGER + 1
                ELSE EXTRACT(YEAR FROM s.application_date)::INTEGER + 4 -- Default to 4 years
            END
        ELSE s.expected_graduation_year
    END
FROM year_levels yl
WHERE s.year_level_id = yl.year_level_id
  AND (s.expected_graduation_year IS NULL OR s.academic_year_registered IS NULL);

-- Step 6: Create view for students eligible for automatic archiving
CREATE OR REPLACE VIEW v_students_eligible_for_archiving AS
SELECT 
    s.student_id,
    s.first_name,
    s.last_name,
    s.email,
    s.status,
    s.year_level_id,
    yl.name as year_level_name,
    s.academic_year_registered,
    s.expected_graduation_year,
    EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER as current_year,
    EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER - s.expected_graduation_year as years_past_graduation,
    s.last_login,
    s.application_date,
    CASE 
        WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > s.expected_graduation_year THEN 'Graduated (past expected graduation year)'
        WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = s.expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6 THEN 'Graduated (current graduation year, past June)'
        WHEN s.last_login IS NOT NULL AND s.last_login < (CURRENT_DATE - INTERVAL '2 years') THEN 'Inactive (no login for 2+ years)'
        WHEN s.last_login IS NULL AND s.application_date < (CURRENT_DATE - INTERVAL '2 years') THEN 'Inactive (never logged in, registered 2+ years ago)'
        ELSE 'Other'
    END as eligibility_reason
FROM students s
LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
WHERE 
    s.is_archived = FALSE
    AND s.status NOT IN ('blacklisted') -- Don't auto-archive blacklisted students
    AND (
        -- Graduated: Current year is past expected graduation year
        EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > s.expected_graduation_year
        -- OR Graduated: Current year equals graduation year AND we're past June (graduation month)
        OR (EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = s.expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6)
        -- OR Inactive: No login for 2+ years
        OR (s.last_login IS NOT NULL AND s.last_login < (CURRENT_DATE - INTERVAL '2 years'))
        -- OR Inactive: Never logged in and registered 2+ years ago
        OR (s.last_login IS NULL AND s.application_date < (CURRENT_DATE - INTERVAL '2 years'))
    );

-- Step 7: Create view for archived students summary
CREATE OR REPLACE VIEW v_archived_students_summary AS
SELECT 
    s.student_id,
    s.first_name,
    s.middle_name,
    s.last_name,
    s.extension_name,
    s.email,
    s.mobile,
    s.status,
    yl.name as year_level_name,
    u.name as university_name,
    s.academic_year_registered,
    s.expected_graduation_year,
    s.is_archived,
    s.archived_at,
    s.archived_by,
    s.archive_reason,
    CONCAT(a.first_name, ' ', a.last_name) as archived_by_name,
    s.application_date,
    s.last_login,
    CASE 
        WHEN s.archived_by IS NULL THEN 'Automatic'
        ELSE 'Manual'
    END as archive_type
FROM students s
LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
LEFT JOIN universities u ON s.university_id = u.university_id
LEFT JOIN admins a ON s.archived_by = a.admin_id
WHERE s.is_archived = TRUE
ORDER BY s.archived_at DESC;

-- Step 8: Create function for automatic archiving
CREATE OR REPLACE FUNCTION archive_graduated_students()
RETURNS TABLE(
    archived_count INTEGER,
    student_ids TEXT[]
) AS $$
DECLARE
    v_archived_count INTEGER;
    v_student_ids TEXT[];
BEGIN
    -- Archive eligible students
    WITH archived AS (
        UPDATE students
        SET 
            is_archived = TRUE,
            archived_at = CURRENT_TIMESTAMP,
            archived_by = NULL, -- NULL indicates automatic archiving
            archive_reason = CASE 
                WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > expected_graduation_year THEN 'Automatically archived: Graduated (past expected graduation year)'
                WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6 THEN 'Automatically archived: Graduated (current graduation year)'
                WHEN last_login IS NOT NULL AND last_login < (CURRENT_DATE - INTERVAL '2 years') THEN 'Automatically archived: Inactive account (no login for 2+ years)'
                WHEN last_login IS NULL AND application_date < (CURRENT_DATE - INTERVAL '2 years') THEN 'Automatically archived: Inactive account (never logged in)'
                ELSE 'Automatically archived'
            END,
            status = 'archived'
        WHERE student_id IN (
            SELECT student_id FROM v_students_eligible_for_archiving
        )
        RETURNING student_id
    )
    SELECT 
        COUNT(*)::INTEGER,
        ARRAY_AGG(student_id)
    INTO v_archived_count, v_student_ids
    FROM archived;
    
    RETURN QUERY SELECT v_archived_count, v_student_ids;
END;
$$ LANGUAGE plpgsql;

-- Step 9: Create function for manual archiving
CREATE OR REPLACE FUNCTION archive_student_manual(
    p_student_id TEXT,
    p_admin_id INTEGER,
    p_reason TEXT
)
RETURNS BOOLEAN AS $$
DECLARE
    v_success BOOLEAN;
BEGIN
    UPDATE students
    SET 
        is_archived = TRUE,
        archived_at = CURRENT_TIMESTAMP,
        archived_by = p_admin_id,
        archive_reason = p_reason,
        status = 'archived'
    WHERE student_id = p_student_id
      AND is_archived = FALSE;
    
    GET DIAGNOSTICS v_success = ROW_COUNT;
    
    RETURN v_success > 0;
END;
$$ LANGUAGE plpgsql;

-- Step 10: Create function for unarchiving students
CREATE OR REPLACE FUNCTION unarchive_student(
    p_student_id TEXT,
    p_admin_id INTEGER
)
RETURNS BOOLEAN AS $$
DECLARE
    v_success BOOLEAN;
    v_old_status TEXT;
BEGIN
    -- Get the old status before archiving (if it was 'archived', default to 'active')
    SELECT 
        CASE 
            WHEN status = 'archived' THEN 'active'
            ELSE status
        END
    INTO v_old_status
    FROM students
    WHERE student_id = p_student_id;
    
    UPDATE students
    SET 
        is_archived = FALSE,
        archived_at = NULL,
        archived_by = NULL,
        archive_reason = NULL,
        status = v_old_status
    WHERE student_id = p_student_id
      AND is_archived = TRUE;
    
    GET DIAGNOSTICS v_success = ROW_COUNT;
    
    RETURN v_success > 0;
END;
$$ LANGUAGE plpgsql;

-- Step 11: Add comments to functions
COMMENT ON FUNCTION archive_graduated_students() IS 'Automatically archives students who have graduated or been inactive for 2+ years. Run annually or as needed.';
COMMENT ON FUNCTION archive_student_manual(TEXT, INTEGER, TEXT) IS 'Manually archives a student with specified reason. Used by admins for manual archiving.';
COMMENT ON FUNCTION unarchive_student(TEXT, INTEGER) IS 'Unarchives a student account. Restores previous status or defaults to active.';

COMMIT;

-- Success message
SELECT 'Student archiving system created successfully!' AS status;

-- Show summary
SELECT 
    'Total students' as metric,
    COUNT(*)::TEXT as value
FROM students
UNION ALL
SELECT 
    'Currently archived',
    COUNT(*)::TEXT
FROM students WHERE is_archived = TRUE
UNION ALL
SELECT 
    'Eligible for archiving',
    COUNT(*)::TEXT
FROM v_students_eligible_for_archiving;
