-- Blacklist Feature Database Updates
-- ===============================================

-- 1. Update students table to include blacklisted status
ALTER TABLE students 
DROP CONSTRAINT IF EXISTS students_status_check;

ALTER TABLE students 
ADD CONSTRAINT students_status_check 
CHECK (status IN ('under_registration', 'applicant', 'active', 'disabled', 'given', 'blacklisted'));

-- 2. Create blacklisted_students table for detailed blacklist information
CREATE TABLE IF NOT EXISTS blacklisted_students (
    blacklist_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id) ON DELETE CASCADE,
    reason_category TEXT CHECK (reason_category IN ('fraudulent_activity', 'academic_misconduct', 'system_abuse', 'other')) NOT NULL,
    detailed_reason TEXT,
    blacklisted_by INT REFERENCES admins(admin_id),
    blacklisted_at TIMESTAMP DEFAULT NOW(),
    admin_email TEXT NOT NULL,
    admin_notes TEXT
);

-- 3. Create index for better performance
CREATE INDEX IF NOT EXISTS idx_blacklisted_students_student_id ON blacklisted_students(student_id);
CREATE INDEX IF NOT EXISTS idx_blacklisted_students_blacklisted_by ON blacklisted_students(blacklisted_by);

-- 4. Create admin blacklist verification table for OTP verification
CREATE TABLE IF NOT EXISTS admin_blacklist_verifications (
    id SERIAL PRIMARY KEY,
    admin_id INT REFERENCES admins(admin_id) ON DELETE CASCADE,
    student_id INT REFERENCES students(student_id) ON DELETE CASCADE,
    otp VARCHAR(6) NOT NULL,
    email VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW(),
    session_data JSONB -- Store blacklist form data temporarily
);

-- 5. Create index for faster lookups
CREATE INDEX IF NOT EXISTS idx_admin_blacklist_verifications_admin_id ON admin_blacklist_verifications(admin_id);
CREATE INDEX IF NOT EXISTS idx_admin_blacklist_verifications_expires ON admin_blacklist_verifications(expires_at);