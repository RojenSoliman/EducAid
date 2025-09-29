<?php
// Simple JSON feed for latest announcements (used by landing page skeleton loader)
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';

$limit = isset($_GET['limit']) && ctype_digit($_GET['limit']) ? (int)$_GET['limit'] : 3;
if ($limit < 1) { $limit = 3; }
if ($limit > 24) { $limit = 24; }
$offset = isset($_GET['offset']) && ctype_digit($_GET['offset']) ? (int)$_GET['offset'] : 0;
if ($offset < 0) { $offset = 0; }

$data = [];
$res = @pg_query_params($connection, "SELECT announcement_id, title, remarks, posted_at, event_date, event_time, location, image_path, is_active FROM announcements ORDER BY is_active DESC, posted_at DESC LIMIT $1 OFFSET $2", [$limit, $offset]);
if ($res) {
  while($r = pg_fetch_assoc($res)) { $data[] = $r; }
  pg_free_result($res);
}

echo json_encode([
  'success' => true,
  'count' => count($data),
  'announcements' => $data,
  'generated_at' => date('c'),
  'limit' => $limit,
  'offset' => $offset
]);
