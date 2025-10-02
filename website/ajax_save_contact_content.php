<?php
/**
 * AJAX Save Contact Content
 * Saves edited content blocks from Contact page
 * Super Admin only
 */

session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check authentication and authorization
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['blocks']) || !is_array($input['blocks'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit;
}

$blocks = $input['blocks'];
$municipalityId = 1; // Default municipality
$changedBy = $_SESSION['username'] ?? 'super_admin';

try {
    $connection->beginTransaction();
    
    $saved = 0;
    foreach ($blocks as $blockData) {
        if (!isset($blockData['key']) || !isset($blockData['html'])) {
            continue;
        }
        
        $key = trim($blockData['key']);
        $html = $blockData['html'];
        $textColor = $blockData['text_color'] ?? null;
        $bgColor = $blockData['bg_color'] ?? null;
        
        // Sanitize HTML (basic)
        $html = strip_tags($html, '<p><br><strong><em><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6><span><div>');
        
        // Save to audit trail first
        $auditStmt = $connection->prepare("
            INSERT INTO contact_content_audit 
            (municipality_id, block_key, html_snapshot, text_color, bg_color, changed_by, changed_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $auditStmt->execute([$municipalityId, $key, $html, $textColor, $bgColor, $changedBy]);
        
        // Update or insert main content
        $stmt = $connection->prepare("
            INSERT INTO contact_content_blocks 
            (municipality_id, block_key, html, text_color, bg_color, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON CONFLICT (block_key) 
            DO UPDATE SET 
                html = EXCLUDED.html,
                text_color = EXCLUDED.text_color,
                bg_color = EXCLUDED.bg_color,
                updated_at = NOW()
        ");
        $stmt->execute([$municipalityId, $key, $html, $textColor, $bgColor]);
        
        $saved++;
    }
    
    $connection->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Successfully saved {$saved} content block(s)",
        'blocks_saved' => $saved
    ]);
    
} catch (Exception $e) {
    $connection->rollBack();
    error_log("Contact save error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
