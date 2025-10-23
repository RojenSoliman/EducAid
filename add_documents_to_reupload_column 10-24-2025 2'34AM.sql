-- Migration: Add documents_to_reupload column to students table
-- This column stores a JSON array of document type codes that need to be re-uploaded
-- Used for selective document rejection instead of requiring all documents to be re-uploaded

-- Add the column if it doesn't exist
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'students' 
        AND column_name = 'documents_to_reupload'
    ) THEN
        ALTER TABLE students 
        ADD COLUMN documents_to_reupload TEXT;
        
        COMMENT ON COLUMN students.documents_to_reupload IS 'JSON array of document type codes that need to be re-uploaded after rejection';
        
        RAISE NOTICE 'Added documents_to_reupload column to students table';
    ELSE
        RAISE NOTICE 'Column documents_to_reupload already exists';
    END IF;
END $$;

-- Example values for documents_to_reupload:
-- NULL or empty: All documents are approved
-- '["00","01"]': Student needs to re-upload EAF and Grades only
-- '["02","03","04"]': Student needs to re-upload Letter, Certificate, and ID Picture

-- Document Type Codes Reference:
-- '00' = EAF (Enrollment Assistance Form)
-- '01' = Academic Grades
-- '02' = Letter to Mayor
-- '03' = Certificate of Indigency
-- '04' = ID Picture
