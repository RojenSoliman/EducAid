-- ============================================================================
-- Migration: Fix Document Rejection Trigger Override Bug
-- Date: 2025-11-01
-- Author: Development Team
-- 
-- Problem: 
-- The trigger set_document_upload_needs() was running on both INSERT and UPDATE,
-- causing it to override manual changes to needs_document_upload when admins
-- rejected student documents. This prevented students from re-uploading.
--
-- Solution:
-- Modify the trigger to only run on INSERT (new student registration) and
-- skip execution on UPDATE (manual admin actions like document rejection).
--
-- Impact:
-- - New student registrations: needs_document_upload set automatically ✓
-- - Document rejection: needs_document_upload stays TRUE after admin sets it ✓
-- - Student re-upload: Upload interface shows correctly instead of read-only ✓
-- ============================================================================

BEGIN;

-- Drop existing trigger and function
DROP TRIGGER IF EXISTS trigger_set_document_upload_needs ON students CASCADE;
DROP FUNCTION IF EXISTS public.set_document_upload_needs() CASCADE;

-- Recreate function with TG_OP check to only run on INSERT
CREATE FUNCTION public.set_document_upload_needs() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Only automatically set needs_document_upload on INSERT (new student creation)
    -- On UPDATE, let the value pass through unchanged (respects manual admin changes)
    IF TG_OP = 'INSERT' THEN
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
    END IF;
    -- On UPDATE: do nothing, return NEW unchanged
    
    RETURN NEW;
END;
$$;

-- Recreate trigger to only fire on INSERT (not UPDATE)
CREATE TRIGGER trigger_set_document_upload_needs 
    BEFORE INSERT ON public.students 
    FOR EACH ROW 
    EXECUTE FUNCTION public.set_document_upload_needs();

-- Add comment explaining the trigger behavior
COMMENT ON FUNCTION public.set_document_upload_needs() IS 
'Automatically sets needs_document_upload for new students based on registration date. Only runs on INSERT to avoid overriding manual admin changes during UPDATE operations (e.g., document rejection).';

COMMENT ON TRIGGER trigger_set_document_upload_needs ON public.students IS 
'Sets needs_document_upload flag for new student registrations. Fires on INSERT only to allow manual updates during document rejection workflow.';

COMMIT;

-- ============================================================================
-- Verification Query (Optional - run after migration to confirm)
-- ============================================================================
-- Uncomment to verify the trigger is configured correctly:
/*
SELECT 
    'Trigger Configuration' as check_type,
    tgname as trigger_name,
    CASE 
        WHEN tgtype & 4 = 4 AND tgtype & 16 = 0 THEN '✓ INSERT only (Correct)'
        WHEN tgtype & 4 = 4 AND tgtype & 16 = 16 THEN '✗ INSERT OR UPDATE (Needs fix)'
        ELSE 'Unknown'
    END as events,
    CASE 
        WHEN tgtype & 2 = 2 THEN '✓ BEFORE'
        ELSE 'AFTER'
    END as timing
FROM pg_trigger
WHERE tgname = 'trigger_set_document_upload_needs'
AND tgrelid = 'students'::regclass;

SELECT 
    'Function Code' as check_type,
    CASE 
        WHEN pg_get_functiondef(oid) LIKE '%TG_OP = ''INSERT''%' 
        THEN '✓ Has TG_OP check (Correct)'
        ELSE '✗ Missing TG_OP check (Needs fix)'
    END as status
FROM pg_proc
WHERE proname = 'set_document_upload_needs';
*/

-- ============================================================================
-- Testing Instructions (for QA)
-- ============================================================================
-- 1. Register a new student → needs_document_upload should be FALSE
-- 2. Student should see "View-Only Mode" (new registrant)
-- 3. Admin badge should show "New Registration"
--
-- 4. Admin rejects documents → needs_document_upload should become TRUE
-- 5. Admin badge should change to "Re-upload"
-- 6. Student should now see upload interface (not read-only)
--
-- 7. Student uploads documents → can successfully upload
-- 8. Admin can reject again → student can re-upload again
-- ============================================================================
