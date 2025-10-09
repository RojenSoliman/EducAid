<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function ann_roll_resp($ok, $msg = '', $extra = []) {
  echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
  exit;
}

if (!isset($connection)) {
  ann_roll_resp(false, 'Database unavailable');
}

$is_super_admin = false;
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
  $role = @getCurrentAdminRole($connection);
  if ($role === 'super_admin') {
    $is_super_admin = true;
  }
}
if (!$is_super_admin && isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
  $is_super_admin = true;
}

if (!$is_super_admin) {
  ann_roll_resp(false, 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$auditId = (int)($input['audit_id'] ?? $input['auditId'] ?? 0);
if ($auditId <= 0) {
  ann_roll_resp(false, 'Audit ID required');
}

$municipalityId = 1;
$adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;

$auditRes = @pg_query_params($connection, "SELECT block_key, new_html, new_text_color, new_bg_color FROM announcements_content_audit WHERE municipality_id=$1 AND id=$2", [$municipalityId, $auditId]);
if ($auditRes === false) {
  ann_roll_resp(false, 'Lookup failed', ['error' => pg_last_error($connection)]);
}
$audit = pg_fetch_assoc($auditRes);
pg_free_result($auditRes);

if (!$audit) {
  ann_roll_resp(false, 'Audit entry not found', ['code' => 404]);
}

$html = $audit['new_html'] ?? '';
$textColor = $audit['new_text_color'] ?? null;
$bgColor = $audit['new_bg_color'] ?? null;

pg_query($connection, "BEGIN");
$current = null;
$currentRes = pg_query_params($connection, "SELECT html, text_color, bg_color FROM announcements_content_blocks WHERE municipality_id=$1 AND block_key=$2", [$municipalityId, $audit['block_key']]);
if ($currentRes && pg_num_rows($currentRes) > 0) {
  $current = pg_fetch_assoc($currentRes);
}
if ($currentRes) {
  pg_free_result($currentRes);
}

$upsert = pg_query_params($connection, "INSERT INTO announcements_content_blocks (municipality_id, block_key, html, text_color, bg_color, updated_at) VALUES ($1,$2,$3,$4,$5,NOW()) ON CONFLICT (municipality_id, block_key) DO UPDATE SET html=EXCLUDED.html, text_color=EXCLUDED.text_color, bg_color=EXCLUDED.bg_color, updated_at=NOW()", [$municipalityId, $audit['block_key'], $html, $textColor, $bgColor]);
if ($upsert === false) {
  pg_query($connection, "ROLLBACK");
  ann_roll_resp(false, 'Failed to restore version', ['error' => pg_last_error($connection)]);
}

$oldHtml = $current['html'] ?? '';
$oldText = $current['text_color'] ?? null;
$oldBg = $current['bg_color'] ?? null;

$log = pg_query_params($connection, "INSERT INTO announcements_content_audit (municipality_id, block_key, old_html, new_html, old_text_color, new_text_color, old_bg_color, new_bg_color, action_type, admin_id) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,'ROLLBACK',$9)", [$municipalityId, $audit['block_key'], $oldHtml, $html, $oldText, $textColor, $oldBg, $bgColor, $adminId]);
if ($log === false) {
  pg_query($connection, "ROLLBACK");
  ann_roll_resp(false, 'Failed to log rollback', ['error' => pg_last_error($connection)]);
}

pg_query($connection, "COMMIT");

ann_roll_resp(true, 'Rolled back to selected version', ['block' => $audit['block_key']]);
