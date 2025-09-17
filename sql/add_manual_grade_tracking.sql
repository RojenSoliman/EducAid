-- Add grading system tracking and manual entry flag to grade uploads table

-- Add column to track which grading system was used
ALTER TABLE grade_uploads 
ADD COLUMN IF NOT EXISTS grading_system_used VARCHAR(20) DEFAULT 'unknown';

-- Add column to extracted_grades to track manual entries
ALTER TABLE extracted_grades 
ADD COLUMN IF NOT EXISTS manual_entry BOOLEAN DEFAULT FALSE;

-- Update any existing records
UPDATE grade_uploads SET grading_system_used = 'auto_detected' WHERE grading_system_used = 'unknown';

-- Add helpful comments
COMMENT ON COLUMN grade_uploads.grading_system_used IS 'The grading system used: gpa, percentage, letter, auto_detected, or unknown';
COMMENT ON COLUMN extracted_grades.manual_entry IS 'TRUE if this grade was manually entered by an admin, FALSE if extracted via OCR';