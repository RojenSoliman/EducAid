-- Simple Theme Settings Table - Starting with Topbar Text Only
CREATE TABLE IF NOT EXISTS theme_settings (
    theme_id SERIAL PRIMARY KEY,
    municipality_id INTEGER DEFAULT 1,  
    topbar_email VARCHAR(100) DEFAULT 'educaid@generaltrias.gov.ph',
    topbar_phone VARCHAR(50) DEFAULT '(046) 886-4454',
    topbar_office_hours VARCHAR(100) DEFAULT 'Mon–Fri 8:00AM - 5:00PM',
    system_name VARCHAR(100) DEFAULT 'EducAid',
    municipality_name VARCHAR(100) DEFAULT 'City of General Trias',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER,
    UNIQUE(municipality_id)
);

INSERT INTO theme_settings (municipality_id) 
VALUES (1)
ON CONFLICT (municipality_id) DO NOTHING;
