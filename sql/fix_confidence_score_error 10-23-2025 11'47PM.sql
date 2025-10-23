-- ==============================================
-- FIX: Confidence Score Calculation Error
-- Issue: Old function still references d.type column
-- ==============================================

-- Step 1: Force drop ALL versions of the function
DROP FUNCTION IF EXISTS calculate_confidence_score(INT);
DROP FUNCTION IF EXISTS calculate_confidence_score(INTEGER);
DROP FUNCTION IF EXISTS calculate_confidence_score(VARCHAR);
DROP FUNCTION IF EXISTS calculate_confidence_score(TEXT);

-- Step 2: Recreate with correct column names
CREATE OR REPLACE FUNCTION calculate_confidence_score(student_id_param VARCHAR) 
RETURNS DECIMAL(5,2) AS $$
DECLARE
    score DECIMAL(5,2) := 0.00;
    doc_count INT := 0;
    avg_ocr_confidence DECIMAL(5,2) := 0.00;
    avg_verification_score DECIMAL(5,2) := 0.00;
    verified_docs INT := 0;
    total_uploaded_docs INT := 0;
    temp_score DECIMAL(5,2);
BEGIN
    -- Personal Information (25 points)
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
        THEN 25.00 
        ELSE 
            (CASE WHEN first_name IS NOT NULL AND first_name != '' THEN 3.00 ELSE 0.00 END +
             CASE WHEN last_name IS NOT NULL AND last_name != '' THEN 3.00 ELSE 0.00 END +
             CASE WHEN email IS NOT NULL AND email != '' THEN 3.00 ELSE 0.00 END +
             CASE WHEN mobile IS NOT NULL AND mobile != '' THEN 3.00 ELSE 0.00 END +
             CASE WHEN bdate IS NOT NULL THEN 3.00 ELSE 0.00 END +
             CASE WHEN sex IS NOT NULL THEN 2.00 ELSE 0.00 END +
             CASE WHEN barangay_id IS NOT NULL THEN 2.00 ELSE 0.00 END +
             CASE WHEN university_id IS NOT NULL THEN 3.00 ELSE 0.00 END +
             CASE WHEN year_level_id IS NOT NULL THEN 3.00 ELSE 0.00 END)
        END
    INTO temp_score
    FROM students 
    WHERE student_id = student_id_param;
    
    score := score + COALESCE(temp_score, 0.00);
    
    -- Document Upload (35 points) - FIXED: Uses document_type_code
    SELECT COUNT(DISTINCT document_type_code) INTO doc_count
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.document_type_code IN ('00', '01', '02', '03', '04')
    AND d.status != 'rejected';
    
    score := score + (doc_count * 7.00);
    
    -- OCR Quality (20 points) - FIXED: No d.type reference
    SELECT 
        COALESCE(AVG(ocr_confidence), 0.00),
        COUNT(*) 
    INTO avg_ocr_confidence, total_uploaded_docs
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.ocr_confidence IS NOT NULL
    AND d.status != 'rejected';
    
    IF total_uploaded_docs > 0 THEN
        score := score + (avg_ocr_confidence * 0.20);
    END IF;
    
    -- Verification Status (15 points) - FIXED: No d.type reference
    SELECT 
        COALESCE(AVG(verification_score), 0.00),
        COUNT(CASE WHEN verification_status = 'passed' THEN 1 END)
    INTO avg_verification_score, verified_docs
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.verification_score IS NOT NULL
    AND d.status != 'rejected';
    
    IF total_uploaded_docs > 0 THEN
        score := score + (avg_verification_score * 0.10);
    END IF;
    
    score := score + LEAST(verified_docs * 1.00, 5.00);
    
    -- Email Verification (5 points)
    SELECT 
        CASE WHEN status IN ('applicant', 'active', 'on_hold') 
        THEN 5.00 ELSE 0.00 END
    INTO temp_score
    FROM students 
    WHERE student_id = student_id_param;
    
    score := score + COALESCE(temp_score, 0.00);
    
    score := GREATEST(0.00, LEAST(100.00, score));
    
    RETURN score;
END;
$$ LANGUAGE plpgsql;

-- Step 3: Test the function
DO $$
DECLARE
    test_student VARCHAR;
    test_score DECIMAL(5,2);
BEGIN
    -- Get a test student
    SELECT student_id INTO test_student 
    FROM students 
    WHERE status = 'under_registration' 
    LIMIT 1;
    
    IF test_student IS NOT NULL THEN
        test_score := calculate_confidence_score(test_student);
        RAISE NOTICE 'Test successful! Student: % | Score: %', test_student, test_score;
    ELSE
        RAISE NOTICE 'No students found with status under_registration';
    END IF;
END $$;

-- Step 4: Update all pending registrations
UPDATE students 
SET confidence_score = calculate_confidence_score(student_id)
WHERE status = 'under_registration';

-- Step 5: Show results
SELECT 
    student_id,
    CONCAT(first_name, ' ', last_name) as name,
    confidence_score,
    get_confidence_level(confidence_score) as level
FROM students 
WHERE status = 'under_registration'
LIMIT 5;
