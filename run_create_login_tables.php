<?php
/**
 * Create Login History Tables
 * Execute via: http://localhost/EducAid/run_create_login_tables.php
 */

require_once __DIR__ . '/config/database.php';

echo "<h2>🔐 Creating Login History & Active Sessions Tables</h2>";
echo "<pre>";

// Read the SQL file
$sqlFile = __DIR__ . "/sql/create_login_history_tables.sql";
if (!file_exists($sqlFile)) {
    die("❌ SQL file not found: $sqlFile");
}

$sql = file_get_contents($sqlFile);

echo "📝 Executing SQL script...\n\n";

$result = pg_query($connection, $sql);

if ($result) {
    echo "✅ Tables created successfully!\n\n";
    
    // Show created tables
    echo "📊 Verifying tables:\n\n";
    
    $tables = ['student_login_history', 'student_active_sessions'];
    
    foreach ($tables as $table) {
        $checkQuery = "SELECT COUNT(*) as count FROM $table";
        $checkResult = pg_query($connection, $checkQuery);
        
        if ($checkResult) {
            $row = pg_fetch_assoc($checkResult);
            echo "✓ $table: {$row['count']} rows\n";
        }
    }
    
    echo "\n🎉 Login history system is ready!\n";
    echo "\nNext steps:\n";
    echo "1. Update unified_login.php to log logins\n";
    echo "2. Add session tracking to student pages\n";
    echo "3. Add UI to student_settings.php\n";
    
} else {
    $error = pg_last_error($connection);
    echo "❌ Error: $error\n";
}

echo "</pre>";

pg_close($connection);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Login Tables - EducAid</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        pre { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #2c3e50; }
    </style>
</head>
<body>
    <p style="margin-top: 20px;">
        <a href="modules/admin/homepage.php" style="padding: 10px 20px; background: #0d6efd; color: white; text-decoration: none; border-radius: 6px;">← Back to Admin Dashboard</a>
    </p>
</body>
</html>
