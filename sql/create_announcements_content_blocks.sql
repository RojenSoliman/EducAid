-- Announcements Page Editable Content Blocks
CREATE TABLE IF NOT EXISTS announcements_content_blocks (
  id SERIAL PRIMARY KEY,
  municipality_id INT NOT NULL DEFAULT 1,
  block_key TEXT NOT NULL,
  html TEXT NOT NULL,
  text_color VARCHAR(20) DEFAULT NULL,
  bg_color VARCHAR(20) DEFAULT NULL,
  updated_at TIMESTAMPTZ DEFAULT NOW(),
  UNIQUE (municipality_id, block_key)
);

CREATE INDEX IF NOT EXISTS idx_announcements_content_blocks_muni_key ON announcements_content_blocks (municipality_id, block_key);
