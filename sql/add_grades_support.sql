-- Migration: Add grades support tables for registration workflow
-- Date: 2024-12-19
-- Purpose: Support grades entry during student registration

-- Create student_grades table if it doesn't exist
CREATE TABLE IF NOT EXISTS student_grades (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(255) NOT NULL,
    subject_name VARCHAR(255) NOT NULL,
    grade_value VARCHAR(10) NOT NULL,
    grade_system VARCHAR(20) NOT NULL CHECK (grade_system IN ('percentage', 'gpa', 'dlsu_gpa', 'letter')),
    units INTEGER NOT NULL CHECK (units > 0 AND units <= 6),
    semester VARCHAR(50),
    academic_year VARCHAR(20),
    source VARCHAR(50) DEFAULT 'manual' CHECK (source IN ('manual', 'registration', 'upload', 'admin_entry')),
    verification_status VARCHAR(20) DEFAULT 'pending' CHECK (verification_status IN ('pending', 'verified', 'rejected', 'needs_review')),
    ocr_confidence DECIMAL(5,2),
    admin_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    updated_by VARCHAR(255)
);

-- Create student_gpa_summary table if it doesn't exist
CREATE TABLE IF NOT EXISTS student_gpa_summary (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(255) NOT NULL,
    semester VARCHAR(50) NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    total_units INTEGER NOT NULL,
    gpa DECIMAL(4,2) NOT NULL,
    grading_system VARCHAR(20) NOT NULL,
    source VARCHAR(50) DEFAULT 'calculated',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(student_id, semester, academic_year, source)
);

-- Create grade_documents table for storing uploaded grade documents
CREATE TABLE IF NOT EXISTS grade_documents (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    file_size INTEGER,
    upload_source VARCHAR(50) DEFAULT 'registration',
    ocr_text TEXT,
    ocr_confidence DECIMAL(5,2),
    processing_status VARCHAR(20) DEFAULT 'pending',
    verification_status VARCHAR(20) DEFAULT 'pending',
    admin_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add indexes for better performance
CREATE INDEX IF NOT EXISTS idx_student_grades_student_id ON student_grades(student_id);
CREATE INDEX IF NOT EXISTS idx_student_grades_semester_year ON student_grades(semester, academic_year);
CREATE INDEX IF NOT EXISTS idx_student_grades_verification ON student_grades(verification_status);
CREATE INDEX IF NOT EXISTS idx_student_gpa_student_id ON student_gpa_summary(student_id);
CREATE INDEX IF NOT EXISTS idx_grade_documents_student_id ON grade_documents(student_id);
CREATE INDEX IF NOT EXISTS idx_grade_documents_status ON grade_documents(processing_status, verification_status);

-- Create function to automatically update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Create triggers for automatic timestamp updates
CREATE TRIGGER update_student_grades_updated_at 
    BEFORE UPDATE ON student_grades 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_student_gpa_summary_updated_at 
    BEFORE UPDATE ON student_gpa_summary 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_grade_documents_updated_at 
    BEFORE UPDATE ON grade_documents 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- Add sample grading system configurations
CREATE TABLE IF NOT EXISTS grading_systems (
    id SERIAL PRIMARY KEY,
    system_name VARCHAR(50) UNIQUE NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    scale_type VARCHAR(20) NOT NULL,
    min_value DECIMAL(5,2),
    max_value DECIMAL(5,2),
    passing_grade DECIMAL(5,2),
    grade_mappings JSONB,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default grading systems
INSERT INTO grading_systems (system_name, display_name, scale_type, min_value, max_value, passing_grade, grade_mappings) 
VALUES 
    ('percentage', 'Percentage (0-100)', 'numeric', 0, 100, 75, '{"A": {"min": 90, "max": 100}, "B": {"min": 80, "max": 89}, "C": {"min": 70, "max": 79}, "D": {"min": 60, "max": 69}, "F": {"min": 0, "max": 59}}'),
    ('gpa', '1.0-5.0 GPA Scale', 'numeric', 1.0, 5.0, 3.0, '{"A": {"min": 1.0, "max": 1.5}, "B": {"min": 1.6, "max": 2.5}, "C": {"min": 2.6, "max": 3.5}, "D": {"min": 3.6, "max": 4.0}, "F": {"min": 4.1, "max": 5.0}}'),
    ('dlsu_gpa', 'DLSU 4.0 GPA Scale (4.0=100%)', 'numeric', 0, 4.0, 2.0, '{"A": {"min": 3.5, "max": 4.0}, "B": {"min": 2.5, "max": 3.49}, "C": {"min": 1.5, "max": 2.49}, "D": {"min": 1.0, "max": 1.49}, "F": {"min": 0, "max": 0.99}}'),
    ('letter', 'Letter Grades (A-F)', 'letter', 0, 4.0, 2.0, '{"A+": 4.0, "A": 4.0, "A-": 3.7, "B+": 3.3, "B": 3.0, "B-": 2.7, "C+": 2.3, "C": 2.0, "C-": 1.7, "D+": 1.3, "D": 1.0, "D-": 0.7, "F": 0.0}')
ON CONFLICT (system_name) DO NOTHING;

-- Add comments for documentation
COMMENT ON TABLE student_grades IS 'Stores individual subject grades for students';
COMMENT ON TABLE student_gpa_summary IS 'Stores calculated GPA summaries by semester/year';
COMMENT ON TABLE grade_documents IS 'Stores uploaded grade documents and OCR results';
COMMENT ON TABLE grading_systems IS 'Configuration for different grading systems';

COMMENT ON COLUMN student_grades.source IS 'Source of grade entry: manual, registration, upload, admin_entry';
COMMENT ON COLUMN student_grades.verification_status IS 'Admin verification status of the grade';
COMMENT ON COLUMN student_grades.ocr_confidence IS 'OCR confidence score if grade was extracted via OCR';

-- Create view for easy grade reporting
CREATE OR REPLACE VIEW student_grade_report AS
SELECT 
    sg.student_id,
    sg.subject_name,
    sg.grade_value,
    sg.grade_system,
    sg.units,
    sg.semester,
    sg.academic_year,
    sg.source,
    sg.verification_status,
    sg.created_at,
    sgs.gpa as semester_gpa,
    sgs.total_units as semester_total_units
FROM student_grades sg
LEFT JOIN student_gpa_summary sgs ON (
    sg.student_id = sgs.student_id 
    AND sg.semester = sgs.semester 
    AND sg.academic_year = sgs.academic_year
    AND sgs.source = 'calculated'
)
ORDER BY sg.student_id, sg.academic_year, sg.semester, sg.subject_name;

COMMENT ON VIEW student_grade_report IS 'Comprehensive view of student grades with GPA summaries';