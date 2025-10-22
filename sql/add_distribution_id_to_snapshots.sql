-- Add distribution_id column to distribution_snapshots table
-- This links snapshots to their compressed ZIP archives
-- Date: 2025-10-20

-- Add the column if it doesn't exist
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_snapshots' 
        AND column_name = 'distribution_id'
    ) THEN
        ALTER TABLE distribution_snapshots 
        ADD COLUMN distribution_id TEXT;
        
        -- Create index for faster lookups
        CREATE INDEX idx_distribution_snapshots_dist_id ON distribution_snapshots(distribution_id);
        
        RAISE NOTICE 'Added distribution_id column to distribution_snapshots table';
    ELSE
        RAISE NOTICE 'distribution_id column already exists in distribution_snapshots table';
    END IF;
END $$;

-- Add archive_filename column to track the actual ZIP file name
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_snapshots' 
        AND column_name = 'archive_filename'
    ) THEN
        ALTER TABLE distribution_snapshots 
        ADD COLUMN archive_filename TEXT;
        
        RAISE NOTICE 'Added archive_filename column to distribution_snapshots table';
    ELSE
        RAISE NOTICE 'archive_filename column already exists in distribution_snapshots table';
    END IF;
END $$;

-- Add files_compressed column to track compression status
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_snapshots' 
        AND column_name = 'files_compressed'
    ) THEN
        ALTER TABLE distribution_snapshots 
        ADD COLUMN files_compressed BOOLEAN DEFAULT FALSE;
        
        RAISE NOTICE 'Added files_compressed column to distribution_snapshots table';
    ELSE
        RAISE NOTICE 'files_compressed column already exists in distribution_snapshots table';
    END IF;
END $$;

-- Add compression_date column
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'distribution_snapshots' 
        AND column_name = 'compression_date'
    ) THEN
        ALTER TABLE distribution_snapshots 
        ADD COLUMN compression_date TIMESTAMP;
        
        RAISE NOTICE 'Added compression_date column to distribution_snapshots table';
    ELSE
        RAISE NOTICE 'compression_date column already exists in distribution_snapshots table';
    END IF;
END $$;

-- Show final structure
SELECT 
    column_name, 
    data_type, 
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_name = 'distribution_snapshots'
ORDER BY ordinal_position;
