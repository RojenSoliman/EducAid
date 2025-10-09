<?php
// Simple test to verify the endpoint is accessible
session_start();

// Set a test session for debugging
$_SESSION['admin_username'] = 'test';
$_SESSION['admin_id'] = 1;

// Test basic response
header('Content-Type: application/json');
echo json_encode([
    'success' => true, 
    'message' => 'Endpoint is accessible',
    'session_data' => [
        'admin_username' => $_SESSION['admin_username'] ?? 'not set',
        'admin_id' => $_SESSION['admin_id'] ?? 'not set'
    ]
]);
?>