-- ===========================================
-- ROLLBACK SCRIPT: Restore student_id as auto-incrementing primary key
-- ===========================================
-- This script rolls back the migration from unique_student_id to auto-incrementing student_id
-- WARNING: Only run this if the migration failed and you need to restore the original structure
-- This will restore the auto-incrementing behavior but may lose the uniqueness of custom IDs

BEGIN;

-- Step 1: Create temporary mapping for rollback
CREATE TEMP TABLE rollback_mapping AS
SELECT 
    student_id as unique_student_id,
    ROW_NUMBER() OVER (ORDER BY created_at) as new_student_id
FROM students;

-- Step 2: Add new auto-incrementing primary key column to students table
ALTER TABLE students DROP CONSTRAINT students_pkey;
ALTER TABLE students ADD COLUMN new_student_id SERIAL;
ALTER TABLE students ADD COLUMN old_unique_student_id TEXT;

-- Update with mapped values
UPDATE students SET 
    old_unique_student_id = student_id,
    new_student_id = (SELECT new_student_id FROM rollback_mapping WHERE rollback_mapping.unique_student_id = students.student_id);

-- Step 3: Drop foreign key constraints
ALTER TABLE applications DROP CONSTRAINT IF EXISTS applications_student_id_fkey;
ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_student_id_fkey;
ALTER TABLE enrollment_forms DROP CONSTRAINT IF EXISTS enrollment_forms_student_id_fkey;
ALTER TABLE distributions DROP CONSTRAINT IF EXISTS distributions_student_id_fkey;
ALTER TABLE qr_logs DROP CONSTRAINT IF EXISTS qr_logs_student_id_fkey;
ALTER TABLE schedules DROP CONSTRAINT IF EXISTS schedules_student_id_fkey;
ALTER TABLE grade_uploads DROP CONSTRAINT IF EXISTS grade_uploads_student_id_fkey;
ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_student_id_fkey;
ALTER TABLE qr_codes DROP CONSTRAINT IF EXISTS qr_codes_student_id_fkey;

-- Step 4: Update foreign key columns in related tables
UPDATE applications SET student_id = (
    SELECT new_student_id FROM students WHERE students.old_unique_student_id = applications.student_id
);

UPDATE documents SET student_id = (
    SELECT new_student_id FROM students WHERE students.old_unique_student_id = documents.student_id
);

UPDATE enrollment_forms SET student_id = (
    SELECT new_student_id FROM students WHERE students.old_unique_student_id = enrollment_forms.student_id
);

UPDATE distributions SET student_id = (
    SELECT new_student_id FROM students WHERE students.old_unique_student_id = distributions.student_id
);

UPDATE qr_logs SET student_id = (
    SELECT new_student_id FROM students WHERE students.old_unique_student_id = qr_logs.student_id
);

UPDATE schedules SET student_id = (
    SELECT new_student_id FROM students WHERE students.old_unique_student_id = schedules.student_id
);

UPDATE grade_uploads SET student_id = (
    SELECT new_student_id FROM students WHERE students.old_unique_student_id = grade_uploads.student_id
);

UPDATE notifications SET student_id = (
    SELECT new_student_id FROM students WHERE students.old_unique_student_id = notifications.student_id
);

UPDATE qr_codes SET student_id = (
    SELECT new_student_id FROM students WHERE students.old_unique_student_id = qr_codes.student_id
);

-- Step 5: Change foreign key columns back to INTEGER type
ALTER TABLE applications ALTER COLUMN student_id TYPE INTEGER USING student_id::INTEGER;
ALTER TABLE documents ALTER COLUMN student_id TYPE INTEGER USING student_id::INTEGER;
ALTER TABLE enrollment_forms ALTER COLUMN student_id TYPE INTEGER USING student_id::INTEGER;
ALTER TABLE distributions ALTER COLUMN student_id TYPE INTEGER USING student_id::INTEGER;
ALTER TABLE qr_logs ALTER COLUMN student_id TYPE INTEGER USING student_id::INTEGER;
ALTER TABLE schedules ALTER COLUMN student_id TYPE INTEGER USING student_id::INTEGER;
ALTER TABLE grade_uploads ALTER COLUMN student_id TYPE INTEGER USING student_id::INTEGER;
ALTER TABLE notifications ALTER COLUMN student_id TYPE INTEGER USING student_id::INTEGER;
ALTER TABLE qr_codes ALTER COLUMN student_id TYPE INTEGER USING student_id::INTEGER;

-- Step 6: Update students table structure
ALTER TABLE students DROP COLUMN student_id;
ALTER TABLE students RENAME COLUMN new_student_id TO student_id;
ALTER TABLE students RENAME COLUMN old_unique_student_id TO unique_student_id;
ALTER TABLE students ADD PRIMARY KEY (student_id);

-- Step 7: Restore foreign key constraints
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

ALTER TABLE qr_codes ADD CONSTRAINT qr_codes_student_id_fkey 
    FOREIGN KEY (student_id) REFERENCES students(student_id);

-- Step 8: Restore original indexes
CREATE UNIQUE INDEX idx_students_unique_id ON students(unique_student_id);
CREATE INDEX idx_students_confidence_score ON students(confidence_score DESC);

-- Step 9: Restore original confidence calculation function
CREATE OR REPLACE FUNCTION calculate_confidence_score(student_id_param INTEGER) 
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

-- Verify rollback
-- SELECT 'Students count after rollback:', COUNT(*) FROM students;
-- SELECT 'Sample student_id (should be integers):', student_id FROM students LIMIT 5;

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