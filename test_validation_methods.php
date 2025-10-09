<?php
// Test the actual GradeValidationService method calls
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
    
    $gradeValidator = new GradeValidationService($pdoConnection);
    
    echo "<h2>Testing GradeValidationService Methods</h2>\n";
    
    // Test the exact grades from your screenshot
    $testGrades = [
        ['subject' => 'Third Year First Semester Second Semester', 'grade' => '1.00'],
        ['subject' => 'Applications Development and', 'grade' => '1.50'],
        ['subject' => 'ICT Elective Emerging Technologies', 'grade' => '1.25'],
        ['subject' => '.25 424-25 ICT Elective', 'grade' => '1.25'],
        ['subject' => '524-25 Advanced Holistic Professional', 'grade' => '1.25'],
        ['subject' => 'ICTNOSC Integrative Programming', 'grade' => '1.25'],
        ['subject' => '824-25 Foreign Language', 'grade' => '1.25'],
        ['subject' => 'Management Information Systems', 'grade' => '1.50'],
        ['subject' => '824-25 Information Assurance and Security', 'grade' => '1.25'],
        ['subject' => 'Networking', 'grade' => '1.50'],
        ['subject' => '824-25 Systems Administration and', 'grade' => '1.50'],
        ['subject' => '424-25 Systems Integration and Maintenance Architecture', 'grade' => '2.25'],
        ['subject' => '824-25 Research Methods in Computing', 'grade' => '1.25']
    ];
    
    echo "<table border='1' style='border-collapse: collapse;'>\n";
    echo "<tr><th>Subject</th><th>Grade</th><th>isSubjectPassing Result</th><th>Expected</th><th>Status</th></tr>\n";
    
    foreach ($testGrades as $test) {
        $grade = $test['grade'];
        $subject = $test['subject'];
        
        // Call the exact same method used in validation
        $result = $gradeValidator->isSubjectPassing('LPU_CAVITE', $grade);
        $actual = $result ? 'PASS' : 'FAIL';
        $expected = (floatval($grade) <= 3.00) ? 'PASS' : 'FAIL';
        $status = ($actual === $expected) ? '✅' : '❌ ERROR';
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($subject) . "</td>";
        echo "<td>{$grade}</td>";
        echo "<td>{$actual}</td>";
        echo "<td>{$expected}</td>";
        echo "<td>{$status}</td>";
        echo "</tr>\n";
        
        // If there's an error, show debug info
        if ($actual !== $expected) {
            echo "<tr><td colspan='5' style='background-color: #ffe6e6;'>";
            echo "DEBUG: Grade {$grade} should be {$expected} but got {$actual}";
            echo "</td></tr>\n";
        }
    }
    
    echo "</table>\n";
    
    // Test the validateApplicant method with sample data
    echo "<h3>Testing validateApplicant Method</h3>\n";
    
    $sampleSubjects = [
        [
            'name' => 'Test Subject 1',
            'rawGrade' => '1.25',
            'units' => '3',
            'confidence' => 95
        ],
        [
            'name' => 'Test Subject 2', 
            'rawGrade' => '2.50',
            'units' => '3',
            'confidence' => 90
        ]
    ];
    
    $validationResult = $gradeValidator->validateApplicant('LPU_CAVITE', $sampleSubjects);
    
    echo "<p><strong>Validation Result:</strong></p>\n";
    echo "<ul>\n";
    echo "<li>Eligible: " . ($validationResult['eligible'] ? 'YES' : 'NO') . "</li>\n";
    echo "<li>Total Subjects: " . $validationResult['totalSubjects'] . "</li>\n";
    echo "<li>Passed Subjects: " . $validationResult['passedSubjects'] . "</li>\n";
    echo "<li>Failed Subjects: " . count($validationResult['failedSubjects']) . "</li>\n";
    if (!empty($validationResult['failedSubjects'])) {
        echo "<li>Failed: " . implode(', ', $validationResult['failedSubjects']) . "</li>\n";
    }
    echo "</ul>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
    echo "<pre>" . $e->getTraceAsString() . "</pre>\n";
}
?>