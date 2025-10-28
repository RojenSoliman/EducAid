-- Add deleted_at column to track when original files were deleted
ALTER TABLE distribution_file_manifest 
ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP DEFAULT NULL;

COMMENT ON COLUMN distribution_file_manifest.deleted_at IS 
'Timestamp when the original file was deleted from uploads after compression';
