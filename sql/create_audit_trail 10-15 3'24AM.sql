-- Audit Trail System for EducAid
-- Tracks all major events, logins, logouts, and administrative actions
-- Created: 2025-10-15

-- Drop table if exists (for clean reinstall)
-- DROP TABLE IF EXISTS audit_logs CASCADE;

-- Create audit_logs table
CREATE TABLE IF NOT EXISTS audit_logs (
    audit_id SERIAL PRIMARY KEY,
    user_id INTEGER,                    -- ID of user who performed action
    user_type VARCHAR(20) NOT NULL,     -- 'admin' or 'student'
    username VARCHAR(255),              -- Username for quick reference
    event_type VARCHAR(50) NOT NULL,    -- Type of event (see categories below)
    event_category VARCHAR(30) NOT NULL, -- Category grouping
    action_description TEXT NOT NULL,   -- Human-readable description
    status VARCHAR(20) DEFAULT 'success', -- 'success', 'failure', 'warning'
    ip_address VARCHAR(45),             -- IPv4 or IPv6
    user_agent TEXT,                    -- Browser/client info
    request_method VARCHAR(10),         -- GET, POST, etc.
    request_uri TEXT,                   -- URL path
    affected_table VARCHAR(100),        -- Table affected by action
    affected_record_id INTEGER,         -- ID of affected record
    old_values JSONB,                   -- Previous state (for updates)
    new_values JSONB,                   -- New state
    metadata JSONB,                     -- Additional context
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    session_id VARCHAR(255)             -- PHP session ID for correlation
);

-- Create indexes for common queries
CREATE INDEX idx_audit_user ON audit_logs(user_id, user_type);
CREATE INDEX idx_audit_event_type ON audit_logs(event_type);
CREATE INDEX idx_audit_category ON audit_logs(event_category);
CREATE INDEX idx_audit_created_at ON audit_logs(created_at DESC);
CREATE INDEX idx_audit_affected ON audit_logs(affected_table, affected_record_id);
CREATE INDEX idx_audit_status ON audit_logs(status);
CREATE INDEX idx_audit_ip ON audit_logs(ip_address);

-- Create composite index for common filtered queries
CREATE INDEX idx_audit_user_date ON audit_logs(user_type, created_at DESC);
CREATE INDEX idx_audit_category_date ON audit_logs(event_category, created_at DESC);

-- Add comments for documentation
COMMENT ON TABLE audit_logs IS 'Comprehensive audit trail for all system events';
COMMENT ON COLUMN audit_logs.event_type IS 'Specific event: login, logout, slot_opened, applicant_approved, etc.';
COMMENT ON COLUMN audit_logs.event_category IS 'Grouping: authentication, slot_management, applicant_management, payroll, schedule, profile, distribution, system';
COMMENT ON COLUMN audit_logs.old_values IS 'JSON snapshot of data before change';
COMMENT ON COLUMN audit_logs.new_values IS 'JSON snapshot of data after change';
COMMENT ON COLUMN audit_logs.metadata IS 'Additional context like reason, notes, batch info, etc.';

-- Insert initial system event
INSERT INTO audit_logs (
    user_id,
    user_type,
    username,
    event_type,
    event_category,
    action_description,
    status,
    ip_address,
    metadata
) VALUES (
    NULL,
    'system',
    'system',
    'audit_system_initialized',
    'system',
    'Audit trail system initialized and ready',
    'success',
    '127.0.0.1',
    '{"version": "1.0", "created_at": "2025-10-15"}'::jsonb
);

-- ============================================
-- EVENT TYPE REFERENCE (for documentation)
-- ============================================
-- 
-- AUTHENTICATION (event_category: 'authentication')
--   - admin_login, student_login
--   - admin_logout, student_logout
--   - login_failed, session_timeout
--
-- SLOT MANAGEMENT (event_category: 'slot_management')
--   - slot_opened, slot_closed
--   - slot_updated, slot_deleted
--
-- APPLICANT MANAGEMENT (event_category: 'applicant_management')
--   - applicant_registered
--   - applicant_approved, applicant_rejected
--   - applicant_verified, applicant_unverified
--   - applicant_migrated
--
-- PAYROLL (event_category: 'payroll')
--   - payroll_generated
--   - payroll_number_changed
--   - qr_code_generated
--
-- SCHEDULE (event_category: 'schedule')
--   - schedule_created
--   - schedule_published, schedule_unpublished
--   - schedule_cleared
--
-- PROFILE (event_category: 'profile')
--   - email_changed
--   - password_changed
--   - profile_updated
--
-- DISTRIBUTION (event_category: 'distribution')
--   - distribution_started
--   - distribution_activated
--   - distribution_completed
--   - documents_deadline_set
--
-- DOCUMENTS (event_category: 'documents')
--   - document_uploaded
--   - document_verified, document_rejected
--   - document_deleted
--
-- SYSTEM (event_category: 'system')
--   - config_changed
--   - bulk_operation
--   - data_export
--   - system_maintenance
--
-- ============================================

-- Create view for recent admin activity
CREATE OR REPLACE VIEW v_recent_admin_activity AS
SELECT 
    audit_id,
    username,
    event_type,
    event_category,
    action_description,
    status,
    ip_address,
    created_at
FROM audit_logs
WHERE user_type = 'admin'
ORDER BY created_at DESC
LIMIT 100;

-- Create view for recent student activity
CREATE OR REPLACE VIEW v_recent_student_activity AS
SELECT 
    audit_id,
    username,
    event_type,
    event_category,
    action_description,
    status,
    ip_address,
    created_at
FROM audit_logs
WHERE user_type = 'student'
ORDER BY created_at DESC
LIMIT 100;

-- Create view for failed login attempts
CREATE OR REPLACE VIEW v_failed_logins AS
SELECT 
    audit_id,
    user_type,
    username,
    ip_address,
    user_agent,
    metadata->>'reason' as failure_reason,
    created_at
FROM audit_logs
WHERE event_type = 'login_failed'
ORDER BY created_at DESC;

-- Grant permissions (adjust as needed for your setup)
-- GRANT SELECT, INSERT ON audit_logs TO your_app_user;
-- GRANT SELECT ON v_recent_admin_activity TO your_app_user;
-- GRANT SELECT ON v_recent_student_activity TO your_app_user;
-- GRANT SELECT ON v_failed_logins TO your_app_user;

-- Success message
SELECT 'Audit trail system created successfully!' AS status;
