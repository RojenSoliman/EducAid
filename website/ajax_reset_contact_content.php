<?php
/**
 * AJAX Reset Contact Content
 * Resets specific block or all blocks to default
 * Super Admin only
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function resp_reset($ok, $msg = '', $extra = []) {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if (!isset($connection)) {
    resp_reset(false, 'Database unavailable');
}

$is_super_admin = false;
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
    $role = @getCurrentAdminRole($connection);
    if ($role === 'super_admin') {
        $is_super_admin = true;
    }
}

if (!$is_super_admin) {
    resp_reset(false, 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$blockKey = trim($input['block_key'] ?? 'all');

if ($blockKey === '' || strtolower($blockKey) === 'all') {
    $result = @pg_query($connection, "DELETE FROM contact_content_blocks WHERE municipality_id=1");
    if ($result === false) {
        resp_reset(false, 'Failed to reset blocks', ['error' => pg_last_error($connection)]);
    }
    resp_reset(true, 'All content blocks reset to defaults');
}

$result = @pg_query_params($connection, "DELETE FROM contact_content_blocks WHERE municipality_id=1 AND block_key=$1", [$blockKey]);
if ($result === false) {
    resp_reset(false, 'Failed to reset block', ['error' => pg_last_error($connection)]);
}

resp_reset(true, "Block '{$blockKey}' reset to default");
