<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/DataExportService.php';

$studentId = $_SESSION['student_id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

// Guard: prevent spamming requests — if a pending/processing/ready (not expired) exists in last 24h, reuse
$existing = pg_query_params($connection, "SELECT * FROM student_data_export_requests WHERE student_id = $1 AND status IN ('pending','processing','ready') AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY requested_at DESC LIMIT 1", [$studentId]);
if ($existing && ($row = pg_fetch_assoc($existing))) {
    echo json_encode([
        'success' => true,
        'request_id' => (int)$row['request_id'],
        'status' => $row['status'],
    ]);
    exit;
}

// Create new request as processing and build synchronously (MVP)
$insert = pg_query_params($connection, "INSERT INTO student_data_export_requests (student_id, status, requested_by_ip, user_agent) VALUES ($1,'processing',$2,$3) RETURNING request_id", [$studentId, $ip, $ua]);
if (!$insert) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to create export request']);
    exit;
}
$req = pg_fetch_assoc($insert);
$requestId = (int)$req['request_id'];

$service = new DataExportService($connection);
$result = $service->buildExport($studentId);

if (!$result['success']) {
    pg_query_params($connection, "UPDATE student_data_export_requests SET status='failed', processed_at=NOW(), error_message=$2 WHERE request_id = $1", [$requestId, $result['error']]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Export failed']);
    exit;
}

// Success: store token, file, size, expiry
$token = $service->generateToken(24);
$expiresAt = date('Y-m-d H:i:s', time() + 7*24*60*60); // 7 days
pg_query_params($connection, "UPDATE student_data_export_requests SET status='ready', processed_at=NOW(), expires_at=$2, download_token=$3, file_path=$4, file_size_bytes=$5 WHERE request_id = $1", [
    $requestId,
    $expiresAt,
    $token,
    $result['zip_path'],
    $result['size']
]);

echo json_encode([
    'success' => true,
    'request_id' => $requestId,
    'status' => 'ready'
]);

pg_close($connection);
