<?php
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log the request
error_log("Password verification request received");
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("Session admin_username: " . ($_SESSION['admin_username'] ?? 'not set'));

// Security checks
if (!isset($_SESSION['admin_username'])) {
    error_log("Unauthorized access - no admin session");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    // Include dependencies
    include_once __DIR__ . '/../../includes/permissions.php';
    include_once __DIR__ . '/../../config/database.php';
    
    error_log("Included dependencies successfully");

    // Check if super admin
    $admin_role = getCurrentAdminRole($connection);
    error_log("Admin role: " . $admin_role);
    
    if ($admin_role !== 'super_admin') {
        error_log("Insufficient permissions");
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
        exit;
    }

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error_log("Invalid request method");
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("JSON input: " . json_encode($input));
    
    if (!$input) {
        error_log("Invalid JSON input");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }

    // Get and validate password
    $password = $input['password'] ?? '';
    if (empty($password)) {
        error_log("Password is empty");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password is required']);
        exit;
    }

    // Get current admin info
    $admin_username = $_SESSION['admin_username'];
    $query = "SELECT password FROM admins WHERE username = $1 AND is_active = TRUE";
    $result = pg_query_params($connection, $query, [$admin_username]);

    if (!$result) {
        error_log("Database query failed: " . pg_last_error($connection));
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database query failed']);
        exit;
    }

    if (pg_num_rows($result) === 0) {
        error_log("Admin account not found for username: " . $admin_username);
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Admin account not found']);
        exit;
    }

    $admin_data = pg_fetch_assoc($result);
    $stored_password = $admin_data['password'];
    
    error_log("Stored password hash length: " . strlen($stored_password));

    // Verify password (assuming password is hashed)
    $password_valid = password_verify($password, $stored_password);
    error_log("Password verification result: " . ($password_valid ? 'true' : 'false'));

    if (!$password_valid) {
        error_log("Invalid password for user: " . $admin_username);
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid password']);
        exit;
    }

    // Store verification status in session with timestamp
    $_SESSION['topbar_settings_verified'] = time();
    $_SESSION['topbar_settings_verified_by'] = $admin_username;

    error_log("Password verification successful");
    echo json_encode([
        'success' => true, 
        'message' => 'Password verified successfully',
        'verified_at' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    error_log("Password verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
}
?>