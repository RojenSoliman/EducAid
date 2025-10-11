<?php
/**
 * Service to save login page content blocks
 * Used by ContentTools editor on unified_login.php in edit mode
 */

session_start();
header('Content-Type: application/json');

// Security: Only allow super admins
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Include database connection
require_once __DIR__ . '/../config/database.php';

if (!isset($connection)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Get municipality ID (default to 1 for General Trias)
$municipality_id = isset($_POST['municipality_id']) ? (int)$_POST['municipality_id'] : 1;

// Sanitize HTML function
function sanitize_html($html) {
    // Allow only safe HTML tags
    $allowed_tags = '<p><br><b><strong><i><em><u><a><span><div><h1><h2><h3><h4><h5><h6><ul><ol><li>';
    $clean_html = strip_tags($html, $allowed_tags);
    
    // Remove potentially dangerous attributes
    $clean_html = preg_replace('/<([a-z][a-z0-9]*)[^>]*?(on\w+|style)\s*=\s*[\'"]?[^\'"]*[\'"]?[^>]*?>/i', '<$1>', $clean_html);
    
    return $clean_html;
}

// Process each content block
$saved_count = 0;
$errors = [];

foreach ($_POST as $key => $value) {
    // Skip non-content fields
    if ($key === 'municipality_id' || !str_starts_with($key, 'login_')) {
        continue;
    }
    
    // Sanitize the HTML content
    $clean_html = sanitize_html($value);
    
    // Check if block exists
    $check_query = "SELECT block_key FROM landing_content_blocks WHERE municipality_id = $1 AND block_key = $2";
    $check_result = pg_query_params($connection, $check_query, [$municipality_id, $key]);
    
    if (!$check_result) {
        $errors[] = "Database error checking block: $key";
        continue;
    }
    
    $exists = pg_num_rows($check_result) > 0;
    pg_free_result($check_result);
    
    if ($exists) {
        // Update existing block
        $update_query = "UPDATE landing_content_blocks 
                        SET html = $1, updated_at = NOW() 
                        WHERE municipality_id = $2 AND block_key = $3";
        $result = pg_query_params($connection, $update_query, [$clean_html, $municipality_id, $key]);
    } else {
        // Insert new block
        $insert_query = "INSERT INTO landing_content_blocks (municipality_id, block_key, html, created_at, updated_at) 
                        VALUES ($1, $2, $3, NOW(), NOW())";
        $result = pg_query_params($connection, $insert_query, [$municipality_id, $key, $clean_html]);
    }
    
    if ($result) {
        $saved_count++;
    } else {
        $errors[] = "Failed to save block: $key";
    }
}

// Log the save action
$admin_id = $_SESSION['admin_id'] ?? null;
if ($admin_id) {
    $log_query = "INSERT INTO admin_activity_log (admin_id, action, details, created_at) 
                  VALUES ($1, $2, $3, NOW())";
    $log_details = json_encode([
        'page' => 'unified_login',
        'blocks_saved' => $saved_count,
        'municipality_id' => $municipality_id
    ]);
    pg_query_params($connection, $log_query, [$admin_id, 'edit_login_page_content', $log_details]);
}

// Return response
if (count($errors) > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Some blocks failed to save',
        'saved' => $saved_count,
        'errors' => $errors
    ]);
} else {
    echo json_encode([
        'success' => true,
        'message' => 'Content saved successfully',
        'saved' => $saved_count
    ]);
}
