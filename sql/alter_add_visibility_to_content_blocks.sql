-- Add visibility toggle for content blocks
-- This allows hiding/showing sections without deleting content

ALTER TABLE landing_content_blocks 
ADD COLUMN IF NOT EXISTS is_visible BOOLEAN DEFAULT TRUE;

-- Add comment
COMMENT ON COLUMN landing_content_blocks.is_visible IS 'Controls whether this content block is displayed (true) or archived (false)';

-- Update existing records to be visible by default
UPDATE landing_content_blocks SET is_visible = TRUE WHERE is_visible IS NULL;
