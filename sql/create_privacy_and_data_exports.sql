-- ============================================
-- Privacy & Data: Export Requests + Privacy Settings
-- Date: 2025-10-31
-- Purpose: Support "Download My Data" and student privacy visibility flags
-- ============================================

-- Export Requests table
CREATE TABLE IF NOT EXISTS student_data_export_requests (
    request_id SERIAL PRIMARY KEY,
    student_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | processing | ready | failed | expired
    requested_at TIMESTAMP NOT NULL DEFAULT NOW(),
    processed_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    download_token VARCHAR(128) NULL,
    file_path TEXT NULL,
    file_size_bytes BIGINT NULL,
    error_message TEXT NULL,
    requested_by_ip VARCHAR(45) NULL,
    user_agent TEXT NULL
);

DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints 
        WHERE constraint_name = 'student_data_export_requests_student_id_fkey'
    ) THEN
        ALTER TABLE student_data_export_requests 
        ADD CONSTRAINT student_data_export_requests_student_id_fkey 
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE;
    END IF;
EXCEPTION WHEN OTHERS THEN
    RAISE NOTICE 'FK create skipped for student_data_export_requests (students may not exist yet)';
END $$;

CREATE INDEX IF NOT EXISTS idx_export_requests_student ON student_data_export_requests(student_id);
CREATE INDEX IF NOT EXISTS idx_export_requests_status ON student_data_export_requests(status);
CREATE INDEX IF NOT EXISTS idx_export_requests_requested_at ON student_data_export_requests(requested_at DESC);

COMMENT ON TABLE student_data_export_requests IS 'Tracks student self-service data export requests';
