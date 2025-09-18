-- ===========================================
-- MIGRATION: Replace student_id with unique_student_id
-- ===========================================
-- This script migrates from auto-incrementing student_id to unique_student_id as primary key
-- WARNING: This is a major schema change. Backup your database before running!

BEGIN;

-- Step 1: Ensure all students have unique_student_id values
-- Generate unique_student_id for any students that don't have one
DO $$
DECLARE
    student_record RECORD;
    new_unique_id TEXT;
BEGIN
    FOR student_record IN 
        SELECT student_id FROM students WHERE unique_student_id IS NULL
    LOOP
        -- Generate a unique ID (format: EDU-YYYY-XXXXXX where XXXXXX is zero-padded student_id)
        new_unique_id := 'EDU-' || EXTRACT(YEAR FROM CURRENT_DATE) || '-' || LPAD(student_record.student_id::TEXT, 6, '0');
        
        -- Ensure uniqueness by adding suffix if needed
        WHILE EXISTS (SELECT 1 FROM students WHERE unique_student_id = new_unique_id) LOOP
            new_unique_id := new_unique_id || '-' || floor(random() * 1000)::TEXT;
        END LOOP;
        
        UPDATE students SET unique_student_id = new_unique_id WHERE student_id = student_record.student_id;
    END LOOP;
END $$;

-- Step 2: Create temporary mapping table for the migration
CREATE TEMP TABLE student_id_mapping AS
SELECT student_id, unique_student_id FROM students;

-- Step 3: Add new foreign key columns (TEXT type) to all related tables
ALTER TABLE applications ADD COLUMN new_student_id TEXT;
ALTER TABLE documents ADD COLUMN new_student_id TEXT;
ALTER TABLE enrollment_forms ADD COLUMN new_student_id TEXT;
ALTER TABLE distributions ADD COLUMN new_student_id TEXT;
ALTER TABLE qr_logs ADD COLUMN new_student_id TEXT;
ALTER TABLE schedules ADD COLUMN new_student_id TEXT;
ALTER TABLE grade_uploads ADD COLUMN new_student_id TEXT;
ALTER TABLE notifications ADD COLUMN new_student_id TEXT;

-- Step 4: Populate new foreign key columns using the mapping
UPDATE applications SET new_student_id = (
    SELECT unique_student_id FROM student_id_mapping 
    WHERE student_id_mapping.student_id = applications.student_id
);

UPDATE documents SET new_student_id = (
    SELECT unique_student_id FROM student_id_mapping 
    WHERE student_id_mapping.student_id = documents.student_id
);

UPDATE enrollment_forms SET new_student_id = (
    SELECT unique_student_id FROM student_id_mapping 
    WHERE student_id_mapping.student_id = enrollment_forms.student_id
);

UPDATE distributions SET new_student_id = (
    SELECT unique_student_id FROM student_id_mapping 
    WHERE student_id_mapping.student_id = distributions.student_id
);

UPDATE qr_logs SET new_student_id = (
    SELECT unique_student_id FROM student_id_mapping 
    WHERE student_id_mapping.student_id = qr_logs.student_id
);

UPDATE schedules SET new_student_id = (
    SELECT unique_student_id FROM student_id_mapping 
    WHERE student_id_mapping.student_id = schedules.student_id
);

UPDATE grade_uploads SET new_student_id = (
    SELECT unique_student_id FROM student_id_mapping 
    WHERE student_id_mapping.student_id = grade_uploads.student_id
);

UPDATE notifications SET new_student_id = (
    SELECT unique_student_id FROM student_id_mapping 
    WHERE student_id_mapping.student_id = notifications.student_id
);

-- Step 5: Drop foreign key constraints
ALTER TABLE applications DROP CONSTRAINT IF EXISTS applications_student_id_fkey;
ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_student_id_fkey;
ALTER TABLE enrollment_forms DROP CONSTRAINT IF EXISTS enrollment_forms_student_id_fkey;
ALTER TABLE distributions DROP CONSTRAINT IF EXISTS distributions_student_id_fkey;
ALTER TABLE qr_logs DROP CONSTRAINT IF EXISTS qr_logs_student_id_fkey;
ALTER TABLE schedules DROP CONSTRAINT IF EXISTS schedules_student_id_fkey;
ALTER TABLE grade_uploads DROP CONSTRAINT IF EXISTS grade_uploads_student_id_fkey;
ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_student_id_fkey;

-- Step 6: Drop old foreign key columns
ALTER TABLE applications DROP COLUMN student_id;
ALTER TABLE documents DROP COLUMN student_id;
ALTER TABLE enrollment_forms DROP COLUMN student_id;
ALTER TABLE distributions DROP COLUMN student_id;
ALTER TABLE qr_logs DROP COLUMN student_id;
ALTER TABLE schedules DROP COLUMN student_id;
ALTER TABLE grade_uploads DROP COLUMN student_id;
ALTER TABLE notifications DROP COLUMN student_id;

-- Step 7: Rename new columns to original names
ALTER TABLE applications RENAME COLUMN new_student_id TO student_id;
ALTER TABLE documents RENAME COLUMN new_student_id TO student_id;
ALTER TABLE enrollment_forms RENAME COLUMN new_student_id TO student_id;
ALTER TABLE distributions RENAME COLUMN new_student_id TO student_id;
ALTER TABLE qr_logs RENAME COLUMN new_student_id TO student_id;
ALTER TABLE schedules RENAME COLUMN new_student_id TO student_id;
ALTER TABLE grade_uploads RENAME COLUMN new_student_id TO student_id;
ALTER TABLE notifications RENAME COLUMN new_student_id TO student_id;

-- Step 8: Update students table - drop old primary key and make unique_student_id the new primary key
ALTER TABLE students DROP CONSTRAINT students_pkey;
ALTER TABLE students DROP COLUMN student_id;
ALTER TABLE students RENAME COLUMN unique_student_id TO student_id;
ALTER TABLE students ADD PRIMARY KEY (student_id);

-- Step 9: Add foreign key constraints with new TEXT primary key
ALTER TABLE applications ADD CONSTRAINT applications_student_id_fkey 
    FOREIGN KEY (student_id) REFERENCES students(student_id);

ALTER TABLE documents ADD CONSTRAINT documents_student_id_fkey 
    FOREIGN KEY (student_id) REFERENCES students(student_id);

ALTER TABLE enrollment_forms ADD CONSTRAINT enrollment_forms_student_id_fkey 
    FOREIGN KEY (student_id) REFERENCES students(student_id);

ALTER TABLE distributions ADD CONSTRAINT distributions_student_id_fkey 
    FOREIGN KEY (student_id) REFERENCES students(student_id);

ALTER TABLE qr_logs ADD CONSTRAINT qr_logs_student_id_fkey 
    FOREIGN KEY (student_id) REFERENCES students(student_id);

ALTER TABLE schedules ADD CONSTRAINT schedules_student_id_fkey 
    FOREIGN KEY (student_id) REFERENCES students(student_id);

ALTER TABLE grade_uploads ADD CONSTRAINT grade_uploads_student_id_fkey 
    FOREIGN KEY (student_id) REFERENCES students(student_id);

ALTER TABLE notifications ADD CONSTRAINT notifications_student_id_fkey 
    FOREIGN KEY (student_id) REFERENCES students(student_id);

-- Step 10: Handle qr_codes table (it references student_unique_id which is now student_id)
ALTER TABLE qr_codes DROP CONSTRAINT IF EXISTS qr_codes_student_unique_id_fkey;
ALTER TABLE qr_codes RENAME COLUMN student_unique_id TO student_id;
ALTER TABLE qr_codes ADD CONSTRAINT qr_codes_student_id_fkey 
    FOREIGN KEY (student_id) REFERENCES students(student_id);

-- Step 11: Recreate indexes
DROP INDEX IF EXISTS idx_students_unique_id;
CREATE INDEX idx_students_confidence_score ON students(confidence_score DESC);
CREATE INDEX idx_grade_uploads_student ON grade_uploads(student_id);
CREATE INDEX idx_extracted_grades_upload ON extracted_grades(upload_id);
CREATE INDEX idx_students_last_login ON students(last_login);

-- Step 12: Update the confidence calculation function to work with TEXT student_id
CREATE OR REPLACE FUNCTION calculate_confidence_score(student_id_param TEXT) 
RETURNS DECIMAL(5,2) AS $$
DECLARE
    score DECIMAL(5,2) := 0.00;
    doc_count INT := 0;
    total_docs INT := 0;
    avg_ocr_confidence DECIMAL(5,2) := 0.00;
    has_all_required_fields BOOLEAN := TRUE;
    temp_score DECIMAL(5,2);
BEGIN
    -- Base score for having all required personal information (30 points)
    SELECT 
        CASE WHEN first_name IS NOT NULL AND first_name != '' 
             AND last_name IS NOT NULL AND last_name != ''
             AND email IS NOT NULL AND email != ''
             AND mobile IS NOT NULL AND mobile != ''
             AND bdate IS NOT NULL
             AND sex IS NOT NULL
             AND barangay_id IS NOT NULL
             AND university_id IS NOT NULL
             AND year_level_id IS NOT NULL
        THEN 30.00 ELSE 0.00 END
    INTO temp_score
    FROM students 
    WHERE student_id = student_id_param;
    
    score := score + temp_score;
    
    -- Document upload score (40 points - 10 points per required document)
    -- Check for enrollment form, certificate of indigency, letter to mayor, ID picture
    SELECT COUNT(*) INTO doc_count
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.type IN ('eaf', 'certificate_of_indigency', 'letter_to_mayor', 'id_picture');
    
    -- Also check enrollment_forms table for EAF
    SELECT COUNT(*) INTO total_docs
    FROM enrollment_forms ef
    WHERE ef.student_id = student_id_param;
    
    -- Add enrollment form to document count
    doc_count := doc_count + total_docs;
    
    -- Score based on number of documents (max 40 points)
    score := score + LEAST(doc_count * 10.00, 40.00);
    
    -- OCR confidence score from documents (20 points)
    SELECT COALESCE(AVG(ocr_confidence), 0.00) INTO avg_ocr_confidence
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.ocr_confidence > 0;
    
    score := score + (avg_ocr_confidence * 0.20); -- Convert to 20 point scale
    
    -- Email verification bonus (10 points)
    -- Assuming if they completed registration, email was verified
    SELECT 
        CASE WHEN status != 'under_registration' THEN 10.00 ELSE 0.00 END
    INTO temp_score
    FROM students 
    WHERE student_id = student_id_param;
    
    score := score + temp_score;
    
    -- Ensure score is between 0 and 100
    score := GREATEST(0.00, LEAST(100.00, score));
    
    RETURN score;
END;
$$ LANGUAGE plpgsql;

COMMIT;

-- Verification queries to run after migration
-- SELECT 'Students count:', COUNT(*) FROM students;
-- SELECT 'Applications with valid student_id:', COUNT(*) FROM applications a JOIN students s ON a.student_id = s.student_id;
-- SELECT 'Documents with valid student_id:', COUNT(*) FROM documents d JOIN students s ON d.student_id = s.student_id;
-- SELECT 'Sample student_id format:', student_id FROM students LIMIT 5;

ANALYZE students;
ANALYZE applications;
ANALYZE documents;
ANALYZE enrollment_forms;
ANALYZE distributions;
ANALYZE qr_logs;
ANALYZE schedules;
ANALYZE grade_uploads;
ANALYZE notifications;
ANALYZE qr_codes;