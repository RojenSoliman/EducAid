<?php
// Test script to create sample notifications for testing
include_once __DIR__ . '/../config/database.php';

try {
    // Insert a few test notifications
    $testNotifications = [
        ['message' => 'System maintenance scheduled for tonight at 11 PM', 'type' => 'system'],
        ['message' => 'New student registration form has been submitted', 'type' => 'registration'],
        ['message' => 'Server backup completed successfully', 'type' => 'system'],
        ['message' => 'Database optimization completed', 'type' => 'maintenance']
    ];

    foreach ($testNotifications as $notif) {
        $query = "INSERT INTO admin_notifications (message, created_at, is_read) VALUES ($1, NOW(), FALSE)";
        $result = pg_query_params($connection, $query, [$notif['message']]);
        
        if ($result) {
            echo "✓ Created notification: " . $notif['message'] . "\n";
        } else {
            echo "✗ Failed to create notification: " . pg_last_error($connection) . "\n";
        }
    }
    
    echo "\nTest notifications created successfully!\n";
    echo "You can now test the notification system.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>