<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function ann_reset_resp($ok, $msg = '', $extra = []) {
  echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
  exit;
}

if (!isset($connection)) {
  ann_reset_resp(false, 'Database unavailable');
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
  http_response_code(403);
  ann_reset_resp(false, 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = strtolower(trim($input['action'] ?? ''));
$blockKey = trim($input['block_key'] ?? $input['key'] ?? '');
$municipalityId = 1;
$adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;

if ($action === 'reset_all' || ($blockKey === '' && $action === '')) {
  $result = @pg_query_params($connection, "DELETE FROM announcements_content_blocks WHERE municipality_id=$1", [$municipalityId]);
  if ($result === false) {
    ann_reset_resp(false, 'Failed to reset blocks', ['error' => pg_last_error($connection)]);
  }
  ann_reset_resp(true, 'All content blocks reset to defaults');
}

if ($blockKey === '') {
  http_response_code(400);
  ann_reset_resp(false, 'Missing block key');
}

pg_query($connection, "BEGIN");
$res = pg_query_params($connection, "SELECT html,text_color,bg_color FROM announcements_content_blocks WHERE municipality_id=$1 AND block_key=$2", [$municipalityId, $blockKey]);
if ($res && pg_num_rows($res) > 0) {
  $old = pg_fetch_assoc($res);
  pg_query_params($connection, "DELETE FROM announcements_content_blocks WHERE municipality_id=$1 AND block_key=$2", [$municipalityId, $blockKey]);
  $stmt = pg_prepare($connection, "aud_del_ann" . $blockKey, "INSERT INTO announcements_content_audit(municipality_id,block_key,old_html,new_html,old_text_color,new_text_color,old_bg_color,new_bg_color,action_type,admin_id)VALUES($1,$2,$3,'',$4,null,$5,null,'DELETE',$6)");
  pg_execute($connection, "aud_del_ann" . $blockKey, [$municipalityId, $blockKey, $old['html'], $old['text_color'], $old['bg_color'], $adminId]);
}
pg_query($connection, "COMMIT");

ann_reset_resp(true, 'Block reset', ['block' => $blockKey]);
