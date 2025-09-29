<?php
// Migration script to add is_read column to admin_notifications table
include_once __DIR__ . '/../config/database.php';

echo "Starting migration: Adding is_read column to admin_notifications table\n";

try {
    // Check if column already exists
    $checkQuery = "SELECT column_name FROM information_schema.columns 
                   WHERE table_name = 'admin_notifications' AND column_name = 'is_read'";
    $checkResult = pg_query($connection, $checkQuery);
    
    if (pg_num_rows($checkResult) > 0) {
        echo "Column 'is_read' already exists. Skipping column creation.\n";
    } else {
        // Add is_read column
        $alterQuery = "ALTER TABLE admin_notifications 
                       ADD COLUMN is_read BOOLEAN DEFAULT FALSE";
        $result1 = pg_query($connection, $alterQuery);
        
        if ($result1) {
            echo "✓ Successfully added is_read column to admin_notifications table\n";
        } else {
            throw new Exception("Failed to add is_read column: " . pg_last_error($connection));
        }
    }
    
    // Create index for better performance (will be skipped if exists)
    $indexQuery = "CREATE INDEX IF NOT EXISTS idx_admin_notifications_is_read 
                   ON admin_notifications(is_read)";
    $result2 = pg_query($connection, $indexQuery);
    
    if ($result2) {
        echo "✓ Successfully created index on is_read column\n";
    } else {
        throw new Exception("Failed to create index: " . pg_last_error($connection));
    }
    
    // Update existing notifications to be marked as unread by default
    $updateQuery = "UPDATE admin_notifications SET is_read = FALSE WHERE is_read IS NULL";
    $result3 = pg_query($connection, $updateQuery);
    
    if ($result3) {
        $affectedRows = pg_affected_rows($result3);
        echo "✓ Updated $affectedRows existing notifications to unread status\n";
    } else {
        throw new Exception("Failed to update existing notifications: " . pg_last_error($connection));
    }
    
    echo "\nMigration completed successfully!\n";
    echo "The admin_notifications table now supports read/unread tracking.\n";
    
} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>