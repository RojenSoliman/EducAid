-- Idempotent create (no DROP; safe if rerun, avoids abort if dependent objects exist)
CREATE TABLE IF NOT EXISTS sidebar_theme_settings (
    id SERIAL PRIMARY KEY,
    municipality_id INTEGER NOT NULL DEFAULT 1,
    sidebar_bg_start VARCHAR(7) DEFAULT '#f8f9fa',
    sidebar_bg_end VARCHAR(7) DEFAULT '#ffffff',
    sidebar_border_color VARCHAR(7) DEFAULT '#dee2e6',
    nav_text_color VARCHAR(7) DEFAULT '#212529',
    nav_icon_color VARCHAR(7) DEFAULT '#6c757d',
    nav_hover_bg VARCHAR(7) DEFAULT '#e9ecef',
    nav_hover_text VARCHAR(7) DEFAULT '#212529',
    nav_active_bg VARCHAR(7) DEFAULT '#0d6efd',
    nav_active_text VARCHAR(7) DEFAULT '#ffffff',
    profile_avatar_bg_start VARCHAR(7) DEFAULT '#0d6efd',
    profile_avatar_bg_end VARCHAR(7) DEFAULT '#0b5ed7',
    profile_name_color VARCHAR(7) DEFAULT '#212529',
    profile_role_color VARCHAR(7) DEFAULT '#6c757d',
    profile_border_color VARCHAR(7) DEFAULT '#dee2e6',
    submenu_bg VARCHAR(7) DEFAULT '#f8f9fa',
    submenu_text_color VARCHAR(7) DEFAULT '#495057',
    submenu_hover_bg VARCHAR(7) DEFAULT '#e9ecef',
    submenu_active_bg VARCHAR(7) DEFAULT '#e7f3ff',
    submenu_active_text VARCHAR(7) DEFAULT '#0d6efd',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uniq_sidebar_muni UNIQUE (municipality_id)
);

-- Pre-9.5 safe seed (no ON CONFLICT). Will only insert if absent.
INSERT INTO sidebar_theme_settings (municipality_id)
SELECT 1
WHERE NOT EXISTS (
    SELECT 1 FROM sidebar_theme_settings WHERE municipality_id = 1
);