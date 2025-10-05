-- Replace legacy color_theme column with primary/secondary color palette
ALTER TABLE municipalities
  DROP COLUMN IF EXISTS color_theme,
  ADD COLUMN IF NOT EXISTS primary_color VARCHAR(7),
  ADD COLUMN IF NOT EXISTS secondary_color VARCHAR(7);

-- Seed sensible defaults where missing so UI has values to read
UPDATE municipalities
SET primary_color = COALESCE(primary_color, '#2e7d32'),
    secondary_color = COALESCE(secondary_color, '#1b5e20');
