<?php
/**
 * AJAX Rollback Contact Block
 * Rollback a block to a previous version from audit history
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
$auditId = $input['audit_id'] ?? null;

if (!$auditId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Audit ID required']);
    exit;
}

try {
    // Get the historical version
    $stmt = $connection->prepare("
        SELECT block_key, html_snapshot, text_color, bg_color
        FROM contact_content_audit
        WHERE id = ? AND municipality_id = 1
    ");
    $stmt->execute([$auditId]);
    $version = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$version) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Version not found']);
        exit;
    }
    
    // Restore this version
    $updateStmt = $connection->prepare("
        INSERT INTO contact_content_blocks 
        (municipality_id, block_key, html, text_color, bg_color, updated_at)
        VALUES (1, ?, ?, ?, ?, NOW())
        ON CONFLICT (block_key)
        DO UPDATE SET
            html = EXCLUDED.html,
            text_color = EXCLUDED.text_color,
            bg_color = EXCLUDED.bg_color,
            updated_at = NOW()
    ");
    $updateStmt->execute([
        $version['block_key'],
        $version['html_snapshot'],
        $version['text_color'],
        $version['bg_color']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => "Block '{$version['block_key']}' rolled back successfully"
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
