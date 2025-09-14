<?php
include __DIR__ . '/config/database.php';

echo "<h2>🛡️ Blacklist System Database Setup</h2>";

// Check if blacklist tables exist
$tableChecks = [
    'blacklisted_students' => "SELECT EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public' AND tablename = 'blacklisted_students')",
    'admin_blacklist_verifications' => "SELECT EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public' AND tablename = 'admin_blacklist_verifications')"
];

$tablesExist = true;
foreach ($tableChecks as $table => $query) {
    $result = pg_query($connection, $query);
    $exists = pg_fetch_result($result, 0, 0) === 't';
    
    if ($exists) {
        echo "<div style='color: green;'>✅ Table '$table' exists</div>";
    } else {
        echo "<div style='color: red;'>❌ Table '$table' missing</div>";
        $tablesExist = false;
    }
}

// Check if students table has blacklisted status
$statusCheck = pg_query($connection, "SELECT column_name FROM information_schema.columns WHERE table_name = 'students' AND column_name = 'status'");
if ($statusCheck && pg_num_rows($statusCheck) > 0) {
    // Check constraint
    $constraintCheck = pg_query($connection, "SELECT con.conname FROM pg_constraint con INNER JOIN pg_class rel ON rel.oid = con.conrelid WHERE rel.relname = 'students' AND con.conname = 'students_status_check'");
    if ($constraintCheck && pg_num_rows($constraintCheck) > 0) {
        echo "<div style='color: green;'>✅ Students table status constraint exists</div>";
    } else {
        echo "<div style='color: orange;'>⚠️ Students table status constraint may need updating</div>";
    }
} else {
    echo "<div style='color: red;'>❌ Students table status column missing</div>";
    $tablesExist = false;
}

if (!$tablesExist) {
    echo "<h3>🔧 Setup Required</h3>";
    echo "<form method='POST'>";
    echo "<input type='hidden' name='setup_blacklist' value='1'>";
    echo "<button type='submit' style='background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>Setup Blacklist Database Tables</button>";
    echo "</form>";
}

// Handle setup
if (isset($_POST['setup_blacklist'])) {
    echo "<h3>🔄 Setting up blacklist system...</h3>";
    
    // Read and execute schema
    $schema = file_get_contents(__DIR__ . '/sql/blacklist_schema.sql');
    
    if ($schema) {
        // Split by semicolons and execute each statement
        $statements = explode(';', $schema);
        $success = true;
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement) && !str_starts_with($statement, '--')) {
                $result = pg_query($connection, $statement);
                if (!$result) {
                    echo "<div style='color: red;'>❌ Error: " . pg_last_error($connection) . "</div>";
                    echo "<div style='color: gray;'>Statement: " . htmlspecialchars($statement) . "</div>";
                    $success = false;
                } else {
                    echo "<div style='color: green;'>✅ Executed successfully</div>";
                }
            }
        }
        
        if ($success) {
            echo "<div style='color: green; padding: 20px; background: #e7f5e7; border: 2px solid green; margin: 20px 0;'>";
            echo "<h3>🎉 Blacklist System Setup Complete!</h3>";
            echo "<p>All database tables and constraints have been created successfully.</p>";
            echo "</div>";
            
            // Refresh page to show updated status
            echo "<script>setTimeout(() => location.reload(), 3000);</script>";
        }
    } else {
        echo "<div style='color: red;'>❌ Could not read blacklist schema file</div>";
    }
}

// Show test options if everything is set up
if ($tablesExist) {
    echo "<h3>🧪 Test Blacklist System</h3>";
    echo "<a href='blacklist_test.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>Test Blacklist Modal</a>";
    echo "<a href='modules/admin/blacklist_archive.php' style='background: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>View Blacklist Archive</a>";
    echo "<a href='session_debug.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Check Session</a>";
}

echo "<br><br><a href='admin_debug.php'>Check Admin Users</a> | <a href='unified_login.php'>Login System</a>";

pg_close($connection);
?>