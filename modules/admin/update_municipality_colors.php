<?php
/**
 * AJAX Endpoint: Update Municipality Colors
 * Updates primary and secondary colors without page refresh
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check if user is super admin
$adminRole = getCurrentAdminRole($connection);
if ($adminRole !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Super admin only.']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate CSRF token
$token = $_POST['csrf_token'] ?? '';
if (!CSRFProtection::validateToken('municipality-colors', $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh and try again.']);
    exit;
}

// Get and validate input
$municipalityId = (int) ($_POST['municipality_id'] ?? 0);
$primaryColor = trim($_POST['primary_color'] ?? '');
$secondaryColor = trim($_POST['secondary_color'] ?? '');

// Validate hex color format
$hexPattern = '/^#[0-9A-Fa-f]{6}$/';
if (!preg_match($hexPattern, $primaryColor)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid primary color format. Use hex format like #2e7d32']);
    exit;
}

if (!preg_match($hexPattern, $secondaryColor)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid secondary color format. Use hex format like #1b5e20']);
    exit;
}

// Check if user has permission to update this municipality
if (!$municipalityId || !in_array($municipalityId, $_SESSION['allowed_municipalities'] ?? [], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to update this municipality.']);
    exit;
}

// Update colors in database
$updateResult = pg_query_params(
    $connection,
    'UPDATE municipalities SET primary_color = $1, secondary_color = $2 WHERE municipality_id = $3',
    [$primaryColor, $secondaryColor, $municipalityId]
);

if ($updateResult) {
    echo json_encode([
        'success' => true,
        'message' => 'Colors updated successfully!',
        'data' => [
            'primary_color' => $primaryColor,
            'secondary_color' => $secondaryColor,
            'municipality_id' => $municipalityId
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
