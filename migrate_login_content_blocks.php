<?php
require_once 'config/database.php';

echo "Creating login_content_blocks table...\n";
$sql = file_get_contents(__DIR__ . '/sql/create_login_content_blocks.sql');
if (!pg_query($connection, $sql)) {
    echo "❌ Failed to create table: " . pg_last_error($connection) . "\n";
    exit(1);
}

echo "Copying existing login_* blocks from landing_content_blocks...\n";
$copySql = "INSERT INTO login_content_blocks (municipality_id, block_key, html, text_color, bg_color, is_visible, created_at, updated_at)
            SELECT municipality_id, block_key, html, text_color, bg_color,
                   COALESCE(is_visible, TRUE), created_at, updated_at
            FROM landing_content_blocks
            WHERE block_key LIKE 'login_%'
              AND NOT EXISTS (
                  SELECT 1 FROM login_content_blocks lcb
                  WHERE lcb.municipality_id = landing_content_blocks.municipality_id
                    AND lcb.block_key = landing_content_blocks.block_key
              );";

if (!pg_query($connection, $copySql)) {
    echo "❌ Failed to copy existing blocks: " . pg_last_error($connection) . "\n";
    exit(1);
}

echo "✅ Migration complete.\n";
