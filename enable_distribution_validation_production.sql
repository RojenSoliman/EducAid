-- ============================================
-- PRODUCTION: Re-enable Distribution Date Validation
-- ============================================
-- This script re-enables the distribution deadline validation trigger
-- after debugging/testing is complete.
-- ============================================

-- Re-enable the distribution deadline validation trigger
CREATE TRIGGER trg_validate_distribution_deadline
    BEFORE INSERT OR UPDATE ON distribution_snapshots
    FOR EACH ROW
    EXECUTE FUNCTION validate_distribution_deadline();

-- Confirmation message
DO $$
BEGIN
    RAISE NOTICE 'Distribution deadline validation trigger has been RE-ENABLED.';
    RAISE NOTICE 'Production safety check is now active!';
END $$;
