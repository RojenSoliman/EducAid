-- ===============================================
-- UNIVERSITY GRADING POLICY SCHEMA
-- Per-subject grade validation system
-- ===============================================

-- Create grading schema if it doesn't exist
CREATE SCHEMA IF NOT EXISTS grading;

-- Create university grading policy table
CREATE TABLE IF NOT EXISTS grading.university_passing_policy (
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
CREATE INDEX IF NOT EXISTS idx_university_policy_active ON grading.university_passing_policy(university_key, is_active);

-- Insert grading policies based on provided university grading systems
INSERT INTO grading.university_passing_policy (university_key, scale_type, higher_is_better, highest_value, passing_value, is_active) VALUES

-- State Universities with 1-5 scale (1.00 = highest, 3.00 = passing threshold)
-- RULE: For 1–5 scale, subject PASSES if grade ≤ 3.00, FAILS if grade > 3.00
('PUP_STO_TOMAS', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('LSPU_MAIN', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('LSPU_LB', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('LSPU_SP', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('LSPU_SIN', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('BSU_MAIN', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('BSU_ARASOF', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('BSU_ALANGILAN', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('BSU_LEMERY', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('BSU_LIPA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('BSU_MALVAR', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('BSU_ROSARIO', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_MAIN', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_BACOOR', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_CARMONA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_CAVITE_CITY', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_DASMARINAS', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_GENTRI', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_IMUS', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_NAIC', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_ROSARIO', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_SILANG', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_TANZA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CVSU_TRECE', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('URS_MAIN', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('URS_ANGONO', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('URS_ANTIPOLO', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('URS_BINANGONAN', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('URS_CAINTA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('URS_CARDONA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('URS_MORONG', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('URS_PILILLA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('URS_RODRIGUEZ', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('QPPU_MAIN', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('SLSU_MAIN', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('SLSU_ALABAT', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('SLSU_CATANAUAN', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('SLSU_GUMACA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('SLSU_INFANTA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('SLSU_TAYABAS', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),

-- Private universities with 0-4 scale (4 highest, 1 passing, higher is better)
('DLSU_DASMARINAS', 'NUMERIC_0_TO_4', TRUE, '4.00', '1.00', TRUE),
('DLSL_LIPA', 'NUMERIC_0_TO_4', TRUE, '4.00', '1.00', TRUE),
('MCL', 'NUMERIC_0_TO_4', TRUE, '4.00', '1.00', TRUE),
('UA&P', 'NUMERIC_0_TO_4', TRUE, '4.00', '1.00', TRUE),
('ACSL', 'NUMERIC_0_TO_4', TRUE, '4.00', '1.00', TRUE),
('ADMU_NUVALI', 'NUMERIC_0_TO_4', TRUE, '4.00', '1.00', TRUE),
('MC_NUVALI', 'NUMERIC_0_TO_4', TRUE, '4.00', '1.00', TRUE),
('NU_LAGUNA', 'NUMERIC_0_TO_4', TRUE, '4.00', '1.00', TRUE),

-- Universities with 1-5 scale (continued)
('UPHSD_LASPINAS', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('UPHSD_MOLINO', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('LPU_BATANGAS', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('LPU_LAGUNA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('TUP_CAVITE', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('FAITH', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('STI_REGION4A', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('AMA_REGION4A', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('FEU_CAVITE', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('EAC_CAVITE', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('UB', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('LPU_CAVITE', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('OLFU_ANTIPOLO', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('OLFU_LAGUNA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('PLM_BATANGAS', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CSJL_BATANGAS', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('HAU_LAGUNA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('UST_LAGUNA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('ADU_CAVITE', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('JRU_CAVITE', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CLL', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('UCC_CAVITE', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CEU_LAGUNA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('TUA_QUEZON', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('MSEUF', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('SLC', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('QCU_BATANGAS', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('SMCL', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('MABINI', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('LCBA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('BEC', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('SPCF', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CC', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('LNC', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('GSC', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('RTU', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('SBC', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('UPHR', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('CSAP', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('PCU_DASMARINAS', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('WUP_CAVITE', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('SFC_LAGUNA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('PHILSCA', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('MPCF', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),
('TI', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE),

-- Default policy for unlisted universities
('OTHER', 'NUMERIC_1_TO_5', FALSE, '1.00', '3.00', TRUE)

ON CONFLICT (university_key, is_active) WHERE is_active = TRUE DO NOTHING;

-- Create PostgreSQL helper function for grade validation
CREATE OR REPLACE FUNCTION grading.grading_is_passing(
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
    FROM grading.university_passing_policy
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
CREATE OR REPLACE FUNCTION grading.update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_grading_policy_updated_at
    BEFORE UPDATE ON grading.university_passing_policy
    FOR EACH ROW
    EXECUTE FUNCTION grading.update_updated_at_column();