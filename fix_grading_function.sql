-- Fix the grading validation function to handle edge cases better
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
    -- Debug logging
    RAISE NOTICE 'grading_is_passing called with university: %, grade: %', p_university_key, p_raw_grade;
    
    -- Get the active grading policy for the university
    SELECT scale_type, higher_is_better, highest_value, passing_value, letter_order
    INTO policy_record
    FROM grading.university_passing_policy
    WHERE university_key = p_university_key AND is_active = TRUE;
    
    -- If no policy found, return false (strict default)
    IF NOT FOUND THEN
        RAISE NOTICE 'No policy found for university: %', p_university_key;
        RETURN FALSE;
    END IF;
    
    RAISE NOTICE 'Policy found - scale: %, higher_better: %, passing: %', 
                 policy_record.scale_type, policy_record.higher_is_better, policy_record.passing_value;
    
    -- Handle different scale types
    CASE policy_record.scale_type
        WHEN 'NUMERIC_1_TO_5', 'NUMERIC_0_TO_4' THEN
            -- Try to convert grades to numeric
            BEGIN
                grade_numeric := p_raw_grade::NUMERIC;
                passing_numeric := policy_record.passing_value::NUMERIC;
                
                RAISE NOTICE 'Numeric conversion - grade: %, passing: %', grade_numeric, passing_numeric;
                
                -- Apply direction logic
                IF policy_record.higher_is_better THEN
                    -- For 0-4 scale, grade must be >= passing value
                    RAISE NOTICE 'Higher is better logic: % >= %', grade_numeric, passing_numeric;
                    RETURN grade_numeric >= passing_numeric;
                ELSE
                    -- For 1-5 scale, grade must be <= passing value
                    RAISE NOTICE 'Lower is better logic: % <= %', grade_numeric, passing_numeric;
                    RETURN grade_numeric <= passing_numeric;
                END IF;
            EXCEPTION WHEN OTHERS THEN
                -- Non-numeric grade fails
                RAISE NOTICE 'Failed to convert grade to numeric: %', p_raw_grade;
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
            RAISE NOTICE 'Unknown scale type: %', policy_record.scale_type;
            RETURN FALSE;
    END CASE;
END;
$$ LANGUAGE plpgsql;