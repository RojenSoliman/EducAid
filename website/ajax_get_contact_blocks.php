<?php
/**
 * AJAX Get Contact Blocks
 * Returns current content blocks for Contact page
 * Super Admin only
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function resp($ok, $msg = '', $extra = []) {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if (!isset($connection)) {
    resp(false, 'Database unavailable');
}

$is_super_admin = false;
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
    $role = @getCurrentAdminRole($connection);
    if ($role === 'super_admin') {
        $is_super_admin = true;
    }
}

if (!$is_super_admin) {
    resp(false, 'Unauthorized');
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];
$keys = [];
if (isset($payload['keys']) && is_array($payload['keys'])) {
    $keys = array_values(array_filter(
        $payload['keys'],
        fn($k) => is_string($k) && trim($k) !== ''
    ));
}

if ($keys) {
    $placeholders = [];
    $params = [];
    foreach ($keys as $i => $key) {
        $placeholders[] = '$' . ($i + 1);
        $params[] = $key;
    }
    $sql = "SELECT block_key, html, text_color, bg_color, updated_at FROM contact_content_blocks WHERE municipality_id=1 AND block_key IN (" . implode(',', $placeholders) . ")";
    $result = @pg_query_params($connection, $sql, $params);
} else {
    $result = @pg_query($connection, "SELECT block_key, html, text_color, bg_color, updated_at FROM contact_content_blocks WHERE municipality_id=1 ORDER BY block_key");
}

$blocks = [];
if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        $blocks[] = $row;
    }
    pg_free_result($result);
    resp(true, 'OK', ['blocks' => $blocks]);
}

resp(false, 'Database query failed', ['error' => pg_last_error($connection)]);
