-- Add admin-specified semester and school year validation settings
-- Run this SQL to set up the new validation requirements

-- Insert/Update valid semester setting (example: "First Semester")
INSERT INTO config (key, value) VALUES ('valid_semester', 'First Semester')
ON CONFLICT (key) DO UPDATE SET value = 'First Semester';

-- Insert/Update valid school year setting (example: "2024-2025")
INSERT INTO config (key, value) VALUES ('valid_school_year', '2024-2025')
ON CONFLICT (key) DO UPDATE SET value = '2024-2025';

-- Query to view current settings
SELECT * FROM config WHERE key IN ('valid_semester', 'valid_school_year');

-- Examples of how to update these values:
-- UPDATE config SET value = 'Second Semester' WHERE key = 'valid_semester';
-- UPDATE config SET value = '2025-2026' WHERE key = 'valid_school_year';

/*
Valid Semester Options:
- First Semester
- Second Semester
- Summer
- Third Semester (for trimester systems)

Valid School Year Format:
- Use format: YYYY-YYYY (e.g., "2024-2025", "2023-2024")
- System will also match variations like "2024–2025", "2024/2025", etc.
*/