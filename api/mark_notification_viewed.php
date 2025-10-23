<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$student_id = $_SESSION['student_id'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$notification_id = $input['notification_id'] ?? null;

if (!$notification_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Notification ID required']);
    exit;
}

// Mark notification as viewed
$result = pg_query_params($connection,
    "UPDATE notifications 
     SET viewed_at = NOW() 
     WHERE notification_id = $1 
     AND student_id = $2 
     AND is_priority = TRUE
     RETURNING notification_id",
    [$notification_id, $student_id]
);

if ($result && pg_num_rows($result) > 0) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Notification not found or already viewed']);
}

pg_close($connection);
?>
