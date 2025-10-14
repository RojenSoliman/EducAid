<?php
/**
 * Distribution Control Migration
 * Sets up database configuration for distribution lifecycle management
 * 
 * SECURITY NOTE: This migration script should only be run by system administrators.
 * Direct browser access should be restricted in production environments.
 */

// Security check - ensure this is being run by an admin
session_start();
if (!isset($_SESSION['admin_username'])) {
    die("Access denied. Admin login required.");
}

// Include database connection
include_once __DIR__ . '/config/database.php';

if (!$connection) {
    die("Database connection failed");
}

echo "<h2>Distribution Control System Migration</h2>\n";
echo "<pre>";

try {
    // Begin transaction
    pg_query($connection, "BEGIN");
    
    echo "Setting up distribution control configuration...\n";
    
    // Insert default config values if they don't exist
    $config_defaults = [
        ['distribution_status', 'inactive'],
        ['slots_open', '0'],
        ['uploads_enabled', '0']
    ];
    
    foreach ($config_defaults as [$key, $default_value]) {
        $check_query = "SELECT key FROM config WHERE key = $1";
        $check_result = pg_query_params($connection, $check_query, [$key]);
        
        if ($check_result && pg_num_rows($check_result) == 0) {
            // Key doesn't exist, insert it
            $insert_query = "INSERT INTO config (key, value) VALUES ($1, $2)";
            $insert_result = pg_query_params($connection, $insert_query, [$key, $default_value]);
            
            if ($insert_result) {
                echo "✓ Added config: $key = $default_value\n";
            } else {
                throw new Exception("Failed to insert config: $key");
            }
        } else {
            echo "• Config already exists: $key\n";
        }
    }
    
    // Create distribution_snapshots table if it doesn't exist (from previous migration)
    $snapshots_table_check = "SELECT 1 FROM information_schema.tables WHERE table_name = 'distribution_snapshots'";
    $snapshots_exists = pg_query($connection, $snapshots_table_check);
    
    if (!$snapshots_exists || pg_num_rows($snapshots_exists) == 0) {
        echo "Creating distribution_snapshots table...\n";
        $create_snapshots = "
            CREATE TABLE distribution_snapshots (
                snapshot_id SERIAL PRIMARY KEY,
                distribution_date DATE NOT NULL,
                academic_year VARCHAR(10),
                semester VARCHAR(20),
                total_students_count INTEGER DEFAULT 0,
                location VARCHAR(255),
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ";
        
        if (pg_query($connection, $create_snapshots)) {
            echo "✓ Created distribution_snapshots table\n";
        } else {
            throw new Exception("Failed to create distribution_snapshots table");
        }
    } else {
        echo "• distribution_snapshots table already exists\n";
    }
    
    // Create document_archives table if it doesn't exist (from previous migration)
    $archives_table_check = "SELECT 1 FROM information_schema.tables WHERE table_name = 'document_archives'";
    $archives_exists = pg_query($connection, $archives_table_check);
    
    if (!$archives_exists || pg_num_rows($archives_exists) == 0) {
        echo "Creating document_archives table...\n";
        $create_archives = "
            CREATE TABLE document_archives (
                archive_id SERIAL PRIMARY KEY,
                student_id VARCHAR(255) NOT NULL,
                document_type VARCHAR(100) NOT NULL,
                file_path TEXT NOT NULL,
                uploaded_date TIMESTAMP,
                academic_year VARCHAR(10),
                semester VARCHAR(20),
                archived_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                distribution_snapshot_id INTEGER REFERENCES distribution_snapshots(snapshot_id) ON DELETE SET NULL
            );
            
            CREATE INDEX idx_document_archives_student ON document_archives(student_id);
            CREATE INDEX idx_document_archives_type ON document_archives(document_type);
            CREATE INDEX idx_document_archives_date ON document_archives(archived_date);
        ";
        
        if (pg_query($connection, $create_archives)) {
            echo "✓ Created document_archives table with indexes\n";
        } else {
            throw new Exception("Failed to create document_archives table");
        }
    } else {
        echo "• document_archives table already exists\n";
    }
    
    // Check current distribution status and provide guidance
    $status_query = "SELECT value FROM config WHERE key = 'distribution_status'";
    $status_result = pg_query($connection, $status_query);
    $current_status = 'inactive';
    
    if ($status_result && $status_row = pg_fetch_assoc($status_result)) {
        $current_status = $status_row['value'];
    }
    
    echo "\nCurrent system status:\n";
    echo "• Distribution Status: $current_status\n";
    
    $slots_query = "SELECT value FROM config WHERE key = 'slots_open'";
    $slots_result = pg_query($connection, $slots_query);
    if ($slots_result && $slots_row = pg_fetch_assoc($slots_result)) {
        $slots_status = $slots_row['value'] === '1' ? 'Open' : 'Closed';
        echo "• Registration Slots: $slots_status\n";
    }
    
    $uploads_query = "SELECT value FROM config WHERE key = 'uploads_enabled'";
    $uploads_result = pg_query($connection, $uploads_query);
    if ($uploads_result && $uploads_row = pg_fetch_assoc($uploads_result)) {
        $uploads_status = $uploads_row['value'] === '1' ? 'Enabled' : 'Disabled';
        echo "• Document Uploads: $uploads_status\n";
    }
    
    // Commit transaction
    pg_query($connection, "COMMIT");
    
    echo "\n✅ Distribution Control Migration completed successfully!\n\n";
    echo "Next steps:\n";
    echo "1. Navigate to Admin > System Controls > Distribution Control\n";
    echo "2. Click 'Start Distribution' to begin a new distribution cycle\n";
    echo "3. Optionally open registration slots for new students\n";
    echo "4. Monitor and manage the distribution lifecycle as needed\n";
    
} catch (Exception $e) {
    // Rollback on error
    pg_query($connection, "ROLLBACK");
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "All changes have been rolled back.\n";
    
    // Show error details
    $error = pg_last_error($connection);
    if ($error) {
        echo "Database error: $error\n";
    }
}

echo "</pre>";

// Close connection
pg_close($connection);
?>