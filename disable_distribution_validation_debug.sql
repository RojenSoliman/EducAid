-- ============================================
-- DEBUGGING: Temporarily Disable Distribution Date Validation
-- ============================================
-- This script disables the distribution deadline validation trigger
-- to allow testing multiple distributions on the same date.
-- 
-- IMPORTANT: Re-enable this trigger in production!
-- ============================================

-- Disable the distribution deadline validation trigger
DROP TRIGGER IF EXISTS trg_validate_distribution_deadline ON distribution_snapshots;

-- Confirmation message
DO $$
BEGIN
    RAISE NOTICE 'Distribution deadline validation trigger has been DISABLED for debugging.';
    RAISE NOTICE 'Remember to re-enable it before going to production!';
END $$;

-- To re-enable later, run this:
-- CREATE TRIGGER trg_validate_distribution_deadline
--     BEFORE INSERT OR UPDATE ON distribution_snapshots
--     FOR EACH ROW
--     EXECUTE FUNCTION validate_distribution_deadline();
