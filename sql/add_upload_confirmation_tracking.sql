-- Add upload confirmation tracking to students table
-- This allows tracking when students confirm their uploads and prevents resubmission

ALTER TABLE students 
ADD COLUMN IF NOT EXISTS uploads_confirmed BOOLEAN DEFAULT FALSE;

ALTER TABLE students 
ADD COLUMN IF NOT EXISTS uploads_confirmed_at TIMESTAMP DEFAULT NULL;

ALTER TABLE students 
ADD COLUMN IF NOT EXISTS uploads_reset_by INTEGER REFERENCES admins(admin_id);

ALTER TABLE students 
ADD COLUMN IF NOT EXISTS uploads_reset_at TIMESTAMP DEFAULT NULL;

ALTER TABLE students 
ADD COLUMN IF NOT EXISTS uploads_reset_reason TEXT;

-- Add comment explaining the columns
COMMENT ON COLUMN students.uploads_confirmed IS 'TRUE when student has confirmed all document uploads and locked submission';
COMMENT ON COLUMN students.uploads_confirmed_at IS 'Timestamp when student confirmed their uploads';
COMMENT ON COLUMN students.uploads_reset_by IS 'Admin ID who reset the uploads to allow resubmission';
COMMENT ON COLUMN students.uploads_reset_at IS 'Timestamp when admin reset the uploads';
COMMENT ON COLUMN students.uploads_reset_reason IS 'Reason provided by admin for resetting uploads';
