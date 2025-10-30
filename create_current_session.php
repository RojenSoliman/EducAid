<?php
/**
 * Manually create an active session for currently logged-in student
 * This is needed if you were logged in before the session tracking was implemented
 */
session_start();
include 'config/database.php';
require_once 'includes/SessionManager.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    die("Error: No student logged in. Please login first.\n");
}

$student_id = $_SESSION['student_id'];
$session_id = session_id();

echo "=== Creating Active Session ===\n\n";
echo "Student ID: $student_id\n";
echo "Session ID: $session_id\n\n";

// Create SessionManager instance
$sessionManager = new SessionManager($connection);

// Log the current login session
$sessionManager->logLogin($student_id, $session_id, 'manual');

echo "✓ Active session created successfully!\n";
echo "\nYou can now view your active session in Settings > Active Sessions\n";

pg_close($connection);
?>
