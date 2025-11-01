-- Fix graduation year calculation to consider current year level
-- Bug: Was calculating registration_year + program_duration (ignoring year level)
-- Fix: Calculate registration_year + (program_duration - year_level + 1)

-- Example:
--   Academic Year: 2025-2026 (start = 2025)
--   Course Duration: 4 years
--   Current Year Level: 3rd year
--   WRONG: 2025 + 4 = 2029
--   CORRECT: 2025 + (4 - 3 + 1) = 2025 + 2 = 2027

CREATE OR REPLACE FUNCTION calculate_expected_graduation_year()
RETURNS TRIGGER AS $$
DECLARE
    program_duration INTEGER;
    registration_year INTEGER;
    current_year_level INTEGER;
    remaining_years INTEGER;
BEGIN
    -- Only calculate if we have course and first_registered_academic_year
    IF NEW.course IS NOT NULL AND NEW.first_registered_academic_year IS NOT NULL THEN
        
        -- Get program duration from courses_mapping
        SELECT cm.program_duration INTO program_duration
        FROM courses_mapping cm
        WHERE cm.normalized_course = NEW.course
        LIMIT 1;
        
        -- If course found in mapping
        IF program_duration IS NOT NULL THEN
            -- Extract year from "2024-2025" format (take first year)
            registration_year := CAST(SPLIT_PART(NEW.first_registered_academic_year, '-', 1) AS INTEGER);
            
            -- Get current year level (default to 1 if not set)
            current_year_level := COALESCE(NEW.year_level_id, 1);
            
            -- Calculate remaining years: program_duration - current_year_level + 1
            -- Example: 4-year course, currently 3rd year = 4 - 3 + 1 = 2 years remaining
            remaining_years := program_duration - current_year_level + 1;
            
            -- Ensure remaining years is never negative (safety check)
            IF remaining_years < 0 THEN
                remaining_years := 0;
            END IF;
            
            -- Calculate graduation year: registration_year + remaining_years
            NEW.expected_graduation_year := registration_year + remaining_years;
            
            -- Log the calculation for debugging
            RAISE NOTICE 'Graduation Calc: Reg Year=%, Course Duration=%, Year Level=%, Remaining=%, Expected Grad=%',
                registration_year, program_duration, current_year_level, remaining_years, NEW.expected_graduation_year;
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Recreate trigger (in case it was dropped)
DROP TRIGGER IF EXISTS trigger_calculate_graduation_year ON students;
CREATE TRIGGER trigger_calculate_graduation_year
    BEFORE INSERT OR UPDATE OF course, first_registered_academic_year, year_level_id
    ON students
    FOR EACH ROW
    EXECUTE FUNCTION calculate_expected_graduation_year();

-- Update existing students with corrected graduation years
DO $$
DECLARE
    updated_count INTEGER := 0;
    student_record RECORD;
BEGIN
    RAISE NOTICE 'Recalculating graduation years for existing students...';
    
    -- Loop through all students with graduation year data
    FOR student_record IN 
        SELECT 
            student_id,
            course,
            first_registered_academic_year,
            year_level_id,
            expected_graduation_year AS old_graduation_year
        FROM students
        WHERE course IS NOT NULL 
          AND first_registered_academic_year IS NOT NULL
        ORDER BY student_id
    LOOP
        -- Update to trigger recalculation
        UPDATE students 
        SET year_level_id = year_level_id  -- Dummy update to trigger function
        WHERE student_id = student_record.student_id;
        
        updated_count := updated_count + 1;
    END LOOP;
    
    RAISE NOTICE '✓ Updated % student records', updated_count;
END $$;

-- Verify the fix with a sample query
SELECT 
    student_id,
    CONCAT(first_name, ' ', last_name) AS name,
    course,
    first_registered_academic_year AS academic_year,
    year_level_id AS current_year,
    expected_graduation_year AS graduation_year,
    CAST(SPLIT_PART(first_registered_academic_year, '-', 1) AS INTEGER) AS reg_year_extracted
FROM students
WHERE expected_graduation_year IS NOT NULL
ORDER BY student_id
LIMIT 10;

RAISE NOTICE '✓ Graduation year calculation fixed!';
RAISE NOTICE '  - Now considers current year level';
RAISE NOTICE '  - Formula: registration_year + (program_duration - year_level + 1)';
RAISE NOTICE '  - Trigger updated to fire on year_level_id changes';
