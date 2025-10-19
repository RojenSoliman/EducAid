<?php
require __DIR__ . '/../config/database.php';

$sql = file_get_contents(__DIR__ . '/add_upload_confirmation_tracking.sql');

if (!$sql) {
    die("Failed to read SQL file\n");
}

$result = pg_query($connection, $sql);

if ($result) {
    echo "✓ Migration successful: Upload confirmation tracking columns added\n";
    echo "✓ Added columns: uploads_confirmed, uploads_confirmed_at, uploads_reset_by, uploads_reset_at, uploads_reset_reason\n";
} else {
    echo "✗ Migration failed: " . pg_last_error($connection) . "\n";
    exit(1);
}

pg_close($connection);
