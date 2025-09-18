-- Migration: Add extension_name column to students table
-- Date: 2025-09-17
-- Description: Adds extension_name field for suffixes like Jr., Sr., I, II, III, etc.

ALTER TABLE students 
ADD COLUMN IF NOT EXISTS extension_name TEXT;

-- Add index for better performance if needed for searches
CREATE INDEX IF NOT EXISTS idx_students_extension_name ON students(extension_name);

-- Update any existing records if needed (optional - uncomment if you want to clean up existing data)
-- UPDATE students SET extension_name = NULL WHERE extension_name = '';