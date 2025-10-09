<?php
// Test script to verify 1-5 scale grading logic
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/services/GradeValidationService.php';

try {
    // Create PDO connection for testing
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbName = getenv('DB_NAME') ?: 'educaid';
    $dbUser = getenv('DB_USER') ?: 'postgres';
    $dbPass = getenv('DB_PASSWORD') ?: '';
    $dbPort = getenv('DB_PORT') ?: '5432';
    
    $pdoConnection = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
    $pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $gradeValidator = new GradeValidationService($pdoConnection);
    
    echo "<h2>Testing 1-5 Grading Scale Logic</h2>\n";
    echo "<p><strong>Rule:</strong> For 1-5 scale, PASS if grade ≤ 3.00, FAIL if grade > 3.00</p>\n";
    echo "<p><strong>Examples:</strong> 1.00-2.75, 3.00 = PASS | 3.25-5.00 = FAIL</p>\n";
    
    // Test cases for 1-5 scale
    $testCases = [
        ['grade' => '1.00', 'expected' => 'PASS'],
        ['grade' => '1.25', 'expected' => 'PASS'],
        ['grade' => '2.50', 'expected' => 'PASS'],
        ['grade' => '2.75', 'expected' => 'PASS'],
        ['grade' => '3.00', 'expected' => 'PASS'], // Exactly 3.00 still passes
        ['grade' => '3.25', 'expected' => 'FAIL'], // 3.25 and above fail
        ['grade' => '4.00', 'expected' => 'FAIL'],
        ['grade' => '5.00', 'expected' => 'FAIL']
    ];
    
    echo "<table border='1' style='border-collapse: collapse; margin: 20px 0;'>\n";
    echo "<tr><th>Grade</th><th>Expected</th><th>Actual</th><th>Status</th></tr>\n";
    
    foreach ($testCases as $test) {
        $grade = $test['grade'];
        $expected = $test['expected'];
        
        // Test with a 1-5 scale university (e.g., PUP_STO_TOMAS)
        $result = $gradeValidator->isSubjectPassing('PUP_STO_TOMAS', $grade);
        $actual = $result ? 'PASS' : 'FAIL';
        $status = ($actual === $expected) ? '✅' : '❌';
        
        echo "<tr>";
        echo "<td>{$grade}</td>";
        echo "<td>{$expected}</td>";
        echo "<td>{$actual}</td>";
        echo "<td>{$status}</td>";
        echo "</tr>\n";
    }
    
    echo "</table>\n";
    
    // Test with your specific grades from the transcript
    echo "<h3>Testing Your Transcript Grades</h3>\n";
    $transcriptGrades = [
        'LIFE AND WORKS OF RIZAL' => '1.75',
        'OPERATIONS RESEARCH' => '2.50',
        'ERGONOMICS 2' => '2.25',
        'INFORMATION SYSTEMS' => '2.50',
        'PROJECT FEASIBILITY' => '2.25',
        'BASIC OCCUPATIONAL SAFETY & HEALTH' => '1.00'
    ];
    
    echo "<table border='1' style='border-collapse: collapse; margin: 20px 0;'>\n";
    echo "<tr><th>Subject</th><th>Grade</th><th>Result</th></tr>\n";
    
    foreach ($transcriptGrades as $subject => $grade) {
        $result = $gradeValidator->isSubjectPassing('PUP_STO_TOMAS', $grade);
        $status = $result ? '✅ PASS' : '❌ FAIL';
        
        echo "<tr>";
        echo "<td>{$subject}</td>";
        echo "<td>{$grade}</td>";
        echo "<td>{$status}</td>";
        echo "</tr>\n";
    }
    
    echo "</table>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
}
?>