-- Header theme settings table
CREATE TABLE IF NOT EXISTS header_theme_settings (
  header_theme_id SERIAL PRIMARY KEY,
  municipality_id INT NOT NULL UNIQUE,
  header_bg_color VARCHAR(7) NOT NULL DEFAULT '#ffffff',
  header_border_color VARCHAR(7) NOT NULL DEFAULT '#e1e7e3',
  header_text_color VARCHAR(7) NOT NULL DEFAULT '#2e7d32',
  header_icon_color VARCHAR(7) NOT NULL DEFAULT '#2e7d32',
  header_hover_bg VARCHAR(7) NOT NULL DEFAULT '#e9f5e9',
  header_hover_icon_color VARCHAR(7) NOT NULL DEFAULT '#1b5e20',
  updated_by INT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO header_theme_settings (municipality_id)
SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM header_theme_settings WHERE municipality_id=1);
