-- ============================================
-- DOCUMENTS TABLE REFACTORING
-- Complete schema for unified document management
-- ============================================

-- Step 1: Drop redundant tables
DROP TABLE IF EXISTS enrollment_forms CASCADE;
DROP TABLE IF EXISTS grade_uploads CASCADE;
DROP TABLE IF EXISTS extracted_grades CASCADE;
DROP TABLE IF EXISTS student_gpa_summary CASCADE;
DROP TABLE IF EXISTS student_grades CASCADE;
DROP TABLE IF EXISTS grade_documents CASCADE;

-- Step 2: Drop existing documents table to recreate with new structure
DROP TABLE IF EXISTS documents CASCADE;

-- Step 3: Create new documents table with enhanced structure
CREATE TABLE documents (
    document_id VARCHAR(100) PRIMARY KEY,  -- Format: STUDENTID-DOCU-YEAR-TYPE
    student_id VARCHAR(100) NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,

    document_type_code VARCHAR(2) NOT NULL,  -- 00=EAF, 01=Grades, 02=Letter, 03=Certificate, 04=ID Picture
    document_type_name VARCHAR(50) NOT NULL, -- Human-readable: eaf, academic_grades, letter_to_mayor, certificate_of_indigency, id_picture

    file_path TEXT NOT NULL,                 -- Actual file location (e.g., assets/uploads/temp/enrollment_forms/filename.pdf)
    file_name VARCHAR(255) NOT NULL,         -- Original filename
    file_extension VARCHAR(10) NOT NULL,     -- pdf, jpg, png, etc.
    file_size_bytes BIGINT,                  -- File size in bytes

    ocr_text_path TEXT,                      -- Path to .ocr.txt file
    ocr_confidence DECIMAL(5,2) DEFAULT 0,   -- Overall OCR confidence (0-100)
    verification_data_path TEXT,             -- Path to .verify.json file
    verification_status VARCHAR(20) DEFAULT 'pending', -- pending, passed, failed, manual_review
    verification_score DECIMAL(5,2) DEFAULT 0,        -- Verification score (0-100)

    verification_details JSONB,              -- Full verification results (checks passed, confidence scores, etc.)

    extracted_grades JSONB,                  -- Array of grade objects with subject, grade, confidence
    average_grade DECIMAL(5,2),              -- Calculated average/GPA
    passing_status BOOLEAN,                  -- All grades passing?

    status VARCHAR(20) DEFAULT 'temp',       -- temp (in temp folder), approved (in student folder), rejected

    upload_date TIMESTAMP DEFAULT NOW(),
    upload_year INTEGER DEFAULT EXTRACT(YEAR FROM NOW()),
    last_modified TIMESTAMP DEFAULT NOW(),
    approved_date TIMESTAMP,
    approved_by INTEGER REFERENCES admins(admin_id),

    notes TEXT,                              -- Admin notes or comments
 
    CONSTRAINT valid_document_type CHECK (document_type_code IN ('00', '01', '02', '03', '04')),
    CONSTRAINT valid_status CHECK (status IN ('temp', 'approved', 'rejected')),
    CONSTRAINT valid_verification_status CHECK (verification_status IN ('pending', 'passed', 'failed', 'manual_review'))
);

-- Create indexes for performance
CREATE INDEX idx_documents_student_id ON documents(student_id);
CREATE INDEX idx_documents_type_code ON documents(document_type_code);
CREATE INDEX idx_documents_type_name ON documents(document_type_name);
CREATE INDEX idx_documents_status ON documents(status);
CREATE INDEX idx_documents_verification_status ON documents(verification_status);
CREATE INDEX idx_documents_upload_date ON documents(upload_date);
CREATE INDEX idx_documents_student_type ON documents(student_id, document_type_code);

-- Add comments for documentation
COMMENT ON TABLE documents IS 'Unified table for all student documents with OCR and verification data';
COMMENT ON COLUMN documents.document_id IS 'Format: STUDENTID-DOCU-YEAR-TYPE (e.g., GENERALTRIAS-2025-3-DWXA3N-DOCU-2025-01)';
COMMENT ON COLUMN documents.document_type_code IS '00=EAF, 01=Grades, 02=Letter to Mayor, 03=Certificate of Indigency, 04=ID Picture';
COMMENT ON COLUMN documents.verification_details IS 'JSONB storing full verification results including individual checks, confidence scores, recommendations';
COMMENT ON COLUMN documents.extracted_grades IS 'JSONB array for grades: [{subject, grade, confidence, passing}]';

-- ============================================
-- HELPER FUNCTION: Generate Document ID
-- ============================================

CREATE OR REPLACE FUNCTION generate_document_id(
    p_student_id VARCHAR(100),
    p_document_type_code VARCHAR(2),
    p_year INTEGER DEFAULT NULL
) RETURNS VARCHAR(100) AS $$
DECLARE
    v_year INTEGER;
    v_document_id VARCHAR(100);
BEGIN
    -- Use provided year or current year
    v_year := COALESCE(p_year, EXTRACT(YEAR FROM NOW()));
    
    -- Format: STUDENTID-DOCU-YEAR-TYPE
    -- Example: GENERALTRIAS-2025-3-DWXA3N-DOCU-2025-01
    v_document_id := p_student_id || '-DOCU-' || v_year::TEXT || '-' || p_document_type_code;
    
    RETURN v_document_id;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION generate_document_id IS 'Generates standardized document ID: STUDENTID-DOCU-YEAR-TYPE';

-- ============================================
-- HELPER FUNCTION: Log to existing audit_logs table
-- ============================================

CREATE OR REPLACE FUNCTION log_document_audit(
    p_user_id INTEGER,
    p_user_type VARCHAR(20),
    p_username VARCHAR(255),
    p_event_type VARCHAR(50),
    p_event_category VARCHAR(30),
    p_action_description TEXT,
    p_affected_table VARCHAR(100) DEFAULT NULL,
    p_affected_record_id INTEGER DEFAULT NULL,
    p_metadata JSONB DEFAULT NULL,
    p_status VARCHAR(20) DEFAULT 'success'
) RETURNS INTEGER AS $$
DECLARE
    v_audit_id INTEGER;
BEGIN
    -- Insert into existing audit_logs table
    INSERT INTO audit_logs (
        user_id,
        user_type,
        username,
        event_type,
        event_category,
        action_description,
        status,
        ip_address,
        user_agent,
        affected_table,
        affected_record_id,
        metadata
    ) VALUES (
        p_user_id,
        p_user_type,
        p_username,
        p_event_type,
        p_event_category,
        p_action_description,
        p_status,
        inet_client_addr()::TEXT,  -- Get client IP if available
        current_setting('application.user_agent', true),  -- Get user agent if set
        p_affected_table,
        p_affected_record_id,
        p_metadata
    ) RETURNING audit_id INTO v_audit_id;
    
    RETURN v_audit_id;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION log_document_audit IS 'Logs document-related actions to existing audit_logs table';

-- Usage Examples:
-- Student Registration:
-- SELECT log_document_audit(
--     NULL,                           -- user_id (NULL for self-registration)
--     'student',                      -- user_type
--     student_id,                     -- username (using student_id)
--     'student_registered',           -- event_type
--     'applicant_management',         -- event_category
--     'Student self-registered',      -- action_description
--     'students',                     -- affected_table
--     NULL,                           -- affected_record_id
--     '{"documents_uploaded": 5}'::JSONB  -- metadata
-- );
--
-- Document Approval:
-- SELECT log_document_audit(
--     admin_id,                       -- user_id
--     'admin',                        -- user_type
--     admin_username,                 -- username
--     'applicant_approved',           -- event_type
--     'applicant_management',         -- event_category
--     'Student application approved', -- action_description
--     'documents',                    -- affected_table
--     NULL,                           -- affected_record_id
--     jsonb_build_object('student_id', student_id, 'documents_count', 5)  -- metadata
-- );

-- ============================================
-- SAMPLE DATA / REFERENCE
-- ============================================

-- Document Type Codes Reference:
-- 00 = Enrollment Form (EAF)
-- 01 = Academic Grades
-- 02 = Letter to Mayor
-- 03 = Certificate of Indigency
-- 04 = ID Picture

-- Example Document IDs:
-- GENERALTRIAS-2025-3-DWXA3N-DOCU-2025-00 (EAF)
-- GENERALTRIAS-2025-3-DWXA3N-DOCU-2025-01 (Grades)
-- GENERALTRIAS-2025-3-DWXA3N-DOCU-2025-02 (Letter)
-- GENERALTRIAS-2025-3-DWXA3N-DOCU-2025-03 (Certificate)
-- GENERALTRIAS-2025-3-DWXA3N-DOCU-2025-04 (ID Picture)

-- Test the generate_document_id function:
-- SELECT generate_document_id('GENERALTRIAS-2025-3-DWXA3N', '01', 2025);
-- Result: GENERALTRIAS-2025-3-DWXA3N-DOCU-2025-01

COMMIT;
