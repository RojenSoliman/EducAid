<?php
// Apply the fixed grading function to database
require_once __DIR__ . '/config/database.php';

try {
    // Create PDO connection
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbName = getenv('DB_NAME') ?: 'educaid';
    $dbUser = getenv('DB_USER') ?: 'postgres';
    $dbPass = getenv('DB_PASSWORD') ?: '';
    $dbPort = getenv('DB_PORT') ?: '5432';
    
    $pdoConnection = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbname", $dbUser, $dbPass);
    $pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Applying Fixed Grading Function</h2>\n";
    
    // Read and execute the SQL
    $sql = file_get_contents(__DIR__ . '/fix_grading_function.sql');
    $pdoConnection->exec($sql);
    
    echo "<p style='color: green;'>✅ Grading function updated with debug logging!</p>\n";
    
    // Test the function with debug output
    echo "<h3>Testing with Debug Output:</h3>\n";
    
    // Enable notice display for debug messages  
    $pdoConnection->exec("SET client_min_messages = NOTICE");
    
    $stmt = $pdoConnection->prepare("SELECT grading.grading_is_passing('LPU_CAVITE', '1.25') AS result");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Test Result:</strong> Grade 1.25 for LPU_CAVITE = " . ($result['result'] ? 'PASS ✅' : 'FAIL ❌') . "</p>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
}
?>