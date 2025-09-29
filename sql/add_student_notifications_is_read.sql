-- Add is_read column to student notifications table for tracking read/unread status
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS is_read BOOLEAN DEFAULT FALSE;

-- Create index for faster unread lookups
CREATE INDEX IF NOT EXISTS idx_notifications_is_read ON notifications(is_read);

-- Normalize existing rows (set NULL to FALSE)
UPDATE notifications SET is_read = FALSE WHERE is_read IS NULL;

-- Notes:
-- Run this migration once before enabling the enhanced student notifications UI.
-- psql example:
-- \i sql/add_student_notifications_is_read.sql;
