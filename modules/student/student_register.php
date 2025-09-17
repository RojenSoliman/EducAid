<?php
include_once '../../config/database.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

/**
 * Convert different grading systems to 4.0 GPA scale for calculation
 */
function convertGradeToGPA($grade, $system) {
    switch ($system) {
        case 'gpa':
            // Traditional 1.0-5.0 scale (1.0 = highest), convert to 4.0 scale
            $gpa_5_scale = min(max(floatval($grade), 1.0), 5.0);
            return max(0, 5.0 - $gpa_5_scale); // Convert to 4.0 scale
            
        case 'dlsu_gpa':
            // DLSU 4.0 scale (4.0 = 100%, 0.0 = failed)
            return min(max(floatval($grade), 0), 4.0);
            
        case 'letter':
            $letterToGPA = [
                'A+' => 4.0, 'A' => 4.0, 'A-' => 3.7,
                'B+' => 3.3, 'B' => 3.0, 'B-' => 2.7,
                'C+' => 2.3, 'C' => 2.0, 'C-' => 1.7,
                'D+' => 1.3, 'D' => 1.0, 'D-' => 0.7,
                'F' => 0.0
            ];
            return $letterToGPA[strtoupper($grade)] ?? 0.0;
            
        case 'percentage':
        default:
            $percentage = floatval($grade);
            if ($percentage >= 97) return 4.0;
            else if ($percentage >= 93) return 3.7;
            else if ($percentage >= 90) return 3.3;
            else if ($percentage >= 87) return 3.0;
            else if ($percentage >= 83) return 2.7;
            else if ($percentage >= 80) return 2.3;
            else if ($percentage >= 77) return 2.0;
            else if ($percentage >= 73) return 1.7;
            else if ($percentage >= 70) return 1.3;
            else if ($percentage >= 65) return 1.0;
            else if ($percentage >= 60) return 0.7;
            else return 0.0;
    }
}

$municipality_id = 1;

// --- Slot check ---
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $slotRes = pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
    $slotInfo = pg_fetch_assoc($slotRes);
    $slotsLeft = 0;
    $noSlotsAvailable = false;
    $slotsFull = false;
    
    if ($slotInfo) {
        // There is an active slot, check if it's full
        $countRes = pg_query_params($connection, "
            SELECT COUNT(*) AS total FROM students
            WHERE slot_id = $1
        ", [$slotInfo['slot_id']]);
        $countRow = pg_fetch_assoc($countRes);
        $slotsLeft = intval($slotInfo['slot_count']) - intval($countRow['total']);
        
        if ($slotsLeft <= 0) {
            $slotsFull = true;
        }
    } else {
        // No active slot available
        $noSlotsAvailable = true;
    }
    
    if ($noSlotsAvailable || $slotsFull) {
        // Determine the appropriate message and styling
        if ($noSlotsAvailable) {
            $title = "EducAid – Registration Not Available";
            $headerText = "Registration is currently closed.";
            $messageText = "Please wait for the next opening of slots.";
            $iconColor = "text-warning";
        } else {
            $title = "EducAid – Registration Closed";
            $headerText = "Slots are full.";
            $messageText = "Please wait for the next announcement before registering again.";
            $iconColor = "text-danger";
        }
        
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <title>$title</title>
            <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
            <link href="../../assets/css/homepage.css" rel="stylesheet" /> 
            <style>
                .alert-container {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    height: 100vh;
                }
                .spinner {
                    width: 3rem;
                    height: 3rem;
                    margin-bottom: 1rem;
                    animation: spin 1s linear infinite;
                }
                @keyframes spin {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }
            </style>
        </head>
        <body class="bg-light">
            <div class="container alert-container">
                <div class="text-center">
                    <svg class="spinner $iconColor" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
                        <circle cx="50" cy="50" fill="none" stroke="currentColor" stroke-width="10" r="35" stroke-dasharray="164.93361431346415 56.97787143782138">
                            <animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="1s" values="0 50 50;360 50 50" keyTimes="0;1"/>
                        </circle>
                    </svg>
                    <h4 class="$iconColor">$headerText</h4>
                    <p>$messageText</p>
                    <a href="../../unified_login.php" class="btn btn-outline-primary mt-3">Back to Login</a>
                </div>
            </div>
        </body>
        </html>
        HTML;
        exit;
    }
}

// --- OTP send ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['sendOtp'])) {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
        exit;
    }

    $checkEmail = pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1", [$email]);
    if (pg_num_rows($checkEmail) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'This email is already registered. Please use a different email or login.']);
        exit;
    }

    $otp = rand(100000, 999999);

    $_SESSION['otp'] = $otp;
    $_SESSION['otp_email'] = $email;
    $_SESSION['otp_timestamp'] = time();

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'dilucayaka02@gmail.com'; // CHANGE for production
        $mail->Password   = 'jlld eygl hksj flvg';    // CHANGE for production
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('dilucayaka02@gmail.com', 'EducAid');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Your EducAid OTP Code';
        $mail->Body    = "Your One-Time Password (OTP) for EducAid registration is: <strong>$otp</strong><br><br>This OTP is valid for 40 seconds.";
        $mail->AltBody = "Your One-Time Password (OTP) for EducAid registration is: $otp. This OTP is valid for 40 seconds.";

        $mail->send();
        echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email. Please check your inbox and spam folder.']);
    } catch (Exception $e) {
        error_log("PHPMailer Error: {$mail->ErrorInfo}");
        echo json_encode(['status' => 'error', 'message' => 'Message could not be sent. Please check your email address and try again.']);
    }
    exit;
}

// --- OTP verify ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verifyOtp'])) {
    $enteredOtp = filter_var($_POST['otp'], FILTER_SANITIZE_NUMBER_INT);
    $email_for_otp = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_email']) || !isset($_SESSION['otp_timestamp'])) {
        echo json_encode(['status' => 'error', 'message' => 'No OTP sent or session expired. Please request a new OTP.']);
        exit;
    }

    if ($_SESSION['otp_email'] !== $email_for_otp) {
         echo json_encode(['status' => 'error', 'message' => 'Email mismatch for OTP. Please ensure you are verifying the correct email.']);
         exit;
    }

    if ((time() - $_SESSION['otp_timestamp']) > 300) {
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']);
        echo json_encode(['status' => 'error', 'message' => 'OTP has expired. Please request a new OTP.']);
        exit;
    }

    if ((int)$enteredOtp === (int)$_SESSION['otp']) {
        echo json_encode(['status' => 'success', 'message' => 'OTP verified successfully!']);
        $_SESSION['otp_verified'] = true;
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid OTP. Please try again.']);
        $_SESSION['otp_verified'] = false;
    }
    exit;
}

// --- Document OCR Processing ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processOcr'])) {
    if (!isset($_FILES['enrollment_form']) || $_FILES['enrollment_form']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'No file uploaded or upload error.']);
        exit;
    }

    $uploadDir = 'assets/uploads/temp/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Clear temp folder
    $files = glob($uploadDir . '*');
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
    }

    $uploadedFile = $_FILES['enrollment_form'];
    $fileName = basename($uploadedFile['name']);
    $targetPath = $uploadDir . $fileName;

    // Validate filename format: Lastname_Firstname_EAF
    $formFirstName = trim($_POST['first_name'] ?? '');
    $formLastName = trim($_POST['last_name'] ?? '');

    if (empty($formFirstName) || empty($formLastName)) {
        echo json_encode(['status' => 'error', 'message' => 'First name and last name are required for filename validation.']);
        exit;
    }

    // Remove file extension and validate format
    $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
    $expectedFormat = $formLastName . '_' . $formFirstName . '_EAF';

    if (strcasecmp($nameWithoutExt, $expectedFormat) !== 0) {
        echo json_encode([
            'status' => 'error', 
            'message' => "Filename must follow format: {$formLastName}_{$formFirstName}_EAF.{file_extension}"
        ]);
        exit;
    }

    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded file.']);
        exit;
    }

    // Get form data for comparison
    $formData = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'middle_name' => trim($_POST['middle_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'extension_name' => trim($_POST['extension_name'] ?? ''),
        'university_id' => intval($_POST['university_id'] ?? 0),
        'year_level_id' => intval($_POST['year_level_id'] ?? 0)
    ];

    // Get university and year level names for comparison
    $universityName = '';
    $yearLevelName = '';

    if ($formData['university_id'] > 0) {
        $uniResult = pg_query_params($connection, "SELECT name FROM universities WHERE university_id = $1", [$formData['university_id']]);
        if ($uniRow = pg_fetch_assoc($uniResult)) {
            $universityName = $uniRow['name'];
        }
    }

    if ($formData['year_level_id'] > 0) {
        $yearResult = pg_query_params($connection, "SELECT name, code FROM year_levels WHERE year_level_id = $1", [$formData['year_level_id']]);
        if ($yearRow = pg_fetch_assoc($yearResult)) {
            $yearLevelName = $yearRow['name'];
        }
    }

    // Enhanced OCR processing with PDF support
    $outputBase = $uploadDir . 'ocr_' . pathinfo($fileName, PATHINFO_FILENAME);
    
    // Check if the file is a PDF and handle accordingly
    $fileExtension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    
    if ($fileExtension === 'pdf') {
        // Try basic PDF text extraction using a simple approach
        $pdfText = '';
        
        // Method 1: Try using pdftotext if available
        $pdfTextCommand = "pdftotext " . escapeshellarg($targetPath) . " - 2>nul";
        $pdfText = @shell_exec($pdfTextCommand);
        
        if (!empty(trim($pdfText))) {
            // Successfully extracted text from PDF
            $ocrText = $pdfText;
        } else {
            // Method 2: Try basic PHP PDF text extraction
            $pdfContent = file_get_contents($targetPath);
            if ($pdfContent !== false) {
                // Very basic PDF text extraction - look for text streams
                preg_match_all('/\(([^)]+)\)/', $pdfContent, $matches);
                if (!empty($matches[1])) {
                    $extractedText = implode(' ', $matches[1]);
                    // Clean up the extracted text
                    $extractedText = preg_replace('/[^\x20-\x7E]/', ' ', $extractedText);
                    $extractedText = preg_replace('/\s+/', ' ', trim($extractedText));
                    
                    if (strlen($extractedText) > 50) { // Only use if we got substantial text
                        $ocrText = $extractedText;
                    }
                }
            }
            
            // If no text could be extracted
            if (empty($ocrText) || strlen(trim($ocrText)) < 10) {
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'Unable to extract text from PDF. Please try one of these alternatives:',
                    'suggestions' => [
                        '1. Convert the PDF to a JPG or PNG image',
                        '2. Take a photo of the document with your phone camera',
                        '3. Scan the document as an image file',
                        '4. Ensure the PDF contains selectable text (not a scanned image)'
                    ]
                ]);
                exit;
            }
        }
    } else {
        // For image files, use standard Tesseract processing
        $command = "tesseract " . escapeshellarg($targetPath) . " " . escapeshellarg($outputBase) .
                   " --oem 1 --psm 6 -l eng 2>&1";

        $tesseractOutput = shell_exec($command);
        $outputFile = $outputBase . ".txt";

        if (!file_exists($outputFile)) {
            echo json_encode([
                'status' => 'error', 
                'message' => 'OCR processing failed. Please ensure the document is clear and readable.',
                'debug_info' => $tesseractOutput,
                'suggestions' => [
                    '1. Make sure the image is clear and high resolution',
                    '2. Ensure good lighting when taking photos',
                    '3. Try straightening the document in the image',
                    '4. Use JPG or PNG format for best results'
                ]
            ]);
            exit;
        }

        $ocrText = file_get_contents($outputFile);
        
        // Clean up temporary OCR files
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
    }
    
    // Clean up temporary OCR files
    if (file_exists($outputFile)) {
        unlink($outputFile);
    }
    
    if (empty(trim($ocrText))) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'No text could be extracted from the document. Please ensure the image is clear and contains readable text.'
        ]);
        exit;
    }
    
    $ocrTextLower = strtolower($ocrText);

    // Enhanced verification results with confidence tracking
    $verification = [
        'first_name' => false,
        'middle_name' => false,
        'last_name' => false,
        'year_level' => false,
        'university' => false,
        'document_keywords' => false,
        'confidence_scores' => [],
        'found_text_snippets' => []
    ];
    
    // Function to calculate similarity score (reusable)
    function calculateEAFSimilarity($needle, $haystack) {
        $needle = strtolower(trim($needle));
        $haystack = strtolower(trim($haystack));
        
        // Exact match
        if (stripos($haystack, $needle) !== false) {
            return 100;
        }
        
        // Check for partial matches with high similarity
        $words = explode(' ', $haystack);
        $maxSimilarity = 0;
        
        foreach ($words as $word) {
            if (strlen($word) >= 3 && strlen($needle) >= 3) {
                $similarity = 0;
                similar_text($needle, $word, $similarity);
                $maxSimilarity = max($maxSimilarity, $similarity);
            }
        }
        
        return $maxSimilarity;
    }

    // Enhanced name checking with similarity scoring
    if (!empty($formData['first_name'])) {
        $similarity = calculateEAFSimilarity($formData['first_name'], $ocrTextLower);
        $verification['confidence_scores']['first_name'] = $similarity;
        
        if ($similarity >= 80) {
            $verification['first_name'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($formData['first_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['first_name'] = $matches[0];
            }
        }
    }

    // Check middle name (optional) with improved matching
    if (empty($formData['middle_name'])) {
        $verification['middle_name'] = true; // Skip if no middle name provided
        $verification['confidence_scores']['middle_name'] = 100;
    } else {
        $similarity = calculateEAFSimilarity($formData['middle_name'], $ocrTextLower);
        $verification['confidence_scores']['middle_name'] = $similarity;
        
        if ($similarity >= 70) { // Slightly lower threshold for middle names
            $verification['middle_name'] = true;
            $pattern = '/\b\w*' . preg_quote(substr($formData['middle_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['middle_name'] = $matches[0];
            }
        }
    }

    // Check last name with improved matching
    if (!empty($formData['last_name'])) {
        $similarity = calculateEAFSimilarity($formData['last_name'], $ocrTextLower);
        $verification['confidence_scores']['last_name'] = $similarity;
        
        if ($similarity >= 80) {
            $verification['last_name'] = true;
            $pattern = '/\b\w*' . preg_quote(substr($formData['last_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['last_name'] = $matches[0];
            }
        }
    }

    // Check year level (must match the specific year level selected by user)
    if (!empty($yearLevelName)) {
        // Create specific variations for the selected year level only
        $selectedYearVariations = [];

        // Extract year number from the year level name
        if (stripos($yearLevelName, '1st') !== false || stripos($yearLevelName, 'first') !== false) {
            $selectedYearVariations = ['1st year', 'first year', '1st yr', 'year 1', 'yr 1', 'freshman'];
        } elseif (stripos($yearLevelName, '2nd') !== false || stripos($yearLevelName, 'second') !== false) {
            $selectedYearVariations = ['2nd year', 'second year', '2nd yr', 'year 2', 'yr 2', 'sophomore'];
        } elseif (stripos($yearLevelName, '3rd') !== false || stripos($yearLevelName, 'third') !== false) {
            $selectedYearVariations = ['3rd year', 'third year', '3rd yr', 'year 3', 'yr 3', 'junior'];
        } elseif (stripos($yearLevelName, '4th') !== false || stripos($yearLevelName, 'fourth') !== false) {
            $selectedYearVariations = ['4th year', 'fourth year', '4th yr', 'year 4', 'yr 4', 'senior'];
        } elseif (stripos($yearLevelName, '5th') !== false || stripos($yearLevelName, 'fifth') !== false) {
            $selectedYearVariations = ['5th year', 'fifth year', '5th yr', 'year 5', 'yr 5'];
        } elseif (stripos($yearLevelName, 'graduate') !== false || stripos($yearLevelName, 'grad') !== false) {
            $selectedYearVariations = ['graduate', 'grad student', 'masters', 'phd', 'doctoral'];
        }

        // Check if any of the specific year level variations are found
        foreach ($selectedYearVariations as $variation) {
            if (stripos($ocrText, $variation) !== false) {
                $verification['year_level'] = true;
                break;
            }
        }
    }

    // Enhanced university name checking with better matching
    if (!empty($universityName)) {
        $universityWords = array_filter(explode(' ', strtolower($universityName)));
        $foundWords = 0;
        $totalWords = count($universityWords);
        $foundSnippets = [];
        
        foreach ($universityWords as $word) {
            if (strlen($word) > 2) { // Check words longer than 2 characters
                $similarity = calculateEAFSimilarity($word, $ocrTextLower);
                if ($similarity >= 70) {
                    $foundWords++;
                    // Try to find the actual matched word in the text
                    $pattern = '/\b\w*' . preg_quote(substr($word, 0, 3), '/') . '\w*\b/i';
                    if (preg_match($pattern, $ocrText, $matches)) {
                        $foundSnippets[] = $matches[0];
                    }
                }
            }
        }
        
        $universityScore = ($foundWords / max($totalWords, 1)) * 100;
        $verification['confidence_scores']['university'] = round($universityScore, 1);
        
        // Accept if at least 60% of university words are found, or if it's a short name and 1+ words found
        if ($universityScore >= 60 || ($totalWords <= 2 && $foundWords >= 1)) {
            $verification['university'] = true;
            if (!empty($foundSnippets)) {
                $verification['found_text_snippets']['university'] = implode(', ', array_unique($foundSnippets));
            }
        }
    }

    // Enhanced document keywords checking
    $documentKeywords = [
        'enrollment', 'assessment', 'form', 'official', 'academic', 'student',
        'tuition', 'fees', 'semester', 'registration', 'course', 'subject',
        'grade', 'transcript', 'record', 'university', 'college', 'school',
        'eaf', 'assessment form', 'billing', 'statement', 'certificate'
    ];

    $keywordMatches = 0;
    $foundKeywords = [];
    $keywordScore = 0;
    
    foreach ($documentKeywords as $keyword) {
        $similarity = calculateEAFSimilarity($keyword, $ocrTextLower);
        if ($similarity >= 80) {
            $keywordMatches++;
            $foundKeywords[] = $keyword;
            $keywordScore += $similarity;
        }
    }
    
    $averageKeywordScore = $keywordMatches > 0 ? ($keywordScore / $keywordMatches) : 0;
    $verification['confidence_scores']['document_keywords'] = round($averageKeywordScore, 1);

    if ($keywordMatches >= 3) {
        $verification['document_keywords'] = true;
        $verification['found_text_snippets']['document_keywords'] = implode(', ', $foundKeywords);
    }

    // Enhanced overall success calculation
    $requiredChecks = ['first_name', 'middle_name', 'last_name', 'year_level', 'university', 'document_keywords'];
    $passedChecks = 0;
    $totalConfidence = 0;
    $confidenceCount = 0;
    
    foreach ($requiredChecks as $check) {
        if ($verification[$check]) {
            $passedChecks++;
        }
        // Add confidence score to total if available
        if (isset($verification['confidence_scores'][$check])) {
            $totalConfidence += $verification['confidence_scores'][$check];
            $confidenceCount++;
        }
    }
    
    $averageConfidence = $confidenceCount > 0 ? ($totalConfidence / $confidenceCount) : 0;
    
    // More nuanced success criteria:
    // Option 1: At least 4 out of 6 checks pass
    // Option 2: At least 3 checks pass with high confidence
    $verification['overall_success'] = ($passedChecks >= 4) || 
                                     ($passedChecks >= 3 && $averageConfidence >= 80);
    
    $verification['summary'] = [
        'passed_checks' => $passedChecks,
        'total_checks' => 6,
        'average_confidence' => round($averageConfidence, 1),
        'recommendation' => $verification['overall_success'] ? 
            'Document validation successful' : 
            'Please ensure the document clearly shows your name, university, year level, and appears to be an official enrollment form'
    ];
    
    // Include OCR text preview for debugging (truncated for security)
    $verification['ocr_text_preview'] = substr($ocrText, 0, 500) . (strlen($ocrText) > 500 ? '...' : '');

    echo json_encode(['status' => 'success', 'verification' => $verification]);
    exit;
}

// --- Letter to Mayor OCR Processing ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processLetterOcr'])) {
    if (!isset($_FILES['letter_to_mayor']) || $_FILES['letter_to_mayor']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'No letter file uploaded or upload error.']);
        exit;
    }

    $uploadDir = 'assets/uploads/temp/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Clear temp folder for letter files
    $letterFiles = glob($uploadDir . 'letter_*');
    foreach ($letterFiles as $file) {
        if (is_file($file)) unlink($file);
    }

    $uploadedFile = $_FILES['letter_to_mayor'];
    $fileName = 'letter_' . basename($uploadedFile['name']);
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded letter file.']);
        exit;
    }

    // Get form data for comparison
    $formData = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'barangay_id' => intval($_POST['barangay_id'] ?? 0)
    ];

    // Get barangay name for comparison
    $barangayName = '';
    if ($formData['barangay_id'] > 0) {
        $barangayResult = pg_query_params($connection, "SELECT name FROM barangays WHERE barangay_id = $1", [$formData['barangay_id']]);
        if ($barangayRow = pg_fetch_assoc($barangayResult)) {
            $barangayName = $barangayRow['name'];
        }
    }

    // Perform OCR using Tesseract with optimized settings for letter documents
    $outputBase = $uploadDir . 'letter_ocr_' . pathinfo($fileName, PATHINFO_FILENAME);
    
    // Check if the file is a PDF and handle accordingly
    $fileExtension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    
    if ($fileExtension === 'pdf') {
        // Try basic PDF text extraction
        $pdfText = '';
        
        // Method 1: Try using pdftotext if available
        $pdfTextCommand = "pdftotext " . escapeshellarg($targetPath) . " - 2>nul";
        $pdfText = @shell_exec($pdfTextCommand);
        
        if (!empty(trim($pdfText))) {
            // Successfully extracted text from PDF
            $ocrText = $pdfText;
        } else {
            // Method 2: Try basic PHP PDF text extraction
            $pdfContent = file_get_contents($targetPath);
            if ($pdfContent !== false) {
                // Very basic PDF text extraction - look for text streams
                preg_match_all('/\(([^)]+)\)/', $pdfContent, $matches);
                if (!empty($matches[1])) {
                    $extractedText = implode(' ', $matches[1]);
                    // Clean up the extracted text
                    $extractedText = preg_replace('/[^\x20-\x7E]/', ' ', $extractedText);
                    $extractedText = preg_replace('/\s+/', ' ', trim($extractedText));
                    
                    if (strlen($extractedText) > 50) { // Only use if we got substantial text
                        $ocrText = $extractedText;
                    }
                }
            }
            
            // If no text could be extracted
            if (empty($ocrText) || strlen(trim($ocrText)) < 10) {
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'Unable to extract text from PDF. Please try one of these alternatives:',
                    'suggestions' => [
                        '1. Convert the PDF to a JPG or PNG image',
                        '2. Take a photo of the document with your phone camera',  
                        '3. Scan the document as an image file',
                        '4. Ensure the PDF contains selectable text (not a scanned image)'
                    ]
                ]);
                exit;
            }
        }
    } else {
        // For image files, use standard Tesseract processing
        $command = "tesseract " . escapeshellarg($targetPath) . " " . escapeshellarg($outputBase) . 
                   " --oem 1 --psm 6 -l eng 2>&1";
        
        $tesseractOutput = shell_exec($command);
        $outputFile = $outputBase . ".txt";
        
        if (!file_exists($outputFile)) {
            echo json_encode([
                'status' => 'error', 
                'message' => 'OCR processing failed. Please ensure the document is clear and readable.',
                'debug_info' => $tesseractOutput,
                'suggestions' => [
                    '1. Make sure the image is clear and high resolution',
                    '2. Ensure good lighting when taking photos',
                    '3. Try straightening the document in the image',
                    '4. Use JPG or PNG format for best results'
                ]
            ]);
            exit;
        }
        
        $ocrText = file_get_contents($outputFile);
        
        // Clean up temporary OCR files
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
    }
    
    if (empty(trim($ocrText))) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'No text could be extracted from the document. Please ensure the image is clear and contains readable text.'
        ]);
        exit;
    }

    // Improved verification checks for letter to mayor with fuzzy matching
    $verification = [
        'first_name' => false,
        'last_name' => false,
        'barangay' => false,
        'mayor_header' => false,
        'confidence_scores' => [],
        'found_text_snippets' => []
    ];
    
    // Normalize OCR text for better matching
    $ocrTextNormalized = strtolower(preg_replace('/[^\w\s]/', ' ', $ocrText));
    $ocrWords = array_filter(explode(' ', $ocrTextNormalized));
    
    // Function to calculate similarity score
    function calculateSimilarity($needle, $haystack) {
        $needle = strtolower(trim($needle));
        $haystack = strtolower(trim($haystack));
        
        // Exact match
        if (stripos($haystack, $needle) !== false) {
            return 100;
        }
        
        // Check for partial matches with high similarity
        $words = explode(' ', $haystack);
        $maxSimilarity = 0;
        
        foreach ($words as $word) {
            if (strlen($word) >= 3 && strlen($needle) >= 3) {
                $similarity = 0;
                similar_text($needle, $word, $similarity);
                $maxSimilarity = max($maxSimilarity, $similarity);
            }
        }
        
        return $maxSimilarity;
    }
    
    // Check first name with improved matching
    if (!empty($formData['first_name'])) {
        $similarity = calculateSimilarity($formData['first_name'], $ocrTextNormalized);
        $verification['confidence_scores']['first_name'] = $similarity;
        
        if ($similarity >= 80) {
            $verification['first_name'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($formData['first_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['first_name'] = $matches[0];
            }
        }
    }
    
    // Check last name with improved matching
    if (!empty($formData['last_name'])) {
        $similarity = calculateSimilarity($formData['last_name'], $ocrTextNormalized);
        $verification['confidence_scores']['last_name'] = $similarity;
        
        if ($similarity >= 80) {
            $verification['last_name'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($formData['last_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['last_name'] = $matches[0];
            }
        }
    }
    
    // Check barangay name with improved matching
    if (!empty($barangayName)) {
        $similarity = calculateSimilarity($barangayName, $ocrTextNormalized);
        $verification['confidence_scores']['barangay'] = $similarity;
        
        if ($similarity >= 70) { // Slightly lower threshold for barangay names
            $verification['barangay'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($barangayName, 0, 4), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['barangay'] = $matches[0];
            }
        }
    }

    // Check for "Office of the Mayor" header with improved pattern matching
    $mayorHeaders = [
        'office of the mayor',
        'mayor\'s office', 
        'office mayor',
        'municipal mayor',
        'city mayor',
        'mayor office',
        'office of mayor',
        'municipal government',
        'city government',
        'local government unit',
        'lgu'
    ];
    
    $mayorHeaderFound = false;
    $mayorConfidence = 0;
    $foundMayorText = '';
    
    foreach ($mayorHeaders as $header) {
        $similarity = calculateSimilarity($header, $ocrTextNormalized);
        if ($similarity > $mayorConfidence) {
            $mayorConfidence = $similarity;
        }
        
        if ($similarity >= 70) {
            $mayorHeaderFound = true;
            // Try to find the actual text snippet
            $pattern = '/[^\n]*' . preg_quote(explode(' ', $header)[0], '/') . '[^\n]*/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $foundMayorText = trim($matches[0]);
            }
            break;
        }
    }
    
    $verification['mayor_header'] = $mayorHeaderFound;
    $verification['confidence_scores']['mayor_header'] = $mayorConfidence;
    if (!empty($foundMayorText)) {
        $verification['found_text_snippets']['mayor_header'] = $foundMayorText;
    }

    // Calculate overall success with improved scoring
    $requiredLetterChecks = ['first_name', 'last_name', 'barangay', 'mayor_header'];
    $passedLetterChecks = 0;
    $totalConfidence = 0;
    
    foreach ($requiredLetterChecks as $check) {
        if ($verification[$check]) {
            $passedLetterChecks++;
        }
        // Add confidence score to total (default 0 if not set)
        $totalConfidence += isset($verification['confidence_scores'][$check]) ? 
            $verification['confidence_scores'][$check] : 0;
    }
    
    $averageConfidence = $totalConfidence / 4;
    
    // More nuanced success criteria:
    // Option 1: At least 3 out of 4 checks pass with decent confidence
    // Option 2: High overall confidence even if only 2 checks pass
    $verification['overall_success'] = ($passedLetterChecks >= 3) || 
                                     ($passedLetterChecks >= 2 && $averageConfidence >= 75);
    
    $verification['summary'] = [
        'passed_checks' => $passedLetterChecks,
        'total_checks' => 4,
        'average_confidence' => round($averageConfidence, 1),
        'recommendation' => $verification['overall_success'] ? 
            'Document validation successful' : 
            'Please ensure the document contains your name, barangay, and mayor office header clearly'
    ];
    
    // Include OCR text for debugging (truncated for security)
    $verification['ocr_text_preview'] = substr($ocrText, 0, 500) . (strlen($ocrText) > 500 ? '...' : '');
    
    // Clean up uploaded file after processing
    if (file_exists($targetPath)) {
        unlink($targetPath);
    }

    echo json_encode(['status' => 'success', 'verification' => $verification]);
    exit;
}

// --- Certificate of Indigency OCR Processing ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processCertificateOcr'])) {
    if (!isset($_FILES['certificate_of_indigency']) || $_FILES['certificate_of_indigency']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'No certificate file uploaded or upload error.']);
        exit;
    }

    $uploadDir = 'assets/uploads/temp/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Clear temp folder for certificate files
    $certificateFiles = glob($uploadDir . 'certificate_*');
    foreach ($certificateFiles as $file) {
        if (is_file($file)) unlink($file);
    }

    $uploadedFile = $_FILES['certificate_of_indigency'];
    $fileName = 'certificate_' . basename($uploadedFile['name']);
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded certificate file.']);
        exit;
    }

    // Get form data for comparison
    $formData = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'barangay_id' => intval($_POST['barangay_id'] ?? 0)
    ];

    // Get barangay name for comparison
    $barangayName = '';
    if ($formData['barangay_id'] > 0) {
        $barangayResult = pg_query_params($connection, "SELECT name FROM barangays WHERE barangay_id = $1", [$formData['barangay_id']]);
        if ($barangayRow = pg_fetch_assoc($barangayResult)) {
            $barangayName = $barangayRow['name'];
        }
    }

    // Perform OCR using Tesseract with optimized settings for certificate documents
    $outputBase = $uploadDir . 'certificate_ocr_' . pathinfo($fileName, PATHINFO_FILENAME);
    
    // Check if the file is a PDF and handle accordingly
    $fileExtension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    
    if ($fileExtension === 'pdf') {
        // Try basic PDF text extraction
        $pdfText = '';
        
        // Method 1: Try using pdftotext if available
        $pdfTextCommand = "pdftotext " . escapeshellarg($targetPath) . " - 2>nul";
        $pdfText = @shell_exec($pdfTextCommand);
        
        if (!empty(trim($pdfText))) {
            // Successfully extracted text from PDF
            $ocrText = $pdfText;
        } else {
            // Method 2: Try basic PHP PDF text extraction
            $pdfContent = file_get_contents($targetPath);
            if ($pdfContent !== false) {
                // Very basic PDF text extraction - look for text streams
                preg_match_all('/\(([^)]+)\)/', $pdfContent, $matches);
                if (!empty($matches[1])) {
                    $extractedText = implode(' ', $matches[1]);
                    // Clean up the extracted text
                    $extractedText = preg_replace('/[^\x20-\x7E]/', ' ', $extractedText);
                    $extractedText = preg_replace('/\s+/', ' ', trim($extractedText));
                    
                    if (strlen($extractedText) > 50) { // Only use if we got substantial text
                        $ocrText = $extractedText;
                    }
                }
            }
            
            // If no text could be extracted
            if (empty($ocrText) || strlen(trim($ocrText)) < 10) {
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'Unable to extract text from PDF. Please try one of these alternatives:',
                    'suggestions' => [
                        '1. Convert the PDF to a JPG or PNG image',
                        '2. Take a photo of the document with your phone camera',  
                        '3. Scan the document as an image file',
                        '4. Ensure the PDF contains selectable text (not a scanned image)'
                    ]
                ]);
                exit;
            }
        }
    } else {
        // For image files, use standard Tesseract processing
        $command = "tesseract " . escapeshellarg($targetPath) . " " . escapeshellarg($outputBase) . 
                   " --oem 1 --psm 6 -l eng 2>&1";
        
        $tesseractOutput = shell_exec($command);
        $outputFile = $outputBase . ".txt";
        
        if (!file_exists($outputFile)) {
            echo json_encode([
                'status' => 'error', 
                'message' => 'OCR processing failed. Please ensure the document is clear and readable.',
                'debug_info' => $tesseractOutput,
                'suggestions' => [
                    '1. Make sure the image is clear and high resolution',
                    '2. Ensure good lighting when taking photos',
                    '3. Try straightening the document in the image',
                    '4. Use JPG or PNG format for best results'
                ]
            ]);
            exit;
        }
        
        $ocrText = file_get_contents($outputFile);
        
        // Clean up temporary OCR files
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
    }
    
    if (empty(trim($ocrText))) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'No text could be extracted from the document. Please ensure the image is clear and contains readable text.'
        ]);
        exit;
    }

    // Improved verification checks for certificate of indigency with fuzzy matching
    $verification = [
        'certificate_title' => false,
        'first_name' => false,
        'last_name' => false,
        'barangay' => false,
        'general_trias' => false,
        'confidence_scores' => [],
        'found_text_snippets' => []
    ];
    
    // Normalize OCR text for better matching
    $ocrTextNormalized = strtolower(preg_replace('/[^\w\s]/', ' ', $ocrText));
    
    // Function to calculate similarity score for certificate
    function calculateCertificateSimilarity($needle, $haystack) {
        $needle = strtolower(trim($needle));
        $haystack = strtolower(trim($haystack));
        
        // Exact match
        if (stripos($haystack, $needle) !== false) {
            return 100;
        }
        
        // Check for partial matches with high similarity
        $words = explode(' ', $haystack);
        $maxSimilarity = 0;
        
        foreach ($words as $word) {
            if (strlen($word) >= 3 && strlen($needle) >= 3) {
                $similarity = 0;
                similar_text($needle, $word, $similarity);
                $maxSimilarity = max($maxSimilarity, $similarity);
            }
        }
        
        return $maxSimilarity;
    }
    
    // Check for "Certificate of Indigency" title variations
    $certificateTitles = [
        'certificate of indigency',
        'indigency certificate',
        'certificate indigency',
        'katunayan ng kahirapan',
        'indigent certificate',
        'poverty certificate'
    ];
    
    $titleFound = false;
    $titleConfidence = 0;
    $foundTitleText = '';
    
    foreach ($certificateTitles as $title) {
        $similarity = calculateCertificateSimilarity($title, $ocrTextNormalized);
        if ($similarity > $titleConfidence) {
            $titleConfidence = $similarity;
        }
        
        if ($similarity >= 70) {
            $titleFound = true;
            // Try to find the actual text snippet
            $pattern = '/[^\n]*' . preg_quote(explode(' ', $title)[0], '/') . '[^\n]*/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $foundTitleText = trim($matches[0]);
            }
            break;
        }
    }
    
    $verification['certificate_title'] = $titleFound;
    $verification['confidence_scores']['certificate_title'] = $titleConfidence;
    if (!empty($foundTitleText)) {
        $verification['found_text_snippets']['certificate_title'] = $foundTitleText;
    }
    
    // Check first name with improved matching
    if (!empty($formData['first_name'])) {
        $similarity = calculateCertificateSimilarity($formData['first_name'], $ocrTextNormalized);
        $verification['confidence_scores']['first_name'] = $similarity;
        
        if ($similarity >= 80) {
            $verification['first_name'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($formData['first_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['first_name'] = $matches[0];
            }
        }
    }
    
    // Check last name with improved matching
    if (!empty($formData['last_name'])) {
        $similarity = calculateCertificateSimilarity($formData['last_name'], $ocrTextNormalized);
        $verification['confidence_scores']['last_name'] = $similarity;
        
        if ($similarity >= 80) {
            $verification['last_name'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($formData['last_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['last_name'] = $matches[0];
            }
        }
    }
    
    // Check barangay name with improved matching
    if (!empty($barangayName)) {
        $similarity = calculateCertificateSimilarity($barangayName, $ocrTextNormalized);
        $verification['confidence_scores']['barangay'] = $similarity;
        
        if ($similarity >= 70) { // Slightly lower threshold for barangay names
            $verification['barangay'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($barangayName, 0, 4), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['barangay'] = $matches[0];
            }
        }
    }
    
    // Check for "General Trias" with variations
    $generalTriasVariations = [
        'general trias',
        'gen trias',
        'general trias city',
        'municipality of general trias',
        'city of general trias'
    ];
    
    $generalTriasFound = false;
    $generalTriasConfidence = 0;
    $foundGeneralTriasText = '';
    
    foreach ($generalTriasVariations as $variation) {
        $similarity = calculateCertificateSimilarity($variation, $ocrTextNormalized);
        if ($similarity > $generalTriasConfidence) {
            $generalTriasConfidence = $similarity;
        }
        
        if ($similarity >= 70) {
            $generalTriasFound = true;
            // Try to find the actual text snippet
            $pattern = '/[^\n]*' . preg_quote(explode(' ', $variation)[0], '/') . '[^\n]*/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $foundGeneralTriasText = trim($matches[0]);
            }
            break;
        }
    }
    
    $verification['general_trias'] = $generalTriasFound;
    $verification['confidence_scores']['general_trias'] = $generalTriasConfidence;
    if (!empty($foundGeneralTriasText)) {
        $verification['found_text_snippets']['general_trias'] = $foundGeneralTriasText;
    }
    
    // Calculate overall success with improved scoring
    $requiredCertificateChecks = ['certificate_title', 'first_name', 'last_name', 'barangay', 'general_trias'];
    $passedCertificateChecks = 0;
    $totalConfidence = 0;
    
    foreach ($requiredCertificateChecks as $check) {
        if ($verification[$check]) {
            $passedCertificateChecks++;
        }
        // Add confidence score to total (default 0 if not set)
        $totalConfidence += isset($verification['confidence_scores'][$check]) ? 
            $verification['confidence_scores'][$check] : 0;
    }
    
    $averageConfidence = $totalConfidence / 5;
    
    // Success criteria: At least 4 out of 5 checks pass with decent confidence
    // OR high overall confidence even if only 3 checks pass
    $verification['overall_success'] = ($passedCertificateChecks >= 4) || 
                                     ($passedCertificateChecks >= 3 && $averageConfidence >= 75);
    
    $verification['summary'] = [
        'passed_checks' => $passedCertificateChecks,
        'total_checks' => 5,
        'average_confidence' => round($averageConfidence, 1),
        'recommendation' => $verification['overall_success'] ? 
            'Certificate validation successful' : 
            'Please ensure the certificate contains your name, barangay, "Certificate of Indigency" title, and "General Trias" clearly'
    ];
    
    // Include OCR text for debugging (truncated for security)
    $verification['ocr_text_preview'] = substr($ocrText, 0, 500) . (strlen($ocrText) > 500 ? '...' : '');
    
    // Clean up uploaded file after processing
    if (file_exists($targetPath)) {
        unlink($targetPath);
    }

    echo json_encode(['status' => 'success', 'verification' => $verification]);
    exit;
}

// --- Registration logic ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['register'])) {
    if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
        echo "<script>alert('OTP not verified. Please verify your email first.'); history.back();</script>";
        exit;
    }

    $firstname = htmlspecialchars(trim($_POST['first_name']));
    $middlename = htmlspecialchars(trim($_POST['middle_name']));
    $lastname = htmlspecialchars(trim($_POST['last_name']));
    $extension_name = htmlspecialchars(trim($_POST['extension_name']));
    $bdate = $_POST['bdate'];
    $sex = htmlspecialchars(trim($_POST['sex']));
    $barangay = filter_var($_POST['barangay_id'], FILTER_VALIDATE_INT);
    $university = filter_var($_POST['university_id'], FILTER_VALIDATE_INT);
    $year_level = filter_var($_POST['year_level_id'], FILTER_VALIDATE_INT);
    $mobile = htmlspecialchars(trim($_POST['phone']));
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if (empty($firstname) || empty($lastname) || empty($bdate) || empty($sex) || empty($barangay) || empty($university) || empty($year_level) || empty($mobile) || empty($email) || empty($pass) || empty($confirm)) {
        echo "<script>alert('Please fill in all required fields.'); history.back();</script>";
        exit;
    }

    if (strlen($pass) < 12) {
        echo "<script>alert('Password must be at least 12 characters.'); history.back();</script>";
        exit;
    }

    if ($pass !== $confirm) {
        echo "<script>alert('Passwords do not match.'); history.back();</script>";
        exit;
    }

    $hashed = password_hash($pass, PASSWORD_ARGON2ID);

    $checkEmail = pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1", [$email]);
    if (pg_num_rows($checkEmail) > 0) {
        echo "<script>alert('Email already exists. Please use a different email or login.'); window.location.href = '../../unified_login.php';</script>";
        exit;
    }

    $checkMobile = pg_query_params($connection, "SELECT 1 FROM students WHERE mobile = $1", [$mobile]);
    if (pg_num_rows($checkMobile) > 0) {
        echo "<script>alert('Mobile number already exists. Please use a different mobile number.'); history.back();</script>";
        exit;
    }

    $slotRes = pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
    $slotInfo = pg_fetch_assoc($slotRes);
    if (!$slotInfo) {
        echo "<script>alert('Registration is currently closed. Please wait for the next opening of slots.'); window.location.href = '../../unified_login.php';</script>";
        exit;
    }

    $countRes = pg_query_params($connection, "
        SELECT COUNT(*) AS total FROM students
        WHERE (status = 'under_registration' OR status = 'applicant' OR status = 'active')
        AND application_date >= $1
    ", [$slotInfo['created_at']]);
    $countRow = pg_fetch_assoc($countRes);
    $slotsUsed = intval($countRow['total']);
    $slotsLeft = intval($slotInfo['slot_count']) - $slotsUsed;

    if ($slotsLeft <= 0) {
        echo "<script>alert('Registration slots are full. Please wait for the next announcement.'); window.location.href = '../../unified_login.php';</script>";
        exit;
    }

    // Generate unique student ID with format: currentyear-yearlevel-######
    function generateUniqueStudentId($connection, $year_level_id) {
        $current_year = date('Y');

        // Get year level code from year_level_id
        $year_query = pg_query_params($connection, "SELECT code FROM year_levels WHERE year_level_id = $1", [$year_level_id]);
        $year_row = pg_fetch_assoc($year_query);

        if (!$year_row) {
            return false;
        }

        // Extract the numeric part from year level (e.g., "1ST" -> "1", "2ND" -> "2")
        $year_level_code = $year_row['code'];
        $year_level_num = preg_replace('/[^0-9]/', '', $year_level_code);
        if (empty($year_level_num)) {
            $year_level_num = '0'; // fallback for non-numeric codes like "GRAD"
        }

        $max_attempts = 100; // Prevent infinite loop
        $attempts = 0;

        do {
            // Generate 6 random digits
            $random_digits = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $unique_id = $current_year . '-' . $year_level_num . '-' . $random_digits;

            // Check if this ID already exists
            $check_query = pg_query_params($connection, "SELECT 1 FROM students WHERE unique_student_id = $1", [$unique_id]);
            $exists = pg_num_rows($check_query) > 0;

            $attempts++;

        } while ($exists && $attempts < $max_attempts);

        if ($attempts >= $max_attempts) {
            return false; // Could not generate unique ID
        }

        return $unique_id;
    }

    // Generate unique student ID
    $unique_student_id = generateUniqueStudentId($connection, $year_level);
    if (!$unique_student_id) {
        echo "<script>alert('Failed to generate unique student ID. Please try again.'); history.back();</script>";
        exit;
    }

    // Get current active slot ID for tracking
    $activeSlotQuery = pg_query_params($connection, "SELECT slot_id FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
    $activeSlot = pg_fetch_assoc($activeSlotQuery);
    $slot_id = $activeSlot ? $activeSlot['slot_id'] : null;

    $insertQuery = "INSERT INTO students (municipality_id, first_name, middle_name, last_name, extension_name, email, mobile, password, sex, status, payroll_no, qr_code, has_received, application_date, bdate, barangay_id, university_id, year_level_id, unique_student_id, slot_id)
                    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, 'under_registration', 0, 0, FALSE, NOW(), $10, $11, $12, $13, $14, $15) RETURNING student_id";

    $result = pg_query_params($connection, $insertQuery, [
        $municipality_id,
        $firstname,
        $middlename,
        $lastname,
        $extension_name,
        $email,
        $mobile,
        $hashed,
        $sex,
        $bdate,
        $barangay,
        $university,
        $year_level,
        $unique_student_id,
        $slot_id
    ]);


    if ($result) {
        $student_id_row = pg_fetch_assoc($result);
        $student_id = $student_id_row['student_id'];

        // Save enrollment form to temporary folder (not permanent until approved)
        $tempFormPath = 'assets/uploads/temp/';
        $tempFiles = glob($tempFormPath . '*');
        if (!empty($tempFiles)) {
            // Create temporary enrollment forms directory for pending students
            $tempEnrollmentDir = '../../assets/uploads/temp/enrollment_forms/';
            if (!file_exists($tempEnrollmentDir)) {
                mkdir($tempEnrollmentDir, 0777, true);
            }

            // Move the file to temporary enrollment location with student ID
            $tempFile = $tempFiles[0]; // Get the first (and should be only) file
            $filename = basename($tempFile);
            $tempEnrollmentPath = $tempEnrollmentDir . $student_id . '_' . $filename;

            if (copy($tempFile, $tempEnrollmentPath)) {
                // Save form record to database with temporary path
                $formQuery = "INSERT INTO enrollment_forms (student_id, file_path, original_filename) VALUES ($1, $2, $3)";
                pg_query_params($connection, $formQuery, [$student_id, $tempEnrollmentPath, $filename]);

                // Clean up original temp file
                unlink($tempFile);
            }
        }

        // Save letter to mayor to temporary folder (not permanent until approved)
        $letterTempFiles = glob($tempFormPath . 'letter_*');
        if (!empty($letterTempFiles)) {
            // Create temporary documents directory for pending students
            $tempDocumentsDir = '../../assets/uploads/temp/documents/';
            if (!file_exists($tempDocumentsDir)) {
                mkdir($tempDocumentsDir, 0777, true);
            }

            // Move the letter file to temporary documents location with student ID
            $letterTempFile = $letterTempFiles[0]; // Get the first (and should be only) letter file
            $letterFilename = basename($letterTempFile);
            $letterTempPath = $tempDocumentsDir . $student_id . '_letter_to_mayor_' . str_replace('letter_', '', $letterFilename);

            if (copy($letterTempFile, $letterTempPath)) {
                // Save letter record to database with temporary path
                $letterQuery = "INSERT INTO documents (student_id, type, file_path, is_valid) VALUES ($1, $2, $3, $4)";
                pg_query_params($connection, $letterQuery, [$student_id, 'letter_to_mayor', $letterTempPath, false]);

                // Clean up original temp letter file
                unlink($letterTempFile);
            }
        }

        // Save certificate of indigency to temporary folder (not permanent until approved)
        $certificateTempFiles = glob($tempFormPath . 'certificate_*');
        if (!empty($certificateTempFiles)) {
            // Create temporary documents directory for pending students (reuse from above)
            if (!isset($tempDocumentsDir)) {
                $tempDocumentsDir = '../../assets/uploads/temp/documents/';
                if (!file_exists($tempDocumentsDir)) {
                    mkdir($tempDocumentsDir, 0777, true);
                }
            }

            // Move the certificate file to temporary documents location with student ID
            $certificateTempFile = $certificateTempFiles[0]; // Get the first (and should be only) certificate file
            $certificateFilename = basename($certificateTempFile);
            $certificateTempPath = $tempDocumentsDir . $student_id . '_certificate_of_indigency_' . str_replace('certificate_', '', $certificateFilename);

            if (copy($certificateTempFile, $certificateTempPath)) {
                // Save certificate record to database with temporary path
                $certificateQuery = "INSERT INTO documents (student_id, type, file_path, is_valid) VALUES ($1, $2, $3, $4)";
                pg_query_params($connection, $certificateQuery, [$student_id, 'certificate_of_indigency', $certificateTempPath, false]);

                // Clean up original temp certificate file
                unlink($certificateTempFile);
            }
        }

        $semester = $slotInfo['semester'];
        $academic_year = $slotInfo['academic_year'];
        $applicationQuery = "INSERT INTO applications (student_id, semester, academic_year) VALUES ($1, $2, $3)";
        pg_query_params($connection, $applicationQuery, [$student_id, $semester, $academic_year]);

        // Process grades data if provided
        if (isset($_POST['grading_system']) && !empty($_POST['grading_system'])) {
            $grading_system = filter_var($_POST['grading_system'], FILTER_SANITIZE_STRING);
            
            // Collect all grades data
            $grades_data = [];
            $i = 1;
            while (isset($_POST["subject_$i"]) && isset($_POST["grade_$i"]) && isset($_POST["units_$i"])) {
                $subject = trim($_POST["subject_$i"]);
                $grade = trim($_POST["grade_$i"]);
                $units = filter_var($_POST["units_$i"], FILTER_VALIDATE_INT);
                
                if (!empty($subject) && !empty($grade) && $units > 0) {
                    $grades_data[] = [
                        'subject' => $subject,
                        'grade' => $grade,
                        'units' => $units
                    ];
                }
                $i++;
            }
            
            // Save grades if any were provided
            if (!empty($grades_data)) {
                try {
                    // Insert individual grades
                    $grade_insert_sql = "INSERT INTO student_grades (
                        student_id, subject_name, grade_value, grade_system, units, 
                        semester, academic_year, source, verification_status, created_at
                    ) VALUES ($1, $2, $3, $4, $5, $6, $7, 'registration', 'pending', NOW())";
                    
                    $total_units = 0;
                    $total_grade_points = 0;
                    
                    foreach ($grades_data as $grade_data) {
                        // Convert grade to GPA for calculation
                        $grade_point = convertGradeToGPA($grade_data['grade'], $grading_system);
                        
                        // Insert grade record
                        pg_query_params($connection, $grade_insert_sql, [
                            $student_id,
                            $grade_data['subject'],
                            $grade_data['grade'],
                            $grading_system,
                            $grade_data['units'],
                            $semester,
                            $academic_year
                        ]);
                        
                        // Calculate totals
                        $total_units += $grade_data['units'];
                        $total_grade_points += ($grade_point * $grade_data['units']);
                    }
                    
                    // Calculate and save GPA summary
                    $gpa = $total_units > 0 ? $total_grade_points / $total_units : 0;
                    
                    $gpa_insert_sql = "INSERT INTO student_gpa_summary (
                        student_id, semester, academic_year, total_units, gpa, 
                        grading_system, source, created_at
                    ) VALUES ($1, $2, $3, $4, $5, $6, 'registration', NOW())";
                    
                    pg_query_params($connection, $gpa_insert_sql, [
                        $student_id,
                        $semester,
                        $academic_year,
                        $total_units,
                        round($gpa, 2),
                        $grading_system
                    ]);
                    
                } catch (Exception $e) {
                    error_log("Grade processing error during registration: " . $e->getMessage());
                    // Continue with registration even if grades fail - they can be added later
                }
            }
        }
        
        // Process grade document if uploaded
        $gradeTempFiles = glob($tempFormPath . 'grade_*');
        if (!empty($gradeTempFiles)) {
            // Create temporary grade documents directory
            $tempGradeDir = '../../assets/uploads/temp/grade_documents/';
            if (!file_exists($tempGradeDir)) {
                mkdir($tempGradeDir, 0777, true);
            }

            // Move the grade document to temporary location with student ID
            $gradeTempFile = $gradeTempFiles[0];
            $gradeFilename = basename($gradeTempFile);
            $gradeTempPath = $tempGradeDir . $student_id . '_grade_document_' . str_replace('grade_', '', $gradeFilename);

            if (copy($gradeTempFile, $gradeTempPath)) {
                // Save grade document record to database
                $gradeDocQuery = "INSERT INTO grade_documents (
                    student_id, file_name, file_path, file_type, upload_source, 
                    processing_status, verification_status, created_at
                ) VALUES ($1, $2, $3, $4, 'registration', 'pending', 'pending', NOW())";
                
                $file_type = mime_content_type($gradeTempPath);
                pg_query_params($connection, $gradeDocQuery, [
                    $student_id, 
                    $gradeFilename, 
                    $gradeTempPath, 
                    $file_type
                ]);

                // Clean up original temp grade file
                unlink($gradeTempFile);
            }
        }

        unset($_SESSION['otp_verified']);

        echo "<script>alert('Registration submitted successfully! Your application is under review. You will receive an email notification once approved.'); window.location.href = '../../unified_login.php';</script>";
        exit;
    } else {
        echo "<script>alert('Registration failed due to a database error. Please try again.');</script>";

    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>EducAid – Register</title>
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="../../assets/css/registration.css" />
    <style>
        .step-panel.d-none { display: none !important; }
        .step-indicator { display: flex; justify-content: center; margin-bottom: 20px; }
        .step {
            display: flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 50%;
            background-color: #e0e0e0; color: #777; font-weight: bold;
            margin: 0 5px; transition: background-color 0.3s, color 0.3s;
        }
        .step.active { background-color: #007bff; color: white; }
        .notifier {
            position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
            padding: 15px 30px; background-color: #f8d7da; color: #721c24;
            border-radius: 5px; display: none; box-shadow: 0 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        /* Spinning animation for loading icons */
        .spin {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        /* Grade entry styles */
        .grade-row {
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 10px !important;
        }
        .grade-row:last-child {
            border-bottom: none;
        }
        .notifier.success { background-color: #d4edda; color: #155724; }
        .verified-email { background-color: #e9f7e9; color: #28a745; }
        
        /* ADD THIS: Enhanced required asterisk styling */
        .form-label .text-danger {
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .form-label .text-muted {
            font-size: 0.85em;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="register-card mx-auto p-4 rounded shadow-sm bg-white" style="max-width: 600px;">
            <h4 class="mb-4 text-center text-primary">
                <i class="bi bi-person-plus-fill me-2"></i>Register for EducAid
            </h4>
            <div class="step-indicator mb-4 text-center">
                <span class="step active" id="step-indicator-1">1</span>
                <span class="step" id="step-indicator-2">2</span>
                <span class="step" id="step-indicator-3">3</span>
                <span class="step" id="step-indicator-4">4</span>
                <span class="step" id="step-indicator-5">5</span>
                <span class="step" id="step-indicator-6">6</span>
                <span class="step" id="step-indicator-7">7</span>
                <span class="step" id="step-indicator-8">8</span>
                <span class="step" id="step-indicator-9">9</span>
            </div>
            <form id="multiStepForm" method="POST" autocomplete="off">
                <!-- Step 1: Personal Information -->
                <div class="step-panel" id="step-1">
                    <div class="mb-3">
                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="first_name" required />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Middle Name <span class="text-muted">(Optional)</span></label>
                        <input type="text" class="form-control" name="middle_name" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="last_name" required />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Extension Name <span class="text-muted">(Optional)</span></label>
                        <select class="form-control" name="extension_name">
                            <option value="">None</option>
                            <option value="Jr.">Jr.</option>
                            <option value="Sr.">Sr.</option>
                            <option value="I">I</option>
                            <option value="II">II</option>
                            <option value="III">III</option>
                            <option value="IV">IV</option>
                            <option value="V">V</option>
                        </select>
                        <small class="form-text text-muted">Select suffix if applicable (Jr., Sr., I, II, etc.)</small>
                    </div>
                    <button type="button" class="btn btn-primary w-100" onclick="nextStep()">Next</button>
                </div>
                <!-- Step 2: Birthdate and Sex -->
                <div class="step-panel d-none" id="step-2">
                    <div class="mb-3">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" class="form-control" name="bdate" required />
                    </div>
                    <div class="mb-3">
                        <label class="form-label d-block">Gender</label>
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input" name="sex" value="Male" required />
                            <label class="form-check-label">Male</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input" name="sex" value="Female" required />
                            <label class="form-check-label">Female</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Barangay</label>
                        <select name="barangay_id" class="form-select" required>
                            <option value="" disabled selected>Select your barangay</option>
                            <?php
                            $res = pg_query_params($connection, "SELECT barangay_id, name FROM barangays WHERE municipality_id = $1 ORDER BY name ASC", [$municipality_id]);
                            while ($row = pg_fetch_assoc($res)) {
                                echo "<option value='{$row['barangay_id']}'>" . htmlspecialchars($row['name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" onclick="nextStep()">Next</button>
                </div>
                <!-- Step 3: University and Year Level -->
                <div class="step-panel d-none" id="step-3">
                      <div class="mb-3">
                          <label class="form-label">University/College</label>
                          <select name="university_id" class="form-select" required>
                              <option value="" disabled selected>Select your university/college</option>
                              <?php
                              $res = pg_query($connection, "SELECT university_id, name FROM universities ORDER BY name ASC");
                              while ($row = pg_fetch_assoc($res)) {
                                  echo "<option value='{$row['university_id']}'>" . htmlspecialchars($row['name']) . "</option>";
                              }
                              ?>
                          </select>
                      </div>
                      <div class="mb-3">
                          <label class="form-label">Year Level</label>
                          <select name="year_level_id" class="form-select" required>
                              <option value="" disabled selected>Select your year level</option>
                              <?php
                              $res = pg_query($connection, "SELECT year_level_id, name FROM year_levels ORDER BY sort_order ASC");
                              while ($row = pg_fetch_assoc($res)) {
                                  echo "<option value='{$row['year_level_id']}'>" . htmlspecialchars($row['name']) . "</option>";
                              }
                              ?>
                          </select>
                      </div>
                      <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                      <button type="button" class="btn btn-primary w-100" onclick="nextStep()">Next</button>
                </div>
                <!-- Step 4: Document Upload and OCR Verification -->
                <div class="step-panel d-none" id="step-4">
                    <div class="mb-3">
                        <label class="form-label">Upload Enrollment Assessment Form</label>
                        <small class="form-text text-muted d-block">
                            Please upload a clear photo or PDF of your Enrollment Assessment Form<br>
                            <strong>Required filename format:</strong> Lastname_Firstname_EAF (e.g., Santos_Juan_EAF.jpg)
                        </small>
                        <input type="file" class="form-control" name="enrollment_form" id="enrollmentForm" accept="image/*,.pdf" required />
                        <div id="filenameError" class="text-danger mt-1" style="display: none;">
                            <small><i class="bi bi-exclamation-triangle me-1"></i>Filename must follow format: Lastname_Firstname_EAF</small>
                        </div>
                    </div>
                    <div id="uploadPreview" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">Preview:</label>
                            <div id="previewContainer" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                                <img id="previewImage" class="img-fluid" style="max-width: 100%; display: none;" />
                                <div id="pdfPreview" class="text-center p-3" style="display: none;">
                                    <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                    <p>PDF File Selected</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="ocrSection" class="d-none">
                        <div class="mb-3">
                            <button type="button" class="btn btn-info w-100" id="processOcrBtn" disabled>
                                <i class="bi bi-search me-2"></i>Process Document
                            </button>
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle me-1"></i>Upload a file with correct filename format to enable processing
                            </small>
                        </div>
                        <div id="ocrResults" class="d-none">
                            <div class="mb-3">
                                <label class="form-label">Verification Results:</label>
                                <div class="verification-checklist">
                                    <div class="form-check" id="check-firstname">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>First Name Match</span>
                                    </div>
                                    <div class="form-check" id="check-middlename">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Middle Name Match</span>
                                    </div>
                                    <div class="form-check" id="check-lastname">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Last Name Match</span>
                                    </div>
                                    <div class="form-check" id="check-yearlevel">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Year Level Match</span>
                                    </div>
                                    <div class="form-check" id="check-university">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>University Match</span>
                                    </div>
                                    <div class="form-check" id="check-document">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Official Document Keywords</span>
                                    </div>
                                </div>
                            </div>
                            <div id="ocrFeedback" class="alert alert-warning mt-3" style="display: none;">
                                <strong>Verification Failed:</strong> Please ensure your document is clear and contains all required information.
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep4Btn" disabled onclick="nextStep()">Next</button>
                </div>
                
                <!-- Step 5: Academic Grades Entry and Document Upload -->
                <div class="step-panel d-none" id="step-5">
                    <div class="mb-4">
                        <h5 class="text-primary">
                            <i class="bi bi-file-earmark-spreadsheet me-2"></i>
                            Academic Grades Information
                        </h5>
                        <p class="text-muted mb-3">
                            Please enter your academic grades manually and upload supporting documents for verification.
                            <br><strong>Minimum Requirements:</strong> 75% average or 3.00 GPA (1.0-5.0 scale)
                        </p>
                    </div>

                    <!-- Grading System Selection -->
                    <div class="mb-4">
                        <label class="form-label">Grading System Used by Your School</label>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="grading_system" id="percentage" value="percentage" checked>
                                    <label class="form-check-label" for="percentage">
                                        Percentage Scale (0% - 100%)
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="grading_system" id="gpa" value="gpa">
                                    <label class="form-check-label" for="gpa">
                                        1.0 - 5.0 GPA Scale (1.0 = Highest)
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="grading_system" id="dlsu_gpa" value="dlsu_gpa">
                                    <label class="form-check-label" for="dlsu_gpa">
                                        4.0 GPA Scale (4.0 = 100%, 0.0 = Failed)
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="grading_system" id="letter" value="letter">
                                    <label class="form-check-label" for="letter">
                                        Letter Grades (A, B, C, D, F)
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Manual Grade Entry Section -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6>Enter Your Grades</h6>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addGradeRow()">
                                <i class="bi bi-plus-circle me-1"></i>Add Subject
                            </button>
                        </div>
                        <div id="gradesContainer">
                            <div class="grade-rows">
                                <!-- Initial grade row -->
                                <div class="row mb-2 grade-row">
                                    <div class="col-md-5">
                                        <input type="text" class="form-control" name="subject_1" placeholder="Subject Name" required>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control grade-input" name="grade_1" placeholder="Grade" required>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" class="form-control units-input" name="units_1" placeholder="Units" min="1" max="6" required>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-danger btn-sm" onclick="removeGradeRow(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                Add all subjects from your most recent semester/year. Minimum 3 subjects required.
                            </small>
                        </div>
                    </div>

                    <!-- Grade Summary -->
                    <div class="mb-4" id="gradeSummary" style="display: none;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">Grade Summary</h6>
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Total Subjects:</small><br>
                                        <strong id="totalSubjects">0</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Average:</small><br>
                                        <strong id="averageGrade">0.00</strong>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <span id="statusBadge" class="badge"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Document Upload Section -->
                    <div class="mb-4">
                        <h6>Upload Supporting Document</h6>
                        <p class="text-muted small">
                            Upload your official transcript, report card, or grade sheet as proof of the grades entered above.
                        </p>
                        <div class="upload-area border rounded p-3" style="border-style: dashed !important;">
                            <input type="file" class="form-control" id="gradeDocument" name="gradeDocument" accept=".pdf,.jpg,.jpeg,.png" 
                                   onchange="handleGradeDocumentUpload(this)">
                            <div class="text-center mt-2">
                                <i class="bi bi-cloud-upload text-muted fs-3"></i>
                                <div class="text-muted">Choose file or drag and drop</div>
                                <small class="text-muted">PDF, JPG, PNG up to 10MB</small>
                            </div>
                        </div>
                        
                        <!-- Document Preview -->
                        <div id="gradeDocumentPreview" class="mt-3" style="display: none;">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <i class="bi bi-file-check me-2"></i>
                                        Document Uploaded
                                    </h6>
                                    <p class="card-text mb-2" id="documentFileName"></p>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="previewDocument()">
                                            <i class="bi bi-eye me-1"></i>Preview
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="extractTextForReference()">
                                            <i class="bi bi-file-text me-1"></i>Extract Text
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Extracted Text Preview -->
                        <div id="extractedTextPreview" class="mt-3" style="display: none;">
                            <div class="card border-info">
                                <div class="card-header bg-info bg-opacity-10">
                                    <h6 class="mb-0">
                                        <i class="bi bi-file-earmark-text me-2"></i>
                                        Extracted Text (For Reference)
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-info py-2">
                                        <small>
                                            <i class="bi bi-info-circle me-1"></i>
                                            This text is extracted for your reference. Please ensure your manual entries above match your document.
                                        </small>
                                    </div>
                                    <div id="extractedTextContent" style="max-height: 200px; overflow-y: auto; font-size: 0.9em; font-family: monospace; background: #f8f9fa; padding: 10px; border-radius: 4px;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep5Btn" disabled onclick="nextStep()">Next</button>
                </div>
                
                <!-- Step 6: Letter to Mayor Upload and OCR Verification -->
                <div class="step-panel d-none" id="step-6">
                    <div class="mb-3">
                        <label class="form-label">Upload Letter to Mayor</label>
                        <small class="form-text text-muted d-block">
                            Please upload a clear photo or PDF of your Letter to Mayor<br>
                            <strong>Required content:</strong> Your name, barangay, and "Office of the Mayor" header
                        </small>
                        <input type="file" class="form-control" name="letter_to_mayor" id="letterToMayorForm" accept="image/*,.pdf" required />
                        <div id="letterFilenameError" class="text-danger mt-1" style="display: none;">
                            <small><i class="bi bi-exclamation-triangle me-1"></i>Please upload a valid letter to mayor document</small>
                        </div>
                    </div>
                    <div id="letterUploadPreview" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">Preview:</label>
                            <div id="letterPreviewContainer" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                                <img id="letterPreviewImage" class="img-fluid" style="max-width: 100%; display: none;" />
                                <div id="letterPdfPreview" class="text-center p-3" style="display: none;">
                                    <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                    <p>PDF File Selected</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="letterOcrSection" class="d-none">
                        <div class="mb-3">
                            <button type="button" class="btn btn-info w-100" id="processLetterOcrBtn" disabled>
                                <i class="bi bi-search me-2"></i>Verify Letter Content
                            </button>
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle me-1"></i>Upload a file to enable verification
                            </small>
                        </div>
                        <div id="letterOcrResults" class="d-none">
                            <div class="mb-3">
                                <label class="form-label">Verification Results:</label>
                                <div class="verification-checklist">
                                    <div class="form-check" id="check-letter-firstname">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>First Name Found</span>
                                    </div>
                                    <div class="form-check" id="check-letter-lastname">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Last Name Found</span>
                                    </div>
                                    <div class="form-check" id="check-letter-barangay">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Barangay Match</span>
                                    </div>
                                    <div class="form-check" id="check-letter-header">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Office of the Mayor Header</span>
                                    </div>
                                </div>
                            </div>
                            <div id="letterOcrFeedback" class="alert alert-warning mt-3" style="display: none;">
                                <strong>Verification Failed:</strong> Please ensure your letter contains your name, barangay, and "Office of the Mayor" header.
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep5Btn" disabled onclick="nextStep()">Next</button>
                </div>
                
                <!-- Step 7: Certificate of Indigency Upload and OCR Verification -->
                <div class="step-panel d-none" id="step-7">
                    <div class="mb-3">
                        <label class="form-label">Upload Certificate of Indigency</label>
                        <small class="form-text text-muted d-block mb-2">
                            Please upload a clear photo or PDF of your Certificate of Indigency<br>
                            <strong>Required elements:</strong> Certificate title, your name, barangay, and "General Trias"
                        </small>
                        <input type="file" class="form-control" name="certificate_of_indigency" id="certificateForm" accept="image/*,.pdf" required />
                        <div class="invalid-feedback" id="certificateError">
                            <small><i class="bi bi-exclamation-triangle me-1"></i>Please upload a valid certificate of indigency document</small>
                        </div>
                    </div>
                    <div id="certificateUploadPreview" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">Preview:</label>
                            <div id="certificatePreviewContainer" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                                <img id="certificatePreviewImage" class="img-fluid" style="max-width: 100%; display: none;" />
                                <div id="certificatePdfPreview" class="text-center p-3" style="display: none;">
                                    <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                    <p>PDF File Selected</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="certificateOcrSection" class="d-none">
                        <div class="mb-3">
                            <button type="button" class="btn btn-info w-100" id="processCertificateOcrBtn" disabled>
                                <i class="bi bi-search me-2"></i>Verify Certificate Content
                            </button>
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle me-1"></i>Upload a file to enable verification
                            </small>
                        </div>
                        <div id="certificateOcrResults" class="d-none">
                            <div class="mb-3">
                                <label class="form-label">Verification Results:</label>
                                <div class="verification-checklist">
                                    <div class="form-check" id="check-certificate-title">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Certificate of Indigency Title</span>
                                    </div>
                                    <div class="form-check" id="check-certificate-firstname">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>First Name Found</span>
                                    </div>
                                    <div class="form-check" id="check-certificate-lastname">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Last Name Found</span>
                                    </div>
                                    <div class="form-check" id="check-certificate-barangay">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Barangay Match</span>
                                    </div>
                                    <div class="form-check" id="check-certificate-city">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>General Trias Found</span>
                                    </div>
                                </div>
                            </div>
                            <div id="certificateOcrFeedback" class="alert alert-warning mt-3" style="display: none;">
                                <strong>Verification Failed:</strong> Please ensure your certificate contains your name, barangay, "Certificate of Indigency" title, and "General Trias".
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep6Btn" disabled onclick="nextStep()">Next</button>
                </div>
                
                <!-- Step 8: OTP Verification -->
                <div class="step-panel d-none" id="step-8">
                      <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="emailInput" required />
                        <span id="emailStatus" class="text-success d-none">Verified</span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" name="phone" maxlength="11" pattern="09[0-9]{9}" placeholder="e.g., 09123456789" required />
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-info" id="sendOtpBtn">Send OTP (Email)</button>
                    </div>
                    <div id="otpSection" class="d-none mt-3">
                        <div class="mb-3">
                            <label class="form-label">Enter OTP</label>
                            <input type="text" class="form-control" name="otp" id="otp" required />
                        </div>
                        <button type="button" class="btn btn-success w-100 mb-2" id="verifyOtpBtn">Verify OTP</button>
                        <div id="timer" class="text-danger mt-2"></div>
                        <button type="button" class="btn btn-warning w-100 mt-3" id="resendOtpBtn" style="display:none;" disabled>Resend OTP</button>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep8Btn" onclick="nextStep()">Next</button>
                </div>
                <!-- Step 9: Password and Confirmation -->
                <div class="step-panel d-none" id="step-9">
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="password" id="password" minlength="12" required />
                    </div>
                    <div class="form-text">
                        Must be at least 12 characters long with letters, numbers, and symbols.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" name="confirm_password" id="confirmPassword" minlength="12" required />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password Strength</label>
                        <div class="progress">
                            <div id="strengthBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
                        </div>
                        <small id="strengthText" class="text-muted"></small>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="agree_terms" id="agreeTerms" required />
                        <label class="form-check-label" for="agreeTerms">
                            I agree to the 
                            <a href="#" class="text-primary" data-bs-toggle="modal" data-bs-target="#termsModal">
                                Terms and Conditions
                            </a>
                            <span class="text-danger">*</span>
                        </label>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="submit" name="register" class="btn btn-success w-100">Submit</button>
                </div>
            </form>
        </div>
    </div>
    
    <?php
    // Set custom login URL for this page
    $footer_login_url = '../../unified_login.php';
    
    // Include the modular footer
    include_once '../../includes/footer.php';
    ?>

    <div id="notifier" class="notifier"></div>
    <!-- Make sure this is included BEFORE your custom JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- OR if you have local Bootstrap files -->
<script src="../../assets/js/bootstrap.bundle.min.js"></script>

<!-- Your registration JavaScript should come AFTER Bootstrap -->
<script src="../../assets/js/student/user_registration.js"></script>

<script>
    // Letter to Mayor Upload Handling
    document.getElementById('letterToMayorForm').addEventListener('change', function(e) {
        const file = e.target.files[0];
        const previewContainer = document.getElementById('letterUploadPreview');
        const previewImage = document.getElementById('letterPreviewImage');
        const pdfPreview = document.getElementById('letterPdfPreview');
        const ocrSection = document.getElementById('letterOcrSection');
        const processBtn = document.getElementById('processLetterOcrBtn');

        if (file) {
            // Show preview section
            previewContainer.classList.remove('d-none');
            ocrSection.classList.remove('d-none');

            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    previewImage.style.display = 'block';
                    pdfPreview.style.display = 'none';
                };
                reader.readAsDataURL(file);
            } else if (file.type === 'application/pdf') {
                previewImage.style.display = 'none';
                pdfPreview.style.display = 'block';
            }

            // Enable OCR processing button
            processBtn.disabled = false;
            processBtn.textContent = 'Verify Letter Content';
        } else {
            previewContainer.classList.add('d-none');
            ocrSection.classList.add('d-none');
            processBtn.disabled = true;
        }
    });

    // Letter to Mayor OCR Processing
    document.getElementById('processLetterOcrBtn').addEventListener('click', function() {
        const formData = new FormData();
        const fileInput = document.getElementById('letterToMayorForm');
        const file = fileInput.files[0];

        if (!file) {
            alert('Please select a letter file first.');
            return;
        }

        // Add form data
        formData.append('letter_to_mayor', file);
        formData.append('processLetterOcr', '1');
        formData.append('first_name', document.querySelector('input[name="first_name"]').value);
        formData.append('last_name', document.querySelector('input[name="last_name"]').value);
        formData.append('barangay_id', document.querySelector('select[name="barangay_id"]').value);

        // Show processing state
        this.disabled = true;
        this.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                displayLetterVerificationResults(data.verification);
            } else {
                // Enhanced error display for PDFs and suggestions
                let errorMessage = data.message;
                if (data.suggestions && data.suggestions.length > 0) {
                    errorMessage += '\n\nSuggestions:\n' + data.suggestions.join('\n');
                }
                alert(errorMessage);
                
                // Also show suggestions in the feedback area
                if (data.suggestions) {
                    const resultsDiv = document.getElementById('letterOcrResults');
                    const feedbackDiv = document.getElementById('letterOcrFeedback');
                    
                    resultsDiv.classList.remove('d-none');
                    feedbackDiv.style.display = 'block';
                    feedbackDiv.className = 'alert alert-warning mt-3';
                    
                    let suggestionHTML = '<strong>' + data.message + '</strong><br><br>';
                    suggestionHTML += '<strong>Please try:</strong><ul>';
                    data.suggestions.forEach(suggestion => {
                        suggestionHTML += '<li>' + suggestion + '</li>';
                    });
                    suggestionHTML += '</ul>';
                    
                    feedbackDiv.innerHTML = suggestionHTML;
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during processing.');
        })
        .finally(() => {
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-search me-2"></i>Verify Letter Content';
        });
    });

    function displayLetterVerificationResults(verification) {
        const resultsDiv = document.getElementById('letterOcrResults');
        const feedbackDiv = document.getElementById('letterOcrFeedback');
        const nextButton = document.getElementById('nextStep5Btn');

        // Update verification checklist with confidence scores
        updateVerificationCheckWithDetails('check-letter-firstname', verification.first_name, 
            verification.confidence_scores?.first_name, verification.found_text_snippets?.first_name);
        updateVerificationCheckWithDetails('check-letter-lastname', verification.last_name, 
            verification.confidence_scores?.last_name, verification.found_text_snippets?.last_name);
        updateVerificationCheckWithDetails('check-letter-barangay', verification.barangay, 
            verification.confidence_scores?.barangay, verification.found_text_snippets?.barangay);
        updateVerificationCheckWithDetails('check-letter-header', verification.mayor_header, 
            verification.confidence_scores?.mayor_header, verification.found_text_snippets?.mayor_header);

        // Show results
        resultsDiv.classList.remove('d-none');

        // Update feedback with detailed information
        if (verification.overall_success) {
            feedbackDiv.style.display = 'none';
            nextButton.disabled = false;
            nextButton.classList.add('btn-success');
            nextButton.classList.remove('btn-primary');
        } else {
            // Show detailed feedback
            let feedbackMessage = `<strong>Verification Result:</strong> ${verification.summary?.recommendation || 'Some checks failed'}<br>`;
            feedbackMessage += `<small>Passed ${verification.summary?.passed_checks || 0} of ${verification.summary?.total_checks || 4} checks`;
            if (verification.summary?.average_confidence) {
                feedbackMessage += ` (Average confidence: ${verification.summary.average_confidence}%)`;
            }
            feedbackMessage += `</small>`;
            
            feedbackDiv.innerHTML = feedbackMessage;
            feedbackDiv.style.display = 'block';
            nextButton.disabled = true;
            nextButton.classList.remove('btn-success');
            nextButton.classList.add('btn-primary');
        }
    }

    function updateVerificationCheck(elementId, passed) {
        const element = document.getElementById(elementId);
        const icon = element.querySelector('i');
        
        if (passed) {
            icon.className = 'bi bi-check-circle text-success me-2';
            element.classList.add('text-success');
            element.classList.remove('text-danger');
        } else {
            icon.className = 'bi bi-x-circle text-danger me-2';
            element.classList.add('text-danger');
            element.classList.remove('text-success');
        }
    }
    
    // Enhanced version with confidence scores and found text
    function updateVerificationCheckWithDetails(elementId, passed, confidence, foundText) {
        const element = document.getElementById(elementId);
        const icon = element.querySelector('i');
        const textSpan = element.querySelector('span');
        
        if (passed) {
            icon.className = 'bi bi-check-circle text-success me-2';
            element.classList.add('text-success');
            element.classList.remove('text-danger');
            
            // Add confidence score and found text if available
            let originalText = textSpan.textContent.split(' (')[0]; // Remove any existing details
            let details = '';
            if (confidence !== undefined) {
                details += ` (${Math.round(confidence)}% match`;
                if (foundText) {
                    details += `, found: "${foundText}"`;
                }
                details += ')';
            }
            textSpan.innerHTML = originalText + '<small class="text-muted">' + details + '</small>';
        } else {
            icon.className = 'bi bi-x-circle text-danger me-2';
            element.classList.add('text-danger');
            element.classList.remove('text-success');
            
            // Show confidence if available for failed checks
            let originalText = textSpan.textContent.split(' (')[0];
            let details = '';
            if (confidence !== undefined && confidence > 0) {
                details += ` <small class="text-muted">(${Math.round(confidence)}% match - needs 70%+)</small>`;
            }
            textSpan.innerHTML = originalText + details;
        }
    }

    // Certificate of Indigency Upload Handling
    document.getElementById('certificateForm').addEventListener('change', function(e) {
        const file = e.target.files[0];
        const previewContainer = document.getElementById('certificateUploadPreview');
        const previewImage = document.getElementById('certificatePreviewImage');
        const pdfPreview = document.getElementById('certificatePdfPreview');
        const ocrSection = document.getElementById('certificateOcrSection');
        const processBtn = document.getElementById('processCertificateOcrBtn');

        if (file) {
            // Show preview section
            previewContainer.classList.remove('d-none');
            ocrSection.classList.remove('d-none');

            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    previewImage.style.display = 'block';
                    pdfPreview.style.display = 'none';
                };
                reader.readAsDataURL(file);
            } else if (file.type === 'application/pdf') {
                previewImage.style.display = 'none';
                pdfPreview.style.display = 'block';
            }

            // Enable OCR processing button
            processBtn.disabled = false;
            processBtn.textContent = 'Verify Certificate Content';
        } else {
            previewContainer.classList.add('d-none');
            ocrSection.classList.add('d-none');
            processBtn.disabled = true;
        }
    });

    // Certificate OCR Processing
    document.getElementById('processCertificateOcrBtn').addEventListener('click', function() {
        const formData = new FormData();
        const fileInput = document.getElementById('certificateForm');
        const file = fileInput.files[0];

        if (!file) {
            alert('Please select a certificate file first.');
            return;
        }

        // Add form data
        formData.append('certificate_of_indigency', file);
        formData.append('processCertificateOcr', '1');
        formData.append('first_name', document.querySelector('input[name="first_name"]').value);
        formData.append('last_name', document.querySelector('input[name="last_name"]').value);
        formData.append('barangay_id', document.querySelector('select[name="barangay_id"]').value);

        // Show processing state
        this.disabled = true;
        this.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                displayCertificateVerificationResults(data.verification);
            } else {
                // Enhanced error display for PDFs and suggestions
                let errorMessage = data.message;
                if (data.suggestions && data.suggestions.length > 0) {
                    errorMessage += '\n\nSuggestions:\n' + data.suggestions.join('\n');
                }
                alert(errorMessage);
                
                // Also show suggestions in the feedback area
                if (data.suggestions) {
                    const resultsDiv = document.getElementById('certificateOcrResults');
                    const feedbackDiv = document.getElementById('certificateOcrFeedback');
                    
                    resultsDiv.classList.remove('d-none');
                    feedbackDiv.style.display = 'block';
                    feedbackDiv.className = 'alert alert-warning mt-3';
                    
                    let suggestionHTML = '<strong>' + data.message + '</strong><br><br>';
                    suggestionHTML += '<strong>Please try:</strong><ul>';
                    data.suggestions.forEach(suggestion => {
                        suggestionHTML += '<li>' + suggestion + '</li>';
                    });
                    suggestionHTML += '</ul>';
                    
                    feedbackDiv.innerHTML = suggestionHTML;
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during processing.');
        })
        .finally(() => {
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-search me-2"></i>Verify Certificate Content';
        });
    });

    function displayCertificateVerificationResults(verification) {
        const resultsDiv = document.getElementById('certificateOcrResults');
        const feedbackDiv = document.getElementById('certificateOcrFeedback');
        const nextButton = document.getElementById('nextStep6Btn');

        // Update verification checklist with confidence scores
        updateVerificationCheckWithDetails('check-certificate-title', verification.certificate_title, 
            verification.confidence_scores?.certificate_title, verification.found_text_snippets?.certificate_title);
        updateVerificationCheckWithDetails('check-certificate-firstname', verification.first_name, 
            verification.confidence_scores?.first_name, verification.found_text_snippets?.first_name);
        updateVerificationCheckWithDetails('check-certificate-lastname', verification.last_name, 
            verification.confidence_scores?.last_name, verification.found_text_snippets?.last_name);
        updateVerificationCheckWithDetails('check-certificate-barangay', verification.barangay, 
            verification.confidence_scores?.barangay, verification.found_text_snippets?.barangay);
        updateVerificationCheckWithDetails('check-certificate-city', verification.general_trias, 
            verification.confidence_scores?.general_trias, verification.found_text_snippets?.general_trias);

        // Show results
        resultsDiv.classList.remove('d-none');

        // Update feedback with detailed information
        if (verification.overall_success) {
            feedbackDiv.style.display = 'none';
            nextButton.disabled = false;
            nextButton.classList.add('btn-success');
            nextButton.classList.remove('btn-primary');
        } else {
            // Show detailed feedback
            let feedbackMessage = `<strong>Verification Result:</strong> ${verification.summary?.recommendation || 'Some checks failed'}<br>`;
            feedbackMessage += `<small>Passed ${verification.summary?.passed_checks || 0} of ${verification.summary?.total_checks || 5} checks`;
            if (verification.summary?.average_confidence) {
                feedbackMessage += ` (Average confidence: ${verification.summary.average_confidence}%)`;
            }
            feedbackMessage += `</small>`;
            
            feedbackDiv.innerHTML = feedbackMessage;
            feedbackDiv.style.display = 'block';
            nextButton.disabled = true;
            nextButton.classList.remove('btn-success');
            nextButton.classList.add('btn-primary');
        }
    }
    
    // Grades Management JavaScript Functions
    function addGradeRow() {
        const container = document.getElementById('gradesContainer');
        const gradeRowsContainer = container.querySelector('.grade-rows');
        const rowCount = gradeRowsContainer.children.length + 1;
        
        const newRow = document.createElement('div');
        newRow.className = 'row mb-2 grade-row';
        newRow.innerHTML = `
            <div class="col-md-5">
                <input type="text" class="form-control" name="subject_${rowCount}" placeholder="Subject Name" required>
            </div>
            <div class="col-md-3">
                <input type="text" class="form-control grade-input" name="grade_${rowCount}" placeholder="Grade" required>
            </div>
            <div class="col-md-2">
                <input type="number" class="form-control units-input" name="units_${rowCount}" placeholder="Units" min="1" max="6" required>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-danger btn-sm" onclick="removeGradeRow(this)">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        
        gradeRowsContainer.appendChild(newRow);
        updateGradeInputs();
        calculateGradeSummary();
    }
    
    function removeGradeRow(button) {
        const row = button.closest('.grade-row');
        row.remove();
        calculateGradeSummary();
    }
    
    function updateGradeInputs() {
        const gradingSystem = document.querySelector('input[name="grading_system"]:checked')?.value || 'percentage';
        const gradeInputs = document.querySelectorAll('.grade-input');
        
        gradeInputs.forEach(input => {
            switch(gradingSystem) {
                case 'gpa':
                    input.placeholder = 'e.g., 1.50';
                    input.type = 'number';
                    input.step = '0.01';
                    input.min = '1.0';
                    input.max = '5.0';
                    break;
                case 'dlsu_gpa':
                    input.placeholder = 'e.g., 3.75';
                    input.type = 'number';
                    input.step = '0.01';
                    input.min = '0.0';
                    input.max = '4.0';
                    break;
                case 'letter':
                    input.placeholder = 'e.g., A+';
                    input.type = 'text';
                    input.removeAttribute('step');
                    input.removeAttribute('min');
                    input.removeAttribute('max');
                    break;
                default: // percentage
                    input.placeholder = 'e.g., 95';
                    input.type = 'number';
                    input.step = '0.1';
                    input.min = '0';
                    input.max = '100';
                    break;
            }
        });
        
        calculateGradeSummary();
    }
    
    function calculateGradeSummary() {
        const gradingSystem = document.querySelector('input[name="grading_system"]:checked')?.value || 'percentage';
        const gradeInputs = document.querySelectorAll('.grade-input');
        const unitsInputs = document.querySelectorAll('.units-input');
        const summaryDiv = document.getElementById('gradeSummary');
        
        let totalGradePoints = 0;
        let totalUnits = 0;
        let validGrades = 0;
        
        gradeInputs.forEach((gradeInput, index) => {
            const grade = gradeInput.value.trim();
            const units = parseFloat(unitsInputs[index]?.value) || 0;
            
            if (grade && units > 0) {
                let gradePoint = 0;
                
                switch(gradingSystem) {
                    case 'gpa':
                        // Traditional 1.0-5.0 scale (1.0 = highest)
                        gradePoint = parseFloat(grade) || 0;
                        // Convert to 4.0 scale for standardized calculation
                        gradePoint = Math.max(0, 5.0 - gradePoint);
                        break;
                    case 'dlsu_gpa':
                        // DLSU 4.0 scale (4.0 = 100%, 0.0 = failed)
                        gradePoint = parseFloat(grade) || 0;
                        break;
                    case 'letter':
                        // Convert letter grades to 4.0 GPA equivalent
                        const letterToGPA = {
                            'A+': 4.0, 'A': 4.0, 'A-': 3.7,
                            'B+': 3.3, 'B': 3.0, 'B-': 2.7,
                            'C+': 2.3, 'C': 2.0, 'C-': 1.7,
                            'D+': 1.3, 'D': 1.0, 'F': 0.0
                        };
                        gradePoint = letterToGPA[grade.toUpperCase()] || 0;
                        break;
                    default: // percentage
                        const percentage = parseFloat(grade) || 0;
                        // Convert percentage to 4.0 GPA scale
                        if (percentage >= 97) gradePoint = 4.0;
                        else if (percentage >= 93) gradePoint = 3.7;
                        else if (percentage >= 90) gradePoint = 3.3;
                        else if (percentage >= 87) gradePoint = 3.0;
                        else if (percentage >= 83) gradePoint = 2.7;
                        else if (percentage >= 80) gradePoint = 2.3;
                        else if (percentage >= 77) gradePoint = 2.0;
                        else if (percentage >= 73) gradePoint = 1.7;
                        else if (percentage >= 70) gradePoint = 1.3;
                        else if (percentage >= 65) gradePoint = 1.0;
                        else gradePoint = 0.0;
                        break;
                }
                
                totalGradePoints += gradePoint * units;
                totalUnits += units;
                validGrades++;
            }
        });
        
        if (validGrades > 0 && totalUnits > 0) {
            const gpa = totalGradePoints / totalUnits;
            
            // Determine status based on grading system
            let status = '';
            let statusClass = '';
            
            switch(gradingSystem) {
                case 'gpa':
                    status = gpa >= 2.0 ? 'Good Standing' : 'Below Requirements';
                    statusClass = gpa >= 2.0 ? 'bg-success' : 'bg-danger';
                    break;
                case 'dlsu_gpa':
                    status = gpa >= 2.0 ? 'Good Standing' : 'Below Requirements';
                    statusClass = gpa >= 2.0 ? 'bg-success' : 'bg-danger';
                    break;
                case 'letter':
                    status = gpa >= 2.0 ? 'Good Standing' : 'Below Requirements';
                    statusClass = gpa >= 2.0 ? 'bg-success' : 'bg-danger';
                    break;
                default: // percentage
                    status = gpa >= 2.0 ? 'Good Standing (75%+)' : 'Below Requirements';
                    statusClass = gpa >= 2.0 ? 'bg-success' : 'bg-danger';
                    break;
            }
            
            summaryDiv.innerHTML = `
                <div class="alert alert-info">
                    <strong>Grade Summary:</strong><br>
                    Subjects Entered: ${validGrades}<br>
                    Total Units: ${totalUnits}<br>
                    Calculated GPA: ${gpa.toFixed(2)}<br>
                    <span class="badge ${statusClass} mt-1">${status}</span>
                </div>
            `;
            summaryDiv.style.display = 'block';
        } else {
            summaryDiv.style.display = 'none';
        }
    }
    
    function handleGradeDocumentUpload(event) {
        const file = event.target.files[0];
        const previewContainer = document.getElementById('gradeUploadPreview');
        const previewImage = document.getElementById('gradePreviewImage');
        const pdfPreview = document.getElementById('gradePdfPreview');
        const ocrSection = document.getElementById('gradeOcrSection');
        const processBtn = document.getElementById('processGradeOcrBtn');
        
        if (file) {
            // Show preview section
            previewContainer.classList.remove('d-none');
            ocrSection.classList.remove('d-none');
            
            // Handle different file types
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    previewImage.classList.remove('d-none');
                    pdfPreview.classList.add('d-none');
                };
                reader.readAsDataURL(file);
            } else if (file.type === 'application/pdf') {
                previewImage.classList.add('d-none');
                pdfPreview.classList.remove('d-none');
                pdfPreview.innerHTML = `<p class="text-muted">PDF: ${file.name}</p>`;
            }
            
            // Enable process button
            processBtn.disabled = false;
        } else {
            previewContainer.classList.add('d-none');
            ocrSection.classList.add('d-none');
        }
    }
    
    function processGradeOCR() {
        const fileInput = document.getElementById('gradeDocument');
        const file = fileInput.files[0];
        const ocrResults = document.getElementById('gradeOcrResults');
        const processBtn = document.getElementById('processGradeOcrBtn');
        
        if (!file) {
            alert('Please select a file first');
            return;
        }
        
        // Show processing state
        processBtn.disabled = true;
        processBtn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Processing...';
        ocrResults.innerHTML = '<div class="text-muted">Processing document...</div>';
        
        // Create form data
        const formData = new FormData();
        formData.append('gradeDocument', file);
        
        // Send to OCR processing endpoint
        fetch('../../services/process_real_grades_ocr.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Display OCR results
                ocrResults.innerHTML = `
                    <div class="alert alert-success">
                        <h6>OCR Text Extracted:</h6>
                        <pre class="small">${data.text}</pre>
                        ${data.grades && data.grades.length > 0 ? `
                            <h6 class="mt-3">Detected Grades:</h6>
                            <ul class="small">
                                ${data.grades.map(grade => `<li>${grade.subject}: ${grade.grade} (${grade.units} units)</li>`).join('')}
                            </ul>
                        ` : ''}
                        <small class="text-muted">Please verify this information matches your uploaded document.</small>
                    </div>
                `;
            } else {
                ocrResults.innerHTML = `
                    <div class="alert alert-warning">
                        <strong>OCR Processing Note:</strong> ${data.message}<br>
                        <small>Please ensure your document is clear and readable, or enter grades manually.</small>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('OCR Error:', error);
            ocrResults.innerHTML = `
                <div class="alert alert-danger">
                    <strong>Error:</strong> Could not process document. Please try again or enter grades manually.
                </div>
            `;
        })
        .finally(() => {
            // Reset button state
            processBtn.disabled = false;
            processBtn.innerHTML = '<i class="bi bi-magic"></i> Process with OCR';
        });
    }
    
    // Add event listeners for grade system change
    document.addEventListener('DOMContentLoaded', function() {
        const gradingSystemInputs = document.querySelectorAll('input[name="grading_system"]');
        gradingSystemInputs.forEach(input => {
            input.addEventListener('change', updateGradeInputs);
        });
        
        // Add event listeners for grade inputs to calculate summary on change
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('grade-input') || e.target.classList.contains('units-input')) {
                calculateGradeSummary();
            }
        });
        
        // Initialize with default values
        updateGradeInputs();
    });
</script>

<!-- ADD this modal HTML before closing </body> tag -->
<!-- Terms and Conditions Modal -->
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="termsModalLabel">
                    <i class="bi bi-file-text me-2"></i>Terms and Conditions
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="terms-content">
                    <h6>1. Eligibility Requirements</h6>
                    <p>To be eligible for EducAid scholarship, applicants must:</p>
                    <ul>
                        <li>Be currently enrolled in an accredited university/college</li>
                        <li>Maintain good academic standing</li>
                        <li>Be a resident of the participating municipality</li>
                        <li>Meet financial need requirements</li>
                    </ul>

                    <h6>2. Application Process</h6>
                    <p>All applicants must complete the online registration process and provide:</p>
                    <ul>
                        <li>Valid enrollment assessment form</li>
                        <li>Accurate personal and academic information</li>
                        <li>Valid email address and phone number for verification</li>
                    </ul>

                    <h6>3. Data Privacy and Security</h6>
                    <p>By registering, you consent to the collection and processing of your personal data for:</p>
                    <ul>
                        <li>Scholarship application evaluation</li>
                        <li>Communication regarding application status</li>
                        <li>Academic monitoring and reporting</li>
                        <li>Statistical analysis for program improvement</li>
                    </ul>

                    <h6>4. Obligations and Responsibilities</h6>
                    <p>If selected for the scholarship, recipients must:</p>
                    <ul>
                        <li>Maintain satisfactory academic performance</li>
                        <li>Provide regular updates on academic progress</li>
                        <li>Attend mandatory orientation and meetings</li>
                        <li>Use scholarship funds exclusively for educational purposes</li>
                    </ul>

                    <h6>5. Program Rules and Regulations</h6>
                    <ul>
                        <li>Scholarship awards are subject to available funding</li>
                        <li>Recipients must complete their program within the standard timeframe</li>
                        <li>Failure to meet requirements may result in scholarship termination</li>
                        <li>False information in the application may lead to immediate disqualification</li>
                    </ul>

                    <h6>6. Contact and Support</h6>
                    <p>For questions or concerns about the EducAid program, please contact:</p>
                    <ul>
                        <li>Email: support@educaid.gov.ph</li>
                        <li>Phone: (123) 456-7890</li>
                        <li>Office Hours: Monday to Friday, 8:00 AM - 5:00 PM</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="acceptTermsBtn" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle me-2"></i>I Accept
                </button>
            </div>
        </div>
    </div>
</div>
</body>
</html>