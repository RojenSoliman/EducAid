<?php
// Start output buffering to prevent any unwanted output
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear any output and set JSON header
ob_end_clean();
header('Content-Type: application/json');

// Security check first
if (!isset($_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

@require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sectionKey = $_POST['section_key'] ?? '';
    $isVisible = isset($_POST['is_visible']) ? filter_var($_POST['is_visible'], FILTER_VALIDATE_BOOLEAN) : true;
    $municipalityId = 1; // Default municipality
    
    if (empty($sectionKey)) {
        echo json_encode(['success' => false, 'error' => 'Section key is required']);
        exit;
    }
    
    // Check if section exists
    $checkQuery = "SELECT id FROM login_content_blocks WHERE municipality_id = $1 AND block_key = $2";
    $checkResult = pg_query_params($connection, $checkQuery, [$municipalityId, $sectionKey]);
    
    if (pg_num_rows($checkResult) > 0) {
        // Update existing record
    $updateQuery = "UPDATE login_content_blocks SET is_visible = $1, updated_at = NOW() WHERE municipality_id = $2 AND block_key = $3";
        $result = pg_query_params($connection, $updateQuery, [$isVisible ? 'true' : 'false', $municipalityId, $sectionKey]);
    } else {
        // Insert new record for section visibility tracking
    $insertQuery = "INSERT INTO login_content_blocks (municipality_id, block_key, html, is_visible, created_at, updated_at) VALUES ($1, $2, $3, $4, NOW(), NOW())";
        $result = pg_query_params($connection, $insertQuery, [$municipalityId, $sectionKey, '', $isVisible ? 'true' : 'false']);
    }
    
    if ($result) {
        // Log activity
        if (isset($_SESSION['admin_id'])) {
            $log_table_check = pg_query_params($connection, "SELECT 1 FROM information_schema.tables WHERE table_name = $1", ['admin_activity_log']);
            if ($log_table_check && pg_num_rows($log_table_check) > 0) {
                $activityQuery = "INSERT INTO admin_activity_log (admin_id, action, details, created_at) 
                                 VALUES ($1, $2, $3, NOW())";
                $action = $isVisible ? 'show_section' : 'hide_section';
                $details = json_encode([
                    'table' => 'login_content_blocks',
                    'section_key' => $sectionKey,
                    'visible' => $isVisible
                ]);
                @pg_query_params($connection, $activityQuery, [
                    $_SESSION['admin_id'],
                    $action,
                    $details
                ]);
            }
            if ($log_table_check) {
                pg_free_result($log_table_check);
            }
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
