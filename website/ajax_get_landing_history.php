<?php
// Returns audit history for landing page editable blocks (preview only)
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function respond($ok, $msg = '', $extra = []) {
  echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(false, 'Invalid method');
}

$is_super_admin = false;
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
  $role = @getCurrentAdminRole($connection);
  if ($role === 'super_admin') { $is_super_admin = true; }
}
if (!$is_super_admin) { respond(false, 'Unauthorized'); }

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];
$block = trim($data['block'] ?? '');
$limit = (int)($data['limit'] ?? 50);
if ($limit < 1) { $limit = 25; }
if ($limit > 200) { $limit = 200; }
$actionType = trim($data['action_type'] ?? '');
$cursor = isset($data['cursor']) ? (int)$data['cursor'] : null; // fetch audit_id < cursor

// Sanitize util (mirrors landing page sanitizer)
function lp_hist_sanitize($html){
  $html = preg_replace('#<script[^>]*>.*?</script>#is','',$html);
  $html = preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i','',$html);
  $html = preg_replace("/on[a-zA-Z]+\s*=\s*'[^']*'/i",'', $html);
  $html = preg_replace('/javascript:/i','',$html);
  return $html;
}

$records = [];
$params = [];
$clauses = ['municipality_id=1'];
if ($block !== '') { $clauses[] = 'block_key=$'.(count($params)+1); $params[] = $block; }
if ($actionType !== '') { $clauses[] = 'action_type=$'.(count($params)+1); $params[] = $actionType; }
if ($cursor !== null && $cursor > 0) { $clauses[] = 'audit_id < $'.(count($params)+1); $params[] = $cursor; }
$where = implode(' AND ', $clauses);
$sql = "SELECT audit_id, block_key, action_type, created_at, new_html, old_html, new_text_color, old_text_color, new_bg_color, old_bg_color
        FROM landing_content_audit
        WHERE $where
        ORDER BY audit_id DESC
        LIMIT " . ($limit + 1);
$res = $params ? @pg_query_params($connection, $sql, $params) : @pg_query($connection, $sql);
$has_more = false;
if ($res) {
  while ($row = pg_fetch_assoc($res)) {
    $content_html = $row['new_html'] !== null && $row['new_html'] !== '' ? $row['new_html'] : ($row['old_html'] ?? '');
    $text_color = $row['new_text_color'] ?? $row['old_text_color'] ?? null;
    $bg_color = $row['new_bg_color'] ?? $row['old_bg_color'] ?? null;
    if (count($records) >= $limit) { $has_more = true; break; }
    $records[] = [
      'audit_id' => (int)$row['audit_id'],
      'block_key' => $row['block_key'],
      'action_type' => $row['action_type'],
      'created_at' => $row['created_at'],
      'html' => lp_hist_sanitize($content_html),
      'text_color' => $text_color,
      'bg_color' => $bg_color
    ];
  }
}
$next_cursor = null;
if ($has_more && $records) { $last = end($records); $next_cursor = $last['audit_id']; }
respond(true, 'OK', [
  'records' => $records,
  'count' => count($records),
  'block' => $block,
  'action_type' => $actionType,
  'has_more' => $has_more,
  'next_cursor' => $next_cursor
]);
