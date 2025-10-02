<?php
/**
 * AJAX Get Contact History
 * Returns edit history for a specific block
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

$blockKey = $_GET['block_key'] ?? null;

if (!$blockKey) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Block key required']);
    exit;
}

try {
    $stmt = $connection->prepare("
        SELECT id, html_snapshot, text_color, bg_color, changed_by, changed_at
        FROM contact_content_audit
        WHERE block_key = ? AND municipality_id = 1
        ORDER BY changed_at DESC
        LIMIT 50
    ");
    $stmt->execute([$blockKey]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'block_key' => $blockKey,
        'history' => $history
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
