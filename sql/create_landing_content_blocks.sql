-- Landing Page Editable Content Blocks
-- Stores inline edited content fragments keyed by a derived selector key.
CREATE TABLE IF NOT EXISTS landing_content_blocks (
  id SERIAL PRIMARY KEY,
  municipality_id INT NOT NULL DEFAULT 1,
  block_key TEXT NOT NULL,
  html TEXT NOT NULL,
  text_color VARCHAR(20) DEFAULT NULL,
  bg_color VARCHAR(20) DEFAULT NULL,
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW(),
  UNIQUE (municipality_id, block_key)
);

-- Optional index for faster retrieval if expanded
CREATE INDEX IF NOT EXISTS idx_landing_content_blocks_muni_key ON landing_content_blocks (municipality_id, block_key);
