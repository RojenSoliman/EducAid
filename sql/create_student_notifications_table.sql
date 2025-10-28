-- Create student_notifications table
-- This is a dedicated notification system for students, separate from admin_notifications

-- Drop table if exists (for clean reinstall)
-- DROP TABLE IF EXISTS student_notifications CASCADE;

-- Create student_notifications table
CREATE TABLE IF NOT EXISTS student_notifications (
    notification_id SERIAL PRIMARY KEY,
    student_id TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) DEFAULT 'info',
    priority VARCHAR(20) DEFAULT 'low',
    action_url TEXT DEFAULT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    is_priority BOOLEAN DEFAULT FALSE,
    viewed_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP DEFAULT NULL,
    CONSTRAINT fk_student 
        FOREIGN KEY (student_id) 
        REFERENCES students(student_id) 
        ON DELETE CASCADE
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_student_notifications_student_id 
    ON student_notifications(student_id);

CREATE INDEX IF NOT EXISTS idx_student_notifications_is_read 
    ON student_notifications(is_read);

CREATE INDEX IF NOT EXISTS idx_student_notifications_created_at 
    ON student_notifications(created_at DESC);

CREATE INDEX IF NOT EXISTS idx_student_notifications_priority 
    ON student_notifications(is_priority, student_id) 
    WHERE is_priority = TRUE;

-- Add comments for documentation
COMMENT ON TABLE student_notifications IS 'Dedicated notification system for students';
COMMENT ON COLUMN student_notifications.notification_id IS 'Unique notification identifier';
COMMENT ON COLUMN student_notifications.student_id IS 'Foreign key reference to students table';
COMMENT ON COLUMN student_notifications.title IS 'Brief notification title';
COMMENT ON COLUMN student_notifications.message IS 'Full notification message/description';
COMMENT ON COLUMN student_notifications.type IS 'Notification type: info, warning, error, success, document, application, etc.';
COMMENT ON COLUMN student_notifications.priority IS 'Priority level: high, medium, low';
COMMENT ON COLUMN student_notifications.action_url IS 'Optional URL to navigate when notification is clicked';
COMMENT ON COLUMN student_notifications.is_read IS 'Whether the notification has been read';
COMMENT ON COLUMN student_notifications.is_priority IS 'TRUE for urgent notifications that need immediate attention';
COMMENT ON COLUMN student_notifications.viewed_at IS 'Timestamp when priority notification was first viewed';
COMMENT ON COLUMN student_notifications.created_at IS 'When the notification was created';
COMMENT ON COLUMN student_notifications.expires_at IS 'Optional expiration date for time-sensitive notifications';

-- Insert sample notifications for testing (optional - remove if not needed)
-- Uncomment the following to insert test data:
/*
INSERT INTO student_notifications (student_id, title, message, type, priority, action_url) VALUES
    (1, 'Welcome to EducAid', 'Your application has been received and is under review.', 'info', 'low', 'application_status.php'),
    (1, 'Document Required', 'Please upload your school ID to complete your application.', 'warning', 'medium', 'upload_documents.php'),
    (1, 'Application Approved!', 'Congratulations! Your scholarship application has been approved.', 'success', 'high', 'application_status.php');
*/

-- Grant permissions (adjust user as needed)
-- GRANT SELECT, INSERT, UPDATE, DELETE ON student_notifications TO your_app_user;
-- GRANT USAGE, SELECT ON SEQUENCE student_notifications_notification_id_seq TO your_app_user;

SELECT 'Student notifications table created successfully!' AS status;
