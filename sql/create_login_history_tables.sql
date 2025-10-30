-- ============================================
-- Login History & Active Sessions Implementation
-- Date: October 31, 2025
-- Purpose: Track student login activity and manage active sessions
-- ============================================

-- Table 1: Student Login History
-- Tracks all login attempts (successful and failed)
CREATE TABLE IF NOT EXISTS student_login_history (
    history_id SERIAL PRIMARY KEY,
    student_id INT NOT NULL,
    login_time TIMESTAMP DEFAULT NOW(),
    logout_time TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    device_type VARCHAR(50),
    browser VARCHAR(100),
    os VARCHAR(100),
    location VARCHAR(255),
    login_method VARCHAR(50) DEFAULT 'password',
    status VARCHAR(20) DEFAULT 'success',
    session_id VARCHAR(255),
    failure_reason TEXT
);

-- Table 2: Student Active Sessions
-- Tracks currently active login sessions
CREATE TABLE IF NOT EXISTS student_active_sessions (
    session_id VARCHAR(255) PRIMARY KEY,
    student_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    last_activity TIMESTAMP DEFAULT NOW(),
    ip_address VARCHAR(45),
    user_agent TEXT,
    device_type VARCHAR(50),
    browser VARCHAR(100),
    os VARCHAR(100),
    location VARCHAR(255),
    expires_at TIMESTAMP,
    is_current BOOLEAN DEFAULT FALSE
);

-- Add foreign keys separately (will skip if students table doesn't exist or constraint already exists)
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
EXCEPTION
    WHEN OTHERS THEN
        RAISE NOTICE 'Foreign key constraints skipped - students table may not be ready';
END $$;

-- Indexes for better query performance
CREATE INDEX IF NOT EXISTS idx_login_history_student ON student_login_history(student_id);
CREATE INDEX IF NOT EXISTS idx_login_history_time ON student_login_history(login_time DESC);
CREATE INDEX IF NOT EXISTS idx_login_history_status ON student_login_history(status);
CREATE INDEX IF NOT EXISTS idx_active_sessions_student ON student_active_sessions(student_id);
CREATE INDEX IF NOT EXISTS idx_active_sessions_activity ON student_active_sessions(last_activity);

-- Add comments
COMMENT ON TABLE student_login_history IS 'Records all student login attempts and activity';
COMMENT ON TABLE student_active_sessions IS 'Tracks currently active student sessions for security management';

-- Display created tables
SELECT 
    'student_login_history' as table_name,
    COUNT(*) as row_count
FROM student_login_history
UNION ALL
SELECT 
    'student_active_sessions' as table_name,
    COUNT(*) as row_count
FROM student_active_sessions;

-- Show table structures (using standard SQL instead of \d)
SELECT 
    table_name,
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns
WHERE table_name IN ('student_login_history', 'student_active_sessions')
ORDER BY table_name, ordinal_position;
