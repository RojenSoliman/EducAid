<?php
// Test LPU_CAVITE grading validation specifically
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/services/GradeValidationService.php';

try {
    // Create PDO connection
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbName = getenv('DB_NAME') ?: 'educaid';
    $dbUser = getenv('DB_USER') ?: 'postgres';
    $dbPass = getenv('DB_PASSWORD') ?: '';
    $dbPort = getenv('DB_PORT') ?: '5432';
    
    $pdoConnection = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
    $pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>LPU_CAVITE Grade Validation Debug</h2>\n";
    
    // First, check if LPU_CAVITE policy exists in database
    $stmt = $pdoConnection->prepare("SELECT * FROM grading.university_passing_policy WHERE university_key = 'LPU_CAVITE' AND is_active = TRUE");
    $stmt->execute();
    $policy = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($policy) {
        echo "<h3>✅ Policy Found for LPU_CAVITE:</h3>\n";
        echo "<ul>\n";
        echo "<li>Scale Type: " . $policy['scale_type'] . "</li>\n";
        echo "<li>Higher is Better: " . ($policy['higher_is_better'] ? 'TRUE' : 'FALSE') . "</li>\n";
        echo "<li>Highest Value: " . $policy['highest_value'] . "</li>\n";
        echo "<li>Passing Value: " . $policy['passing_value'] . "</li>\n";
        echo "</ul>\n";
    } else {
        echo "<h3>❌ No Policy Found for LPU_CAVITE!</h3>\n";
        echo "<p>Let's check what university keys exist:</p>\n";
        
        $stmt = $pdoConnection->prepare("SELECT university_key FROM grading.university_passing_policy WHERE is_active = TRUE LIMIT 10");
        $stmt->execute();
        $universities = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo "<ul>\n";
        foreach ($universities as $uni) {
            echo "<li>" . $uni . "</li>\n";
        }
        echo "</ul>\n";
    }
    
    // Test the validation function directly
    echo "<h3>Direct Function Test:</h3>\n";
    
    $gradeValidator = new GradeValidationService($pdoConnection);
    
    $testGrades = ['1.75', '2.25', '2.50', '3.00', '3.25'];
    
    echo "<table border='1' style='border-collapse: collapse;'>\n";
    echo "<tr><th>Grade</th><th>Result</th><th>Expected</th><th>Status</th></tr>\n";
    
    foreach ($testGrades as $grade) {
        $result = $gradeValidator->isSubjectPassing('LPU_CAVITE', $grade);
        $actual = $result ? 'PASS' : 'FAIL';
        
        // Expected results based on grade <= 3.00 rule
        $expected = (floatval($grade) <= 3.00) ? 'PASS' : 'FAIL';
        $status = ($actual === $expected) ? '✅' : '❌';
        
        echo "<tr>";
        echo "<td>{$grade}</td>";
        echo "<td>{$actual}</td>";
        echo "<td>{$expected}</td>";
        echo "<td>{$status}</td>";
        echo "</tr>\n";
    }
    
    echo "</table>\n";
    
    // Test raw SQL function call
    echo "<h3>Raw SQL Function Test:</h3>\n";
    $stmt = $pdoConnection->prepare("SELECT grading.grading_is_passing('LPU_CAVITE', '2.50') AS result");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>grading.grading_is_passing('LPU_CAVITE', '2.50') = " . ($result['result'] ? 'TRUE' : 'FALSE') . "</p>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
    echo "<p>Error trace:</p><pre>" . $e->getTraceAsString() . "</pre>\n";
}
?>