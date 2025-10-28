<?php
require 'config/database.php';

echo "=== Migration 1: Add deleted_at to manifest ===\n";

$sql = "ALTER TABLE distribution_file_manifest ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP DEFAULT NULL";

$result = pg_query($connection, $sql);

if ($result) {
    echo "SUCCESS: Added deleted_at column to distribution_file_manifest\n";
} else {
    echo "ERROR: " . pg_last_error($connection) . "\n";
}
