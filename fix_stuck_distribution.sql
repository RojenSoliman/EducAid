-- Fix the stuck 2025-2026 distribution by marking it as compressed
-- This will prevent it from showing up on end_distribution.php again

UPDATE distribution_snapshots 
SET 
    files_compressed = true,
    compression_date = NOW()
WHERE distribution_id = 'GENERALTRIAS-DISTR-2025-10-24-054028'
AND files_compressed IS FALSE;

-- Verify the update
SELECT 
    snapshot_id, 
    distribution_id, 
    academic_year, 
    semester, 
    finalized_at,
    files_compressed,
    compression_date
FROM distribution_snapshots 
WHERE distribution_id = 'GENERALTRIAS-DISTR-2025-10-24-054028';
