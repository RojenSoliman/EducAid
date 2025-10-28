<?php
/**
 * Distribution Status API
 * Returns current distribution status for real-time student notifications
 */
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/workflow_control.php';

// Optional: Verify student is logged in (for security)
if (!isset($_SESSION['student_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

try {
    $workflow_status = getWorkflowStatus($connection);
    
    // Get distribution details
    $distribution_status = $workflow_status['distribution_status'] ?? 'inactive';
    $uploads_enabled = $workflow_status['uploads_enabled'] ?? false;
    
    // Get academic period and deadline
    $details = [];
    $config_query = "SELECT key, value FROM config WHERE key IN ('current_academic_year', 'current_semester', 'documents_deadline')";
    $config_result = pg_query($connection, $config_query);
    
    if ($config_result) {
        while ($row = pg_fetch_assoc($config_result)) {
            $details[$row['key']] = $row['value'];
        }
    }
    
    // Calculate time remaining to deadline
    $deadline = $details['documents_deadline'] ?? null;
    $time_remaining = null;
    if ($deadline) {
        $deadline_dt = new DateTime($deadline);
        $now = new DateTime();
        if ($deadline_dt > $now) {
            $diff = $now->diff($deadline_dt);
            $time_remaining = [
                'days' => $diff->days,
                'hours' => $diff->h,
                'formatted' => $diff->days . ' days, ' . $diff->h . ' hours'
            ];
        }
    }
    
    // Check if student has submitted documents
    $student_id = $_SESSION['student_id'];
    $docs_query = pg_query_params($connection, 
        "SELECT COUNT(*) as count FROM documents WHERE student_id = $1",
        [$student_id]
    );
    $docs_submitted = false;
    if ($docs_query) {
        $docs_row = pg_fetch_assoc($docs_query);
        $docs_submitted = intval($docs_row['count']) > 0;
    }
    
    echo json_encode([
        'success' => true,
        'status' => $distribution_status,
        'uploads_enabled' => $uploads_enabled,
        'academic_year' => $details['current_academic_year'] ?? null,
        'semester' => $details['current_semester'] ?? null,
        'deadline' => $deadline,
        'time_remaining' => $time_remaining,
        'documents_submitted' => $docs_submitted,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}
