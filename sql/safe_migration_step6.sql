-- ===========================================
-- SAFE MIGRATION STEP 6: Final cleanup and function update
-- ===========================================

-- Recreate indexes
DROP INDEX IF EXISTS idx_students_unique_id;
CREATE INDEX IF NOT EXISTS idx_students_confidence_score ON students(confidence_score DESC);
CREATE INDEX IF NOT EXISTS idx_grade_uploads_student ON grade_uploads(student_id);
CREATE INDEX IF NOT EXISTS idx_students_last_login ON students(last_login);

-- Update the confidence calculation function
CREATE OR REPLACE FUNCTION calculate_confidence_score(student_id_param TEXT) 
RETURNS DECIMAL(5,2) AS $$
DECLARE
    score DECIMAL(5,2) := 0.00;
    doc_count INT := 0;
    total_docs INT := 0;
    avg_ocr_confidence DECIMAL(5,2) := 0.00;
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
    
    -- Document upload score (40 points)
    SELECT COUNT(*) INTO doc_count
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.type IN ('eaf', 'certificate_of_indigency', 'letter_to_mayor', 'id_picture');
    
    -- Also check enrollment_forms table
    SELECT COUNT(*) INTO total_docs
    FROM enrollment_forms ef
    WHERE ef.student_id = student_id_param;
    
    doc_count := doc_count + total_docs;
    score := score + LEAST(doc_count * 10.00, 40.00);
    
    -- OCR confidence score (20 points)
    SELECT COALESCE(AVG(ocr_confidence), 0.00) INTO avg_ocr_confidence
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.ocr_confidence > 0;
    
    score := score + (avg_ocr_confidence * 0.20);
    
    -- Email verification bonus (10 points)
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

-- Update statistics
ANALYZE students;
ANALYZE applications;
ANALYZE documents;
ANALYZE enrollment_forms;
ANALYZE distributions;
ANALYZE qr_logs;
ANALYZE schedules;
ANALYZE grade_uploads;
ANALYZE notifications;

-- Final verification
SELECT 'Migration completed successfully!' as status;
SELECT 'Students count:' as info, COUNT(*) as value FROM students
UNION ALL
SELECT 'Sample student_id format:', student_id FROM students LIMIT 1;