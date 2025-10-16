<?php
/**
 * Automatic Archiving Check
 * 
 * This script runs automatically when admin dashboard is accessed.
 * Checks if automatic archiving is needed and prompts admin to run it.
 * 
 * No cron job required - runs on-demand when admins login.
 */

session_start();
require_once __DIR__ . '/../../config/database.php';

// Only run for super admins
if (!isset($_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'super_admin') {
    echo json_encode(['should_archive' => false]);
    exit;
}

// Check when archiving was last run
$lastRunQuery = "
    SELECT MAX(created_at) as last_run
    FROM audit_trail
    WHERE event_category = 'archive' 
      AND event_type = 'bulk_archiving_executed'
";

$lastRunResult = pg_query($connection, $lastRunQuery);
$lastRun = pg_fetch_assoc($lastRunResult);
$lastRunDate = $lastRun['last_run'] ?? null;

// Determine if we should prompt for archiving
$currentMonth = date('n'); // 1-12
$currentYear = date('Y');
$shouldPrompt = false;
$message = '';

if ($lastRunDate === null) {
    // Never run before - prompt if it's after June
    if ($currentMonth >= 7) {
        $shouldPrompt = true;
        $message = "Automatic archiving has never been run. It's recommended to run it now to archive graduated students.";
    }
} else {
    // Check if last run was more than a year ago
    $lastRunYear = date('Y', strtotime($lastRunDate));
    $lastRunMonth = date('n', strtotime($lastRunDate));
    
    // Prompt if:
    // 1. Current year is different from last run year AND we're past June
    // 2. OR it's been over a year since last run
    if (($currentYear > $lastRunYear && $currentMonth >= 7) || 
        (strtotime($lastRunDate) < strtotime('-1 year'))) {
        $shouldPrompt = true;
        $lastRunFormatted = date('F j, Y', strtotime($lastRunDate));
        $message = "Automatic archiving was last run on {$lastRunFormatted}. It's time to run it again for this academic year.";
    }
}

// Count eligible students
if ($shouldPrompt) {
    $countQuery = "
        SELECT COUNT(*) as eligible_count
        FROM students
        WHERE is_archived = FALSE
          AND status NOT IN ('blacklisted')
          AND (
              EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > expected_graduation_year
              OR (EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6)
              OR (last_login IS NOT NULL AND last_login < (CURRENT_DATE - INTERVAL '2 years'))
              OR (last_login IS NULL AND application_date < (CURRENT_DATE - INTERVAL '2 years'))
          )
    ";
    
    $countResult = pg_query($connection, $countQuery);
    $count = pg_fetch_assoc($countResult);
    $eligibleCount = $count['eligible_count'] ?? 0;
    
    if ($eligibleCount > 0) {
        $message .= " There are currently <strong>{$eligibleCount} students</strong> eligible for archiving.";
    } else {
        // No students to archive, don't prompt
        $shouldPrompt = false;
    }
}

echo json_encode([
    'should_archive' => $shouldPrompt,
    'message' => $message,
    'eligible_count' => $eligibleCount ?? 0,
    'last_run' => $lastRunDate ? date('F j, Y', strtotime($lastRunDate)) : 'Never'
]);

pg_close($connection);
?>
