<?php
/**
 * generate_and_apply_theme.php
 * AJAX endpoint to generate and apply theme colors from primary/secondary colors
 * Applies to sidebar and topbar themes (universal across all pages)
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
require_once __DIR__ . '/../../services/ThemeGeneratorService.php';

// Prevent any output before JSON
ob_start();

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display, just log
ini_set('log_errors', 1);
error_log("=== THEME GENERATOR START ===");

header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['admin_id'])) {
    error_log("THEME GEN: Authentication failed - no admin_id in session");
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

error_log("THEME GEN: Authenticated - admin_id: " . $_SESSION['admin_id']);

// Check if super admin
$adminRole = getCurrentAdminRole($connection);
if ($adminRole !== 'super_admin') {
    error_log("THEME GEN: Access denied - role: $adminRole");
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Super admin only.']);
    exit;
}

error_log("THEME GEN: Role check passed - super_admin");

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate CSRF token (don't consume it so it can be reused if user clicks multiple times)
$token = $_POST['csrf_token'] ?? '';
if (!CSRFProtection::validateToken('generate-theme', $token, false)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token invalid. Please refresh the page and try again.']);
    exit;
}

// Get municipality ID
$municipalityId = (int) ($_POST['municipality_id'] ?? 0);
error_log("THEME GEN: Municipality ID: $municipalityId");

// Get municipality colors from database
$query = "SELECT primary_color, secondary_color, name FROM municipalities WHERE municipality_id = $1";
$result = pg_query_params($connection, $query, [$municipalityId]);
$municipality = pg_fetch_assoc($result);

if (!$municipality) {
    error_log("THEME GEN: Municipality not found - ID: $municipalityId");
    ob_end_clean();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Municipality not found']);
    exit;
}

error_log("THEME GEN: Municipality found - " . $municipality['name']);
error_log("THEME GEN: Primary color: " . $municipality['primary_color']);
error_log("THEME GEN: Secondary color: " . $municipality['secondary_color']);

// Generate and apply theme
try {
    error_log("THEME GEN: Starting theme generation...");
    
    $generator = new ThemeGeneratorService($connection);
    $result = $generator->generateAndApplyTheme(
        $municipalityId,
        $municipality['primary_color'],
        $municipality['secondary_color']
    );

    error_log("THEME GEN: Generation result - " . json_encode($result));

    if (!$result['success']) {
        error_log("THEME GEN: Generation failed - " . json_encode($result));
        ob_end_clean();
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    // Return success
    error_log("THEME GEN: Success! Colors applied: " . ($result['colors_applied'] ?? 'unknown'));
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Theme generated and applied successfully!',
        'data' => [
            'municipality_name' => $municipality['name'],
            'sidebar_updated' => true,
            'topbar_updated' => true,
            'colors_applied' => $result['colors_applied'] ?? 19
        ]
    ]);
    error_log("=== THEME GENERATOR END (SUCCESS) ===");
} catch (Exception $e) {
    error_log("THEME GEN: Exception caught - " . $e->getMessage());
    error_log("THEME GEN: Stack trace - " . $e->getTraceAsString());
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error generating theme: ' . $e->getMessage()
    ]);
    error_log("=== THEME GENERATOR END (ERROR) ===");
}
