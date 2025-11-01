<?php
session_start();

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$studentId = $_SESSION['student_id'];
$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
$token = $_GET['token'] ?? '';

if (!$requestId || !$token) {
    http_response_code(400);
    echo 'Invalid request';
    exit;
}

$res = pg_query_params($connection, "SELECT * FROM student_data_export_requests WHERE request_id = $1 AND student_id = $2 LIMIT 1", [$requestId, $studentId]);
$row = $res ? pg_fetch_assoc($res) : null;
if (!$row) { http_response_code(404); echo 'Not found'; exit; }

if ($row['status'] !== 'ready') { http_response_code(409); echo 'Not ready'; exit; }
if (empty($row['expires_at']) || strtotime($row['expires_at']) < time()) {
    pg_query_params($connection, "UPDATE student_data_export_requests SET status='expired' WHERE request_id = $1", [$requestId]);
    http_response_code(410); echo 'Expired'; exit;
}
if (!hash_equals($row['download_token'], $token)) { http_response_code(403); echo 'Forbidden'; exit; }

$filePath = $row['file_path'];
if (!$filePath || !file_exists($filePath)) { http_response_code(404); echo 'File missing'; exit; }

$filename = basename($filePath);
header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
