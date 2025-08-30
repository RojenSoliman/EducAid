<?php
session_start();
include '../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['upload_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit;
}

$uploadId = $input['upload_id'];

try {
    // Simulate OCR processing with Philippine grading system
    $mockOcrResult = simulatePhilippineGradeOCR();
    
    // Update grade_uploads table with OCR results
    $updateQuery = "UPDATE grade_uploads SET 
                    ocr_processed = TRUE,
                    ocr_confidence = $1,
                    extracted_text = $2,
                    validation_status = $3
                    WHERE upload_id = $4 AND student_id = $5";
    
    $result = pg_query_params($connection, $updateQuery, [
        $mockOcrResult['confidence'],
        $mockOcrResult['extracted_text'],
        $mockOcrResult['status'],
        $uploadId,
        $_SESSION['student_id']
    ]);
    
    if (!$result) {
        throw new Exception('Failed to update grades upload: ' . pg_last_error($connection));
    }
    
    // Insert extracted grades
    if (isset($mockOcrResult['grades']) && is_array($mockOcrResult['grades'])) {
        foreach ($mockOcrResult['grades'] as $grade) {
            $gradeQuery = "INSERT INTO extracted_grades 
                          (upload_id, subject_name, grade_value, grade_numeric, grade_percentage, extraction_confidence, is_passing) 
                          VALUES ($1, $2, $3, $4, $5, $6, $7)";
            
            pg_query_params($connection, $gradeQuery, [
                $uploadId,
                $grade['subject'],
                $grade['original_grade'],
                $grade['numeric_grade'],
                $grade['percentage_grade'],
                $mockOcrResult['confidence'],
                $grade['is_passing']
            ]);
        }
    }
    
    // Add notification to student
    $notificationMessage = '';
    switch ($mockOcrResult['status']) {
        case 'passed':
            $notificationMessage = 'Great! Your grades have been processed and meet the minimum requirements (75% or 3.00 GPA).';
            break;
        case 'failed':
            $notificationMessage = 'Your grades have been processed but do not meet the minimum requirements (75% or 3.00 GPA). Please contact administration for assistance.';
            break;
        case 'manual_review':
            $notificationMessage = 'Your grades have been uploaded and are under manual review by administration due to unclear OCR results.';
            break;
    }
    
    if ($notificationMessage) {
        $notifQuery = "INSERT INTO notifications (student_id, message) VALUES ($1, $2)";
        pg_query_params($connection, $notifQuery, [$_SESSION['student_id'], $notificationMessage]);
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Grades results updated successfully',
        'ocr_result' => $mockOcrResult
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function simulatePhilippineGradeOCR() {
    // Simulate various Philippine grading scenarios
    $scenarios = [
        // Scenario 1: High performing student (1.0-5.0 scale)
        [
            'confidence' => rand(85, 95),
            'extracted_text' => "UNIVERSITY OF THE PHILIPPINES\nTRANSCRIPT OF RECORDS\n\nMATH 101 - 1.25\nENG 101 - 1.50\nSCI 101 - 1.75\nHIST 101 - 2.00\nPE 101 - 1.00\n\nGeneral Weighted Average: 1.50",
            'grades' => [
                ['subject' => 'Mathematics 101', 'original_grade' => '1.25', 'numeric_grade' => 1.25, 'percentage_grade' => 91.25],
                ['subject' => 'English 101', 'original_grade' => '1.50', 'numeric_grade' => 1.50, 'percentage_grade' => 87.50],
                ['subject' => 'Science 101', 'original_grade' => '1.75', 'numeric_grade' => 1.75, 'percentage_grade' => 83.75],
                ['subject' => 'History 101', 'original_grade' => '2.00', 'numeric_grade' => 2.00, 'percentage_grade' => 80.00],
                ['subject' => 'Physical Education 101', 'original_grade' => '1.00', 'numeric_grade' => 1.00, 'percentage_grade' => 95.00]
            ]
        ],
        // Scenario 2: Average student (percentage scale)
        [
            'confidence' => rand(78, 88),
            'extracted_text' => "POLYTECHNIC UNIVERSITY OF THE PHILIPPINES\nREPORT CARD - SEMESTER 1\n\nCalculus I: 82%\nPhysics: 79%\nChemistry: 86%\nEnglish: 88%\nFilipino: 85%\n\nGeneral Average: 84%",
            'grades' => [
                ['subject' => 'Calculus I', 'original_grade' => '82%', 'numeric_grade' => 2.28, 'percentage_grade' => 82.00],
                ['subject' => 'Physics', 'original_grade' => '79%', 'numeric_grade' => 2.52, 'percentage_grade' => 79.00],
                ['subject' => 'Chemistry', 'original_grade' => '86%', 'numeric_grade' => 1.96, 'percentage_grade' => 86.00],
                ['subject' => 'English', 'original_grade' => '88%', 'numeric_grade' => 1.80, 'percentage_grade' => 88.00],
                ['subject' => 'Filipino', 'original_grade' => '85%', 'numeric_grade' => 2.04, 'percentage_grade' => 85.00]
            ]
        ],
        // Scenario 3: Borderline student
        [
            'confidence' => rand(70, 82),
            'extracted_text' => "DE LA SALLE UNIVERSITY\nSTUDENT GRADE REPORT\n\nAccounting 101 - 2.75\nEconomics - 3.00\nStatistics - 2.50\nBusiness Law - 3.25\nMarketing - 2.25\n\nSemester GPA: 2.75",
            'grades' => [
                ['subject' => 'Accounting 101', 'original_grade' => '2.75', 'numeric_grade' => 2.75, 'percentage_grade' => 76.25],
                ['subject' => 'Economics', 'original_grade' => '3.00', 'numeric_grade' => 3.00, 'percentage_grade' => 75.00],
                ['subject' => 'Statistics', 'original_grade' => '2.50', 'numeric_grade' => 2.50, 'percentage_grade' => 78.50],
                ['subject' => 'Business Law', 'original_grade' => '3.25', 'numeric_grade' => 3.25, 'percentage_grade' => 73.75],
                ['subject' => 'Marketing', 'original_grade' => '2.25', 'numeric_grade' => 2.25, 'percentage_grade' => 79.75]
            ]
        ],
        // Scenario 4: Below passing (failing student)
        [
            'confidence' => rand(75, 85),
            'extracted_text' => "UNIVERSITY OF SANTO TOMAS\nSTUDENT ACADEMIC RECORD\n\nPhilosophy - 3.50\nTheology - 4.00\nMathematics - 3.75\nLiterature - 3.25\nHistory - 5.00\n\nCumulative GPA: 3.90",
            'grades' => [
                ['subject' => 'Philosophy', 'original_grade' => '3.50', 'numeric_grade' => 3.50, 'percentage_grade' => 71.50],
                ['subject' => 'Theology', 'original_grade' => '4.00', 'numeric_grade' => 4.00, 'percentage_grade' => 70.00],
                ['subject' => 'Mathematics', 'original_grade' => '3.75', 'numeric_grade' => 3.75, 'percentage_grade' => 70.75],
                ['subject' => 'Literature', 'original_grade' => '3.25', 'numeric_grade' => 3.25, 'percentage_grade' => 73.75],
                ['subject' => 'History', 'original_grade' => '5.00', 'numeric_grade' => 5.00, 'percentage_grade' => 65.00]
            ]
        ]
    ];
    
    // Randomly select a scenario
    $scenario = $scenarios[array_rand($scenarios)];
    
    // Process grades and determine passing status
    $passingCount = 0;
    $totalSubjects = count($scenario['grades']);
    
    foreach ($scenario['grades'] as &$grade) {
        // Determine if grade is passing (3.00 or better in 1.0-5.0 scale, 75% or higher in percentage)
        $isPassing = ($grade['numeric_grade'] <= 3.00 && $grade['percentage_grade'] >= 75.00);
        $grade['is_passing'] = $isPassing;
        
        if ($isPassing) {
            $passingCount++;
        }
    }
    
    // Determine overall status
    $passingPercentage = ($passingCount / $totalSubjects) * 100;
    
    if ($scenario['confidence'] < 60) {
        $status = 'manual_review';
    } elseif ($passingPercentage >= 70) { // At least 70% of subjects must be passing
        $status = 'passed';
    } else {
        $status = 'failed';
    }
    
    return [
        'success' => true,
        'confidence' => $scenario['confidence'],
        'extracted_text' => $scenario['extracted_text'],
        'grades' => $scenario['grades'],
        'status' => $status,
        'passing_count' => $passingCount,
        'total_subjects' => $totalSubjects,
        'passing_percentage' => round($passingPercentage, 1),
        'gpa_equivalent' => calculateGPA($scenario['grades'])
    ];
}

function calculateGPA($grades) {
    $totalPoints = 0;
    $totalSubjects = count($grades);
    
    foreach ($grades as $grade) {
        $totalPoints += $grade['numeric_grade'];
    }
    
    return round($totalPoints / $totalSubjects, 2);
}

// Convert percentage to 1.0-5.0 scale (common in Philippines)
function percentageToGPA($percentage) {
    if ($percentage >= 95) return 1.00;
    if ($percentage >= 90) return 1.25;
    if ($percentage >= 85) return 1.50;
    if ($percentage >= 80) return 1.75;
    if ($percentage >= 75) return 2.00;
    if ($percentage >= 70) return 2.25;
    if ($percentage >= 65) return 2.50;
    if ($percentage >= 60) return 2.75;
    if ($percentage >= 55) return 3.00;
    return 5.00; // Failing
}

// Convert 1.0-5.0 scale to percentage
function gpaToPercentage($gpa) {
    if ($gpa <= 1.00) return 95.00;
    if ($gpa <= 1.25) return 91.25;
    if ($gpa <= 1.50) return 87.50;
    if ($gpa <= 1.75) return 83.75;
    if ($gpa <= 2.00) return 80.00;
    if ($gpa <= 2.25) return 77.25;
    if ($gpa <= 2.50) return 75.50;
    if ($gpa <= 2.75) return 72.75;
    if ($gpa <= 3.00) return 70.00;
    return 65.00; // Failing
}
?>
