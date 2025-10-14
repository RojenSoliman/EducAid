<?php
// Add visibility column to landing_content_blocks table
require_once 'config/database.php';

echo "Running migration: Add visibility column...\n";

$sql = file_get_contents('sql/alter_add_visibility_to_content_blocks.sql');

if (pg_query($connection, $sql)) {
    echo "✅ Migration completed successfully!\n";
    echo "Added 'is_visible' column to landing_content_blocks table.\n";
} else {
    echo "❌ Migration failed: " . pg_last_error($connection) . "\n";
}

pg_close($connection);
?>
