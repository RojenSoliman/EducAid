<?php
// Test the notification API functionality
session_start();
$_SESSION['admin_username'] = 'test'; // Simulate admin session

// Test get_count action
$_POST['action'] = 'get_count';
echo "Testing get_count API:\n";
include 'notifications_api.php';
echo "\n\n";

// Reset for next test
unset($_POST);

// Test mark_all_read action
$_POST['action'] = 'mark_all_read';
echo "Testing mark_all_read API:\n";
include 'notifications_api.php';
echo "\n\n";

// Test get_count again after marking all as read
unset($_POST);
$_POST['action'] = 'get_count';
echo "Testing get_count API after marking all as read:\n";
include 'notifications_api.php';
?>