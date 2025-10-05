<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function ann_hist_resp($ok, $msg = '', $extra = []) {
  echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
  exit;
}

if (!isset($connection)) {
  ann_hist_resp(false, 'Database unavailable');
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
  ann_hist_resp(false, 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$block = trim($input['block'] ?? $input['key'] ?? '');
$limit = (int)($input['limit'] ?? 50);
if ($limit < 1) { $limit = 25; }
if ($limit > 200) { $limit = 200; }
$actionType = trim($input['action_type'] ?? '');

$municipalityId = 1;
$params = [$municipalityId];
$clauses = ['municipality_id=$1'];

if ($block !== '') {
  $params[] = $block;
  $clauses[] = 'block_key=$' . count($params);
}
if ($actionType !== '') {
  $params[] = $actionType;
  $clauses[] = 'action_type=$' . count($params);
}

$sql = 'SELECT id, block_key, new_html, new_text_color, new_bg_color, action_type, changed_at, admin_id '
   . 'FROM announcements_content_audit WHERE ' . implode(' AND ', $clauses)
   . ' ORDER BY changed_at DESC LIMIT ' . $limit;

$res = @pg_query_params($connection, $sql, $params);
if ($res === false) {
  ann_hist_resp(false, 'Query failed', ['error' => pg_last_error($connection)]);
}

$records = [];
while ($row = pg_fetch_assoc($res)) {
  $records[] = [
    'audit_id' => (int)$row['id'],
    'block_key' => $row['block_key'],
    'html' => $row['new_html'],
    'text_color' => $row['new_text_color'],
    'bg_color' => $row['new_bg_color'],
    'action_type' => $row['action_type'],
    'created_at' => $row['changed_at'],
    'admin_id' => $row['admin_id']
  ];
}
pg_free_result($res);

ann_hist_resp(true, 'OK', ['records' => $records, 'count' => count($records), 'block' => $block]);
