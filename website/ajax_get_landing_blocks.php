<?php
// Return current landing page blocks for given keys (used for post-save refresh)
header('Content-Type: application/json');
session_start();
@include_once __DIR__ . '/../config/database.php';

if (!isset($connection)) {
  echo json_encode(['success' => false, 'message' => 'Database unavailable']);
  exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];
$keys = isset($payload['keys']) && is_array($payload['keys'])
  ? array_values(array_filter($payload['keys'], fn($k) => is_string($k) && $k !== ''))
  : [];

if (!$keys) {
  echo json_encode(['success' => true, 'blocks' => []]);
  exit;
}

$placeholders = [];
$params = [];
foreach ($keys as $idx => $key) {
  $placeholders[] = '$' . ($idx + 1);
  $params[] = $key;
}

$sql = 'SELECT block_key, html, text_color, bg_color FROM landing_content_blocks WHERE municipality_id = 1 AND block_key IN (' . implode(',', $placeholders) . ')';
$res = @pg_query_params($connection, $sql, $params);
$blocks = [];

if ($res) {
  while ($row = pg_fetch_assoc($res)) {
    $row['html'] = sanitize_landing_html($row['html'] ?? '');
    $blocks[] = $row;
  }
}

echo json_encode(['success' => true, 'blocks' => $blocks]);

function sanitize_landing_html($html) {
  if ($html === '' || $html === null) {
    return '';
  }
  // Strip script tags entirely
  $html = preg_replace('#<script[^>]*>.*?</script>#is', '', $html);
  // Remove inline event handlers (on*) and javascript: URLs
  $html = preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i', '', $html);
  $html = preg_replace("/on[a-zA-Z]+\s*=\s*'[^']*'/i", '', $html);
  $html = preg_replace('/javascript:/i', '', $html);
  return $html;
}
