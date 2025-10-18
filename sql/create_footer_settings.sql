-- Basic footer theme table
CREATE TABLE IF NOT EXISTS footer_settings (
    footer_id        SERIAL PRIMARY KEY,
    municipality_id  INTEGER NOT NULL DEFAULT 1,
    footer_bg_color  VARCHAR(7)  NOT NULL DEFAULT '#1e3a8a',
    footer_text_color VARCHAR(7) NOT NULL DEFAULT '#cbd5e1',
    footer_heading_color VARCHAR(7) NOT NULL DEFAULT '#ffffff',
    footer_link_color VARCHAR(7) NOT NULL DEFAULT '#e2e8f0',
    footer_link_hover_color VARCHAR(7) NOT NULL DEFAULT '#fbbf24',
    footer_divider_color VARCHAR(7) NOT NULL DEFAULT '#fbbf24',
    footer_title     VARCHAR(100) NOT NULL DEFAULT 'EducAid',
    footer_description TEXT DEFAULT 'Making education accessible throughout General Trias City through innovative scholarship solutions.',
    contact_address  TEXT DEFAULT 'General Trias City Hall, Cavite',
    contact_phone    VARCHAR(50)  DEFAULT '+63 (046) 123-4567',
    contact_email    VARCHAR(100) DEFAULT 'info@educaid-gentrias.gov.ph',
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Optional default row (run separately if you prefer)
INSERT INTO footer_settings (municipality_id)
SELECT 1
WHERE NOT EXISTS (
    SELECT 1 FROM footer_settings WHERE municipality_id = 1 AND is_active = TRUE
);

