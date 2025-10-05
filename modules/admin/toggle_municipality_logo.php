<?php
/**
 * Toggle Municipality Logo Type (Custom vs Preset)
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

// Security checks
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$adminRole = getCurrentAdminRole($connection);
if ($adminRole !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF validation
$token = $_POST['csrf_token'] ?? '';
if (!CSRFProtection::validateToken('municipality-logo-toggle', $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$municipalityId = (int) ($_POST['municipality_id'] ?? 0);
$useCustom = filter_var($_POST['use_custom'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!$municipalityId) {
    echo json_encode(['success' => false, 'message' => 'Municipality ID is required']);
    exit;
}

// Update database
$updateQuery = "UPDATE municipalities 
                SET use_custom_logo = $1,
                    updated_at = NOW()
                WHERE municipality_id = $2";

$updateResult = pg_query_params($connection, $updateQuery, [$useCustom ? 't' : 'f', $municipalityId]);

if (!$updateResult) {
    echo json_encode(['success' => false, 'message' => 'Failed to update: ' . pg_last_error($connection)]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Logo preference updated',
    'data' => [
        'municipality_id' => $municipalityId,
        'use_custom_logo' => $useCustom
    ]
]);
?>
