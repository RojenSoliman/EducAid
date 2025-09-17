-- Add confidence scoring system to EducAid
-- This SQL script adds confidence scoring functionality to help admins prioritize reviews

-- Add confidence score column to students table
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS confidence_score DECIMAL(5,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS confidence_notes TEXT;

-- Add confidence score to documents table for individual document scoring
ALTER TABLE documents 
ADD COLUMN IF NOT EXISTS ocr_confidence DECIMAL(5,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS validation_confidence DECIMAL(5,2) DEFAULT 0.00;

-- Create index for faster confidence score queries
CREATE INDEX IF NOT EXISTS idx_students_confidence_score ON students(confidence_score DESC);

-- Create function to calculate confidence score
CREATE OR REPLACE FUNCTION calculate_confidence_score(student_id_param INT) 
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

-- Create function to get confidence level text
CREATE OR REPLACE FUNCTION get_confidence_level(score DECIMAL(5,2)) 
RETURNS TEXT AS $$
BEGIN
    IF score >= 85.00 THEN
        RETURN 'Very High';
    ELSIF score >= 70.00 THEN
        RETURN 'High';
    ELSIF score >= 50.00 THEN
        RETURN 'Medium';
    ELSIF score >= 30.00 THEN
        RETURN 'Low';
    ELSE
        RETURN 'Very Low';
    END IF;
END;
$$ LANGUAGE plpgsql;

-- Update existing students with confidence scores
UPDATE students 
SET confidence_score = calculate_confidence_score(student_id)
WHERE status = 'under_registration';

COMMENT ON COLUMN students.confidence_score IS 'Confidence score (0-100) based on data completeness, document quality, and validation results';
COMMENT ON COLUMN students.confidence_notes IS 'Notes about confidence score calculation';
COMMENT ON COLUMN documents.ocr_confidence IS 'OCR processing confidence score for document readability';
COMMENT ON COLUMN documents.validation_confidence IS 'Manual validation confidence score';