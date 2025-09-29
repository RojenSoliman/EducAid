<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

// Basic test endpoint
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');

if ($userMessage === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty message']);
    exit;
}

// Just return a simple response without calling the API
echo json_encode([
    'reply' => 'Hello! This is a test response. You said: ' . $userMessage,
    'model' => 'test'
]);
?>