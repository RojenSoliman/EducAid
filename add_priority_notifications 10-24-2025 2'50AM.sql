-- Migration: Add priority notification support
-- Allows marking certain notifications as urgent/priority that persist until dismissed

-- Add is_priority column to notifications table
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
        RAISE NOTICE 'Column is_priority already exists';
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
        ADD COLUMN viewed_at TIMESTAMP DEFAULT NULL;
        
        COMMENT ON COLUMN notifications.viewed_at IS 'Timestamp when priority notification was first viewed (for one-time display)';
        
        RAISE NOTICE 'Added viewed_at column to notifications table';
    ELSE
        RAISE NOTICE 'Column viewed_at already exists';
    END IF;
END $$;

-- Priority notifications are shown as modals that appear once and persist until dismissed
-- Regular notifications are shown in the notifications dropdown
-- Usage:
-- INSERT INTO notifications (student_id, message, is_priority, is_read) 
-- VALUES ($student_id, 'URGENT: Your documents were rejected...', TRUE, FALSE);
