<?php
/**
 * Slot Threshold Monitor
 * 
 * Runs periodically (via cron or Windows Task Scheduler) to check if distribution slots
 * are running low and notify eligible students who haven't applied yet.
 * 
 * Schedule: Run every 2-4 hours during distribution periods
 * 
 * Windows Task Scheduler:
 * php.exe "C:\xampp\htdocs\EducAid\check_slot_thresholds.php"
 * 
 * Linux Cron (every 2 hours):
 * Add to crontab: 0 STAR/2 STAR STAR STAR /usr/bin/php /path/to/EducAid/check_slot_thresholds.php
 * (Replace STAR with asterisk symbol)
 */

// Ensure CLI execution only
if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/student_notification_helper.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting slot threshold check...\n";

// Get active distributions with their slot usage
$query = "
    SELECT 
        ss.slot_id,
        ss.municipality_id,
        ss.slot_count,
        ss.academic_year,
        ss.semester,
        ss.created_at as distribution_start,
        COUNT(s.student_id) as slots_used,
        (ss.slot_count - COUNT(s.student_id)) as slots_left,
        ROUND((COUNT(s.student_id)::decimal / ss.slot_count) * 100, 2) as fill_percentage
    FROM signup_slots ss
    LEFT JOIN students s ON s.slot_id = ss.slot_id
    WHERE ss.is_active = TRUE
    GROUP BY ss.slot_id, ss.municipality_id, ss.slot_count, ss.academic_year, ss.semester, ss.created_at
";

$result = pg_query($connection, $query);
if (!$result) {
    echo "ERROR: Failed to query distributions\n";
    pg_close($connection);
    exit(1);
}

$distributions = pg_fetch_all($result);
if (!$distributions) {
    echo "No active distributions found.\n";
    pg_close($connection);
    exit(0);
}

foreach ($distributions as $dist) {
    $slot_id = $dist['slot_id'];
    $municipality_id = $dist['municipality_id'];
    $slots_left = (int)$dist['slots_left'];
    $fill_percentage = (float)$dist['fill_percentage'];
    $slot_count = (int)$dist['slot_count'];
    $period = trim(($dist['academic_year'] ?? '') . ' ' . ($dist['semester'] ?? ''));
    
    echo "\n=== Distribution #{$slot_id} (Municipality #{$municipality_id}) ===\n";
    echo "Period: {$period}\n";
    echo "Slots: {$dist['slots_used']}/{$slot_count} used ({$fill_percentage}% full)\n";
    echo "Slots left: {$slots_left}\n";

    // Determine threshold level and if we should notify
    $threshold_key = null;
    $notification_title = null;
    $notification_message = null;
    $notification_type = 'warning';
    $is_priority = false;

    if ($fill_percentage >= 99) {
        $threshold_key = 'critical_99';
        $notification_title = '🚨 Last Chance to Apply!';
        $notification_message = "Only {$slots_left} slot(s) remaining for {$period}. The distribution is almost full. Apply now before it's too late!";
        $is_priority = true; // Show as modal
        $notification_type = 'error';
    } elseif ($fill_percentage >= 95) {
        $threshold_key = 'urgent_95';
        $notification_title = '⚠️ Running Out of Slots!';
        $notification_message = "Only {$slots_left} slots remaining for {$period}. Don't miss your chance to apply.";
        $is_priority = true;
        $notification_type = 'warning';
    } elseif ($fill_percentage >= 90) {
        $threshold_key = 'warning_90';
        $notification_title = '⏰ Slots Filling Fast';
        $notification_message = "Slots are filling up quickly for {$period}. Only {$slots_left} slots left. Apply soon to secure your spot.";
        $notification_type = 'warning';
    } elseif ($fill_percentage >= 80) {
        $threshold_key = 'notice_80';
        $notification_title = '📢 Limited Slots Available';
        $notification_message = "{$slots_left} slots available for {$period}. Consider applying soon.";
        $notification_type = 'info';
    }

    if (!$threshold_key) {
        echo "Below 80% threshold, no notification needed.\n";
        continue;
    }

    echo "Threshold '{$threshold_key}' triggered.\n";

    // Check if we've already notified for this threshold to avoid spam
    $check_query = "
        SELECT last_notified_at, last_threshold
        FROM slot_threshold_notifications
        WHERE slot_id = $1 AND municipality_id = $2
    ";
    $check_result = pg_query_params($connection, $check_query, [$slot_id, $municipality_id]);
    $last_notification = $check_result ? pg_fetch_assoc($check_result) : null;

    // Skip if we've already sent this threshold level notification (don't spam)
    if ($last_notification) {
        $last_threshold = $last_notification['last_threshold'];
        $last_notified = $last_notification['last_notified_at'];
        
        // If same threshold, check if enough time has passed (at least 4 hours)
        if ($last_threshold === $threshold_key) {
            $hours_since = (time() - strtotime($last_notified)) / 3600;
            if ($hours_since < 4) {
                echo "Already notified for this threshold {$hours_since} hours ago. Skipping.\n";
                continue;
            }
        }
        
        // If we're at a lower threshold than before, skip (don't go backwards)
        $threshold_levels = ['notice_80' => 1, 'warning_90' => 2, 'urgent_95' => 3, 'critical_99' => 4];
        if ($threshold_levels[$last_threshold] >= $threshold_levels[$threshold_key]) {
            echo "Already sent higher/equal threshold notification. Skipping.\n";
            continue;
        }
    }

    // Find eligible students: verified, in this municipality, no existing application for this slot
    $students_query = "
        SELECT DISTINCT s.student_id, s.first_name, s.email
        FROM students s
        WHERE s.municipality_id = $1
          AND s.is_verified = TRUE
          AND s.student_id NOT IN (
              SELECT student_id FROM students WHERE slot_id = $2
          )
        LIMIT 5000
    ";
    
    $students_result = pg_query_params($connection, $students_query, [$municipality_id, $slot_id]);
    if (!$students_result) {
        echo "ERROR: Failed to fetch eligible students\n";
        continue;
    }

    $students = pg_fetch_all($students_result);
    $student_count = $students ? count($students) : 0;

    echo "Found {$student_count} eligible students to notify.\n";

    if ($student_count === 0) {
        echo "No eligible students, skipping.\n";
        continue;
    }

    // Send notifications
    $success_count = 0;
    $failed_count = 0;

    foreach ($students as $student) {
        $result = createStudentNotification(
            $connection,
            $student['student_id'],
            $notification_title,
            $notification_message,
            $notification_type,
            $is_priority ? 'high' : 'medium',
            '../modules/student/student_register.php', // Action URL
            $is_priority,
            null // No expiration
        );

        if ($result) {
            $success_count++;
        } else {
            $failed_count++;
        }

        // Rate limiting: small delay to avoid overwhelming the mail server
        if ($success_count % 50 === 0) {
            usleep(100000); // 0.1 second pause every 50 notifications
        }
    }

    echo "Notifications sent: {$success_count} success, {$failed_count} failed.\n";

    // Record that we've notified for this threshold
    $upsert_query = "
        INSERT INTO slot_threshold_notifications (slot_id, municipality_id, last_threshold, last_notified_at, students_notified)
        VALUES ($1, $2, $3, NOW(), $4)
        ON CONFLICT (slot_id, municipality_id)
        DO UPDATE SET
            last_threshold = EXCLUDED.last_threshold,
            last_notified_at = EXCLUDED.last_notified_at,
            students_notified = EXCLUDED.students_notified
    ";
    pg_query_params($connection, $upsert_query, [$slot_id, $municipality_id, $threshold_key, $success_count]);
}

echo "\n[" . date('Y-m-d H:i:s') . "] Slot threshold check completed.\n";
pg_close($connection);
exit(0);
