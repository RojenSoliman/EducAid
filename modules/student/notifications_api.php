<?php
// AJAX endpoint for student notifications (mark read, mark all read, get unread count)
include __DIR__ . '/../../config/database.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$student_id = $_SESSION['student_id'];

// Detect if is_read column exists to avoid runtime errors if migration not yet applied
$hasReadColumn = false;
$colCheckSql = "SELECT 1 FROM information_schema.columns WHERE table_name='notifications' AND column_name='is_read' LIMIT 1";
$colCheckRes = @pg_query($connection, $colCheckSql);
if ($colCheckRes && pg_num_rows($colCheckRes) > 0) {
    $hasReadColumn = true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST requests allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

if (!$hasReadColumn) {
    // Graceful degradation: treat everything as read actions no-op
    if (in_array($action, ['mark_all_read','mark_read'])) {
        echo json_encode(['success' => true, 'message' => 'Read tracking not enabled (migration pending)']);
        exit;
    }
    if ($action === 'get_count') {
        echo json_encode(['success' => true, 'count' => 0]);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

switch ($action) {
    case 'mark_all_read':
        $res = pg_query_params($connection, "UPDATE notifications SET is_read = TRUE WHERE (is_read = FALSE OR is_read IS NULL) AND student_id = $1", [$student_id]);
        if ($res) echo json_encode(['success' => true, 'message' => 'All marked read']); else echo json_encode(['success' => false, 'message' => 'Failed']);
        break;
    case 'mark_read':
        $nid = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
        if ($nid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); break; }
        $res = pg_query_params($connection, "UPDATE notifications SET is_read = TRUE WHERE notification_id = $1 AND student_id = $2", [$nid, $student_id]);
        if ($res) echo json_encode(['success'=>true,'message'=>'Marked read']); else echo json_encode(['success'=>false,'message'=>'Failed']);
        break;
    case 'get_count':
        $res = pg_query_params($connection, "SELECT COUNT(*) AS c FROM notifications WHERE student_id = $1 AND (is_read = FALSE OR is_read IS NULL)", [$student_id]);
        if ($res) { $row = pg_fetch_assoc($res); echo json_encode(['success'=>true,'count'=>(int)$row['c']]); } else echo json_encode(['success'=>false,'message'=>'Failed']);
        break;
    default:
        echo json_encode(['success'=>false,'message'=>'Invalid action']);
}

pg_close($connection);
?>
