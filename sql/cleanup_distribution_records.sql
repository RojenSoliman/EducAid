-- Distribution Cleanup Script
-- Run this in phpMyAdmin or pgAdmin to clean up test records

-- Option 1: Delete only the duplicate test records (IDs 2 and 3)
-- DELETE FROM distributions WHERE distribution_id IN (2, 3);

-- Option 2: Start completely fresh (recommended for testing)
DELETE FROM distributions;

-- Verify cleanup
SELECT COUNT(*) as remaining_records FROM distributions;

-- Check student statuses
SELECT status, COUNT(*) as count 
FROM students 
GROUP BY status 
ORDER BY status;

-- Result should show:
-- remaining_records: 0
-- Students with various statuses (active, applicant, etc.) but no 'given' yet
