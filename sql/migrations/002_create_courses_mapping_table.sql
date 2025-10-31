-- ============================================================================
-- MIGRATION: Create Courses Mapping Table
-- ============================================================================
-- Purpose: Normalize course names and store program duration
-- Date: October 31, 2025
-- Author: System Migration
-- Dependencies: universities table (optional FK)
-- ============================================================================

-- Enable pg_trgm extension for fuzzy text matching (if not already enabled)
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Drop table if exists (for clean re-run)
DROP TABLE IF EXISTS courses_mapping CASCADE;

-- Create courses_mapping table
CREATE TABLE courses_mapping (
    mapping_id SERIAL PRIMARY KEY,
    raw_course_name VARCHAR(255) NOT NULL,     -- What OCR reads (e.g., "BSCS", "BS CompSci")
    normalized_course VARCHAR(255) NOT NULL,   -- Standardized name (e.g., "BS Computer Science")
    program_duration INTEGER NOT NULL CHECK (program_duration IN (4, 5)),  -- 4 or 5 years
    course_category VARCHAR(100),              -- Engineering, Science, Arts, Business, etc.
    is_verified BOOLEAN DEFAULT FALSE,         -- Admin has verified this mapping
    university_id INTEGER REFERENCES universities(university_id) ON DELETE SET NULL,  -- Optional: specific to one university
    occurrence_count INTEGER DEFAULT 1,        -- How many students have this course
    created_by INTEGER REFERENCES admins(admin_id) ON DELETE SET NULL,  -- Admin who created mapping
    verified_by INTEGER REFERENCES admins(admin_id) ON DELETE SET NULL, -- Admin who verified
    created_at TIMESTAMP DEFAULT NOW(),
    last_seen TIMESTAMP DEFAULT NOW(),         -- Last time this course was encountered
    updated_at TIMESTAMP DEFAULT NOW(),
    notes TEXT,                                -- Additional notes about this course
    
    -- Ensure unique raw course name (or per university if specified)
    UNIQUE(raw_course_name, university_id)
);

-- Create indexes for performance
CREATE INDEX idx_courses_mapping_raw_name ON courses_mapping(raw_course_name);
CREATE INDEX idx_courses_mapping_normalized ON courses_mapping(normalized_course);
CREATE INDEX idx_courses_mapping_category ON courses_mapping(course_category);
CREATE INDEX idx_courses_mapping_university ON courses_mapping(university_id);
CREATE INDEX idx_courses_mapping_verified ON courses_mapping(is_verified);
CREATE INDEX idx_courses_mapping_duration ON courses_mapping(program_duration);

-- Create full-text search index for course names
CREATE INDEX idx_courses_mapping_raw_name_trgm ON courses_mapping USING gin(raw_course_name gin_trgm_ops);
CREATE INDEX idx_courses_mapping_normalized_trgm ON courses_mapping USING gin(normalized_course gin_trgm_ops);

-- Create trigger to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_courses_mapping_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_courses_mapping_timestamp
    BEFORE UPDATE ON courses_mapping
    FOR EACH ROW
    EXECUTE FUNCTION update_courses_mapping_updated_at();

-- ============================================================================
-- HELPER FUNCTIONS
-- ============================================================================

-- Function to find course mapping (case-insensitive, fuzzy match)
CREATE OR REPLACE FUNCTION find_course_mapping(
    p_raw_course VARCHAR,
    p_university_id INTEGER DEFAULT NULL
)
RETURNS TABLE(
    mapping_id INTEGER,
    raw_course_name VARCHAR,
    normalized_course VARCHAR,
    program_duration INTEGER,
    course_category VARCHAR,
    similarity_score REAL
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        cm.mapping_id,
        cm.raw_course_name,
        cm.normalized_course,
        cm.program_duration,
        cm.course_category,
        similarity(cm.raw_course_name, p_raw_course) AS similarity_score
    FROM courses_mapping cm
    WHERE 
        (p_university_id IS NULL OR cm.university_id IS NULL OR cm.university_id = p_university_id)
        AND (
            LOWER(cm.raw_course_name) = LOWER(p_raw_course)
            OR similarity(cm.raw_course_name, p_raw_course) > 0.6
        )
    ORDER BY 
        CASE WHEN LOWER(cm.raw_course_name) = LOWER(p_raw_course) THEN 0 ELSE 1 END,
        similarity_score DESC
    LIMIT 5;
END;
$$ LANGUAGE plpgsql;

-- Function to add or update course mapping
CREATE OR REPLACE FUNCTION upsert_course_mapping(
    p_raw_course VARCHAR,
    p_normalized_course VARCHAR,
    p_duration INTEGER,
    p_category VARCHAR DEFAULT NULL,
    p_university_id INTEGER DEFAULT NULL,
    p_admin_id INTEGER DEFAULT NULL
)
RETURNS INTEGER AS $$
DECLARE
    v_mapping_id INTEGER;
BEGIN
    -- Try to find existing mapping
    SELECT mapping_id INTO v_mapping_id
    FROM courses_mapping
    WHERE LOWER(raw_course_name) = LOWER(p_raw_course)
        AND (university_id IS NULL AND p_university_id IS NULL OR university_id = p_university_id);
    
    IF v_mapping_id IS NOT NULL THEN
        -- Update existing mapping
        UPDATE courses_mapping
        SET 
            normalized_course = p_normalized_course,
            program_duration = p_duration,
            course_category = COALESCE(p_category, course_category),
            occurrence_count = occurrence_count + 1,
            last_seen = NOW(),
            verified_by = COALESCE(p_admin_id, verified_by),
            is_verified = TRUE
        WHERE mapping_id = v_mapping_id;
    ELSE
        -- Insert new mapping
        INSERT INTO courses_mapping (
            raw_course_name,
            normalized_course,
            program_duration,
            course_category,
            university_id,
            created_by,
            verified_by,
            is_verified
        ) VALUES (
            p_raw_course,
            p_normalized_course,
            p_duration,
            p_category,
            p_university_id,
            p_admin_id,
            p_admin_id,
            TRUE
        )
        RETURNING mapping_id INTO v_mapping_id;
    END IF;
    
    RETURN v_mapping_id;
END;
$$ LANGUAGE plpgsql;

-- ============================================================================
-- SEED INITIAL DATA - Common Courses
-- ============================================================================

INSERT INTO courses_mapping (raw_course_name, normalized_course, program_duration, course_category, is_verified, notes) VALUES
    -- Computer Science & IT
    ('BSCS', 'BS Computer Science', 4, 'Information Technology', TRUE, 'Common abbreviation'),
    ('BS Computer Science', 'BS Computer Science', 4, 'Information Technology', TRUE, 'Full name'),
    ('BSIT', 'BS Information Technology', 4, 'Information Technology', TRUE, 'Common abbreviation'),
    ('BS Information Technology', 'BS Information Technology', 4, 'Information Technology', TRUE, 'Full name'),
    
    -- Engineering (5-year programs)
    ('BSCE', 'BS Civil Engineering', 4, 'Engineering', TRUE, 'Common abbreviation'),
    ('BS Civil Engineering', 'BS Civil Engineering', 4, 'Engineering', TRUE, 'Full name'),
    ('BSEE', 'BS Electrical Engineering', 4, 'Engineering', TRUE, 'Common abbreviation'),
    ('BS Electrical Engineering', 'BS Electrical Engineering', 4, 'Engineering', TRUE, 'Full name'),
    ('BSME', 'BS Mechanical Engineering', 4, 'Engineering', TRUE, 'Common abbreviation'),
    ('BS Mechanical Engineering', 'BS Mechanical Engineering', 4, 'Engineering', TRUE, 'Full name'),
    ('BSCpE', 'BS Computer Engineering', 4, 'Engineering', TRUE, 'Common abbreviation'),
    ('BS Computer Engineering', 'BS Computer Engineering', 4, 'Engineering', TRUE, 'Full name'),
    ('BSECE', 'BS Electronics & Communications Engineering', 4, 'Engineering', TRUE, 'Common abbreviation'),
    ('BS Electronics Engineering', 'BS Electronics & Communications Engineering', 4, 'Engineering', TRUE, 'Alternative name'),
    ('BSIE', 'BS Industrial Engineering', 4, 'Engineering', TRUE, 'Common abbreviation'),
    ('BS Industrial Engineering', 'BS Industrial Engineering', 4, 'Engineering', TRUE, 'Full name'),
    ('BSChE', 'BS Chemical Engineering', 4, 'Engineering', TRUE, 'Common abbreviation'),
    ('BS Chemical Engineering', 'BS Chemical Engineering', 4, 'Engineering', TRUE, 'Full name'),

    -- Architecture (5-year program)
    ('BS Architecture', 'BS Architecture', 5, 'Architecture', TRUE, 'Full name'),
    ('BSA', 'BS Architecture', 5, 'Architecture', TRUE, 'Common abbreviation'),
    
    -- Business & Accounting (4-year programs)
    ('BSBA', 'BS Business Administration', 4, 'Business', TRUE, 'Common abbreviation'),
    ('BS Business Administration', 'BS Business Administration', 4, 'Business', TRUE, 'Full name'),
    ('BSA', 'BS Accountancy', 4, 'Business', TRUE, 'Common abbreviation'),
    ('BS Accountancy', 'BS Accountancy', 4, 'Business', TRUE, 'Full name'),
    ('BSAIS', 'BS Accounting Information Systems', 4, 'Business', TRUE, 'Common abbreviation'),
    
    -- Education (4-year programs)
    ('BSED', 'BS Education', 4, 'Education', TRUE, 'Common abbreviation'),
    ('BS Education', 'BS Education', 4, 'Education', TRUE, 'Full name'),
    ('BEED', 'Bachelor of Elementary Education', 4, 'Education', TRUE, 'Common abbreviation'),
    ('BSPED', 'BS Physical Education', 4, 'Education', TRUE, 'Common abbreviation'),
    
    -- Science (4-year programs)
    ('BS Biology', 'BS Biology', 4, 'Science', TRUE, 'Full name'),
    ('BS Chemistry', 'BS Chemistry', 4, 'Science', TRUE, 'Full name'),
    ('BS Physics', 'BS Physics', 4, 'Science', TRUE, 'Full name'),
    ('BS Mathematics', 'BS Mathematics', 4, 'Science', TRUE, 'Full name'),
    ('BS Psychology', 'BS Psychology', 4, 'Science', TRUE, 'Full name'),
    
    -- Medical/Health Sciences (4-year programs)
    ('BSN', 'BS Nursing', 4, 'Medical/Health', TRUE, 'Common abbreviation'),
    ('BS Nursing', 'BS Nursing', 4, 'Medical/Health', TRUE, 'Full name'),
    ('BSPT', 'BS Physical Therapy', 4, 'Medical/Health', TRUE, 'Common abbreviation'),
    ('BS Pharmacy', 'BS Pharmacy', 4, 'Medical/Health', TRUE, 'Full name'),
    ('BS Medical Technology', 'BS Medical Technology', 4, 'Medical/Health', TRUE, 'Full name'),
    
    -- Arts & Humanities (4-year programs)
    ('AB Psychology', 'AB Psychology', 4, 'Arts & Humanities', TRUE, 'Full name'),
    ('AB Political Science', 'AB Political Science', 4, 'Arts & Humanities', TRUE, 'Full name'),
    ('AB Communication', 'AB Communication', 4, 'Arts & Humanities', TRUE, 'Full name'),
    ('AB English', 'AB English', 4, 'Arts & Humanities', TRUE, 'Full name'),
    ('AB History', 'AB History', 4, 'Arts & Humanities', TRUE, 'Full name'),
    
    -- Tourism & Hospitality (4-year programs)
    ('BSHM', 'BS Hospitality Management', 4, 'Tourism & Hospitality', TRUE, 'Common abbreviation'),
    ('BSTM', 'BS Tourism Management', 4, 'Tourism & Hospitality', TRUE, 'Common abbreviation')
ON CONFLICT (raw_course_name, university_id) DO NOTHING;

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Verify table creation
SELECT 
    'courses_mapping table created successfully' AS status,
    COUNT(*) AS total_mappings,
    COUNT(DISTINCT normalized_course) AS unique_courses,
    SUM(CASE WHEN is_verified THEN 1 ELSE 0 END) AS verified_mappings,
    COUNT(DISTINCT course_category) AS categories
FROM courses_mapping;

-- Show course distribution by category
SELECT 
    course_category,
    COUNT(*) AS course_count,
    AVG(program_duration)::NUMERIC(3,1) AS avg_duration,
    STRING_AGG(DISTINCT normalized_course, ', ' ORDER BY normalized_course) AS courses
FROM courses_mapping
WHERE is_verified = TRUE
GROUP BY course_category
ORDER BY course_count DESC;

-- Show program duration distribution
SELECT 
    program_duration,
    COUNT(*) AS course_count,
    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER (), 2) AS percentage
FROM courses_mapping
WHERE is_verified = TRUE
GROUP BY program_duration
ORDER BY program_duration;

-- ============================================================================
-- COMMENTS FOR DOCUMENTATION
-- ============================================================================

COMMENT ON TABLE courses_mapping IS 'Normalizes course names from OCR and stores program duration for automatic graduation calculation';
COMMENT ON COLUMN courses_mapping.raw_course_name IS 'Course name as extracted from OCR (e.g., BSCS, BS CompSci)';
COMMENT ON COLUMN courses_mapping.normalized_course IS 'Standardized course name (e.g., BS Computer Science)';
COMMENT ON COLUMN courses_mapping.program_duration IS 'Program length in years (4 or 5) - determines graduation year';
COMMENT ON COLUMN courses_mapping.occurrence_count IS 'Number of students with this course - helps identify common courses';
COMMENT ON COLUMN courses_mapping.university_id IS 'If set, this mapping only applies to specific university';

-- ============================================================================
-- ROLLBACK SCRIPT (if needed)
-- ============================================================================
-- DROP FUNCTION IF EXISTS find_course_mapping(VARCHAR, INTEGER);
-- DROP FUNCTION IF EXISTS upsert_course_mapping(VARCHAR, VARCHAR, INTEGER, VARCHAR, INTEGER, INTEGER);
-- DROP TRIGGER IF EXISTS trigger_update_courses_mapping_timestamp ON courses_mapping;
-- DROP FUNCTION IF EXISTS update_courses_mapping_updated_at();
-- DROP TABLE IF EXISTS courses_mapping CASCADE;
