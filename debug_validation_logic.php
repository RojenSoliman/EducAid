<?php
// Debug the exact validation logic step by step
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
    
    echo "<h2>Debug Validation Logic for LPU_CAVITE</h2>\n";
    
    // Get the policy details
    $stmt = $pdoConnection->prepare("SELECT * FROM grading.university_passing_policy WHERE university_key = 'LPU_CAVITE' AND is_active = TRUE");
    $stmt->execute();
    $policy = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($policy) {
        echo "<h3>Policy Details:</h3>\n";
        echo "<ul>\n";
        foreach ($policy as $key => $value) {
            if ($key === 'higher_is_better') {
                $value = $value ? 'TRUE' : 'FALSE';
            }
            echo "<li><strong>{$key}:</strong> {$value}</li>\n";
        }
        echo "</ul>\n";
        
        // Test specific grades manually in SQL
        $testGrades = ['1.00', '1.25', '1.50', '2.25', '2.50', '3.00', '3.25'];
        
        echo "<h3>Direct SQL Function Tests:</h3>\n";
        echo "<table border='1' style='border-collapse: collapse;'>\n";
        echo "<tr><th>Grade</th><th>SQL Result</th><th>Expected</th><th>Status</th></tr>\n";
        
        foreach ($testGrades as $grade) {
            $stmt = $pdoConnection->prepare("SELECT grading.grading_is_passing('LPU_CAVITE', ?) AS result");
            $stmt->execute([$grade]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $sqlResult = $result['result'] ? 'PASS' : 'FAIL';
            $expected = (floatval($grade) <= 3.00) ? 'PASS' : 'FAIL';
            $status = ($sqlResult === $expected) ? '✅' : '❌';
            
            echo "<tr>";
            echo "<td>{$grade}</td>";
            echo "<td>{$sqlResult}</td>";
            echo "<td>{$expected}</td>";
            echo "<td>{$status}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
        
        // Test the manual logic
        echo "<h3>Manual Logic Check:</h3>\n";
        echo "<p>Policy: scale_type = {$policy['scale_type']}, higher_is_better = " . ($policy['higher_is_better'] ? 'TRUE' : 'FALSE') . ", passing_value = {$policy['passing_value']}</p>\n";
        
        foreach ($testGrades as $grade) {
            $gradeNum = floatval($grade);
            $passingNum = floatval($policy['passing_value']); // Should be 3.00
            
            if ($policy['higher_is_better']) {
                $result = $gradeNum >= $passingNum;
                $logic = "{$grade} >= {$policy['passing_value']}";
            } else {
                $result = $gradeNum <= $passingNum;
                $logic = "{$grade} <= {$policy['passing_value']}";
            }
            
            $resultText = $result ? 'PASS' : 'FAIL';
            echo "<p>{$logic} = {$resultText}</p>\n";
        }
        
    } else {
        echo "<p style='color: red;'>❌ No policy found for LPU_CAVITE!</p>\n";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
}
?>