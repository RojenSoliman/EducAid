<?php
session_start();
require_once '../config/database.php';
require_once '../includes/permissions.php';

// Only allow super admin
if (!isset($_SESSION['admin_id'])) {
    die('Access denied');
}

$role = getCurrentAdminRole($connection);
if ($role !== 'super_admin') {
    die('Super admin only');
}

echo "<h2>Contact Content Blocks Debug</h2>";
echo "<pre>";

// Check if table exists
$table_check = @pg_query($connection, "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'contact_content_blocks')");
if ($table_check) {
    $exists = pg_fetch_result($table_check, 0, 0);
    echo "Table exists: " . ($exists === 't' ? 'YES' : 'NO') . "\n\n";
    pg_free_result($table_check);
}

// Get all blocks
$res = @pg_query($connection, "SELECT block_key, html, text_color, bg_color, updated_at FROM contact_content_blocks WHERE municipality_id=1 ORDER BY block_key");

if ($res) {
    echo "Blocks found: " . pg_num_rows($res) . "\n\n";
    
    while ($row = pg_fetch_assoc($res)) {
        echo "Block: " . $row['block_key'] . "\n";
        echo "HTML: " . (empty($row['html']) ? '[EMPTY]' : $row['html']) . "\n";
        echo "Text Color: " . ($row['text_color'] ?? '[NULL]') . "\n";
        echo "BG Color: " . ($row['bg_color'] ?? '[NULL]') . "\n";
        echo "Updated: " . ($row['updated_at'] ?? '[NULL]') . "\n";
        echo str_repeat('-', 80) . "\n";
    }
    
    pg_free_result($res);
} else {
    echo "Query failed: " . pg_last_error($connection) . "\n";
}

echo "</pre>";
?>
