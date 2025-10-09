<?php
// Check database setup for grading system
require_once __DIR__ . '/config/database.php';

try {
    // Create PDO connection
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbName = getenv('DB_NAME') ?: 'educaid';
    $dbUser = getenv('DB_USER') ?: 'postgres';
    $dbPass = getenv('DB_PASSWORD') ?: '';
    $dbPort = getenv('DB_PORT') ?: '5432';
    
    $pdoConnection = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
    $pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Database Grading System Check</h2>\n";
    
    // Check if grading schema exists
    $stmt = $pdoConnection->query("SELECT schema_name FROM information_schema.schemata WHERE schema_name = 'grading'");
    $schema = $stmt->fetch();
    
    if ($schema) {
        echo "<p>✅ Grading schema exists</p>\n";
    } else {
        echo "<p>❌ Grading schema NOT found</p>\n";
    }
    
    // Check if university_passing_policy table exists
    $stmt = $pdoConnection->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'grading' AND table_name = 'university_passing_policy'");
    $table = $stmt->fetch();
    
    if ($table) {
        echo "<p>✅ university_passing_policy table exists</p>\n";
        
        // Count records
        $stmt = $pdoConnection->query("SELECT COUNT(*) as count FROM grading.university_passing_policy");
        $count = $stmt->fetch()['count'];
        echo "<p>📊 Total grading policies: {$count}</p>\n";
        
    } else {
        echo "<p>❌ university_passing_policy table NOT found</p>\n";
    }
    
    // Check if grading_is_passing function exists
    $stmt = $pdoConnection->query("SELECT routine_name FROM information_schema.routines WHERE routine_schema = 'grading' AND routine_name = 'grading_is_passing'");
    $function = $stmt->fetch();
    
    if ($function) {
        echo "<p>✅ grading_is_passing function exists</p>\n";
    } else {
        echo "<p>❌ grading_is_passing function NOT found</p>\n";
        echo "<p>🔧 Need to create the grading system!</p>\n";
    }
    
    // Show sample policies if they exist
    if ($table) {
        echo "<h3>Sample Grading Policies:</h3>\n";
        $stmt = $pdoConnection->query("SELECT university_key, scale_type, higher_is_better, passing_value FROM grading.university_passing_policy WHERE is_active = TRUE LIMIT 5");
        $policies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($policies) {
            echo "<table border='1' style='border-collapse: collapse;'>\n";
            echo "<tr><th>University</th><th>Scale</th><th>Higher Better</th><th>Passing</th></tr>\n";
            foreach ($policies as $policy) {
                echo "<tr>";
                echo "<td>{$policy['university_key']}</td>";
                echo "<td>{$policy['scale_type']}</td>";
                echo "<td>" . ($policy['higher_is_better'] ? 'YES' : 'NO') . "</td>";
                echo "<td>{$policy['passing_value']}</td>";
                echo "</tr>\n";
            }
            echo "</table>\n";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
}
?>