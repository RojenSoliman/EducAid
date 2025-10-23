-- Add unique constraint to prevent duplicate distributions for same academic period
-- This ensures that only ONE finalized distribution can exist per academic year/semester combination
-- Created: October 24, 2025

-- Step 1: Check if constraint already exists
DO $$ 
BEGIN
    -- Drop the constraint if it exists (for re-running this script)
    IF EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'unique_finalized_distribution_period'
    ) THEN
        ALTER TABLE distribution_snapshots 
        DROP CONSTRAINT unique_finalized_distribution_period;
        RAISE NOTICE 'Dropped existing constraint: unique_finalized_distribution_period';
    END IF;
END $$;

-- Step 2: Create a partial unique index
-- This allows multiple NULL finalized_at entries (drafts/incomplete distributions)
-- but only ONE non-NULL finalized_at per academic_year/semester combination
CREATE UNIQUE INDEX IF NOT EXISTS idx_unique_finalized_distribution 
ON distribution_snapshots (academic_year, semester) 
WHERE finalized_at IS NOT NULL;

COMMENT ON INDEX idx_unique_finalized_distribution IS 
'Ensures only one finalized distribution can exist per academic year/semester combination. Allows multiple unfinalized drafts.';

-- Step 3: Verify the constraint
DO $$ 
DECLARE
    duplicate_count INTEGER;
    r RECORD;
BEGIN
    -- Check for existing duplicates
    SELECT COUNT(*) INTO duplicate_count
    FROM (
        SELECT academic_year, semester, COUNT(*) as cnt
        FROM distribution_snapshots
        WHERE finalized_at IS NOT NULL
        GROUP BY academic_year, semester
        HAVING COUNT(*) > 1
    ) duplicates;
    
    IF duplicate_count > 0 THEN
        RAISE WARNING 'Found % duplicate finalized distributions. These need to be resolved manually.', duplicate_count;
        
        -- Show the duplicates
        RAISE NOTICE 'Duplicate distributions:';
        FOR r IN 
            SELECT academic_year, semester, COUNT(*) as cnt, 
                   string_agg(snapshot_id::text, ', ') as snapshot_ids
            FROM distribution_snapshots
            WHERE finalized_at IS NOT NULL
            GROUP BY academic_year, semester
            HAVING COUNT(*) > 1
        LOOP
            RAISE NOTICE '  % % - % snapshots (IDs: %)', 
                r.semester, r.academic_year, r.cnt, r.snapshot_ids;
        END LOOP;
        
        RAISE NOTICE 'To resolve, you can:';
        RAISE NOTICE '  1. Keep the most recent snapshot and set finalized_at = NULL on older ones:';
        RAISE NOTICE '     UPDATE distribution_snapshots SET finalized_at = NULL WHERE snapshot_id = <old_id>;';
        RAISE NOTICE '  2. Or delete the older snapshots entirely:';
        RAISE NOTICE '     DELETE FROM distribution_snapshots WHERE snapshot_id = <old_id>;';
    ELSE
        RAISE NOTICE 'No duplicate finalized distributions found. Constraint is safe.';
    END IF;
END $$;

-- Step 4: Create a function to validate distribution dates
CREATE OR REPLACE FUNCTION validate_distribution_deadline()
RETURNS TRIGGER AS $$
DECLARE
    last_dist_date DATE;
    last_dist_info TEXT;
BEGIN
    -- Only validate when finalized_at is being set (distribution is being finalized)
    IF NEW.finalized_at IS NOT NULL AND (OLD.finalized_at IS NULL OR OLD IS NULL) THEN
        -- Get the most recent finalized distribution date
        SELECT distribution_date, 
               semester || ' ' || academic_year 
        INTO last_dist_date, last_dist_info
        FROM distribution_snapshots
        WHERE finalized_at IS NOT NULL 
          AND snapshot_id != NEW.snapshot_id
        ORDER BY finalized_at DESC
        LIMIT 1;
        
        IF FOUND AND NEW.distribution_date <= last_dist_date THEN
            RAISE EXCEPTION 'Distribution date (%) cannot be on or before the last finalized distribution date (% for %). Please choose a later date.',
                NEW.distribution_date, last_dist_date, last_dist_info;
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Step 5: Create trigger to enforce distribution date validation
DROP TRIGGER IF EXISTS trg_validate_distribution_deadline ON distribution_snapshots;

CREATE TRIGGER trg_validate_distribution_deadline
    BEFORE INSERT OR UPDATE ON distribution_snapshots
    FOR EACH ROW
    EXECUTE FUNCTION validate_distribution_deadline();

COMMENT ON TRIGGER trg_validate_distribution_deadline ON distribution_snapshots IS 
'Validates that new distributions have dates after the last finalized distribution';

-- Success message
DO $$ 
BEGIN
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Distribution uniqueness constraints applied successfully!';
    RAISE NOTICE '========================================';
    RAISE NOTICE 'The following protections are now active:';
    RAISE NOTICE '  1. UNIQUE INDEX: Prevents duplicate finalized distributions per academic period';
    RAISE NOTICE '  2. TRIGGER: Validates distribution dates are chronological';
    RAISE NOTICE '';
    RAISE NOTICE 'Your system now enforces:';
    RAISE NOTICE '  ✓ Only ONE finalized distribution per academic year/semester';
    RAISE NOTICE '  ✓ Distribution dates must be after previous distribution';
    RAISE NOTICE '  ✓ Multiple draft/unfinalized distributions are allowed';
    RAISE NOTICE '========================================';
END $$;
