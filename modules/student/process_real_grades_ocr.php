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
    // Get the file path from database
    $query = "SELECT file_path, file_type FROM grade_uploads WHERE upload_id = $1 AND student_id = $2";
    $result = pg_query_params($connection, $query, [$uploadId, $_SESSION['student_id']]);
    
    if (!$result || pg_num_rows($result) === 0) {
        throw new Exception('Upload not found');
    }
    
    $upload = pg_fetch_assoc($result);
    $filePath = $upload['file_path'];
    $fileType = $upload['file_type'];
    
    if (!file_exists($filePath)) {
        throw new Exception('File not found on server');
    }
    
    // Process the actual file with OCR
    $ocrResult = processGradeDocument($filePath, $fileType);
    
    // Update grade_uploads table with OCR results
    $updateQuery = "UPDATE grade_uploads SET 
                    ocr_processed = TRUE,
                    ocr_confidence = $1,
                    extracted_text = $2,
                    validation_status = $3
                    WHERE upload_id = $4 AND student_id = $5";
    
    $updateResult = pg_query_params($connection, $updateQuery, [
        $ocrResult['confidence'],
        $ocrResult['extracted_text'],
        $ocrResult['status'],
        $uploadId,
        $_SESSION['student_id']
    ]);
    
    if (!$updateResult) {
        throw new Exception('Failed to update grades upload: ' . pg_last_error($connection));
    }
    
    // Insert extracted grades if found
    if (isset($ocrResult['grades']) && is_array($ocrResult['grades'])) {
        foreach ($ocrResult['grades'] as $grade) {
            $gradeQuery = "INSERT INTO extracted_grades 
                          (upload_id, subject_name, grade_value, grade_numeric, grade_percentage, extraction_confidence, is_passing) 
                          VALUES ($1, $2, $3, $4, $5, $6, $7)";
            
            pg_query_params($connection, $gradeQuery, [
                $uploadId,
                $grade['subject'],
                $grade['original_grade'],
                $grade['numeric_grade'],
                $grade['percentage_grade'],
                $grade['extraction_confidence'],
                $grade['is_passing']
            ]);
        }
    }
    
    // Add notification based on results
    $notificationMessage = generateNotificationMessage($ocrResult);
    if ($notificationMessage) {
        $notifQuery = "INSERT INTO notifications (student_id, message) VALUES ($1, $2)";
        pg_query_params($connection, $notifQuery, [$_SESSION['student_id'], $notificationMessage]);
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Grades processed successfully',
        'ocr_result' => $ocrResult
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function processGradeDocument($filePath, $fileType) {
    $extractedText = '';
    $confidence = 0;
    
    try {
        // Convert PDF to image if needed
        if ($fileType === 'pdf') {
            $tempImagePath = convertPdfToImage($filePath);
            $ocrPath = $tempImagePath;
        } else {
            $ocrPath = $filePath;
        }
        
        // Use Tesseract to extract text
        $command = "tesseract \"$ocrPath\" stdout -l eng --psm 6 2>&1";
        $extractedText = shell_exec($command);
        
        // Clean up temp file if created
        if (isset($tempImagePath) && file_exists($tempImagePath)) {
            unlink($tempImagePath);
        }
        
        if (empty($extractedText)) {
            throw new Exception('No text could be extracted from the document');
        }
        
        // Parse grades from extracted text
        $parsedGrades = parseGradesFromText($extractedText);
        
        // Calculate confidence based on how many grades we found
        $confidence = calculateConfidence($extractedText, $parsedGrades);
        
        // Determine status
        $status = determineGradeStatus($parsedGrades, $confidence);
        
        return [
            'success' => true,
            'confidence' => $confidence,
            'extracted_text' => $extractedText,
            'grades' => $parsedGrades,
            'status' => $status,
            'total_subjects' => count($parsedGrades),
            'passing_count' => countPassingGrades($parsedGrades)
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'confidence' => 0,
            'extracted_text' => $extractedText,
            'grades' => [],
            'status' => 'manual_review',
            'error' => $e->getMessage()
        ];
    }
}

function convertPdfToImage($pdfPath) {
    $tempImagePath = tempnam(sys_get_temp_dir(), 'grade_ocr_') . '.png';
    
    // Try using ImageMagick (if available)
    $command = "magick \"$pdfPath[0]\" \"$tempImagePath\" 2>&1";
    $output = shell_exec($command);
    
    if (!file_exists($tempImagePath)) {
        // Fallback: try using different PDF to image tools
        $command = "pdftoppm -png -f 1 -l 1 \"$pdfPath\" > \"$tempImagePath\" 2>&1";
        shell_exec($command);
    }
    
    if (!file_exists($tempImagePath)) {
        throw new Exception('Could not convert PDF to image for OCR processing');
    }
    
    return $tempImagePath;
}

function parseGradesFromText($text) {
    $grades = [];
    $lines = explode("\n", $text);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Pattern 1: Subject Name - Grade (1.0-5.0 scale)
        if (preg_match('/(.+?)\s*[-–—:]\s*([1-5]\.?\d*)\s*$/i', $line, $matches)) {
            $subject = trim($matches[1]);
            $gradeValue = floatval($matches[2]);
            
            if ($gradeValue >= 1.0 && $gradeValue <= 5.0) {
                $grades[] = createGradeEntry($subject, $matches[2], $gradeValue, 'gpa');
                continue;
            }
        }
        
        // Pattern 2: Subject Name - Percentage (75-100%)
        if (preg_match('/(.+?)\s*[-–—:]\s*(\d{2,3})%?\s*$/i', $line, $matches)) {
            $subject = trim($matches[1]);
            $percentage = intval($matches[2]);
            
            if ($percentage >= 0 && $percentage <= 100) {
                $gpaEquivalent = percentageToGPA($percentage);
                $grades[] = createGradeEntry($subject, $matches[2] . '%', $gpaEquivalent, 'percentage', $percentage);
                continue;
            }
        }
        
        // Pattern 3: Course Code + Subject Name + Grade
        if (preg_match('/([A-Z]{2,4}\s*\d+)\s*(.+?)\s*[-–—:]\s*([1-5]\.?\d*|8\d|9\d)\s*$/i', $line, $matches)) {
            $courseCode = trim($matches[1]);
            $subjectName = trim($matches[2]);
            $gradeValue = $matches[3];
            
            $subject = $courseCode . ' - ' . $subjectName;
            
            if (is_numeric($gradeValue)) {
                $numericGrade = floatval($gradeValue);
                
                // Handle percentage grades (80-100 range)
                if ($numericGrade >= 75 && $numericGrade <= 100) {
                    $gpaEquivalent = percentageToGPA($numericGrade);
                    $grades[] = createGradeEntry($subject, $gradeValue, $gpaEquivalent, 'percentage', $numericGrade);
                }
                // Handle GPA grades (1.0-5.0 range)
                elseif ($numericGrade >= 1.0 && $numericGrade <= 5.0) {
                    $grades[] = createGradeEntry($subject, $gradeValue, $numericGrade, 'gpa');
                }
            }
        }
    }
    
    return $grades;
}

function createGradeEntry($subject, $originalGrade, $numericGrade, $type, $percentage = null) {
    if ($percentage === null) {
        $percentage = gpaToPercentage($numericGrade);
    }
    
    $isPassing = ($numericGrade <= 3.00 && $percentage >= 75.0);
    
    return [
        'subject' => $subject,
        'original_grade' => $originalGrade,
        'numeric_grade' => $numericGrade,
        'percentage_grade' => $percentage,
        'is_passing' => $isPassing,
        'extraction_confidence' => calculateGradeConfidence($subject, $originalGrade),
        'grade_type' => $type
    ];
}

function calculateGradeConfidence($subject, $grade) {
    $confidence = 50; // Base confidence
    
    // Increase confidence if subject looks like a real course
    if (preg_match('/[A-Z]{2,4}\s*\d+/i', $subject)) {
        $confidence += 20; // Has course code
    }
    
    if (strlen($subject) > 3 && strlen($subject) < 50) {
        $confidence += 15; // Reasonable subject name length
    }
    
    // Increase confidence if grade is in expected format
    if (preg_match('/^[1-5]\.?\d*$/', $grade)) {
        $confidence += 15; // Valid GPA format
    } elseif (preg_match('/^\d{2,3}%?$/', $grade)) {
        $confidence += 10; // Valid percentage format
    }
    
    return min($confidence, 95); // Cap at 95%
}

function calculateConfidence($text, $grades) {
    $baseConfidence = 30;
    
    // Increase confidence based on number of grades found
    $gradeCount = count($grades);
    if ($gradeCount >= 1) $baseConfidence += 20;
    if ($gradeCount >= 3) $baseConfidence += 15;
    if ($gradeCount >= 5) $baseConfidence += 10;
    
    // Check for typical grade document indicators
    $indicators = [
        '/transcript/i' => 15,
        '/grade.*report/i' => 15,
        '/academic.*record/i' => 10,
        '/university|college/i' => 10,
        '/semester|term/i' => 5,
        '/gpa|average/i' => 5
    ];
    
    foreach ($indicators as $pattern => $points) {
        if (preg_match($pattern, $text)) {
            $baseConfidence += $points;
        }
    }
    
    return min($baseConfidence, 95);
}

function determineGradeStatus($grades, $confidence) {
    if ($confidence < 60 || count($grades) === 0) {
        return 'manual_review';
    }
    
    $passingCount = countPassingGrades($grades);
    $totalGrades = count($grades);
    $passingPercentage = ($passingCount / $totalGrades) * 100;
    
    // Require at least 70% of subjects to be passing
    if ($passingPercentage >= 70) {
        return 'passed';
    } else {
        return 'failed';
    }
}

function countPassingGrades($grades) {
    return array_reduce($grades, function($count, $grade) {
        return $count + ($grade['is_passing'] ? 1 : 0);
    }, 0);
}

function generateNotificationMessage($ocrResult) {
    switch ($ocrResult['status']) {
        case 'passed':
            $count = $ocrResult['passing_count'] ?? 0;
            $total = $ocrResult['total_subjects'] ?? 0;
            return "Great! Your grades have been processed successfully. Found {$total} subjects with {$count} passing grades. You meet the minimum requirements.";
            
        case 'failed':
            $count = $ocrResult['passing_count'] ?? 0;
            $total = $ocrResult['total_subjects'] ?? 0;
            return "Your grades have been processed but may not meet the minimum requirements. Found {$total} subjects with {$count} passing grades. Please contact administration for review.";
            
        case 'manual_review':
            return "Your grades document has been uploaded and requires manual review by administration. The system couldn't automatically extract all grade information.";
            
        default:
            return "Your grades have been uploaded and are being processed.";
    }
}

// Grade conversion functions (Philippine system)
function percentageToGPA($percentage) {
    if ($percentage >= 98) return 1.00;
    if ($percentage >= 95) return 1.25;
    if ($percentage >= 92) return 1.50;
    if ($percentage >= 89) return 1.75;
    if ($percentage >= 86) return 2.00;
    if ($percentage >= 83) return 2.25;
    if ($percentage >= 80) return 2.50;
    if ($percentage >= 77) return 2.75;
    if ($percentage >= 75) return 3.00;
    if ($percentage >= 70) return 3.50;
    if ($percentage >= 65) return 4.00;
    return 5.00; // Failing
}

function gpaToPercentage($gpa) {
    if ($gpa <= 1.00) return 98.0;
    if ($gpa <= 1.25) return 95.0;
    if ($gpa <= 1.50) return 92.0;
    if ($gpa <= 1.75) return 89.0;
    if ($gpa <= 2.00) return 86.0;
    if ($gpa <= 2.25) return 83.0;
    if ($gpa <= 2.50) return 80.0;
    if ($gpa <= 2.75) return 77.0;
    if ($gpa <= 3.00) return 75.0;
    if ($gpa <= 3.50) return 70.0;
    if ($gpa <= 4.00) return 65.0;
    return 60.0; // Failing
}
?>