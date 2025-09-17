<?php
session_start();
header('Content-Type: application/json');

// Simple debug endpoint to test what's being received
echo json_encode([
    'status' => 'debug',
    'message' => 'Debug endpoint reached',
    'method' => $_SERVER['REQUEST_METHOD'],
    'post_data' => $_POST,
    'session_admin_id' => $_SESSION['admin_id'] ?? null,
    'session_admin_username' => $_SESSION['admin_username'] ?? null,
    'timestamp' => date('Y-m-d H:i:s')
]);
?>