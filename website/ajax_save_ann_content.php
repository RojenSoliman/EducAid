<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function ann_resp($ok, $msg = '', $extra = []) {
  echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
  exit;
}

function ann_ensure_tables($connection) {
  @pg_query($connection, "CREATE TABLE IF NOT EXISTS announcements_content_blocks (
    id SERIAL PRIMARY KEY,
    municipality_id INT NOT NULL DEFAULT 1,
    block_key TEXT NOT NULL,
    html TEXT,
    text_color VARCHAR(20) DEFAULT NULL,
    bg_color VARCHAR(20) DEFAULT NULL,
    updated_at TIMESTAMPTZ DEFAULT NOW()
  )");
  @pg_query($connection, "CREATE UNIQUE INDEX IF NOT EXISTS announcements_content_blocks_unique ON announcements_content_blocks(municipality_id, block_key)");
  @pg_query($connection, "CREATE TABLE IF NOT EXISTS announcements_content_audit (
    id SERIAL PRIMARY KEY,
    municipality_id INT NOT NULL DEFAULT 1,
    block_key TEXT NOT NULL,
    old_html TEXT,
    new_html TEXT,
    old_text_color VARCHAR(20),
    new_text_color VARCHAR(20),
    old_bg_color VARCHAR(20),
    new_bg_color VARCHAR(20),
    action_type VARCHAR(20) NOT NULL,
    admin_id INT,
    admin_username TEXT,
    changed_at TIMESTAMPTZ DEFAULT NOW()
  )");
  @pg_query($connection, "CREATE INDEX IF NOT EXISTS idx_announcements_content_audit_muni_key ON announcements_content_audit (municipality_id, block_key)");
}

function ann_sanitize_html_content($html) {
  $html = preg_replace('#<script[^>]*>.*?</script>#is', '', (string)$html);
  $html = preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i', '', $html);
  $html = preg_replace("/on[a-zA-Z]+\s*=\s*'[^']*'/i", '', $html);
  $html = preg_replace('/javascript:/i', '', $html);
  return $html;
}

function ann_validate_color($value) {
  if (!is_string($value)) {
    return null;
  }
  $value = trim($value);
  if ($value === '') {
    return null;
  }
  if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
    return strtolower($value);
  }
  return null;
}

if (!isset($connection)) {
  ann_resp(false, 'Database unavailable');
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
  ann_resp(false, 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['blocks']) || !is_array($input['blocks'])) {
  http_response_code(400);
  ann_resp(false, 'Invalid blocks');
}

$adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
$municipalityId = 1;
$updated = [];

ann_ensure_tables($connection);

if (!@pg_query($connection, 'BEGIN')) {
  ann_resp(false, 'Failed to start save', ['error' => pg_last_error($connection)]);
}

$error = null;
$adminUsername = $_SESSION['admin_username'] ?? $_SESSION['username'] ?? null;

foreach ($input['blocks'] as $block) {
  $key = trim($block['key'] ?? '');
  if ($key === '') {
    continue;
  }

  $html = ann_sanitize_html_content($block['html'] ?? '');
  $textColor = ann_validate_color($block['textColor'] ?? null);
  $bgColor = ann_validate_color($block['bgColor'] ?? null);

  $existing = @pg_query_params($connection, "SELECT html, text_color, bg_color FROM announcements_content_blocks WHERE municipality_id=$1 AND block_key=$2", [$municipalityId, $key]);
  if ($existing === false) {
    $error = ['message' => 'Lookup failed', 'detail' => pg_last_error($connection)];
    break;
  }
  $old = pg_fetch_assoc($existing) ?: null;
  pg_free_result($existing);

  $upsertSql = "INSERT INTO announcements_content_blocks (municipality_id, block_key, html, text_color, bg_color, updated_at) VALUES ($1,$2,$3,$4,$5,NOW()) ON CONFLICT (municipality_id, block_key) DO UPDATE SET html=EXCLUDED.html, text_color=EXCLUDED.text_color, bg_color=EXCLUDED.bg_color, updated_at=NOW()";
  $upsertParams = [$municipalityId, $key, $html, $textColor, $bgColor];
  $upsert = @pg_query_params($connection, $upsertSql, $upsertParams);
  if ($upsert === false) {
    $error = ['message' => 'Save failed', 'detail' => pg_last_error($connection)];
    break;
  }

  $action = $old ? 'UPDATE' : 'INSERT';
  $historySql = "INSERT INTO announcements_content_audit (municipality_id, block_key, old_html, new_html, old_text_color, new_text_color, old_bg_color, new_bg_color, admin_username, action_type, admin_id) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)";
  $historyParams = [
    $municipalityId,
    $key,
    $old['html'] ?? '',
    $html,
    $old['text_color'] ?? null,
    $textColor,
    $old['bg_color'] ?? null,
    $bgColor,
    $adminUsername,
    $action,
    $adminId
  ];

  $history = @pg_query_params($connection, $historySql, $historyParams);
  if ($history === false) {
    $error = ['message' => 'History log failed', 'detail' => pg_last_error($connection)];
    break;
  }

  $updated[] = $key;
}

if ($error !== null) {
  @pg_query($connection, 'ROLLBACK');
  ann_resp(false, $error['message'], ['error' => $error['detail'] ?? null]);
}

if (!@pg_query($connection, 'COMMIT')) {
  ann_resp(false, 'Failed to commit changes', ['error' => pg_last_error($connection)]);
}

ann_resp(true, 'Announcements content saved', ['updated' => $updated]);
