-- ============================================
-- OPTIONAL: Clean Up Unused Documents Table Fields
-- Date: October 30, 2025
-- UPDATED: Keeping ocr_confidence and verification_score (needed for UI display)
-- ============================================
-- 
-- WARNING: This is a BREAKING CHANGE that removes unused fields
-- Only run this if you've reviewed DOCUMENTS_TABLE_ANALYSIS.md
-- and updated all code that references these fields
--
-- Fields to remove:
-- - ocr_text_path (redundant - can read from file_path + .ocr.txt)
-- - verification_data_path (redundant - can read from file_path + .verify.json)
-- - extracted_grades (redundant - data in verification_details JSONB)
-- - average_grade (redundant - data in verification_details JSONB)
-- - passing_status (redundant - data in verification_details JSONB)
-- - notes (never used)
--
-- Fields KEPT (actively used):
-- - ocr_confidence (used in UI for confidence badges)
-- - verification_score (used in UI for verification display)
-- - status (updated to 'approved' when admin approves)
-- - approved_by (records which admin approved the document)
-- - approved_date (timestamp when approved)
--
-- ============================================

-- Step 1: Backup current data (just in case)
-- Uncomment if you want a backup table
-- CREATE TABLE documents_backup_20251030 AS SELECT * FROM documents;

-- Step 2: Show what will be removed
SELECT 
    'Fields to be removed:' as info,
    COUNT(*) FILTER (WHERE ocr_text_path IS NOT NULL) as ocr_text_path_populated,
    COUNT(*) FILTER (WHERE verification_data_path IS NOT NULL) as verification_data_path_populated,
    COUNT(*) FILTER (WHERE extracted_grades IS NOT NULL) as extracted_grades_populated,
    COUNT(*) FILTER (WHERE average_grade IS NOT NULL) as average_grade_populated,
    COUNT(*) FILTER (WHERE passing_status IS NOT NULL) as passing_status_populated,
    COUNT(*) FILTER (WHERE notes IS NOT NULL) as notes_populated,
    COUNT(*) FILTER (WHERE status = 'approved') as approved_status_count,
    COUNT(*) FILTER (WHERE approved_by IS NOT NULL) as approved_by_populated,
    COUNT(*) FILTER (WHERE ocr_confidence > 0) as ocr_confidence_populated,
    COUNT(*) FILTER (WHERE verification_score > 0) as verification_score_populated,
    COUNT(*) as total_documents
FROM documents;

--Step 3: Remove unused fields
BEGIN;

ALTER TABLE documents 
  DROP COLUMN IF EXISTS ocr_text_path,
  DROP COLUMN IF EXISTS verification_data_path,
  DROP COLUMN IF EXISTS extracted_grades,
  DROP COLUMN IF EXISTS average_grade,
  DROP COLUMN IF EXISTS passing_status,
  DROP COLUMN IF EXISTS notes;

--NOTE: We are KEEPING ocr_confidence, verification_score, status, approved_by, and approved_date

--Update comments
COMMENT ON TABLE documents IS 'Unified document management with verification data in JSONB (cleaned schema as of 2025-10-30)';

COMMIT;

-- Step 4: Verify final schema
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns
WHERE table_name = 'documents'
ORDER BY ordinal_position;

-- ============================================
-- RECOVERY: Restore ocr_confidence and verification_score if accidentally deleted
-- ============================================
-- If you accidentally deleted the ocr_confidence and verification_score columns,
-- run this to restore them:

BEGIN;

-- Add back the columns
ALTER TABLE documents 
  ADD COLUMN IF NOT EXISTS ocr_confidence DECIMAL(5,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS verification_score DECIMAL(5,2) DEFAULT 0;

-- Repopulate from verification_details JSONB where possible
UPDATE documents 
SET ocr_confidence = COALESCE(
    (verification_details->>'ocr_confidence')::DECIMAL(5,2),
    0
),
verification_score = COALESCE(
    (verification_details->>'verification_score')::DECIMAL(5,2),
    (verification_details->'summary'->>'average_confidence')::DECIMAL(5,2),
    0
)
WHERE verification_details IS NOT NULL;

-- Add comments
COMMENT ON COLUMN documents.ocr_confidence IS 'OCR confidence score 0-100 (used in UI)';
COMMENT ON COLUMN documents.verification_score IS 'Verification score 0-100 (used in UI)';

COMMIT;

-- ============================================
-- After running this cleanup, you should also update:
-- 1. DocumentService.php - Remove references to deleted columns in INSERT statements
-- 2. Any other services that write to these fields
-- ============================================

-- Expected final schema:
-- ✓ document_id (PK)
-- ✓ student_id (FK)
-- ✓ document_type_code
-- ✓ document_type_name
-- ✓ file_path
-- ✓ file_name
-- ✓ file_extension
-- ✓ file_size_bytes
-- ✓ ocr_confidence (kept - used in UI)
-- ✓ verification_score (kept - used in UI)
-- ✓ verification_details (JSONB - contains ALL verification data)
-- ✓ verification_status
-- ✓ status
-- ✓ upload_date
-- ✓ upload_year
-- ✓ last_modified
-- ✓ approved_date
-- ✓ approved_by
