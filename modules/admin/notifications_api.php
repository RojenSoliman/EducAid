<?php
// AJAX endpoint to mark admin notifications as read
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' || php_sapi_name() === 'cli') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_all_read') {
        $result = pg_query($connection, "UPDATE admin_notifications SET is_read = TRUE WHERE is_read = FALSE OR is_read IS NULL");
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to mark notifications as read']);
        }
    }
    elseif ($action === 'mark_read' && isset($_POST['notification_id'])) {
        $notification_id = intval($_POST['notification_id']);
        $result = pg_query_params($connection, "UPDATE admin_notifications SET is_read = TRUE WHERE admin_notification_id = $1", [$notification_id]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to mark notification as read']);
        }
    }
    elseif ($action === 'get_count') {
        $countQuery = pg_query($connection, "SELECT COUNT(*) as count FROM admin_notifications WHERE is_read = FALSE");
        
        if ($countQuery) {
            $count = pg_fetch_assoc($countQuery)['count'];
            echo json_encode(['success' => true, 'count' => intval($count)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to get notification count']);
        }
    }
    else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Only POST requests allowed']);
}

pg_close($connection);
?>