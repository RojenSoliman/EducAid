<?php
// Setup grading system in database
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
    
    echo "<h2>Setting up Grading System...</h2>\n";
    
    // Read and execute the SQL file
    $sql = file_get_contents(__DIR__ . '/sql/grading_policy_schema.sql');
    
    // Execute the SQL (split by semicolons and execute each statement)
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $pdoConnection->exec($statement);
            } catch (Exception $e) {
                // Some statements might fail if they already exist, that's OK
                echo "<p style='color: orange;'>Notice: " . $e->getMessage() . "</p>\n";
            }
        }
    }
    
    echo "<p style='color: green;'>✅ Grading system setup completed!</p>\n";
    
    // Verify LPU_CAVITE is now in the database
    $stmt = $pdoConnection->prepare("SELECT * FROM grading.university_passing_policy WHERE university_key = 'LPU_CAVITE'");
    $stmt->execute();
    $policy = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($policy) {
        echo "<h3>✅ LPU_CAVITE Policy Verified:</h3>\n";
        echo "<ul>\n";
        echo "<li>Scale: " . $policy['scale_type'] . "</li>\n";
        echo "<li>Higher is Better: " . ($policy['higher_is_better'] ? 'YES' : 'NO') . "</li>\n";
        echo "<li>Passing Value: " . $policy['passing_value'] . "</li>\n";
        echo "</ul>\n";
        
        // Test the function
        $stmt = $pdoConnection->prepare("SELECT grading.grading_is_passing('LPU_CAVITE', '2.50') AS result");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<p><strong>Test:</strong> Grade 2.50 for LPU_CAVITE = " . ($result['result'] ? 'PASS ✅' : 'FAIL ❌') . "</p>\n";
        
    } else {
        echo "<p style='color: red;'>❌ LPU_CAVITE policy not found after setup!</p>\n";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
}
?>