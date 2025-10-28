<?php
// API endpoint to mark all student notifications as read
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

// Update all unread notifications for this student
$query = "UPDATE student_notifications 
          SET is_read = TRUE 
          WHERE student_id = $1 AND is_read = FALSE 
          AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)";

$result = pg_query_params($connection, $query, [$student_id]);

if ($result) {
    $affected = pg_affected_rows($result);
    echo json_encode([
        'success' => true, 
        'count' => $affected,
        'message' => "$affected notification(s) marked as read"
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to update notifications']);
}

pg_close($connection);
?>
