-- Add is_read column to admin_notifications table for tracking read/unread status
ALTER TABLE admin_notifications 
ADD COLUMN IF NOT EXISTS is_read BOOLEAN DEFAULT FALSE;

-- Create index for better performance when querying unread notifications
CREATE INDEX IF NOT EXISTS idx_admin_notifications_is_read ON admin_notifications(is_read);

-- Update existing notifications to be marked as unread by default
UPDATE admin_notifications SET is_read = FALSE WHERE is_read IS NULL;