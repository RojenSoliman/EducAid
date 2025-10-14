<?php
/**
 * apply_generated_theme.php
 * AJAX endpoint to apply generated theme to sidebar and topbar
 * Updates database tables with generated colors
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
if (!CSRFProtection::validateToken('apply-generated-theme', $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh and try again.']);
    exit;
}

// Get municipality ID
$municipalityId = (int) ($_POST['municipality_id'] ?? 0);

if (!$municipalityId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid municipality ID']);
    exit;
}

// Check if user has permission for this municipality
if (!in_array($municipalityId, $_SESSION['allowed_municipalities'] ?? [], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to update this municipality.']);
    exit;
}

// Get municipality colors from database
$query = "SELECT primary_color, secondary_color, name FROM municipalities WHERE municipality_id = $1";
$result = pg_query_params($connection, $query, [$municipalityId]);
$municipality = pg_fetch_assoc($result);

if (!$municipality) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Municipality not found']);
    exit;
}

try {
    // Generate and apply theme
    $generator = new ThemeGeneratorService($connection);
    $applyResult = $generator->generateAndApplyTheme(
        $municipalityId,
        $municipality['primary_color'],
        $municipality['secondary_color']
    );
    
    if (!$applyResult['success']) {
        http_response_code(400);
        echo json_encode($applyResult);
        exit;
    }
    
    // Success response
    echo json_encode([
        'success' => true,
        'message' => 'Theme applied successfully!',
        'data' => [
            'municipality_name' => $municipality['name'],
            'municipality_id' => $municipalityId,
            'primary_color' => $municipality['primary_color'],
            'secondary_color' => $municipality['secondary_color'],
            'sidebar_updated' => $applyResult['sidebar_updated'],
            'topbar_updated' => $applyResult['topbar_updated'],
            'colors_applied' => [
                'sidebar_colors_count' => count($applyResult['sidebar_colors'] ?? []),
                'topbar_colors_count' => count($applyResult['topbar_colors'] ?? [])
            ]
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Theme application error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to apply theme: ' . $e->getMessage()
    ]);
}
