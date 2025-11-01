<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$studentId = $_SESSION['student_id'];

$latest = pg_query_params($connection, "SELECT request_id, status, requested_at, processed_at, expires_at, file_size_bytes, download_token FROM student_data_export_requests WHERE student_id = $1 ORDER BY requested_at DESC LIMIT 1", [$studentId]);
if (!$latest) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to fetch status']);
    exit;
}

$row = pg_fetch_assoc($latest);
if (!$row) {
    echo json_encode(['success' => true, 'exists' => false]);
    exit;
}

// If ready but expired, mark as expired and hide details
if ($row['status'] === 'ready' && !empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
    pg_query_params($connection, "UPDATE student_data_export_requests SET status='expired' WHERE request_id = $1", [(int)$row['request_id']]);
    $row['status'] = 'expired';
}

$response = [
    'success' => true,
    'exists' => true,
    'request_id' => (int)$row['request_id'],
    'status' => $row['status'],
    'requested_at' => $row['requested_at'],
    'processed_at' => $row['processed_at'],
    'expires_at' => $row['expires_at'],
    'file_size_bytes' => isset($row['file_size_bytes']) ? (int)$row['file_size_bytes'] : null
];

// If ready, provide a download URL with token for convenience
if ($row['status'] === 'ready' && !empty($row['download_token'])) {
    $response['download_url'] = sprintf('/api/student/download_export.php?request_id=%d&token=%s', (int)$row['request_id'], urlencode($row['download_token']));
}

echo json_encode($response);
pg_close($connection);
