
-- ===========================================
-- EDUCAID PostgreSQL SCHEMA (Updated)
-- ===========================================

-- Municipalities (for CMS multi-tenancy)
CREATE TABLE municipalities (
    municipality_id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    color_theme TEXT,
    banner_image TEXT,
    logo_image TEXT
);

-- Admin users
CREATE TABLE admins (
    admin_id SERIAL PRIMARY KEY,
    municipality_id INT REFERENCES municipalities(municipality_id),
    first_name TEXT NOT NULL,
    middle_name TEXT,
    last_name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    role TEXT CHECK (role IN ('super_admin', 'sub_admin')) DEFAULT 'super_admin',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    last_login TIMESTAMP
);

-- Students
CREATE TABLE students (
    student_id SERIAL PRIMARY KEY,
    municipality_id INT REFERENCES municipalities(municipality_id),
    first_name TEXT,
    middle_name TEXT,
    last_name TEXT,
    extension_name TEXT, -- For suffixes like Jr., Sr., I, II, III, etc.
    email TEXT UNIQUE,
    mobile TEXT,
    password TEXT NOT NULL,
    sex TEXT CHECK (sex IN ('Male', 'Female')),
    status TEXT CHECK (status IN ('under_registration', 'applicant', 'active', 'disabled', 'given')) DEFAULT 'under_registration',
    payroll_no INT,
    qr_code TEXT,
    has_received BOOLEAN DEFAULT FALSE,
    application_date TIMESTAMP DEFAULT NOW(),
    bdate DATE,
    barangay_id INT REFERENCES barangays(barangay_id)
);

-- Applications (No GPA, used for tracking per semester)
CREATE TABLE applications (
    application_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    semester TEXT,
    academic_year TEXT,
    is_valid BOOLEAN DEFAULT TRUE,
    remarks TEXT
);

-- Student-uploaded documents
CREATE TABLE documents (
    document_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    type TEXT CHECK (type IN ('school_id', 'eaf', 'certificate_of_indigency', 'letter_to_mayor', 'id_picture')),
    file_path TEXT,
    upload_date TIMESTAMP DEFAULT NOW(),
    is_valid BOOLEAN DEFAULT FALSE,  -- Set to FALSE by default, to be validated by admin
    validation_notes TEXT
);

-- Enrollment Assessment Forms uploaded during registration
CREATE TABLE enrollment_forms (
    form_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    file_path TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    upload_date TIMESTAMP DEFAULT NOW()
);

-- Announcements (only one active per LGU)
-- Announcements (general broadcasts)
CREATE TABLE announcements (
    announcement_id SERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    remarks TEXT,
    posted_at TIMESTAMP NOT NULL DEFAULT NOW(),
    is_active BOOLEAN NOT NULL DEFAULT FALSE
);

-- Aid distribution tracking
CREATE TABLE distributions (
    distribution_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    date_given DATE,
    verified_by INT REFERENCES admins(admin_id),
    remarks TEXT
);

-- QR scan logs (optional analytics)
CREATE TABLE qr_logs (
    log_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    scanned_at TIMESTAMP DEFAULT NOW(),
    scanned_by INT REFERENCES admins(admin_id)
);

CREATE TABLE barangays (
    barangay_id SERIAL PRIMARY KEY,
    municipality_id INT REFERENCES municipalities(municipality_id),
    name TEXT NOT NULL
);
-- Signup slots for students
-- This table allows municipalities to manage the number of students they can accommodate
CREATE TABLE signup_slots (
    slot_id SERIAL PRIMARY KEY,
    municipality_id INT REFERENCES municipalities(municipality_id),
    academic_year TEXT,
    semester TEXT,
    slot_count INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE config (
    key TEXT PRIMARY KEY,
    value TEXT
);

INSERT INTO config (key, value) VALUES ('student_list_finalized', '0');

-- Notifications for students (rejections, announcements, schedule postings)
CREATE TABLE IF NOT EXISTS notifications (
    notification_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE schedules (
    schedule_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    payroll_no INT NOT NULL,
    batch_no INT NOT NULL,
    distribution_date DATE NOT NULL,
    time_slot TEXT NOT NULL,
    location TEXT NOT NULL DEFAULT '',
    status TEXT CHECK (status IN ('scheduled', 'completed', 'missed')) DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Admin notifications for system events (e.g. profile updates)
CREATE TABLE IF NOT EXISTS admin_notifications (
    admin_notification_id SERIAL PRIMARY KEY,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Create universities table
CREATE TABLE IF NOT EXISTS universities (
    university_id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    code TEXT UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Create year levels table
CREATE TABLE IF NOT EXISTS year_levels (
    year_level_id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    code TEXT UNIQUE NOT NULL,
    sort_order INT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Add university and year level columns to students table
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS university_id INT REFERENCES universities(university_id),
ADD COLUMN IF NOT EXISTS year_level_id INT REFERENCES year_levels(year_level_id);

-- Insert year levels
INSERT INTO year_levels (name, code, sort_order) VALUES
('1st Year', '1ST', 1),
('2nd Year', '2ND', 2),
('3rd Year', '3RD', 3),
('4th Year', '4TH', 4),
('5th Year', '5TH', 5)
ON CONFLICT (code) DO NOTHING;

-- Add unique student identifier column to students table
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS unique_student_id TEXT UNIQUE;

-- Create index for faster lookups
CREATE INDEX IF NOT EXISTS idx_students_unique_id ON students(unique_student_id);

CREATE TABLE qr_codes (
    qr_id SERIAL PRIMARY KEY,
    payroll_number INT NOT NULL,
    unique_id TEXT UNIQUE NOT NULL,
    student_unique_id TEXT REFERENCES students(unique_student_id),
    status TEXT CHECK (status IN ('Pending', 'Done')) DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT NOW(),
    -- Optionally, you can add admin_id if you want to track who generated it
    admin_id INT REFERENCES admins(admin_id),
    -- Optionally, you can add a name field if you want to store a name
    name TEXT
);
CREATE TABLE qr_codes (
    qr_id SERIAL PRIMARY KEY,
    payroll_number INT NOT NULL,
    student_unique_id TEXT REFERENCES students(unique_student_id),
    status TEXT CHECK (status IN ('Pending', 'Done')) DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT NOW()
    -- Optionally, you can add admin_id if you want to track who generated it
);

ALTER TABLE qr_codes ADD COLUMN unique_id TEXT;

-- Add last_login column to students table
ALTER TABLE students ADD COLUMN IF NOT EXISTS last_login TIMESTAMP;

-- Grades upload tables for OCR processing
CREATE TABLE IF NOT EXISTS grade_uploads (
    upload_id SERIAL PRIMARY KEY,
    student_id INTEGER REFERENCES students(student_id),
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(10) NOT NULL, -- 'pdf' or 'image'
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ocr_processed BOOLEAN DEFAULT FALSE,
    ocr_confidence DECIMAL(5,2), -- 0.00 to 100.00
    extracted_text TEXT,
    validation_status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'passed', 'failed', 'manual_review'
    admin_reviewed BOOLEAN DEFAULT FALSE,
    admin_notes TEXT,
    reviewed_by INTEGER REFERENCES admins(admin_id),
    reviewed_at TIMESTAMP
);

CREATE TABLE IF NOT EXISTS extracted_grades (
    grade_id SERIAL PRIMARY KEY,
    upload_id INTEGER REFERENCES grade_uploads(upload_id),
    subject_name VARCHAR(100),
    grade_value VARCHAR(10), -- Store as string to handle different formats
    grade_numeric DECIMAL(4,2), -- Normalized numeric value (1.0-5.0 scale)
    grade_percentage DECIMAL(5,2), -- Percentage equivalent (60-100)
    semester VARCHAR(20),
    school_year VARCHAR(10),
    extraction_confidence DECIMAL(5,2),
    is_passing BOOLEAN DEFAULT FALSE
);

-- Indexes for better performance
CREATE INDEX IF NOT EXISTS idx_grade_uploads_student ON grade_uploads(student_id);
CREATE INDEX IF NOT EXISTS idx_grade_uploads_status ON grade_uploads(validation_status);
CREATE INDEX IF NOT EXISTS idx_extracted_grades_upload ON extracted_grades(upload_id);
CREATE INDEX IF NOT EXISTS idx_students_last_login ON students(last_login);

-- Add flag to track manually finished slots
ALTER TABLE signup_slots 
ADD COLUMN manually_finished BOOLEAN DEFAULT FALSE,
ADD COLUMN finished_at TIMESTAMP NULL;

-- Update existing inactive slots to show they were not manually finished
UPDATE signup_slots 
SET manually_finished = FALSE 
WHERE is_active = FALSE;

-- Add maximum capacity column to municipalities table
ALTER TABLE municipalities 
ADD COLUMN IF NOT EXISTS max_capacity INT DEFAULT NULL;

-- Set a default capacity if none exists
UPDATE municipalities 
SET max_capacity = 1000 
WHERE max_capacity IS NULL AND municipality_id = 1;

-- Create admin OTP verification table
CREATE TABLE IF NOT EXISTS admin_otp_verifications (
    id SERIAL PRIMARY KEY,
    admin_id INT REFERENCES admins(admin_id) ON DELETE CASCADE,
    otp VARCHAR(6) NOT NULL,
    email VARCHAR(255) NOT NULL,
    purpose VARCHAR(50) NOT NULL, -- 'email_change' or 'password_change'
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Create index for faster lookups
CREATE INDEX IF NOT EXISTS idx_admin_otp_admin_purpose ON admin_otp_verifications(admin_id, purpose);
CREATE INDEX IF NOT EXISTS idx_admin_otp_expires ON admin_otp_verifications(expires_at);

-- Distribution snapshots for tracking finalized distributions
CREATE TABLE IF NOT EXISTS distribution_snapshots (
    snapshot_id SERIAL PRIMARY KEY,
    distribution_date DATE NOT NULL,
    location TEXT NOT NULL,
    total_students_count INT NOT NULL,
    active_slot_id INT,
    academic_year TEXT,
    semester TEXT,
    finalized_by INT REFERENCES admins(admin_id),
    finalized_at TIMESTAMP DEFAULT NOW(),
    notes TEXT,
    -- Store JSON data for schedules and student details
    schedules_data JSONB,
    students_data JSONB
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_distribution_snapshots_date ON distribution_snapshots(distribution_date);
CREATE INDEX IF NOT EXISTS idx_distribution_snapshots_finalized_by ON distribution_snapshots(finalized_by);

UPDATE 9/17/2025 3:37PM
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