<?php
/**
 * Grades OCR Test Script
 * 
 * This script tests the Grades OCR year level validation functionality
 * without requiring the full registration form.
 * 
 * Usage:
 * 1. Upload a grades document image using the form below
 * 2. Select the declared year level
 * 3. Click "Run OCR Test"
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create test_documents directory if it doesn't exist
$uploadDir = __DIR__ . '/test_documents/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grades OCR Test Script</title>
<style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    h1 { color: #333; border-bottom: 3px solid #007bff; padding-bottom: 10px; }
    h2 { color: #555; margin-top: 30px; }
    h3 { color: #666; }
    .form-group { margin-bottom: 20px; }
    label { display: block; font-weight: bold; margin-bottom: 5px; color: #333; }
    input[type='file'], select { width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; box-sizing: border-box; }
    button { background: #007bff; color: white; padding: 12px 30px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
    button:hover { background: #0056b3; }
    .success { color: green; padding: 15px; background: #efe; border: 2px solid green; border-radius: 4px; margin: 10px 0; }
    .error { color: red; padding: 15px; background: #fee; border: 2px solid red; border-radius: 4px; margin: 10px 0; }
    .warning { color: orange; padding: 15px; background: #ffc; border: 2px solid orange; border-radius: 4px; margin: 10px 0; }
    .info { padding: 10px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px; margin: 10px 0; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    table td, table th { padding: 10px; border: 1px solid #ddd; text-align: left; }
    table th { background: #f8f9fa; font-weight: bold; }
    pre { background: #f5f5f5; padding: 15px; border: 1px solid #ccc; border-radius: 4px; overflow: auto; max-height: 400px; }
    code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }
    details { margin: 20px 0; }
    summary { cursor: pointer; color: #007bff; font-weight: bold; padding: 10px; background: #f8f9fa; border-radius: 4px; }
    hr { border: none; border-top: 1px solid #ddd; margin: 30px 0; }
</style>
</head>
<body>
<div class="container">

<h1>📄 Grades OCR Test Script</h1>
<p>Test the year level validation feature by uploading a grades document.</p>

<?php
// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['grades_image'])) {
    
    // Get form data
    $declaredYearLevel = $_POST['year_level'] ?? '';
    
    // Handle file upload
    $file = $_FILES['grades_image'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo "<div class='error'>";
        echo "<strong>Upload Error:</strong> ";
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                echo "File is too large.";
                break;
            case UPLOAD_ERR_PARTIAL:
                echo "File was only partially uploaded.";
                break;
            case UPLOAD_ERR_NO_FILE:
                echo "No file was uploaded.";
                break;
            default:
                echo "Unknown error occurred.";
        }
        echo "</div>";
    } else {
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/tiff'];
        $fileType = mime_content_type($file['tmp_name']);
        
        if (!in_array($fileType, $allowedTypes)) {
            echo "<div class='error'>";
            echo "<strong>Invalid File Type:</strong> Only JPG, PNG, and TIFF images are allowed.";
            echo "</div>";
        } else {
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'grades_test_' . time() . '.' . $extension;
            $testImagePath = $uploadDir . $filename;
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $testImagePath)) {
                
                // Convert year level to number
                $declaredYearNum = 0;
                if (stripos($declaredYearLevel, '1st') !== false || stripos($declaredYearLevel, 'First') !== false) {
                    $declaredYearNum = 1;
                } elseif (stripos($declaredYearLevel, '2nd') !== false || stripos($declaredYearLevel, 'Second') !== false) {
                    $declaredYearNum = 2;
                } elseif (stripos($declaredYearLevel, '3rd') !== false || stripos($declaredYearLevel, 'Third') !== false) {
                    $declaredYearNum = 3;
                } elseif (stripos($declaredYearLevel, '4th') !== false || stripos($declaredYearLevel, 'Fourth') !== false) {
                    $declaredYearNum = 4;
                }

                echo "<h2>✅ Test Configuration</h2>";
                echo "<table>";
                echo "<tr><td><strong>Uploaded File:</strong></td><td>" . htmlspecialchars($file['name']) . "</td></tr>";
                echo "<tr><td><strong>Declared Year Level:</strong></td><td>" . htmlspecialchars($declaredYearLevel) . "</td></tr>";
                echo "<tr><td><strong>Declared Year Number:</strong></td><td>" . htmlspecialchars($declaredYearNum) . "</td></tr>";
                echo "<tr><td><strong>File Size:</strong></td><td>" . number_format(filesize($testImagePath)) . " bytes</td></tr>";
                echo "<tr><td><strong>File Type:</strong></td><td>" . htmlspecialchars($fileType) . "</td></tr>";
                echo "</table>";
                echo "<hr>";

                // Perform OCR using Tesseract
                echo "<h2>📋 Step 1: OCR Text Extraction</h2>";
                $tesseractPath = 'C:/Program Files/Tesseract-OCR/tesseract.exe';

                if (!file_exists($tesseractPath)) {
                    echo "<div class='warning'>";
                    echo "<strong>WARNING:</strong> Tesseract not found at default path. Trying 'tesseract' command...";
                    echo "</div>";
                    $tesseractPath = 'tesseract';
                }

                $tempTsvFile = tempnam(sys_get_temp_dir(), 'ocr_') . '.tsv';
                $command = sprintf(
                    '"%s" "%s" "%s" tsv',
                    $tesseractPath,
                    $testImagePath,
                    substr($tempTsvFile, 0, -4)
                );

                echo "<p><strong>Command:</strong> <code>" . htmlspecialchars($command) . "</code></p>";

                exec($command, $output, $returnCode);

                if ($returnCode !== 0) {
                    echo "<div class='error'>";
                    echo "<strong>ERROR:</strong> Tesseract OCR failed with return code: " . $returnCode;
                    echo "</div>";
                } elseif (!file_exists($tempTsvFile)) {
                    echo "<div class='error'>";
                    echo "<strong>ERROR:</strong> OCR output file not created.";
                    echo "</div>";
                } else {
                    // Read OCR text
                    $ocrText = '';
                    $handle = fopen($tempTsvFile, 'r');
                    $header = fgetcsv($handle, 0, "\t");

                    while (($data = fgetcsv($handle, 0, "\t")) !== false) {
                        if (count($data) >= 12 && isset($data[11]) && trim($data[11]) !== '') {
                            $ocrText .= trim($data[11]) . ' ';
                        }
                    }
                    fclose($handle);
                    unlink($tempTsvFile);

                    echo "<div class='success'>";
                    echo "<strong>✓ OCR Extraction Successful</strong><br>";
                    echo "Extracted " . strlen($ocrText) . " characters";
                    echo "</div>";

                    echo "<h3>OCR Text Preview (first 500 characters):</h3>";
                    echo "<pre>";
                    echo htmlspecialchars(substr($ocrText, 0, 500));
                    if (strlen($ocrText) > 500) {
                        echo "\n... (" . (strlen($ocrText) - 500) . " more characters)";
                    }
                    echo "</pre>";
                    echo "<hr>";

                    // Test Year Level Validation Function
                    echo "<h2>🔍 Step 2: Year Level Validation</h2>";

                    /**
                     * Validate Declared Year Level
                     * This is the same function used in student_register.php
                     */
                    function validateDeclaredYear($ocrText, $declaredYearLevel) {
                        // Convert declared year level to number (1, 2, 3, or 4)
                        $declaredYearNum = 0;
                        if (stripos($declaredYearLevel, '1st') !== false || stripos($declaredYearLevel, 'First') !== false) {
                            $declaredYearNum = 1;
                        } elseif (stripos($declaredYearLevel, '2nd') !== false || stripos($declaredYearLevel, 'Second') !== false) {
                            $declaredYearNum = 2;
                        } elseif (stripos($declaredYearLevel, '3rd') !== false || stripos($declaredYearLevel, 'Third') !== false) {
                            $declaredYearNum = 3;
                        } elseif (stripos($declaredYearLevel, '4th') !== false || stripos($declaredYearLevel, 'Fourth') !== false) {
                            $declaredYearNum = 4;
                        }
                        
                        if ($declaredYearNum == 0) {
                            return [
                                'valid' => false,
                                'confidence' => 0,
                                'message' => 'Invalid year level format'
                            ];
                        }
                        
                        // Define all year level variations
                        $yearVariations = [
                            1 => ['first year', '1st year', 'year 1', 'year i'],
                            2 => ['second year', '2nd year', 'year 2', 'year ii'],
                            3 => ['third year', '3rd year', 'year 3', 'year iii'],
                            4 => ['fourth year', '4th year', 'year 4', 'year iv']
                        ];
                        
                        $ocrTextLower = strtolower($ocrText);
                        
                        // Check if declared year level exists in the document
                        $matchedVariation = null;
                        foreach ($yearVariations[$declaredYearNum] as $variation) {
                            if (stripos($ocrTextLower, $variation) !== false) {
                                $matchedVariation = $variation;
                                break;
                            }
                        }
                        
                        if ($matchedVariation === null) {
                            return [
                                'valid' => false,
                                'confidence' => 0,
                                'message' => "Declared year level '$declaredYearLevel' not found in document"
                            ];
                        }
                        
                        // CORRECTED: Extract grades ONLY from the declared year section
                        // Find the start position of the declared year
                        $yearSectionStart = stripos($ocrTextLower, $matchedVariation);
                        
                        // Find the end position (next year level marker or end of document)
                        $yearSectionEnd = strlen($ocrText);
                        foreach ($yearVariations as $otherNum => $otherVariations) {
                            if ($otherNum == $declaredYearNum) continue; // Skip the declared year
                            
                            foreach ($otherVariations as $otherVariation) {
                                $otherPos = stripos($ocrTextLower, $otherVariation, $yearSectionStart + 1);
                                if ($otherPos !== false && $otherPos < $yearSectionEnd) {
                                    $yearSectionEnd = $otherPos;
                                    break;
                                }
                            }
                        }
                        
                        // Extract ONLY the declared year section
                        $yearSection = substr($ocrText, $yearSectionStart, $yearSectionEnd - $yearSectionStart);
                        
                        // Count subject markers in the extracted section
                        $subjectPatterns = [
                            '/\b[A-Z]{2,6}\s*\d{3,4}\b/i',  // Course codes (e.g., CS101, MATH201)
                            '/\bSubject[:：]\s*[A-Za-z]/i',   // "Subject:" labels
                            '/\bCourse[:：]\s*[A-Za-z]/i',    // "Course:" labels
                        ];
                        
                        $subjectCount = 0;
                        foreach ($subjectPatterns as $pattern) {
                            preg_match_all($pattern, $yearSection, $matches);
                            $subjectCount += count($matches[0]);
                        }
                        
                        // Calculate confidence based on section clarity
                        $confidence = 50; // Base confidence for finding the year
                        
                        if ($subjectCount > 0) {
                            $confidence += 30; // Bonus for finding subjects
                        }
                        
                        if ($subjectCount >= 5) {
                            $confidence += 15; // Additional bonus for finding multiple subjects
                        }
                        
                        // Check for clear section boundaries
                        if ($yearSectionEnd < strlen($ocrText)) {
                            $confidence += 5; // Bonus for clear end boundary
                        }
                        
                        $confidence = min($confidence, 100);
                        
                        return [
                            'valid' => true,
                            'confidence' => $confidence,
                            'message' => "Found declared year level section with $subjectCount subject(s)",
                            'extractedSection' => $yearSection,
                            'subjectCount' => $subjectCount
                        ];
                    }

                    // Run validation
                    $result = validateDeclaredYear($ocrText, $declaredYearLevel);

                    echo "<h3>Validation Result:</h3>";
                    if ($result['valid']) {
                        echo "<div class='success'>";
                        echo "<strong>✓ VALIDATION PASSED</strong><br>";
                        echo "<strong>Confidence:</strong> " . $result['confidence'] . "%<br>";
                        echo "<strong>Message:</strong> " . htmlspecialchars($result['message']) . "<br>";
                        if (isset($result['subjectCount'])) {
                            echo "<strong>Subjects Found:</strong> " . $result['subjectCount'];
                        }
                        echo "</div>";
                        
                        if (isset($result['extractedSection'])) {
                            echo "<h3>Extracted Year Section:</h3>";
                            echo "<pre>";
                            echo htmlspecialchars($result['extractedSection']);
                            echo "</pre>";
                        }
                    } else {
                        echo "<div class='error'>";
                        echo "<strong>✗ VALIDATION FAILED</strong><br>";
                        echo "<strong>Confidence:</strong> " . $result['confidence'] . "%<br>";
                        echo "<strong>Message:</strong> " . htmlspecialchars($result['message']);
                        echo "</div>";
                    }

                    echo "<hr>";
                    echo "<h2>📊 Test Complete</h2>";
                    echo "<p>Test completed at: " . date('Y-m-d H:i:s') . "</p>";

                    // Additional debugging info
                    echo "<h3>Debug Information:</h3>";
                    echo "<ul>";
                    echo "<li>Full OCR text length: " . strlen($ocrText) . " characters</li>";
                    echo "<li>PHP Version: " . phpversion() . "</li>";
                    echo "<li>Memory Usage: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB</li>";
                    echo "</ul>";

                    // Show full OCR text (collapsible)
                    echo "<details>";
                    echo "<summary><strong>Click to view full OCR text</strong></summary>";
                    echo "<pre>";
                    echo htmlspecialchars($ocrText);
                    echo "</pre>";
                    echo "</details>";

                    // Delete uploaded file after processing
                    if (isset($_POST['delete_after']) && $_POST['delete_after'] === 'yes') {
                        unlink($testImagePath);
                        echo "<div class='info'><strong>Note:</strong> Uploaded file has been deleted.</div>";
                    } else {
                        echo "<div class='info'><strong>Note:</strong> Uploaded file saved as: " . htmlspecialchars($filename) . "</div>";
                    }
                }
                
            } else {
                // Failed to move uploaded file
                echo "<div class='error'>";
                echo "<strong>Upload Error:</strong> Failed to save uploaded file.";
                echo "</div>";
            }
        }
    }
}

// Show upload form
?>
<hr>
<h2>📤 Upload Grades Document</h2>
<form method="POST" enctype="multipart/form-data">

<div class="form-group">
<label for="grades_image">Select Grades Document Image:</label>
<input type="file" id="grades_image" name="grades_image" accept="image/jpeg,image/png,image/jpg,image/tiff" required>
<small style="display: block; margin-top: 5px; color: #666;">Supported formats: JPG, PNG, TIFF (Max 10MB)</small>
</div>

<div class="form-group">
<label for="year_level">Declared Year Level:</label>
<select id="year_level" name="year_level" required>
<option value="">-- Select Year Level --</option>
<option value="1st Year">1st Year</option>
<option value="2nd Year">2nd Year</option>
<option value="3rd Year">3rd Year</option>
<option value="4th Year">4th Year</option>
</select>
</div>

<div class="form-group">
<label>
<input type="checkbox" name="delete_after" value="yes" checked> 
Delete uploaded file after test
</label>
</div>

<button type="submit">🚀 Run OCR Test</button>
</form>

<hr>
<div class="info">
<strong>ℹ️ About This Test:</strong><br>
This script tests the Grades OCR validation that extracts and validates ONLY the declared year level section.<br>
For example: If you select '3rd Year', the system will extract and validate ONLY 3rd year subjects (ignoring 1st, 2nd, 4th year).
</div>

</div>
</body>
</html>
