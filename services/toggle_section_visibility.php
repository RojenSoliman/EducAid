<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Security check
if (!isset($_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sectionKey = $_POST['section_key'] ?? '';
    $isVisible = isset($_POST['is_visible']) ? filter_var($_POST['is_visible'], FILTER_VALIDATE_BOOLEAN) : true;
    $municipalityId = 1; // Default municipality
    
    if (empty($sectionKey)) {
        echo json_encode(['success' => false, 'error' => 'Section key is required']);
        exit;
    }
    
    // Check if section exists
    $checkQuery = "SELECT id FROM landing_content_blocks WHERE municipality_id = $1 AND block_key = $2";
    $checkResult = pg_query_params($connection, $checkQuery, [$municipalityId, $sectionKey]);
    
    if (pg_num_rows($checkResult) > 0) {
        // Update existing record
        $updateQuery = "UPDATE landing_content_blocks SET is_visible = $1, updated_at = NOW() WHERE municipality_id = $2 AND block_key = $3";
        $result = pg_query_params($connection, $updateQuery, [$isVisible ? 'true' : 'false', $municipalityId, $sectionKey]);
    } else {
        // Insert new record for section visibility tracking
        $insertQuery = "INSERT INTO landing_content_blocks (municipality_id, block_key, html, is_visible, created_at, updated_at) VALUES ($1, $2, $3, $4, NOW(), NOW())";
        $result = pg_query_params($connection, $insertQuery, [$municipalityId, $sectionKey, '', $isVisible ? 'true' : 'false']);
    }
    
    if ($result) {
        // Log activity
        if (isset($_SESSION['admin_id'])) {
            $activityQuery = "INSERT INTO admin_activity_logs (admin_id, action, table_affected, record_id, details) 
                             VALUES ($1, $2, $3, $4, $5)";
            $action = $isVisible ? 'show_section' : 'hide_section';
            $details = json_encode(['section_key' => $sectionKey, 'visible' => $isVisible]);
            pg_query_params($connection, $activityQuery, [
                $_SESSION['admin_id'],
                $action,
                'landing_content_blocks',
                $sectionKey,
                $details
            ]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => $isVisible ? 'Section is now visible' : 'Section is now hidden (archived)',
            'is_visible' => $isVisible
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to update visibility: ' . pg_last_error($connection)
        ]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}

pg_close($connection);
?>
