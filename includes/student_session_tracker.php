<?php
/**
 * Student Session Activity Tracker
 * Include this file at the top of every student page to track session activity
 * Usage: include __DIR__ . '/../../includes/student_session_tracker.php';
 */

// Only track if student is logged in
if (isset($_SESSION['student_id']) && isset($connection)) {
    require_once __DIR__ . '/SessionManager.php';
    
    $sessionManager = new SessionManager($connection);
    
    // Update last activity for current session
    $sessionManager->updateActivity(session_id());
    
    // Clean up expired sessions periodically (1% chance per page load)
    if (rand(1, 100) === 1) {
        $sessionManager->cleanupExpiredSessions();
    }
}
