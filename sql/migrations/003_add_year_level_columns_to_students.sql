-- ============================================================================
-- MIGRATION: Add Year Level Management Columns to Students Table
-- ============================================================================
-- Purpose: Add columns for tracking academic progression and course info
-- Date: October 31, 2025
-- Author: System Migration
-- Dependencies: academic_years table, courses_mapping table
-- ============================================================================

-- Add new columns to students table
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS first_registered_academic_year VARCHAR(20),
ADD COLUMN IF NOT EXISTS current_academic_year VARCHAR(20),
ADD COLUMN IF NOT EXISTS year_level_history JSONB DEFAULT '[]'::jsonb,
ADD COLUMN IF NOT EXISTS last_year_level_update TIMESTAMP,
ADD COLUMN IF NOT EXISTS course VARCHAR(255),
ADD COLUMN IF NOT EXISTS course_verified BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS expected_graduation_year INTEGER;

-- Add comments to document the columns
COMMENT ON COLUMN students.first_registered_academic_year IS 'The academic year when student first registered (e.g., "2024-2025"). Never changes.';
COMMENT ON COLUMN students.current_academic_year IS 'The current academic year for this student. Updates during year advancement.';
COMMENT ON COLUMN students.year_level_history IS 'JSON array tracking year level progression: [{year: "2024-2025", level: "1st Year", updated_at: "2024-06-15"}]';
COMMENT ON COLUMN students.last_year_level_update IS 'Timestamp of last year level advancement. Prevents double advancement.';
COMMENT ON COLUMN students.course IS 'Student degree program (normalized from OCR). E.g., "BS Computer Science"';
COMMENT ON COLUMN students.course_verified IS 'TRUE if course was verified from enrollment form via OCR';
COMMENT ON COLUMN students.expected_graduation_year IS 'Calculated graduation year based on registration year + program duration';

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_students_first_registered_year ON students(first_registered_academic_year);
CREATE INDEX IF NOT EXISTS idx_students_current_academic_year ON students(current_academic_year);
CREATE INDEX IF NOT EXISTS idx_students_course ON students(course);
CREATE INDEX IF NOT EXISTS idx_students_course_verified ON students(course_verified);
CREATE INDEX IF NOT EXISTS idx_students_expected_graduation ON students(expected_graduation_year);
CREATE INDEX IF NOT EXISTS idx_students_year_level_history ON students USING gin(year_level_history);

-- Create function to initialize year level history when year_level_id changes
CREATE OR REPLACE FUNCTION initialize_year_level_history()
RETURNS TRIGGER AS $$
DECLARE
    year_level_name TEXT;
BEGIN
    -- If year_level_history is empty and we have a year_level_id, initialize it
    IF (NEW.year_level_history = '[]'::jsonb OR NEW.year_level_history IS NULL) 
       AND NEW.year_level_id IS NOT NULL 
       AND NEW.current_academic_year IS NOT NULL THEN
        
        -- Get the year level name from year_levels table
        SELECT yl.year_level_name INTO year_level_name
        FROM year_levels yl
        WHERE yl.year_level_id = NEW.year_level_id;
        
        -- Initialize history with current year level
        NEW.year_level_history = jsonb_build_array(
            jsonb_build_object(
                'academic_year', NEW.current_academic_year,
                'year_level_id', NEW.year_level_id,
                'year_level_name', COALESCE(year_level_name, 'Unknown'),
                'updated_at', NOW()
            )
        );
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Create trigger to auto-initialize year_level_history
DROP TRIGGER IF EXISTS trigger_initialize_year_level_history ON students;
CREATE TRIGGER trigger_initialize_year_level_history
    BEFORE INSERT OR UPDATE OF year_level_id, current_academic_year
    ON students
    FOR EACH ROW
    EXECUTE FUNCTION initialize_year_level_history();

-- Create function to update expected_graduation_year based on course
-- Formula: registration_year + (program_duration - year_level + 1)
-- Example: 2025 + (4 - 3 + 1) = 2027 (3rd year student in 4-year course)
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
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Create trigger to auto-calculate expected_graduation_year
DROP TRIGGER IF EXISTS trigger_calculate_graduation_year ON students;
CREATE TRIGGER trigger_calculate_graduation_year
    BEFORE INSERT OR UPDATE OF course, first_registered_academic_year, year_level_id
    ON students
    FOR EACH ROW
    EXECUTE FUNCTION calculate_expected_graduation_year();

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================

DO $$
BEGIN
    RAISE NOTICE '✓ Added 7 new columns to students table';
    RAISE NOTICE '✓ Created 6 indexes for performance';
    RAISE NOTICE '✓ Created trigger for year_level_history initialization';
    RAISE NOTICE '✓ Created trigger for expected_graduation_year calculation';
    RAISE NOTICE '';
    RAISE NOTICE 'Students table is now ready for year level management!';
END $$;
