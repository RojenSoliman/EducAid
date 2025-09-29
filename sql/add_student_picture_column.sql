-- Add student_picture column to students table
-- This column will store the file path to the student's profile picture

ALTER TABLE students ADD COLUMN student_picture TEXT;

-- Add a comment to document the column
COMMENT ON COLUMN students.student_picture IS 'File path to the student profile picture (relative path from web root)';

-- Optional: You can run this to verify the column was added
-- SELECT column_name, data_type, is_nullable 
-- FROM information_schema.columns 
-- WHERE table_name = 'students' AND column_name = 'student_picture';