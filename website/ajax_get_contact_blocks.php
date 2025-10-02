<?php
/**
 * AJAX Get Contact Blocks
 * Returns current content blocks for Contact page
 * Super Admin only
 */

session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $stmt = $connection->prepare("
        SELECT block_key, html, text_color, bg_color, updated_at
        FROM contact_content_blocks
        WHERE municipality_id = 1
        ORDER BY block_key
    ");
    $stmt->execute();
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'blocks' => $blocks
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
