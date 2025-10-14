-- Add created_at column to landing_content_blocks if it doesn't exist
-- Safe to run multiple times

DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 
        FROM information_schema.columns 
        WHERE table_name = 'landing_content_blocks' 
        AND column_name = 'created_at'
    ) THEN
        ALTER TABLE landing_content_blocks 
        ADD COLUMN created_at TIMESTAMPTZ DEFAULT NOW();
        
        -- Update existing rows to have created_at = updated_at
        UPDATE landing_content_blocks 
        SET created_at = updated_at 
        WHERE created_at IS NULL;
    END IF;
END $$;
