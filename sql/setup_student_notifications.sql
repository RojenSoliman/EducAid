-- Run this script to set up the student notification system
-- This will create the table and insert some sample notifications for testing

-- ==========================================
-- Student Notification System Setup
-- ==========================================

-- Step 1: Create the student_notifications table
-- (Run create_student_notifications_table.sql first)

-- Step 2: Verifying table creation
SELECT 
    tablename, 
    schemaname 
FROM pg_tables 
WHERE tablename = 'student_notifications';

-- Step 3: Checking table structure
SELECT 
    column_name, 
    data_type, 
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_name = 'student_notifications'
ORDER BY ordinal_position;

-- Step 4: Creating sample test notifications
-- (Note: Update student_id values to match actual students in your database)

-- Get a sample student ID from your database
-- You'll need to replace 'SAMPLE_STUDENT_ID' with an actual student ID

-- Sample notification 1: Info type
INSERT INTO student_notifications (student_id, title, message, type, priority, action_url)
SELECT 
    student_id,
    'Welcome to EducAid',
    'Your account has been successfully created. Please complete your profile and upload required documents.',
    'info',
    'low',
    'student_profile.php'
FROM students 
WHERE status = 'applicant'
LIMIT 1;

-- Sample notification 2: Success type
INSERT INTO student_notifications (student_id, title, message, type, priority, action_url)
SELECT 
    student_id,
    'Profile Verification Complete',
    'Your profile information has been verified and approved by the administrator.',
    'success',
    'medium',
    'student_profile.php'
FROM students 
WHERE status != 'under_registration'
LIMIT 1;

-- Sample notification 3: Warning type with expiration
INSERT INTO student_notifications (student_id, title, message, type, priority, expires_at)
SELECT 
    student_id,
    'Document Submission Deadline',
    'Please submit all required documents by November 15, 2025 to avoid delays in processing.',
    'warning',
    'medium',
    CURRENT_TIMESTAMP + INTERVAL '30 days'
FROM students 
WHERE documents_submitted = FALSE
LIMIT 1;

-- Sample notification 4: High priority error
INSERT INTO student_notifications (student_id, title, message, type, priority, is_priority, action_url)
SELECT 
    student_id,
    'Document Rejected',
    'Your Certificate of Indigency was rejected due to unclear image. Please re-upload a clearer copy.',
    'error',
    'high',
    TRUE,
    'student_documents.php'
FROM students 
WHERE status = 'applicant'
LIMIT 1;

-- Sample notification 5: Document type
INSERT INTO student_notifications (student_id, title, message, type, priority, action_url)
SELECT 
    student_id,
    'Document Under Review',
    'Your submitted documents are currently under review. You will be notified once the review is complete.',
    'document',
    'low',
    'student_documents.php'
FROM students 
WHERE documents_submitted = TRUE AND documents_validated = FALSE
LIMIT 1;

-- Step 5: Verifying sample data
SELECT 
    notification_id,
    student_id,
    title,
    type,
    priority,
    is_read,
    created_at
FROM student_notifications 
ORDER BY created_at DESC
LIMIT 5;

-- ==========================================
-- Setup Complete!
-- ==========================================
-- 
-- Next steps:
-- 1. Include bell_notifications.php in student_header.php
-- 2. Test the notification bell in student portal
-- 3. Integrate notification creation in your application logic
-- 
-- For detailed documentation, see:
-- STUDENT_NOTIFICATION_SYSTEM_GUIDE.md
-- ==========================================

