-- ============================================
-- Fix student_id column type mismatch
-- Date: October 31, 2025
-- Problem: student_id in students table is VARCHAR, but INT in session tables
-- Solution: Alter session tables to use VARCHAR for student_id
-- ============================================

-- First, drop the existing foreign key constraints
DO $$ 
BEGIN
    -- Drop foreign key from student_login_history
    IF EXISTS (
        SELECT 1 FROM information_schema.table_constraints 
        WHERE constraint_name = 'student_login_history_student_id_fkey'
    ) THEN
        ALTER TABLE student_login_history DROP CONSTRAINT student_login_history_student_id_fkey;
    END IF;
    
    -- Drop foreign key from student_active_sessions
    IF EXISTS (
        SELECT 1 FROM information_schema.table_constraints 
        WHERE constraint_name = 'student_active_sessions_student_id_fkey'
    ) THEN
        ALTER TABLE student_active_sessions DROP CONSTRAINT student_active_sessions_student_id_fkey;
    END IF;
END $$;

-- Alter student_id column type in student_login_history
ALTER TABLE student_login_history 
ALTER COLUMN student_id TYPE VARCHAR(50);

-- Alter student_id column type in student_active_sessions
ALTER TABLE student_active_sessions 
ALTER COLUMN student_id TYPE VARCHAR(50);

-- Recreate foreign key constraints with the correct type
DO $$ 
BEGIN
    -- Add foreign key for login history
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints 
        WHERE constraint_name = 'student_login_history_student_id_fkey'
    ) THEN
        ALTER TABLE student_login_history 
        ADD CONSTRAINT student_login_history_student_id_fkey 
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE;
    END IF;
    
    -- Add foreign key for active sessions
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints 
        WHERE constraint_name = 'student_active_sessions_student_id_fkey'
    ) THEN
        ALTER TABLE student_active_sessions 
        ADD CONSTRAINT student_active_sessions_student_id_fkey 
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE;
    END IF;
END $$;

-- Verify the changes
SELECT 
    table_name, 
    column_name, 
    data_type, 
    character_maximum_length
FROM information_schema.columns 
WHERE table_name IN ('students', 'student_login_history', 'student_active_sessions')
  AND column_name = 'student_id'
ORDER BY table_name;
