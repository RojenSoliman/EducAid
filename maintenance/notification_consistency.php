<?php
// Lightweight maintenance script for admin notification consistency.
// Run manually or via a scheduled task/cron (Windows Task Scheduler) occasionally.
// Actions:
//  1. Normalize NULL is_read values -> FALSE
//  2. (Optional) Purge very old read notifications (commented out)

include __DIR__ . '/../config/database.php';

$changed = 0;
$res = pg_query($connection, "UPDATE admin_notifications SET is_read = FALSE WHERE is_read IS NULL RETURNING admin_notification_id");
if ($res) {
    $changed = pg_num_rows($res);
}

echo "Normalized NULL is_read values: $changed\n";

// Optional retention policy (uncomment to enable):
// $purge = pg_query($connection, "DELETE FROM admin_notifications WHERE is_read = TRUE AND created_at < NOW() - INTERVAL '90 days'");
// if ($purge) { echo 'Purged ' . pg_affected_rows($purge) . " old read notifications.\n"; }

// Show summary counts
$summary = pg_query($connection, "SELECT 
    SUM(CASE WHEN is_read = FALSE THEN 1 ELSE 0 END) AS unread,
    SUM(CASE WHEN is_read = TRUE THEN 1 ELSE 0 END)  AS read
  FROM admin_notifications");
if ($summary) {
    $row = pg_fetch_assoc($summary);
    echo "Unread: {$row['unread']} | Read: {$row['read']}\n";
}

pg_close($connection);
?>