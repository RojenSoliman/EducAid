-- Adds event-related fields and media support to announcements
ALTER TABLE announcements
    ADD COLUMN IF NOT EXISTS event_date date NULL,
    ADD COLUMN IF NOT EXISTS event_time time NULL,
    ADD COLUMN IF NOT EXISTS location text NULL,
    ADD COLUMN IF NOT EXISTS image_path text NULL,
    ADD COLUMN IF NOT EXISTS updated_at timestamptz DEFAULT now();

-- Backfill updated_at where missing
UPDATE announcements SET updated_at = posted_at WHERE updated_at IS NULL;
