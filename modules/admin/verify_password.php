<?php
session_start();
header('Content-Type: application/json');

// Security checks
if (!isset($_SESSION['admin_username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Include dependencies
include_once __DIR__ . '/../../includes/permissions.php';
include_once __DIR__ . '/../../config/database.php';
include_once __DIR__ . '/../../includes/CSRFProtection.php';

try {
    // Check if super admin
    $admin_role = getCurrentAdminRole($connection);
    if ($admin_role !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
        exit;
    }

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }

    // CSRF Protection
    $csrf_token = $input['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('password_verification', $csrf_token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security token validation failed']);
        exit;
    }

    // Get and validate password
    $password = $input['password'] ?? '';
    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password is required']);
        exit;
    }

    // Get current admin info
    $admin_username = $_SESSION['admin_username'];
    $query = "SELECT password FROM admins WHERE username = $1 AND is_active = TRUE";
    $result = pg_query_params($connection, $query, [$admin_username]);

    if (!$result || pg_num_rows($result) === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Admin account not found']);
        exit;
    }

    $admin_data = pg_fetch_assoc($result);
    $stored_password = $admin_data['password'];

    // Verify password (assuming password is hashed)
    $password_valid = password_verify($password, $stored_password);

    if (!$password_valid) {
        // Log failed attempt
        $log_query = "INSERT INTO admin_activity_log (admin_id, action, details, ip_address, timestamp) 
                      VALUES ($1, $2, $3, $4, CURRENT_TIMESTAMP)";
        $log_details = json_encode([
            'action' => 'password_verification_failed',
            'context' => 'topbar_settings_access',
            'username' => $admin_username
        ]);
        
        pg_query_params($connection, $log_query, [
            $_SESSION['admin_id'] ?? 0,
            'Failed Password Verification',
            $log_details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid password']);
        exit;
    }

    // Log successful verification
    $log_query = "INSERT INTO admin_activity_log (admin_id, action, details, ip_address, timestamp) 
                  VALUES ($1, $2, $3, $4, CURRENT_TIMESTAMP)";
    $log_details = json_encode([
        'action' => 'password_verification_success',
        'context' => 'topbar_settings_access',
        'username' => $admin_username
    ]);
    
    pg_query_params($connection, $log_query, [
        $_SESSION['admin_id'] ?? 0,
        'Successful Password Verification',
        $log_details,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    // Store verification status in session with timestamp
    $_SESSION['topbar_settings_verified'] = time();
    $_SESSION['topbar_settings_verified_by'] = $admin_username;

    echo json_encode([
        'success' => true, 
        'message' => 'Password verified successfully',
        'verified_at' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    error_log("Password verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>