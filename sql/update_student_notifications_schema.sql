-- Migration: Update student notifications table to match admin_notifications structure
-- This adds all necessary columns for a complete notification system

-- Add title column for notification subject/header
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'notifications' 
        AND column_name = 'title'
    ) THEN
        ALTER TABLE notifications 
        ADD COLUMN title VARCHAR(255);
        
        -- Set default title for existing notifications
        UPDATE notifications SET title = 'Notification' WHERE title IS NULL;
        
        COMMENT ON COLUMN notifications.title IS 'Short title/subject for the notification';
        RAISE NOTICE 'Added title column to notifications table';
    ELSE
        RAISE NOTICE 'title column already exists in notifications table';
    END IF;
END $$;

-- Add type column for categorizing notifications
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'notifications' 
        AND column_name = 'type'
    ) THEN
        ALTER TABLE notifications 
        ADD COLUMN type VARCHAR(50) DEFAULT 'info';
        
        COMMENT ON COLUMN notifications.type IS 'Type of notification: announcement, document, schedule, system, warning, error, success';
        RAISE NOTICE 'Added type column to notifications table';
    ELSE
        RAISE NOTICE 'type column already exists in notifications table';
    END IF;
END $$;

-- Add priority column for marking urgent notifications
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'notifications' 
        AND column_name = 'priority'
    ) THEN
        ALTER TABLE notifications 
        ADD COLUMN priority VARCHAR(20) DEFAULT 'low';
        
        COMMENT ON COLUMN notifications.priority IS 'Priority level: low, medium, high';
        RAISE NOTICE 'Added priority column to notifications table';
    ELSE
        RAISE NOTICE 'priority column already exists in notifications table';
    END IF;
END $$;

-- Add is_read column for tracking read/unread status
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'notifications' 
        AND column_name = 'is_read'
    ) THEN
        ALTER TABLE notifications 
        ADD COLUMN is_read BOOLEAN DEFAULT FALSE;
        
        -- Create index for better performance when querying unread notifications
        CREATE INDEX IF NOT EXISTS idx_notifications_is_read ON notifications(is_read);
        
        -- Set existing notifications as unread
        UPDATE notifications SET is_read = FALSE WHERE is_read IS NULL;
        
        COMMENT ON COLUMN notifications.is_read IS 'Track whether the notification has been read';
        RAISE NOTICE 'Added is_read column to notifications table';
    ELSE
        RAISE NOTICE 'is_read column already exists in notifications table';
    END IF;
END $$;

-- Add action_url column for clickable notifications
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'notifications' 
        AND column_name = 'action_url'
    ) THEN
        ALTER TABLE notifications 
        ADD COLUMN action_url TEXT;
        
        COMMENT ON COLUMN notifications.action_url IS 'Optional URL to navigate to when notification is clicked';
        RAISE NOTICE 'Added action_url column to notifications table';
    ELSE
        RAISE NOTICE 'action_url column already exists in notifications table';
    END IF;
END $$;

-- Add expires_at column for auto-expiring notifications
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'notifications' 
        AND column_name = 'expires_at'
    ) THEN
        ALTER TABLE notifications 
        ADD COLUMN expires_at TIMESTAMP;
        
        COMMENT ON COLUMN notifications.expires_at IS 'Optional expiration timestamp - notifications expire automatically after this time';
        RAISE NOTICE 'Added expires_at column to notifications table';
    ELSE
        RAISE NOTICE 'expires_at column already exists in notifications table';
    END IF;
END $$;

-- Add is_priority column (for modal display like document rejections)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'notifications' 
        AND column_name = 'is_priority'
    ) THEN
        ALTER TABLE notifications 
        ADD COLUMN is_priority BOOLEAN DEFAULT FALSE;
        
        COMMENT ON COLUMN notifications.is_priority IS 'TRUE for urgent notifications that need immediate attention (e.g., document rejections)';
        RAISE NOTICE 'Added is_priority column to notifications table';
    ELSE
        RAISE NOTICE 'is_priority column already exists in notifications table';
    END IF;
END $$;

-- Add viewed_at column to track when priority notifications were first viewed
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'notifications' 
        AND column_name = 'viewed_at'
    ) THEN
        ALTER TABLE notifications 
        ADD COLUMN viewed_at TIMESTAMP;
        
        COMMENT ON COLUMN notifications.viewed_at IS 'Timestamp when priority notification was first viewed (for one-time display)';
        RAISE NOTICE 'Added viewed_at column to notifications table';
    ELSE
        RAISE NOTICE 'viewed_at column already exists in notifications table';
    END IF;
END $$;

-- Create composite index for efficient queries
CREATE INDEX IF NOT EXISTS idx_notifications_student_unread 
ON notifications(student_id, is_read, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_notifications_student_priority 
ON notifications(student_id, is_priority, viewed_at);

-- Display completion message
DO $$
BEGIN
    RAISE NOTICE '====================================';
    RAISE NOTICE 'Student notifications schema update complete!';
    RAISE NOTICE 'The notifications table now matches the admin_notifications structure';
    RAISE NOTICE '====================================';
END $$;
