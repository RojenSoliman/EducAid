-- Migration: Change distribution_id from INTEGER to TEXT for identifiable IDs
-- Format: GENERALTRIAS-DISTR-2025-10-19-001

BEGIN;

-- Step 1: Drop the existing sequence (if it exists)
DROP SEQUENCE IF EXISTS distributions_distribution_id_seq CASCADE;

-- Step 2: Add a temporary column for new IDs
ALTER TABLE distributions ADD COLUMN new_distribution_id TEXT;

-- Step 3: Generate new IDs for existing records (if any exist)
DO $$
DECLARE
    rec RECORD;
    counter INT := 1;
    new_id TEXT;
    dist_date DATE;
BEGIN
    FOR rec IN SELECT distribution_id, date_given FROM distributions ORDER BY distribution_id
    LOOP
        dist_date := COALESCE(rec.date_given, CURRENT_DATE);
        new_id := 'GENERALTRIAS-DISTR-' || TO_CHAR(dist_date, 'YYYY-MM-DD') || '-' || LPAD(counter::TEXT, 3, '0');
        UPDATE distributions SET new_distribution_id = new_id WHERE distribution_id = rec.distribution_id;
        counter := counter + 1;
    END LOOP;
END $$;

-- Step 4: Drop the old column
ALTER TABLE distributions DROP COLUMN distribution_id;

-- Step 5: Rename new column to distribution_id
ALTER TABLE distributions RENAME COLUMN new_distribution_id TO distribution_id;

-- Step 6: Set distribution_id as PRIMARY KEY
ALTER TABLE distributions ADD PRIMARY KEY (distribution_id);

-- Step 7: Update any foreign key references if they exist
-- (Check distribution_files table if it references distributions)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_files' 
        AND column_name = 'distribution_id'
    ) THEN
        -- If distribution_files exists and references distributions
        -- We need to handle this carefully
        -- For now, just ensure the column is TEXT type
        ALTER TABLE distribution_files ALTER COLUMN distribution_id TYPE TEXT;
    END IF;
END $$;

COMMIT;

-- Verify the changes
SELECT 
    column_name, 
    data_type, 
    character_maximum_length 
FROM information_schema.columns 
WHERE table_name = 'distributions' 
AND column_name = 'distribution_id';

-- Show existing records with new IDs
SELECT * FROM distributions ORDER BY distribution_id;
