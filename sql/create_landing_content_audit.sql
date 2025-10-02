-- Audit log for inline landing page edits
CREATE TABLE IF NOT EXISTS landing_content_audit (
  audit_id BIGSERIAL PRIMARY KEY,
  municipality_id INT NOT NULL DEFAULT 1,
  block_key TEXT NOT NULL,
  admin_id INT NOT NULL,
  admin_username TEXT NULL,
  action_type VARCHAR(20) NOT NULL, -- update | reset_all | delete | reset_block
  old_html TEXT NULL,
  new_html TEXT NULL,
  old_text_color VARCHAR(20) NULL,
  new_text_color VARCHAR(20) NULL,
  old_bg_color VARCHAR(20) NULL,
  new_bg_color VARCHAR(20) NULL,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_landing_content_audit_muni_key ON landing_content_audit (municipality_id, block_key);
