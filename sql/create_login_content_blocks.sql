-- Dedicated storage for Unified Login page editable blocks
CREATE TABLE IF NOT EXISTS login_content_blocks (
    id SERIAL PRIMARY KEY,
    municipality_id INT NOT NULL DEFAULT 1,
    block_key TEXT NOT NULL,
    html TEXT NOT NULL,
    text_color VARCHAR(20) DEFAULT NULL,
    bg_color VARCHAR(20) DEFAULT NULL,
    is_visible BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (municipality_id, block_key)
);

CREATE INDEX IF NOT EXISTS idx_login_content_blocks_muni_key
    ON login_content_blocks (municipality_id, block_key);
