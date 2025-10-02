<?php
/**
 * AJAX Reset Contact Content
 * Resets specific block or all blocks to default
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

$input = json_decode(file_get_contents('php://input'), true);
$blockKey = $input['block_key'] ?? 'all';

try {
    if ($blockKey === 'all') {
        // Delete all blocks (will revert to defaults)
        $stmt = $connection->prepare("DELETE FROM contact_content_blocks WHERE municipality_id = 1");
        $stmt->execute();
        $message = "All content blocks reset to defaults";
    } else {
        // Delete specific block
        $stmt = $connection->prepare("DELETE FROM contact_content_blocks WHERE block_key = ? AND municipality_id = 1");
        $stmt->execute([$blockKey]);
        $message = "Block '{$blockKey}' reset to default";
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
