<?php
// API endpoint to delete a student notification
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

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['notification_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Notification ID required']);
    exit;
}

$notification_id = (int)$data['notification_id'];

// Delete notification - ensure it belongs to this student
$query = "DELETE FROM student_notifications 
          WHERE notification_id = $1 AND student_id = $2 
          RETURNING notification_id";

$result = pg_query_params($connection, $query, [$notification_id, $student_id]);

if ($result && pg_affected_rows($result) > 0) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Notification not found']);
}

pg_close($connection);
?>
