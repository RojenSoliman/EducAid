<?php
session_start();
header('Content-Type: application/json');

// Security check
if (!isset($_SESSION['admin_username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include_once __DIR__ . '/CSRFProtection.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['form_name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    
    $form_name = $input['form_name'];
    $token = CSRFProtection::generateToken($form_name);
    
    echo json_encode([
        'success' => true,
        'token' => $token,
        'form_name' => $form_name
    ]);
    
} catch (Exception $e) {
    error_log("CSRF token generation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>