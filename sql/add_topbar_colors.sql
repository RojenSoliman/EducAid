-- Add topbar color fields to theme_settings table
ALTER TABLE theme_settings 
ADD COLUMN IF NOT EXISTS topbar_bg_color VARCHAR(7) DEFAULT '#2e7d32',
ADD COLUMN IF NOT EXISTS topbar_bg_gradient VARCHAR(7) DEFAULT '#1b5e20',
ADD COLUMN IF NOT EXISTS topbar_text_color VARCHAR(7) DEFAULT '#ffffff',
ADD COLUMN IF NOT EXISTS topbar_link_color VARCHAR(7) DEFAULT '#e8f5e9';

-- Update existing record with default colors if it exists
UPDATE theme_settings 
SET 
    topbar_bg_color = COALESCE(topbar_bg_color, '#2e7d32'),
    topbar_bg_gradient = COALESCE(topbar_bg_gradient, '#1b5e20'),
    topbar_text_color = COALESCE(topbar_text_color, '#ffffff'),
    topbar_link_color = COALESCE(topbar_link_color, '#e8f5e9')
WHERE municipality_id = 1;