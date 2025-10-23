-- Migration: Remove redundant upload confirmation columns
-- These columns were part of the old upload confirmation system where students
-- had to manually "confirm" their uploads before admin review.
-- 
-- The new system uses:
-- - needs_document_upload: Simple boolean flag
-- - documents_to_reupload: JSON array of specific documents to re-upload
-- - Audit trail: Complete history of all actions
--
-- This migration safely removes the old columns.

-- Drop the redundant columns
DO $$
BEGIN
    -- Drop uploads_confirmed
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'students' 
        AND column_name = 'uploads_confirmed'
    ) THEN
        ALTER TABLE students DROP COLUMN uploads_confirmed;
        RAISE NOTICE 'Dropped uploads_confirmed column';
    END IF;
    
    -- Drop uploads_confirmed_at
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'students' 
        AND column_name = 'uploads_confirmed_at'
    ) THEN
        ALTER TABLE students DROP COLUMN uploads_confirmed_at;
        RAISE NOTICE 'Dropped uploads_confirmed_at column';
    END IF;
    
    -- Drop uploads_reset_by
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'students' 
        AND column_name = 'uploads_reset_by'
    ) THEN
        ALTER TABLE students DROP COLUMN uploads_reset_by;
        RAISE NOTICE 'Dropped uploads_reset_by column';
    END IF;
    
    -- Drop uploads_reset_at
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'students' 
        AND column_name = 'uploads_reset_at'
    ) THEN
        ALTER TABLE students DROP COLUMN uploads_reset_at;
        RAISE NOTICE 'Dropped uploads_reset_at column';
    END IF;
    
    -- Drop uploads_reset_reason
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'students' 
        AND column_name = 'uploads_reset_reason'
    ) THEN
        ALTER TABLE students DROP COLUMN uploads_reset_reason;
        RAISE NOTICE 'Dropped uploads_reset_reason column';
    END IF;
    
    RAISE NOTICE 'Successfully removed all redundant upload confirmation columns';
    RAISE NOTICE 'New system uses: needs_document_upload, documents_to_reupload, and audit_trail';
END $$;
