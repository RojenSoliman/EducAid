-- ========================================
-- COMPLETE STUDENT ARCHIVING SYSTEM SETUP
-- ========================================
-- This is the ONLY file you need to run to set up student archiving
-- Run this ONCE with: psql -U postgres -d educaid_db -f setup_archiving_system_COMPLETE.sql
--
-- What this does:
-- 1. Adds archiving columns to students table
-- 2. Creates performance indexes
-- 3. Creates archive/unarchive functions (with correct TEXT student_id type)
-- 4. Creates views for archived students
-- 5. Updates audit log categories
-- ========================================

\echo '========================================';
\echo 'STUDENT ARCHIVING SYSTEM SETUP';
\echo '========================================';
\echo '';

-- ========================================
-- STEP 1: Add Archiving Columns to Students Table
-- ========================================
\echo 'Step 1: Adding archiving columns to students table...';

-- Add is_archived flag (defaults to FALSE for existing students)
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE;

-- Add archived timestamp
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP WITH TIME ZONE;

-- Add admin who archived (foreign key to admins table)
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS archived_by INTEGER REFERENCES admins(admin_id);

-- Add archive reason
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS archive_reason TEXT;

-- Add expected graduation year (useful for automatic archiving)
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS expected_graduation_year INTEGER;

\echo '✓ Archiving columns added successfully';
\echo '';

-- ========================================
-- STEP 2: Create Indexes for Performance
-- ========================================
\echo 'Step 2: Creating performance indexes...';

-- Index for querying archived students
CREATE INDEX IF NOT EXISTS idx_students_is_archived 
ON students(is_archived) WHERE is_archived = TRUE;

-- Index for querying by graduation year
CREATE INDEX IF NOT EXISTS idx_students_graduation_year 
ON students(expected_graduation_year) WHERE expected_graduation_year IS NOT NULL;

-- Composite index for common queries
CREATE INDEX IF NOT EXISTS idx_students_archived_date 
ON students(is_archived, archived_at) WHERE is_archived = TRUE;

\echo '✓ Performance indexes created successfully';
\echo '';

-- ========================================
-- STEP 3: Create Archive Student Function (Manual)
-- ========================================
\echo 'Step 3: Creating archive_student_manual function...';

-- Drop existing versions (both INTEGER and TEXT) if they exist
DROP FUNCTION IF EXISTS archive_student_manual(INTEGER, INTEGER, TEXT);
DROP FUNCTION IF EXISTS archive_student_manual(TEXT, INTEGER, TEXT);

-- Create function with TEXT student_id (matching your database schema)
CREATE OR REPLACE FUNCTION archive_student_manual(
    p_student_id TEXT,           -- TEXT type to match students table
    p_admin_id INTEGER,
    p_reason TEXT
) RETURNS BOOLEAN AS $$
BEGIN
    -- Archive the student
    UPDATE students 
    SET 
        is_archived = TRUE,
        archived_at = NOW(),
        archived_by = p_admin_id,
        archive_reason = p_reason
    WHERE student_id = p_student_id
    AND is_archived = FALSE; -- Only archive if not already archived
    
    -- Return TRUE if row was updated, FALSE otherwise
    IF FOUND THEN
        RETURN TRUE;
    ELSE
        RETURN FALSE;
    END IF;
END;
$$ LANGUAGE plpgsql;

\echo '✓ archive_student_manual function created successfully';
\echo '';

-- ========================================
-- STEP 4: Create Unarchive Student Function
-- ========================================
\echo 'Step 4: Creating unarchive_student function...';

-- Drop existing versions if they exist
DROP FUNCTION IF EXISTS unarchive_student(INTEGER, INTEGER);
DROP FUNCTION IF EXISTS unarchive_student(TEXT, INTEGER);

-- Create function with TEXT student_id
CREATE OR REPLACE FUNCTION unarchive_student(
    p_student_id TEXT,           -- TEXT type to match students table
    p_admin_id INTEGER
) RETURNS BOOLEAN AS $$
BEGIN
    -- Unarchive the student
    UPDATE students 
    SET 
        is_archived = FALSE,
        archived_at = NULL,
        archived_by = NULL,
        archive_reason = NULL
    WHERE student_id = p_student_id
    AND is_archived = TRUE; -- Only unarchive if currently archived
    
    -- Return TRUE if row was updated, FALSE otherwise
    IF FOUND THEN
        RETURN TRUE;
    ELSE
        RETURN FALSE;
    END IF;
END;
$$ LANGUAGE plpgsql;

\echo '✓ unarchive_student function created successfully';
\echo '';

-- ========================================
-- STEP 5: Create Automatic Archiving Function
-- ========================================
\echo 'Step 5: Creating automatic archiving function...';

-- Drop existing version if it exists
DROP FUNCTION IF EXISTS archive_students_automatic(INTEGER, INTEGER);

-- Create automatic archiving function
CREATE OR REPLACE FUNCTION archive_students_automatic(
    p_graduation_year INTEGER,
    p_admin_id INTEGER
) RETURNS TABLE(
    archived_count INTEGER,
    student_ids TEXT[]
) AS $$
DECLARE
    v_student_ids TEXT[];
    v_count INTEGER;
BEGIN
    -- Collect student IDs that will be archived
    SELECT ARRAY_AGG(student_id)
    INTO v_student_ids
    FROM students
    WHERE expected_graduation_year = p_graduation_year
    AND is_archived = FALSE
    AND status IN ('active', 'given'); -- Only archive active/given students
    
    -- Archive matching students
    UPDATE students
    SET 
        is_archived = TRUE,
        archived_at = NOW(),
        archived_by = p_admin_id,
        archive_reason = 'Automatic archiving: Graduated in ' || p_graduation_year
    WHERE expected_graduation_year = p_graduation_year
    AND is_archived = FALSE
    AND status IN ('active', 'given');
    
    GET DIAGNOSTICS v_count = ROW_COUNT;
    
    -- Return results
    archived_count := v_count;
    student_ids := v_student_ids;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql;

\echo '✓ archive_students_automatic function created successfully';
\echo '';

-- ========================================
-- STEP 6: Create Active Students View
-- ========================================
\echo 'Step 6: Creating active_students view...';

-- Drop existing view if it exists
DROP VIEW IF EXISTS active_students;

-- Create view for active (non-archived) students
CREATE VIEW active_students AS
SELECT 
    s.*,
    m.name as municipality_name,
    b.name as barangay_name,
    yl.name as year_level_name
FROM students s
LEFT JOIN municipalities m ON s.municipality_id = m.municipality_id
LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
WHERE s.is_archived = FALSE OR s.is_archived IS NULL;

\echo '✓ active_students view created successfully';
\echo '';

-- ========================================
-- STEP 7: Create Archived Students View
-- ========================================
\echo 'Step 7: Creating archived_students_view...';

-- Drop existing view if it exists
DROP VIEW IF EXISTS archived_students_view;

-- Create view for archived students with additional details
CREATE VIEW archived_students_view AS
SELECT 
    s.student_id,
    s.first_name,
    s.middle_name,
    s.last_name,
    s.extension_name,
    s.email,
    s.mobile,
    s.sex,
    s.bdate,
    s.status,
    s.application_date as registration_date,
    s.is_archived,
    s.archived_at,
    s.archived_by,
    s.archive_reason,
    s.expected_graduation_year,
    m.name as municipality_name,
    b.name as barangay_name,
    yl.name as year_level_name,
    a.username as archived_by_username,
    CONCAT(a.first_name, ' ', a.last_name) as archived_by_name
FROM students s
LEFT JOIN municipalities m ON s.municipality_id = m.municipality_id
LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
LEFT JOIN admins a ON s.archived_by = a.admin_id
WHERE s.is_archived = TRUE;

\echo '✓ archived_students_view created successfully';
\echo '';

-- ========================================
-- STEP 8: Update Audit Logs for Archiving Events
-- ========================================
\echo 'Step 8: Updating audit logs for archiving events...';

-- Add archive event category to existing audit_logs if not already present
-- This ensures archive events are properly logged
UPDATE audit_logs 
SET event_category = 'archive'
WHERE event_type IN ('student_archived', 'student_unarchived', 'automatic_archiving')
AND (event_category IS NULL OR event_category != 'archive');

\echo '✓ Audit logs updated successfully';
\echo '';

-- ========================================
-- VERIFICATION
-- ========================================
\echo '========================================';
\echo 'VERIFICATION';
\echo '========================================';
\echo '';

-- Check columns
\echo 'Archiving columns in students table:';
SELECT 
    column_name, 
    data_type,
    column_default
FROM information_schema.columns 
WHERE table_name = 'students' 
AND column_name IN ('is_archived', 'archived_at', 'archived_by', 'archive_reason', 'expected_graduation_year')
ORDER BY ordinal_position;

\echo '';

-- Check functions
\echo 'Archive functions created:';
SELECT 
    p.proname as function_name,
    pg_get_function_arguments(p.oid) as arguments
FROM pg_proc p
JOIN pg_namespace n ON p.pronamespace = n.oid
WHERE n.nspname = 'public' 
AND p.proname LIKE '%archive%'
ORDER BY p.proname;

\echo '';

-- Check views
\echo 'Archive views created:';
SELECT 
    schemaname,
    viewname
FROM pg_views
WHERE schemaname = 'public'
AND viewname IN ('active_students', 'archived_students_view');

\echo '';

-- Check indexes
\echo 'Archive indexes created:';
SELECT 
    indexname,
    tablename
FROM pg_indexes
WHERE schemaname = 'public'
AND indexname LIKE '%archive%'
ORDER BY indexname;

\echo '';
\echo '========================================';
\echo 'SETUP COMPLETE!';
\echo '========================================';
\echo '';
\echo 'Next steps:';
\echo '1. The archive button in manage_applicants.php is now functional';
\echo '2. Visit modules/admin/archived_students.php to view archived students';
\echo '3. Use modules/admin/run_automatic_archiving_admin.php for bulk archiving';
\echo '4. Check audit logs for all archiving activities';
\echo '';
\echo 'IMPORTANT: Archived students cannot log in to the system.';
\echo '';
