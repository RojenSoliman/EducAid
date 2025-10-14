-- Document archival and distribution cycle management
-- This migration adds support for archiving documents during distributions
-- and tracking when students need to re-upload documents

-- Create document archives table to store old documents
CREATE TABLE IF NOT EXISTS document_archives (
    archive_id SERIAL PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    original_document_id INTEGER,
    document_type VARCHAR(50) NOT NULL,
    file_path TEXT NOT NULL,
    original_upload_date TIMESTAMP,
    archived_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    distribution_snapshot_id INTEGER,
    academic_year VARCHAR(20),
    semester VARCHAR(20),
    FOREIGN KEY (distribution_snapshot_id) REFERENCES distribution_snapshots(snapshot_id)
);

-- Add index for efficient queries
CREATE INDEX IF NOT EXISTS idx_document_archives_student_id ON document_archives(student_id);
CREATE INDEX IF NOT EXISTS idx_document_archives_distribution ON document_archives(distribution_snapshot_id);

-- Add column to students table to track last distribution they participated in
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS last_distribution_snapshot_id INTEGER,
ADD COLUMN IF NOT EXISTS needs_document_upload BOOLEAN DEFAULT FALSE;

-- Add foreign key constraint for last distribution
ALTER TABLE students 
ADD CONSTRAINT fk_students_last_distribution 
FOREIGN KEY (last_distribution_snapshot_id) 
REFERENCES distribution_snapshots(snapshot_id);

-- Update existing students to set needs_document_upload based on their status
-- Students who are 'active' or 'given' and don't have a recent registration should need upload
UPDATE students 
SET needs_document_upload = TRUE 
WHERE status IN ('active', 'given', 'applicant') 
AND application_date < (
    SELECT COALESCE(MAX(finalized_at), '1970-01-01'::timestamp) 
    FROM distribution_snapshots
);

-- Add trigger to automatically set needs_document_upload for new registrations
CREATE OR REPLACE FUNCTION set_document_upload_needs()
RETURNS TRIGGER AS $$
BEGIN
    -- New registrations after the last distribution don't need upload tab
    -- (they upload during registration)
    IF NEW.status = 'under_registration' OR NEW.application_date > (
        SELECT COALESCE(MAX(finalized_at), '1970-01-01'::timestamp) 
        FROM distribution_snapshots
    ) THEN
        NEW.needs_document_upload = FALSE;
    ELSE
        NEW.needs_document_upload = TRUE;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_set_document_upload_needs
    BEFORE INSERT OR UPDATE ON students
    FOR EACH ROW
    EXECUTE FUNCTION set_document_upload_needs();

-- Create function to archive documents during distribution finalization
CREATE OR REPLACE FUNCTION archive_student_documents(
    p_student_id VARCHAR(50),
    p_distribution_snapshot_id INTEGER,
    p_academic_year VARCHAR(20),
    p_semester VARCHAR(20)
) RETURNS VOID AS $$
BEGIN
    -- Archive documents table entries
    INSERT INTO document_archives (
        student_id, original_document_id, document_type, file_path, 
        original_upload_date, distribution_snapshot_id, academic_year, semester
    )
    SELECT 
        d.student_id, d.document_id, d.type, d.file_path,
        d.upload_date, p_distribution_snapshot_id, p_academic_year, p_semester
    FROM documents d
    WHERE d.student_id = p_student_id;
    
    -- Archive grade uploads
    INSERT INTO document_archives (
        student_id, original_document_id, document_type, file_path,
        original_upload_date, distribution_snapshot_id, academic_year, semester
    )
    SELECT 
        g.student_id, g.upload_id, 'grades', g.file_path,
        g.upload_date, p_distribution_snapshot_id, p_academic_year, p_semester
    FROM grade_uploads g
    WHERE g.student_id = p_student_id;
END;
$$ LANGUAGE plpgsql;

-- Comment explaining the logic
COMMENT ON TABLE document_archives IS 'Stores archived documents from previous distribution cycles';
COMMENT ON COLUMN students.needs_document_upload IS 'TRUE if student needs to use Upload Documents tab (existing students), FALSE if documents come from registration (new students)';
COMMENT ON COLUMN students.last_distribution_snapshot_id IS 'References the last distribution this student participated in';