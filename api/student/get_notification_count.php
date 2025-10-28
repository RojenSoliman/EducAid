<?php
// API endpoint to get unread student notification count
session_start();
header('Content-Type: application/json');

// Verify student is logged in
if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$student_id = $_SESSION['student_id'];

// Get count of unread notifications
$query = "SELECT COUNT(*) as unread_count 
          FROM student_notifications 
          WHERE student_id = $1 AND is_read = FALSE 
          AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)";

$result = pg_query_params($connection, $query, [$student_id]);

if ($result) {
    $row = pg_fetch_assoc($result);
    echo json_encode([
        'success' => true, 
        'count' => (int)$row['unread_count']
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to fetch notification count']);
}

pg_close($connection);
?>
