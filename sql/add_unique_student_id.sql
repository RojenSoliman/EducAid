-- ===============================================
-- ADD UNIQUE STUDENT IDENTIFIER
-- Format: currentyear-yearlevel-****** (6 random digits)
-- ===============================================

-- Add unique student identifier column to students table
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS unique_student_id TEXT UNIQUE;

-- Create index for faster lookups
CREATE INDEX IF NOT EXISTS idx_students_unique_id ON students(unique_student_id);
