-- Contact Page Content Blocks
-- Allows inline editing of all contact page content by Super Admin

-- Create table for contact content blocks
CREATE TABLE IF NOT EXISTS contact_content_blocks (
    id SERIAL PRIMARY KEY,
    municipality_id INT NOT NULL DEFAULT 1,
    block_key VARCHAR(100) NOT NULL,
    html TEXT NOT NULL DEFAULT '',
    text_color VARCHAR(20) DEFAULT NULL,
    bg_color VARCHAR(20) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INT REFERENCES admins(id) ON DELETE SET NULL,
    UNIQUE(municipality_id, block_key)
);

-- Create audit table for tracking changes
CREATE TABLE IF NOT EXISTS contact_content_audit (
    audit_id SERIAL PRIMARY KEY,
    municipality_id INT NOT NULL DEFAULT 1,
    block_key VARCHAR(100) NOT NULL,
    html TEXT,
    text_color VARCHAR(20),
    bg_color VARCHAR(20),
    action_type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT REFERENCES admins(id) ON DELETE SET NULL
);

-- Insert default content blocks for municipality_id = 1
INSERT INTO contact_content_blocks (municipality_id, block_key, html) VALUES
-- Hero Section
(1, 'hero_title', 'Contact'),
(1, 'hero_subtitle', 'We''re here to assist with application issues, document submission, schedules, QR release, and portal access concerns.'),

-- Contact Cards
(1, 'visit_title', 'Visit Us'),
(1, 'visit_address', 'City Government of General Trias, Cavite'),
(1, 'visit_hours', 'Mon–Fri • 8:00 AM – 5:00 PM<br/>(excluding holidays)'),

(1, 'call_title', 'Call Us'),
(1, 'call_primary', '(046) 886-4454'),
(1, 'call_secondary', '(046) 509-5555 (Operator)'),

(1, 'email_title', 'Email Us'),
(1, 'email_primary', 'educaid@generaltrias.gov.ph'),
(1, 'email_secondary', 'support@ (coming soon)'),

-- Form Section
(1, 'form_title', 'Send an Inquiry'),
(1, 'form_subtitle', 'Have a question? Fill out the form below and we''ll get back to you.'),

-- Help Section
(1, 'help_title', 'Before You Contact'),
(1, 'help_intro', 'Many common questions can be answered quickly through our self-help resources:'),

-- Response Time
(1, 'response_time_title', 'Response Time'),
(1, 'response_time_text', 'We aim to respond to inquiries within 1-2 business days during office hours (Mon-Fri, 8:00 AM - 5:00 PM).'),

-- Offices & Topics
(1, 'offices_title', 'Program Offices'),
(1, 'topics_title', 'Common Topics')

ON CONFLICT (municipality_id, block_key) DO NOTHING;

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_contact_blocks_muni ON contact_content_blocks(municipality_id);
CREATE INDEX IF NOT EXISTS idx_contact_blocks_key ON contact_content_blocks(block_key);
CREATE INDEX IF NOT EXISTS idx_contact_audit_muni ON contact_content_audit(municipality_id);
CREATE INDEX IF NOT EXISTS idx_contact_audit_key ON contact_content_audit(block_key);
CREATE INDEX IF NOT EXISTS idx_contact_audit_created ON contact_content_audit(created_at DESC);

-- Grant permissions (adjust as needed)
-- GRANT SELECT, INSERT, UPDATE ON contact_content_blocks TO your_app_user;
-- GRANT SELECT, INSERT ON contact_content_audit TO your_app_user;
