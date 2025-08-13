<?php
// Database Migration Script - Update Admins Table
// Visit this page in your browser to run the migration

include __DIR__ . '/../config/database.php';

echo "<h2>Admin Table Migration</h2>";
echo "<pre>";

try {
    // Check if migration is needed
    $checkQuery = "SELECT column_name FROM information_schema.columns WHERE table_name = 'admins' AND column_name = 'role'";
    $checkResult = pg_query($connection, $checkQuery);
    
    if (pg_num_rows($checkResult) > 0) {
        echo "✅ Migration already completed - 'role' column exists\n";
    } else {
        echo "🔄 Starting migration...\n\n";
        
        // Add role column
        echo "Adding 'role' column...\n";
        $sql1 = "ALTER TABLE admins ADD COLUMN role TEXT CHECK (role IN ('super_admin', 'sub_admin')) DEFAULT 'super_admin'";
        if (pg_query($connection, $sql1)) {
            echo "✅ 'role' column added successfully\n";
        } else {
            echo "❌ Error adding 'role' column: " . pg_last_error($connection) . "\n";
        }
        
        // Add is_active column
        echo "Adding 'is_active' column...\n";
        $sql2 = "ALTER TABLE admins ADD COLUMN is_active BOOLEAN DEFAULT TRUE";
        if (pg_query($connection, $sql2)) {
            echo "✅ 'is_active' column added successfully\n";
        } else {
            echo "❌ Error adding 'is_active' column: " . pg_last_error($connection) . "\n";
        }
        
        // Add created_at column
        echo "Adding 'created_at' column...\n";
        $sql3 = "ALTER TABLE admins ADD COLUMN created_at TIMESTAMP DEFAULT NOW()";
        if (pg_query($connection, $sql3)) {
            echo "✅ 'created_at' column added successfully\n";
        } else {
            echo "❌ Error adding 'created_at' column: " . pg_last_error($connection) . "\n";
        }
        
        // Add last_login column
        echo "Adding 'last_login' column...\n";
        $sql4 = "ALTER TABLE admins ADD COLUMN last_login TIMESTAMP";
        if (pg_query($connection, $sql4)) {
            echo "✅ 'last_login' column added successfully\n";
        } else {
            echo "❌ Error adding 'last_login' column: " . pg_last_error($connection) . "\n";
        }
        
        // Update existing records
        echo "\nUpdating existing admin records...\n";
        $sql5 = "UPDATE admins SET role = 'super_admin', is_active = TRUE, created_at = NOW() WHERE role IS NULL OR is_active IS NULL OR created_at IS NULL";
        if (pg_query($connection, $sql5)) {
            echo "✅ Existing admin records updated successfully\n";
        } else {
            echo "❌ Error updating existing records: " . pg_last_error($connection) . "\n";
        }
        
        // Add notification
        echo "Adding system notification...\n";
        $sql6 = "INSERT INTO admin_notifications (message) VALUES ('Admin role-based access control system has been implemented')";
        if (pg_query($connection, $sql6)) {
            echo "✅ System notification added\n";
        } else {
            echo "❌ Error adding notification: " . pg_last_error($connection) . "\n";
        }
        
        echo "\n🎉 Migration completed successfully!\n\n";
    }
    
    // Show current table structure
    echo "📋 Current admins table structure:\n";
    echo "=" . str_repeat("=", 60) . "\n";
    $structureQuery = "SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'admins' ORDER BY ordinal_position";
    $structureResult = pg_query($connection, $structureQuery);
    
    while ($row = pg_fetch_assoc($structureResult)) {
        printf("%-20s %-15s %-10s %s\n", 
            $row['column_name'], 
            $row['data_type'], 
            $row['is_nullable'], 
            $row['column_default'] ?? 'NULL'
        );
    }
    
    echo "\n👥 Current admin accounts:\n";
    echo "=" . str_repeat("=", 80) . "\n";
    $adminsQuery = "SELECT admin_id, username, first_name, last_name, email, role, is_active, created_at FROM admins ORDER BY admin_id";
    $adminsResult = pg_query($connection, $adminsQuery);
    
    while ($admin = pg_fetch_assoc($adminsResult)) {
        printf("ID: %-3s | %-15s | %-20s | %-10s | Active: %s\n",
            $admin['admin_id'],
            $admin['username'],
            $admin['first_name'] . ' ' . $admin['last_name'],
            $admin['role'],
            $admin['is_active'] === 't' ? 'Yes' : 'No'
        );
    }
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}

echo "\n</pre>";
echo "<p><strong>Note:</strong> All existing admin accounts have been set to 'super_admin' role by default.</p>";
echo "<p><a href='../modules/admin/admin_management.php'>Go to Admin Management</a> | <a href='../modules/admin/homepage.php'>Go to Dashboard</a></p>";

pg_close($connection);
?>
