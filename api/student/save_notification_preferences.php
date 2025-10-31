<?php
/**
 * Save Student Notification Preferences (email-only)
 */
require_once __DIR__ . '/../../config/database.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
$student_id = $_SESSION['student_id'];

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success' => false, 'error' => 'Invalid payload']); exit; }

$email_enabled = !empty($input['email_enabled']);
$email_frequency = ($input['email_frequency'] ?? 'immediate') === 'daily' ? 'daily' : 'immediate';
$fields = [
    'email_announcement','email_document','email_schedule','email_warning',
    'email_error','email_success','email_system','email_info'
];
$sets = ['email_enabled' => $email_enabled ? 'true' : 'false', 'email_frequency' => $email_frequency];
foreach ($fields as $f) {
    $sets[$f] = !empty($input[$f]) ? 'true' : 'false';
}

// Ensure row exists
$res = @pg_query_params($connection, "SELECT 1 FROM student_notification_preferences WHERE student_id = $1", [$student_id]);
if (!$res || !pg_fetch_row($res)) {
    @pg_query_params($connection, "INSERT INTO student_notification_preferences (student_id) VALUES ($1)", [$student_id]);
}

// Build dynamic update
$sql = "UPDATE student_notification_preferences SET ";
$params = [];
$idx = 1;
$assigns = [];
foreach ($sets as $col => $val) {
    if ($col === 'email_frequency') {
        $assigns[] = "$col = $" . $idx; $params[] = $val; $idx++;
    } else {
        // boolean literals
        $assigns[] = "$col = $val";
    }
}
$sql .= implode(', ', $assigns) . " WHERE student_id = $" . $idx;
$params[] = $student_id;

$ok = @pg_query_params($connection, $sql, $params);
echo json_encode(['success' => $ok !== false]);
