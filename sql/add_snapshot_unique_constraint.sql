-- Add missing UNIQUE constraint to distribution_student_snapshot
-- This constraint is required for ON CONFLICT in the snapshot creation query

ALTER TABLE distribution_student_snapshot
ADD CONSTRAINT distribution_student_snapshot_unique 
UNIQUE (snapshot_id, student_id);

-- Create index for performance
CREATE INDEX IF NOT EXISTS idx_dss_snapshot_student 
ON distribution_student_snapshot(snapshot_id, student_id);

-- Also add NOT NULL constraint on snapshot_id for data integrity
ALTER TABLE distribution_student_snapshot
ALTER COLUMN snapshot_id SET NOT NULL;

SELECT 'Constraints added successfully!' as status;
