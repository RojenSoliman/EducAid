<?php
/**
 * AJAX Rollback Contact Block
 * Rollback a block to a previous version from audit history
 * Super Admin only
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function resp_roll($ok, $msg = '', $extra = []) {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if (!isset($connection)) {
    resp_roll(false, 'Database unavailable');
}

$is_super_admin = false;
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
    $role = @getCurrentAdminRole($connection);
    if ($role === 'super_admin') {
        $is_super_admin = true;
    }
}

if (!$is_super_admin) {
    resp_roll(false, 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$auditId = (int)($input['audit_id'] ?? 0);
if ($auditId <= 0) {
    resp_roll(false, 'Audit ID required');
}

$versionRes = @pg_query_params($connection, "SELECT block_key, html_snapshot, text_color, bg_color FROM contact_content_audit WHERE municipality_id=1 AND id=$1", [$auditId]);
if ($versionRes === false) {
    resp_roll(false, 'Lookup failed', ['error' => pg_last_error($connection)]);
}
$version = pg_fetch_assoc($versionRes);
pg_free_result($versionRes);

if (!$version) {
    resp_roll(false, 'Version not found', ['code' => 404]);
}

@pg_query($connection, "CREATE UNIQUE INDEX IF NOT EXISTS contact_content_blocks_unique ON contact_content_blocks(municipality_id, block_key)");
@pg_prepare($connection, 'contact_upsert_roll', "INSERT INTO contact_content_blocks (municipality_id, block_key, html, text_color, bg_color) VALUES (1,$1,$2,$3,$4)
    ON CONFLICT (municipality_id, block_key) DO UPDATE SET html=EXCLUDED.html, text_color=EXCLUDED.text_color, bg_color=EXCLUDED.bg_color, updated_at=NOW()");

$res = @pg_execute($connection, 'contact_upsert_roll', [
    $version['block_key'],
    $version['html_snapshot'],
    $version['text_color'],
    $version['bg_color']
]);

if ($res === false) {
    resp_roll(false, 'Failed to restore version', ['error' => pg_last_error($connection)]);
}

resp_roll(true, "Block '{$version['block_key']}' rolled back successfully");
