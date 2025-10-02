<?php
// Return current about page blocks for given keys (used to refresh after save / rollback)
header('Content-Type: application/json');
session_start();
@include_once __DIR__ . '/../config/database.php';

if (!isset($connection)) { echo json_encode(['success'=>false,'message'=>'DB unavailable']); exit; }
$raw = file_get_contents('php://input');
$in = json_decode($raw, true) ?: [];
$keys = isset($in['keys']) && is_array($in['keys']) ? array_filter($in['keys'], fn($k)=>is_string($k) && $k!=='') : [];
if (!$keys) { echo json_encode(['success'=>true,'blocks'=>[]]); exit; }

$ph = [];$params=[]; foreach($keys as $i=>$k){ $ph[] = '$'.($i+1); $params[] = $k; }
$sql = "SELECT block_key, html, text_color, bg_color FROM about_content_blocks WHERE municipality_id=1 AND block_key IN (".implode(',', $ph).")";
$res = @pg_query_params($connection, $sql, $params);
$blocks = [];
if ($res) { while($r = pg_fetch_assoc($res)) { $blocks[] = $r; } }

echo json_encode(['success'=>true,'blocks'=>$blocks]);
