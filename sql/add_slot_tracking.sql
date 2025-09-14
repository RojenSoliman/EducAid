-- Migration: Add slot_id to students table for tracking registration slot
-- Purpose: Maintain data integrity by permanently tracking which slot each student registered under
-- Date: September 14, 2025

-- Add slot_id column to students table
ALTER TABLE students 
ADD COLUMN slot_id INTEGER REFERENCES signup_slots(slot_id);

-- Add index for performance
CREATE INDEX IF NOT EXISTS idx_students_slot_id ON students(slot_id);

-- Add comment for documentation
COMMENT ON COLUMN students.slot_id IS 'Tracks which signup slot the student originally registered under for audit trail and data integrity';

-- Update existing students to set slot_id to current active slot (if any)
-- This is a one-time migration for existing data
DO $$
DECLARE
    current_slot_id INTEGER;
BEGIN
    -- Get the current active slot
    SELECT slot_id INTO current_slot_id 
    FROM signup_slots 
    WHERE is_active = TRUE 
    ORDER BY created_at DESC 
    LIMIT 1;
    
    -- If there's an active slot, assign it to students who don't have a slot_id
    IF current_slot_id IS NOT NULL THEN
        UPDATE students 
        SET slot_id = current_slot_id 
        WHERE slot_id IS NULL 
        AND status IN ('under_registration', 'applicant', 'active', 'given');
        
        RAISE NOTICE 'Updated % students with slot_id %', 
            (SELECT COUNT(*) FROM students WHERE slot_id = current_slot_id), 
            current_slot_id;
    ELSE
        RAISE NOTICE 'No active slot found. Students will get slot_id when registering.';
    END IF;
END $$;

-- Verify the migration
SELECT 
    COUNT(*) as total_students,
    COUNT(slot_id) as students_with_slot,
    COUNT(*) - COUNT(slot_id) as students_without_slot
FROM students;

-- Show breakdown by slot
SELECT 
    ss.slot_id,
    ss.semester,
    ss.academic_year,
    ss.is_active,
    COUNT(s.student_id) as student_count
FROM signup_slots ss
LEFT JOIN students s ON ss.slot_id = s.slot_id
GROUP BY ss.slot_id, ss.semester, ss.academic_year, ss.is_active
ORDER BY ss.created_at DESC;