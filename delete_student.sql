-- Complete Student Deletion Script
-- This will remove a student and ALL associated data
-- Date: November 1, 2025

-- STEP 1: Find the student you want to delete (SAFE TO RUN)
SELECT 
    student_id,
    first_name,
    last_name,
    email,
    status,
    needs_document_upload,
    documents_submitted,
    created_at
FROM students 
ORDER BY created_at DESC
LIMIT 20;

-- STEP 2: Replace 'YOUR_STUDENT_ID' below and uncomment to delete
-- WARNING: This is PERMANENT and cannot be undone!

/*
-- Set the student ID to delete
\set student_id 'YOUR_STUDENT_ID'

-- Delete in correct order (respecting foreign keys)

-- 1. Delete student notifications
DELETE FROM student_notifications WHERE student_id = :'student_id';

-- 2. Delete notifications (if exists)
DELETE FROM notifications WHERE student_id = :'student_id';

-- 3. Delete documents
DELETE FROM documents WHERE student_id = :'student_id';

-- 4. Delete audit logs
DELETE FROM audit_log WHERE student_id = :'student_id';
DELETE FROM audit_logs WHERE student_id = :'student_id';

-- 5. Delete OTP records (if exists)
DELETE FROM student_otp WHERE student_id = :'student_id';

-- 6. Delete distribution records (if exists)
DELETE FROM distributions WHERE student_id = :'student_id';

-- 7. Delete the student record itself
DELETE FROM students WHERE student_id = :'student_id';

-- Show confirmation
SELECT 'Student deleted successfully!' AS result;
*/

-- STEP 3: Verify deletion (SAFE TO RUN)
/*
SELECT 
    student_id,
    first_name,
    last_name,
    email
FROM students 
WHERE student_id = 'YOUR_STUDENT_ID';
-- Should return 0 rows if successful
*/
