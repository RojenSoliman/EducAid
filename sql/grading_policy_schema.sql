-- ===============================================
-- UNIVERSITY GRADING POLICY SCHEMA
-- Per-subject grade validation system
-- ===============================================

-- Create university grading policy table in public schema for VS Code visibility
CREATE TABLE IF NOT EXISTS university_passing_policy (
    policy_id SERIAL PRIMARY KEY,
    university_key TEXT NOT NULL REFERENCES universities(code),
    scale_type TEXT NOT NULL CHECK (scale_type IN ('NUMERIC_1_TO_5', 'NUMERIC_0_TO_4', 'PERCENT', 'LETTER')),
    higher_is_better BOOLEAN NOT NULL,
    highest_value TEXT NOT NULL,
    passing_value TEXT NOT NULL,
    letter_order TEXT[], -- Required for LETTER scale type
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(university_key, is_active) -- Only one active policy per university
);

-- Create index for fast lookups
CREATE INDEX IF NOT EXISTS idx_university_policy_active ON university_passing_policy(university_key, is_active);

-- Insert grading policies for ALL universities from the universities table
-- Automatically assigns grading system based on university type
-- Default: 1-5 scale (lower is better) for most Philippine universities
INSERT INTO university_passing_policy (university_key, scale_type, higher_is_better, highest_value, passing_value, is_active)
SELECT 
    code as university_key,
    CASE 
        -- Private universities known to use 0-4 scale (4.0 system)
        WHEN code IN ('DLSU_DASMARINAS', 'DLSL_LIPA', 'MCL', 'UA&P', 'ACSL', 'ADMU_NUVALI', 'MC_NUVALI', 'NU_LAGUNA') 
        THEN 'NUMERIC_0_TO_4'
        -- Default: Philippine standard 1-5 scale
        ELSE 'NUMERIC_1_TO_5'
    END as scale_type,
    CASE 
        -- For 0-4 scale: higher is better
        WHEN code IN ('DLSU_DASMARINAS', 'DLSL_LIPA', 'MCL', 'UA&P', 'ACSL', 'ADMU_NUVALI', 'MC_NUVALI', 'NU_LAGUNA') 
        THEN TRUE
        -- For 1-5 scale: lower is better
        ELSE FALSE
    END as higher_is_better,
    CASE 
        -- For 0-4 scale: 4.00 is highest
        WHEN code IN ('DLSU_DASMARINAS', 'DLSL_LIPA', 'MCL', 'UA&P', 'ACSL', 'ADMU_NUVALI', 'MC_NUVALI', 'NU_LAGUNA') 
        THEN '4.00'
        -- For 1-5 scale: 1.00 is highest
        ELSE '1.00'
    END as highest_value,
    CASE 
        -- For 0-4 scale: 1.00 is passing
        WHEN code IN ('DLSU_DASMARINAS', 'DLSL_LIPA', 'MCL', 'UA&P', 'ACSL', 'ADMU_NUVALI', 'MC_NUVALI', 'NU_LAGUNA') 
        THEN '1.00'
        -- For 1-5 scale: 3.00 is passing
        ELSE '3.00'
    END as passing_value,
    TRUE as is_active
FROM universities
ON CONFLICT (university_key, is_active) WHERE is_active = TRUE DO NOTHING;

-- Create PostgreSQL helper function for grade validation
CREATE OR REPLACE FUNCTION grading_is_passing(
    p_university_key TEXT,
    p_raw_grade TEXT
) RETURNS BOOLEAN AS $$
DECLARE
    policy_record RECORD;
    grade_numeric NUMERIC;
    passing_numeric NUMERIC;
    letter_index INT;
    passing_index INT;
BEGIN
    -- Get the active grading policy for the university
    SELECT scale_type, higher_is_better, highest_value, passing_value, letter_order
    INTO policy_record
    FROM university_passing_policy
    WHERE university_key = p_university_key AND is_active = TRUE;
    
    -- If no policy found, return false (strict default)
    IF NOT FOUND THEN
        RETURN FALSE;
    END IF;
    
    -- Handle different scale types
    CASE policy_record.scale_type
        WHEN 'NUMERIC_1_TO_5', 'NUMERIC_0_TO_4' THEN
            -- Try to convert grades to numeric
            BEGIN
                grade_numeric := p_raw_grade::NUMERIC;
                passing_numeric := policy_record.passing_value::NUMERIC;
                
                -- Apply direction logic
                IF policy_record.higher_is_better THEN
                    -- For 0-4 scale, grade must be >= passing value
                    RETURN grade_numeric >= passing_numeric;
                ELSE
                    -- For 1-5 scale, grade must be <= passing value
                    RETURN grade_numeric <= passing_numeric;
                END IF;
            EXCEPTION WHEN OTHERS THEN
                -- Non-numeric grade fails
                RETURN FALSE;
            END;
            
        WHEN 'PERCENT' THEN
            -- Handle percentage grades
            BEGIN
                grade_numeric := p_raw_grade::NUMERIC;
                passing_numeric := policy_record.passing_value::NUMERIC;
                
                -- For percentages, higher is always better
                RETURN grade_numeric >= passing_numeric;
            EXCEPTION WHEN OTHERS THEN
                RETURN FALSE;
            END;
            
        WHEN 'LETTER' THEN
            -- Handle letter grades using letter_order array
            IF policy_record.letter_order IS NULL THEN
                RETURN FALSE;
            END IF;
            
            -- Find index of grade and passing grade in letter_order array
            SELECT idx INTO letter_index FROM unnest(policy_record.letter_order) WITH ORDINALITY AS t(letter, idx) WHERE letter = p_raw_grade;
            SELECT idx INTO passing_index FROM unnest(policy_record.letter_order) WITH ORDINALITY AS t(letter, idx) WHERE letter = policy_record.passing_value;
            
            -- If either grade not found in order, fail
            IF letter_index IS NULL OR passing_index IS NULL THEN
                RETURN FALSE;
            END IF;
            
            -- Pass if grade index <= passing index (earlier in array means better)
            RETURN letter_index <= passing_index;
            
        ELSE
            -- Unknown scale type
            RETURN FALSE;
    END CASE;
END;
$$ LANGUAGE plpgsql STABLE;

-- Create update trigger for updated_at
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS update_grading_policy_updated_at ON university_passing_policy;
CREATE TRIGGER update_grading_policy_updated_at
    BEFORE UPDATE ON university_passing_policy
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();