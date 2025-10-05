-- Populate contact_content_blocks with default data
-- Run this in pgAdmin or psql

-- Clear any existing data first (optional - remove if you want to keep existing data)
-- DELETE FROM contact_content_blocks WHERE municipality_id = 1;

-- Insert default content blocks for municipality_id = 1
INSERT INTO contact_content_blocks (municipality_id, block_key, html) VALUES
(1, 'hero_title', 'Contact'),
(1, 'hero_subtitle', 'We''re here to assist with application issues, document submission, schedules, QR release, and portal access concerns.'),
(1, 'visit_title', 'Visit Us'),
(1, 'visit_address', 'City Government of General Trias, Cavite'),
(1, 'visit_hours', 'Mon–Fri • 8:00 AM – 5:00 PM<br/>(excluding holidays)'),
(1, 'call_title', 'Call Us'),
(1, 'call_primary', '(046) 886-4454'),
(1, 'call_secondary', '(046) 509-5555 (Operator)'),
(1, 'email_title', 'Email Us'),
(1, 'email_primary', 'educaid@generaltrias.gov.ph'),
(1, 'email_secondary', 'support@ (coming soon)'),
(1, 'form_title', 'Send an Inquiry'),
(1, 'form_subtitle', 'Have a question? Fill out the form below and we''ll get back to you.'),
(1, 'help_title', 'Before You Contact'),
(1, 'help_intro', 'Many common questions can be answered quickly through our self-help resources:'),
(1, 'response_time_title', 'Response Time'),
(1, 'response_time_text', 'We aim to respond to inquiries within 1-2 business days during office hours (Mon-Fri, 8:00 AM - 5:00 PM).'),
(1, 'offices_title', 'Program Offices'),
(1, 'topics_title', 'Common Topics');

-- Verify the inserts
SELECT COUNT(*) as total_blocks FROM contact_content_blocks WHERE municipality_id = 1;
