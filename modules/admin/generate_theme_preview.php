<?php
/**
 * generate_theme_preview.php
 * AJAX endpoint to generate theme preview from primary/secondary colors
 * Returns color palette without applying to database
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
require_once __DIR__ . '/../../services/ThemeGeneratorService.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check if super admin
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
if (!CSRFProtection::validateToken('generate-theme-preview', $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh and try again.']);
    exit;
}

// Get municipality ID and colors
$municipalityId = (int) ($_POST['municipality_id'] ?? 0);

// Get municipality colors from database
$query = "SELECT primary_color, secondary_color, name FROM municipalities WHERE municipality_id = $1";
$result = pg_query_params($connection, $query, [$municipalityId]);
$municipality = pg_fetch_assoc($result);

if (!$municipality) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Municipality not found']);
    exit;
}

// Generate theme preview
$generator = new ThemeGeneratorService($connection);
$previewResult = $generator->generateThemePreview(
    $municipalityId,
    $municipality['primary_color'],
    $municipality['secondary_color']
);

if (!$previewResult['success']) {
    http_response_code(400);
    echo json_encode($previewResult);
    exit;
}

// Return preview data
echo json_encode([
    'success' => true,
    'message' => 'Theme preview generated successfully',
    'data' => [
        'municipality_name' => $municipality['name'],
        'municipality_id' => $municipalityId,
        'primary_color' => $municipality['primary_color'],
        'secondary_color' => $municipality['secondary_color'],
        'palette' => $previewResult['palette'],
        'sidebar_colors' => $previewResult['sidebar_colors'],
        'topbar_colors' => $previewResult['topbar_colors'],
        'validation' => $previewResult['validation']
    ]
]);
