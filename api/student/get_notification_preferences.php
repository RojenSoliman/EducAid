<?php
/**
 * Get Student Notification Preferences (email-only)
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

// Ensure table exists defensively
$tbl = @pg_query_params($connection, "SELECT 1 FROM information_schema.tables WHERE table_name=$1", ['student_notification_preferences']);
if (!$tbl || !pg_fetch_row($tbl)) {
    echo json_encode(['success' => false, 'error' => 'Preferences not supported']);
    exit;
}

// Fetch or create preferences with defaults
$res = @pg_query_params($connection, "SELECT * FROM student_notification_preferences WHERE student_id = $1", [$student_id]);
if (!$res || !($row = pg_fetch_assoc($res))) {
    $ins = @pg_query_params($connection, "INSERT INTO student_notification_preferences (student_id) VALUES ($1) RETURNING *", [$student_id]);
    if (!$ins) { echo json_encode(['success' => false]); exit; }
    $row = pg_fetch_assoc($ins);
}

// Normalize booleans to true/false strings for JS simplicity
$boolFields = [
    'email_enabled','email_announcement','email_document','email_schedule','email_warning',
    'email_error','email_success','email_system','email_info'
];
foreach ($boolFields as $bf) {
    if (isset($row[$bf])) $row[$bf] = ($row[$bf] === 't');
}

echo json_encode(['success' => true, 'preferences' => $row]);
