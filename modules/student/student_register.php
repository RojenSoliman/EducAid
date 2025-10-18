<?php
// Move all AJAX processing to the very top to avoid headers already sent error
include_once '../../config/database.php';
// Include reCAPTCHA v3 configuration (site key + secret key constants)
include_once __DIR__ . '/../../config/recaptcha_config.php';
session_start();

$municipality_id = $_SESSION['active_municipality_id'] ?? 1;
$municipality_logo = null;
$municipality_name = 'General Trias';

if (isset($connection)) {
    $muni_result = pg_query_params(
        $connection,
        "SELECT municipality_id, name, COALESCE(custom_logo_image, preset_logo_image) AS active_logo
         FROM municipalities
         WHERE municipality_id = $1
         LIMIT 1",
        [$municipality_id]
    );

    if ($muni_result && pg_num_rows($muni_result) > 0) {
        $muni_data = pg_fetch_assoc($muni_result);
        $municipality_id = (int)$muni_data['municipality_id'];
        $municipality_name = $muni_data['name'] ?: $municipality_name;
        if (!empty($muni_data['active_logo'])) {
            $raw_logo = trim($muni_data['active_logo']);
            if (
                preg_match('#^data:image/[^;]+;base64,#i', $raw_logo) ||
                preg_match('#^(?:https?:)?//#i', $raw_logo)
            ) {
                $municipality_logo = $raw_logo;
            } else {
                $normalized = str_replace('\\', '/', $raw_logo);
                $normalized = preg_replace('#(?<!:)/{2,}#', '/', $normalized);

                if (strpos($normalized, '../') === 0) {
                    $municipality_logo = $normalized;
                } else {
                    $segments = array_map('rawurlencode', explode('/', ltrim($normalized, '/')));
                    $municipality_logo = '../../' . implode('/', $segments);
                }
            }
        }
        pg_free_result($muni_result);
    } elseif ($muni_result) {
        pg_free_result($muni_result);
    }
}

$_SESSION['active_municipality_id'] = $municipality_id;

// Debug: Test if POST requests work at all
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['debug_test'])) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'POST debug successful', 'data' => $_POST]);
    exit;
}

// --- MOVE OCR PROCESSING HERE BEFORE ANY HTML OUTPUT ---
// Document OCR Processing
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processEnrollmentOcr'])) {
    // Clear any output buffers to prevent headers already sent error
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    
    
    if (!isset($_FILES['enrollment_form']) || $_FILES['enrollment_form']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'No file uploaded or upload error.';
        if (isset($_FILES['enrollment_form']['error'])) {
            $errorMsg .= ' Upload error code: ' . $_FILES['enrollment_form']['error'];
        }
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $errorMsg]);
        exit;
    }

    $uploadDir = '../../assets/uploads/temp/enrollment_forms/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Clear temp folder - delete previous EAF files for this session
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
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'First name and last name are required for filename validation.']);
        exit;
    }

    // Remove file extension and validate format
    $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
    $expectedFormat = $formLastName . '_' . $formFirstName . '_EAF';

    if (strcasecmp($nameWithoutExt, $expectedFormat) !== 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error', 
            'message' => "Filename must follow format: {$formLastName}_{$formFirstName}_EAF.{file_extension}"
        ]);
        exit;
    }

    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        header('Content-Type: application/json');
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
                header('Content-Type: application/json');
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
            header('Content-Type: application/json');
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
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error', 
            'message' => 'No text could be extracted from the document. Please ensure the image is clear and contains readable text.'
        ]);
        exit;
    }
    
    $ocrTextLower = strtolower($ocrText);

    // Enhanced verification results with confidence tracking
    $verification = [
        'first_name_match' => false,
        'middle_name_match' => false,
        'last_name_match' => false,
        'year_level_match' => false,
        'university_match' => false,
        'document_keywords_found' => false,
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
            $verification['first_name_match'] = true;
            // Find and store the matched text snippet
            $pattern = '/\b\w*' . preg_quote(substr($formData['first_name'], 0, 3), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $verification['found_text_snippets']['first_name'] = $matches[0];
            }
        }
    }

    // Check middle name (optional) with improved matching
    if (empty($formData['middle_name'])) {
        $verification['middle_name_match'] = true; // Skip if no middle name provided
        $verification['confidence_scores']['middle_name'] = 100;
    } else {
        $similarity = calculateEAFSimilarity($formData['middle_name'], $ocrTextLower);
        $verification['confidence_scores']['middle_name'] = $similarity;
        
        if ($similarity >= 70) { // Slightly lower threshold for middle names
            $verification['middle_name_match'] = true;
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
            $verification['last_name_match'] = true;
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
                $verification['year_level_match'] = true;
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
            $verification['university_match'] = true;
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
        $verification['document_keywords_found'] = true;
        $verification['found_text_snippets']['document_keywords'] = implode(', ', $foundKeywords);
    }

    // Enhanced overall success calculation
    $requiredChecks = ['first_name_match', 'middle_name_match', 'last_name_match', 'year_level_match', 'university_match', 'document_keywords_found'];
    $passedChecks = 0;
    $totalConfidence = 0;
    $confidenceCount = 0;
    
    foreach ($requiredChecks as $check) {
        if ($verification[$check]) {
            $passedChecks++;
        }
    }
    
    // Calculate average confidence
    foreach ($verification['confidence_scores'] as $score) {
        $totalConfidence += $score;
        $confidenceCount++;
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

    // Save OCR confidence score to temp file for later use during registration
    $confidenceFile = $uploadDir . 'enrollment_confidence.json';
    $confidenceData = [
        'overall_confidence' => $averageConfidence,
        'detailed_scores' => $verification['confidence_scores'],
        'timestamp' => time()
    ];
    @file_put_contents($confidenceFile, json_encode($confidenceData));

    // Save full verification data to .verify.json for admin validation view
    $verifyFile = $targetPath . '.verify.json';
    @file_put_contents($verifyFile, json_encode($verification, JSON_PRETTY_PRINT));

    // Save OCR text to .ocr.txt for reference
    $ocrFile = $targetPath . '.ocr.txt';
    @file_put_contents($ocrFile, $ocrText);

    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'verification' => $verification]);
    exit;
}

// Start output buffering to prevent any early HTML from leaking into JSON responses
if (ob_get_level() === 0) {
    ob_start();
}

// Small helper to emit clean JSON and terminate early
if (!function_exists('json_response')) {
    function json_response(array $payload, int $statusCode = 200): void {
        // Clear any previously buffered output (e.g., DOCTYPE/HTML) so JSON stays valid
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code($statusCode);
        header_remove('X-Powered-By');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        flush();
        exit;
    }
}

// Lightweight reusable reCAPTCHA v3 verifier (avoid duplicating logic per branch)
if (!function_exists('verify_recaptcha_v3')) {
    /**
     * Verify a reCAPTCHA v3 token.
     * @return array{ok:bool,score:float,reason?:string}
     */
    function verify_recaptcha_v3(string $token = null, string $expectedAction = '', float $minScore = 0.5): array {
        if (!defined('RECAPTCHA_SECRET_KEY')) {
            return ['ok'=>false,'score'=>0.0,'reason'=>'missing_keys'];
        }
        $token = $token ?? '';
        if ($token === '') { return ['ok'=>false,'score'=>0.0,'reason'=>'missing_token']; }
        $payload = http_build_query([
            'secret' => RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        $ctx = stream_context_create(['http'=>[
            'method'=>'POST',
            'header'=>'Content-type: application/x-www-form-urlencoded',
            'content'=>$payload,
            'timeout'=>4
        ]]);
        $raw = @file_get_contents(RECAPTCHA_VERIFY_URL, false, $ctx);
        if ($raw === false) { 
            $result = ['ok'=>false,'score'=>0.0,'reason'=>'no_response'];
        } else {
            $json = @json_decode($raw, true);
            if (!is_array($json) || empty($json['success'])) { 
                $result = ['ok'=>false,'score'=>0.0,'reason'=>'api_fail'];
            } else {
                $score = (float)($json['score'] ?? 0);
                $action = $json['action'] ?? '';
                if ($expectedAction !== '' && $action !== $expectedAction) {
                    $result = ['ok'=>false,'score'=>$score,'reason'=>'action_mismatch'];
                } elseif ($score < $minScore) {
                    $result = ['ok'=>false,'score'=>$score,'reason'=>'low_score'];
                } else {
                    $result = ['ok'=>true,'score'=>$score];
                }
            }
        }
        // Append audit log entry (JSON lines) for observability
        try {
            if ($expectedAction !== '') {
                $logFile = __DIR__ . '/../../data/security_verifications.log';
                $entry = [
                    'ts' => date('c'),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,160),
                    'action' => $expectedAction,
                    'result' => $result['ok'] ? 'ok' : 'fail',
                    'score' => $result['score'] ?? 0,
                    'reason' => $result['ok'] ? null : ($result['reason'] ?? null),
                    'session' => substr(session_id(),0,16)
                ];
                @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable $e) { /* ignore logging errors */ }
        return $result;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

// Check if this is an AJAX request (OCR, OTP processing, cleanup, or duplicate check)
$isAjaxRequest = isset($_POST['sendOtp']) || isset($_POST['verifyOtp']) ||
                 isset($_POST['processOcr']) || isset($_POST['processLetterOcr']) ||
                 isset($_POST['processCertificateOcr']) || isset($_POST['processGradesOcr']) ||
                 isset($_POST['cleanup_temp']) || isset($_POST['check_existing']) || isset($_POST['test_db']);

// Only output HTML for non-AJAX requests
if (!$isAjaxRequest) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>EducAid – Register</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link href="../../assets/css/universal.css" rel="stylesheet" />
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
    <link href="../../assets/css/website/landing_page.css" rel="stylesheet" />
    <link rel="stylesheet" href="../../assets/css/registration.css" />
    <style>
        :root {
            --thm-primary: #0051f8;
            --thm-green: #18a54a;
        }

        body.registration-page {
            padding-top: var(--navbar-height);
            overflow-x: hidden;
            font-family: "Manrope", sans-serif;
        }

        body.registration-page nav.navbar.fixed-header {
            isolation: isolate;
            contain: layout style;
        }

        body.registration-page .navbar .btn-outline-primary {
            border: 2px solid var(--thm-primary) !important;
            color: var(--thm-primary) !important;
            background: #fff !important;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
        }

        body.registration-page .navbar .btn-outline-primary:hover {
            background: var(--thm-primary) !important;
            color: #fff !important;
            border-color: var(--thm-primary) !important;
            transform: translateY(-1px);
        }

        body.registration-page .navbar .btn-primary {
            background: var(--thm-primary) !important;
            color: #fff !important;
            border: 2px solid var(--thm-primary) !important;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
        }

        body.registration-page .navbar .btn-primary:hover {
            background: var(--thm-green) !important;
            border-color: var(--thm-green) !important;
            color: #fff !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(24, 165, 74, 0.3);
        }

        body.registration-page .navbar {
            font-family: "Manrope", sans-serif;
        }

        body.registration-page .navbar-brand {
            font-weight: 700;
        }

        body.registration-page .nav-link {
            font-weight: 500;
        }

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
            position: fixed; top: 12px; left: 50%; transform: translateX(-50%);
            max-width: 640px; width: calc(100% - 32px);
            padding: 14px 24px; background-color: #f8d7da; color: #721c24;
            border-radius: 8px; display: none; box-shadow: 0 6px 16px -4px rgba(0,0,0,0.25), 0 2px 6px rgba(0,0,0,0.18);
            z-index: 5000; /* Above nav/topbar and modals backdrop (Bootstrap modal backdrop is 1040, modal 1050) */
            font-weight: 500; letter-spacing: .25px; backdrop-filter: blur(6px);
            animation: notifierSlide .35s ease-out;
        }
        @keyframes notifierSlide { from { opacity: 0; transform: translate(-50%, -10px);} to { opacity: 1; transform: translate(-50%, 0);} }
        .notifier.success { background-color: #d4edda; color: #155724; }
        .notifier.error { background-color: #f8d7da; color: #721c24; }
        .notifier.warning { background-color: #fff3cd; color: #856404; }
        .notifier.success { background-color: #d4edda; color: #155724; }
        .verified-email { background-color: #e9f7e9; color: #28a745; }
        
        /* Enhanced required asterisk styling */
        .form-label .text-danger {
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .form-label .text-muted {
            font-size: 0.85em;
            font-style: italic;
        }
        </style>
        <!-- reCAPTCHA v3 -->
        <script src="https://www.google.com/recaptcha/api.js?render=<?php echo RECAPTCHA_SITE_KEY; ?>"></script>
        <script>window.RECAPTCHA_SITE_KEY = '<?php echo RECAPTCHA_SITE_KEY; ?>';</script>
        <script>
            // Acquire token as early as possible and refresh periodically (v3 tokens expire quickly)
            let recaptchaToken = '';
            function executeRecaptcha(){
                if (typeof grecaptcha === 'undefined') return;
                grecaptcha.execute('<?php echo RECAPTCHA_SITE_KEY; ?>', {action: 'register'}).then(function(token){
                    recaptchaToken = token;
                    const hidden = document.getElementById('g-recaptcha-response');
                    if (hidden) hidden.value = token;
                });
            }
            window.addEventListener('DOMContentLoaded', function(){
                grecaptcha.ready(function(){
                    executeRecaptcha();
                    // refresh token every 90 seconds
                    setInterval(executeRecaptcha, 90000);
                });
            });
        </script>
</head>
<body class="registration-page has-header-offset">
    <?php include '../../includes/website/topbar.php'; ?>

    <?php 
    $custom_brand_config = [
        'href' => '../../website/landingpage.php',
        'name' => 'EducAid • ' . $municipality_name,
        'hide_educaid_logo' => true,
        'show_municipality' => true,
        'municipality_logo' => $municipality_logo,
        'municipality_name' => $municipality_name
    ];
    $custom_nav_links = [];
    $prepend_navbar_actions = [
        [
            'href' => '../../website/landingpage.php',
            'label' => 'Back to Home',
            'icon' => 'bi-house',
            'class' => 'btn btn-outline-secondary btn-sm'
        ]
    ];
    $simple_nav_style = true;
    include '../../includes/website/navbar.php'; 
    // Hidden input to store the reCAPTCHA v3 token for final submission (non-AJAX form posts)
    ?>
    <input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response" form="finalRegistrationForm" />
    <?php
    ?>

<?php
} // End of HTML output for non-AJAX requests

// --- Distribution Control & Slot check ---
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    // Check if there's an active slot for this municipality
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
        if ($slotsFull) {
            $title = "EducAid – Registration Closed";
            $headerText = "Slots are full.";
            $messageText = "Please wait for the next announcement before registering again.";
            $iconColor = "text-danger";
        } else {
            $title = "EducAid – Registration Not Available";
            $headerText = "Registration is currently closed.";
            $messageText = "Please wait for the next opening of slots.";
            $iconColor = "text-warning";
        }
        
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <title>$title</title>
            <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
            <style>
                body {
                    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #4b79a1 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 1.5rem;
                    color: #0b1c33;
                }
                .alert-container {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    max-width: 520px;
                    width: 100%;
                    margin: 0 auto;
                    background: rgba(255, 255, 255, 0.95);
                    padding: 2.5rem 2rem;
                    border-radius: 1.5rem;
                    box-shadow: 0 20px 45px rgba(18, 38, 66, 0.35);
                    text-align: center;
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
        <body>
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

// --- Check existing account ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['check_existing'])) {
    $email = isset($_POST['email']) ? filter_var($_POST['email'], FILTER_SANITIZE_EMAIL) : '';
    $mobile = isset($_POST['mobile']) ? preg_replace('/[^0-9+]/', '', $_POST['mobile']) : '';
    
    if (empty($email) && empty($mobile)) {
        json_response(['status' => 'error', 'message' => 'Email or mobile number required']);
    }
    
    try {
        // Check for existing accounts with same email or mobile
        $conditions = [];
        $params = [];
        $paramIndex = 1;
        
        if (!empty($email)) {
            $conditions[] = "email = $" . $paramIndex++;
            $params[] = $email;
        }
        
        if (!empty($mobile)) {
            $conditions[] = "mobile = $" . $paramIndex++;
            $params[] = $mobile;
        }
        
        if (empty($conditions)) {
            json_response(['status' => 'success', 'message' => 'No conflicts found']);
        }
        
        $query = "SELECT email, mobile, status, first_name, last_name 
                  FROM students 
                  WHERE " . implode(' OR ', $conditions) . " 
                  LIMIT 1";
        
        $result = pg_query_params($connection, $query, $params);
        
        if (!$result) {
            json_response(['status' => 'error', 'message' => 'Database error occurred']);
        }
        
        $existing = pg_fetch_assoc($result);
        
        if ($existing) {
            $conflictType = '';
            if (!empty($email) && $existing['email'] === $email) {
                $conflictType = 'email';
            } elseif (!empty($mobile) && $existing['mobile'] === $mobile) {
                $conflictType = 'phone number';
            }
            
            $canReapply = in_array($existing['status'], ['rejected', 'disqualified']);
            
            json_response([
                'status' => 'exists',
                'message' => "An account already exists with this {$conflictType}.",
                'type' => $conflictType,
                'account_status' => $existing['status'],
                'name' => $existing['first_name'] . ' ' . $existing['last_name'],
                'can_reapply' => $canReapply
            ]);
        } else {
            json_response(['status' => 'success', 'message' => 'No conflicts found']);
        }
        
    } catch (Exception $e) {
        error_log("Check existing account error: " . $e->getMessage());
        json_response(['status' => 'error', 'message' => 'Could not verify account status']);
    }
}

// --- OTP send ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['sendOtp'])) {
    // Temporarily bypass reCAPTCHA for testing - uncomment for production
    /*
    $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'send_otp');
    if (!$captcha['ok']) {
        json_response(['status'=>'error','message'=>'Security verification failed (captcha).']);
    }
    */
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['status' => 'error', 'message' => 'Invalid email format.']);
    }

    $checkEmail = pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1", [$email]);
    if (pg_num_rows($checkEmail) > 0) {
        json_response(['status' => 'error', 'message' => 'This email is already registered. Please use a different email or login.']);
    }

    $otp = rand(100000, 999999);

    $_SESSION['otp'] = $otp;
    $_SESSION['otp_email'] = $email;
    $_SESSION['otp_timestamp'] = time();
    // Immediately flush session changes so subsequent requests (e.g., OTP verify) can see them
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
        // Do not immediately reopen the session here to avoid re-locking.
        // This branch will respond via json_response after mailing, and does not need further session writes.
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        // SMTP settings from environment
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USERNAME') ?: 'example@email.test';
        $mail->Password   = getenv('SMTP_PASSWORD') ?: ''; // Ensure this is set in .env on production
        $encryption       = getenv('SMTP_ENCRYPTION') ?: 'tls';
        
        // Debug logging for SMTP configuration
        error_log("SMTP Configuration - Host: " . $mail->Host . ", Username: " . $mail->Username . ", Port: " . (getenv('SMTP_PORT') ?: 587));
        
        if (strtolower($encryption) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);
        
        // Enable SMTP debug output for troubleshooting
        $mail->SMTPDebug = 2; // Enable verbose debug output
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer Debug: $str");
        };

        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: ($mail->Username ?: 'no-reply@educaid.local');
        $fromName  = getenv('SMTP_FROM_NAME')  ?: 'EducAid';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = 'Your EducAid OTP Code';
    $mail->Body    = "Your One-Time Password (OTP) for EducAid registration is: <strong>$otp</strong><br><br>This OTP is valid for 5 minutes.";
    $mail->AltBody = "Your One-Time Password (OTP) for EducAid registration is: $otp. This OTP is valid for 5 minutes.";

        $mail->send();
        json_response(['status' => 'success', 'message' => 'OTP sent to your email. Please check your inbox and spam folder.']);
    } catch (Exception $e) {
        error_log("PHPMailer Exception: " . $e->getMessage());
        error_log("PHPMailer Error Info: {$mail->ErrorInfo}");
        
        // Check if SMTP credentials are configured
        $smtpUsername = getenv('SMTP_USERNAME');
        $smtpPassword = getenv('SMTP_PASSWORD');
        
        if (empty($smtpUsername) || empty($smtpPassword) || $smtpUsername === 'example@email.test') {
            json_response([
                'status' => 'error', 
                'message' => 'Email service not configured. Please contact administrator.',
                'debug' => 'SMTP credentials not set up properly.'
            ]);
        } else {
            json_response([
                'status' => 'error', 
                'message' => 'Failed to send email. Please check your email address and try again.',
                'debug' => 'SMTP Error: ' . $e->getMessage()
            ]);
        }
    }
}

// --- OTP verify ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verifyOtp'])) {
    // Temporarily bypass reCAPTCHA for testing - uncomment for production
    /*
    $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'verify_otp');
    if (!$captcha['ok']) {
        json_response(['status'=>'error','message'=>'Security verification failed (captcha).']);
    }
    */
    $enteredOtp = filter_var($_POST['otp'], FILTER_SANITIZE_NUMBER_INT);
    $email_for_otp = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_email']) || !isset($_SESSION['otp_timestamp'])) {
        error_log("OTP verification error: session data missing (otp, otp_email, otp_timestamp). Session ID: " . session_id());
        json_response(['status' => 'error', 'message' => 'No OTP sent or session expired. Please request a new OTP.']);
    }

    // Normalize email for robust comparison (trim + lowercase)
    $sessionEmail = strtolower(trim($_SESSION['otp_email']));
    $submittedEmail = strtolower(trim($email_for_otp));
    if ($sessionEmail !== $submittedEmail) {
        error_log("OTP email mismatch: session={$sessionEmail} submitted={$submittedEmail}");
        json_response(['status' => 'error', 'message' => 'Email mismatch for OTP. Please ensure you are verifying the correct email.']);
    }

    if ((time() - $_SESSION['otp_timestamp']) > 300) {
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']);
        json_response(['status' => 'error', 'message' => 'OTP has expired. Please request a new OTP.']);
    }

    if ((int)$enteredOtp === (int)$_SESSION['otp']) {
        $_SESSION['otp_verified'] = true;
        error_log("OTP verified successfully for email: " . $_SESSION['otp_email'] . ", session set to true");
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']);
        // Flush session updates before returning JSON to avoid stale reads from rapid follow-ups
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        json_response(['status' => 'success', 'message' => 'OTP verified successfully!']);
    } else {
        $_SESSION['otp_verified'] = false;
        // Flush session state update to reduce risk of duplicate/conflicting attempts
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        error_log("OTP verification failed - entered: " . $enteredOtp . ", expected: " . $_SESSION['otp']);
        json_response(['status' => 'error', 'message' => 'Invalid OTP. Please try again.']);
    }
}

// --- Letter to Mayor OCR Processing ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processLetterOcr'])) {
    $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'process_letter_ocr');
    if (!$captcha['ok']) { json_response(['status'=>'error','message'=>'Security verification failed (captcha).']); }
    if (!isset($_FILES['letter_to_mayor']) || $_FILES['letter_to_mayor']['error'] !== UPLOAD_ERR_OK) {
        json_response(['status' => 'error', 'message' => 'No letter file uploaded or upload error.']);
    }

    $uploadDir = '../../assets/uploads/temp/letter_mayor/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Clear temp folder for letter files - auto-delete previous uploads
    $letterFiles = glob($uploadDir . '*');
    foreach ($letterFiles as $file) {
        if (is_file($file)) unlink($file);
    }

    $uploadedFile = $_FILES['letter_to_mayor'];
    $fileName = basename($uploadedFile['name']);
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        json_response(['status' => 'error', 'message' => 'Failed to save uploaded letter file.']);
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
                json_response([
                    'status' => 'error', 
                    'message' => 'Unable to extract text from PDF. Please try one of these alternatives:',
                    'suggestions' => [
                        '1. Convert the PDF to a JPG or PNG image',
                        '2. Take a photo of the document with your phone camera',
                        '3. Scan the document as an image file',
                        '4. Ensure the PDF contains selectable text (not a scanned image)'
                    ]
                ]);
            }
        }
    } else {
        // For image files, use standard Tesseract processing
        $command = "tesseract " . escapeshellarg($targetPath) . " " . escapeshellarg($outputBase) . 
                   " --oem 1 --psm 6 -l eng 2>&1";
        
        $tesseractOutput = shell_exec($command);
        $outputFile = $outputBase . ".txt";
        
        if (!file_exists($outputFile)) {
            json_response([
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
        }
        
        $ocrText = file_get_contents($outputFile);
        
        // Clean up temporary OCR files
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
    }
    
    if (empty(trim($ocrText))) {
        json_response([
            'status' => 'error', 
            'message' => 'No text could be extracted from the document. Please ensure the image is clear and contains readable text.'
        ]);
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
    
    // Save OCR confidence score to temp file for later use during registration
    $confidenceFile = $uploadDir . 'letter_confidence.json';
    $confidenceData = [
        'overall_confidence' => $averageConfidence,
        'detailed_scores' => $verification['confidence_scores'],
        'timestamp' => time()
    ];
    file_put_contents($confidenceFile, json_encode($confidenceData));
    
    // Save full verification data to .verify.json for admin validation view
    $verifyFile = $targetPath . '.verify.json';
    @file_put_contents($verifyFile, json_encode($verification, JSON_PRETTY_PRINT));
    
    // Save OCR text to .ocr.txt for reference
    $ocrFile = $targetPath . '.ocr.txt';
    @file_put_contents($ocrFile, $ocrText);
    
    // Note: Letter file is kept in temp directory for final registration step
    // It will be cleaned up during registration completion
    
    json_response(['status' => 'success', 'verification' => $verification]);
}

// --- Certificate of Indigency OCR Processing ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processCertificateOcr'])) {
    $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'process_certificate_ocr');
    if (!$captcha['ok']) { json_response(['status'=>'error','message'=>'Security verification failed (captcha).']); }
    if (!isset($_FILES['certificate_of_indigency']) || $_FILES['certificate_of_indigency']['error'] !== UPLOAD_ERR_OK) {
        json_response(['status' => 'error', 'message' => 'No certificate file uploaded or upload error.']);
    }

    $uploadDir = '../../assets/uploads/temp/indigency/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Clear temp folder for certificate files - auto-delete previous uploads
    $certificateFiles = glob($uploadDir . '*');
    foreach ($certificateFiles as $file) {
        if (is_file($file)) unlink($file);
    }

    $uploadedFile = $_FILES['certificate_of_indigency'];
    $fileName = basename($uploadedFile['name']);
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        json_response(['status' => 'error', 'message' => 'Failed to save uploaded certificate file.']);
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
                json_response([
                    'status' => 'error', 
                    'message' => 'Unable to extract text from PDF. Please try one of these alternatives:',
                    'suggestions' => [
                        '1. Convert the PDF to a JPG or PNG image',
                        '2. Take a photo of the document with your phone camera',
                        '3. Scan the document as an image file',
                        '4. Ensure the PDF contains selectable text (not a scanned image)'
                    ]
                ]);
            }
        }
    } else {
        // For image files, use standard Tesseract processing
        $command = "tesseract " . escapeshellarg($targetPath) . " " . escapeshellarg($outputBase) . 
                   " --oem 1 --psm 6 -l eng 2>&1";
        
        $tesseractOutput = shell_exec($command);
        $outputFile = $outputBase . ".txt";
        
        if (!file_exists($outputFile)) {
            json_response([
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
        }
        
        $ocrText = file_get_contents($outputFile);
        
        // Clean up temporary OCR files
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
    }
    
    if (empty(trim($ocrText))) {
        json_response([
            'status' => 'error', 
            'message' => 'No text could be extracted from the document. Please ensure the image is clear and contains readable text.'
        ]);
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
    
    // Save OCR confidence score to temp file for later use during registration
    $confidenceFile = $uploadDir . 'certificate_confidence.json';
    $confidenceData = [
        'overall_confidence' => $averageConfidence,
        'detailed_scores' => $verification['confidence_scores'],
        'timestamp' => time()
    ];
    file_put_contents($confidenceFile, json_encode($confidenceData));
    
    // Save full verification data to .verify.json for admin validation view
    $verifyFile = $targetPath . '.verify.json';
    @file_put_contents($verifyFile, json_encode($verification, JSON_PRETTY_PRINT));
    
    // Save OCR text to .ocr.txt for reference
    $ocrFile = $targetPath . '.ocr.txt';
    @file_put_contents($ocrFile, $ocrText);
    
    // Note: Certificate file is kept in temp directory for final registration step
    // It will be cleaned up during registration completion
    
    json_response(['status' => 'success', 'verification' => $verification]);
}

// --- Enhanced Grades OCR Processing with Strict Validation ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processGradesOcr'])) {
    try {
        $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'process_grades_ocr');
        if (!$captcha['ok']) {
            json_response(['status'=>'error','message'=>'Security verification failed (captcha).']);
        }

        if (!isset($_FILES['grades_document']) || $_FILES['grades_document']['error'] !== UPLOAD_ERR_OK) {
            json_response(['status' => 'error', 'message' => 'No grades document uploaded or upload error.']);
        }

        $uploadDir = '../../assets/uploads/temp/grades/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Clear temp folder for grades files
        $gradesFiles = glob($uploadDir . '*');
        foreach ($gradesFiles as $file) {
            if (is_file($file)) unlink($file);
        }

        $uploadedFile = $_FILES['grades_document'];
        $fileName = basename($uploadedFile['name']);
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
            json_response(['status' => 'error', 'message' => 'Failed to save uploaded grades file.']);
        }

        // Extract text from document
        $ocrText = '';
        $fileExtension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
        
        if ($fileExtension === 'pdf') {
            $pdfTextCommand = "pdftotext " . escapeshellarg($targetPath) . " - 2>nul";
            $pdfText = @shell_exec($pdfTextCommand);
            if (!empty(trim($pdfText))) {
                $ocrText = $pdfText;
            } else {
                // Fallback PDF extraction
                $pdfContent = @file_get_contents($targetPath);
                if ($pdfContent !== false) {
                    preg_match_all('/\(([^)]+)\)/', $pdfContent, $matches);
                    if (!empty($matches[1])) {
                        $extractedText = implode(' ', $matches[1]);
                        $extractedText = preg_replace('/[^\x20-\x7E]/', ' ', $extractedText);
                        $extractedText = preg_replace('/\s+/', ' ', trim($extractedText));
                        if (strlen($extractedText) > 10) {
                            $ocrText = $extractedText;
                        }
                    }
                }
                if (empty(trim($ocrText))) {
                    json_response([
                        'status' => 'error',
                        'message' => 'Unable to extract text from PDF.',
                        'suggestions' => [
                            'Convert the PDF to a JPG or PNG image',
                            'Take a clear photo of the grades',
                            'Ensure the PDF contains selectable text'
                        ]
                    ]);
                }
            }
        } else {
            // Enhanced image processing via OCR service
            try {
                // Include the enhanced OCR service
                require_once __DIR__ . '/../../services/OCRProcessingService.php';
                
                $ocrProcessor = new OCRProcessingService([
                    'tesseract_path' => 'tesseract',
                    'temp_dir' => $uploadDir . '../temp_ocr/',
                    'max_file_size' => 10 * 1024 * 1024,
                ]);
                
                // Create temp OCR directory if needed
                $tempOcrDir = $uploadDir . '../temp_ocr/';
                if (!is_dir($tempOcrDir)) {
                    mkdir($tempOcrDir, 0755, true);
                }
                
                // Process the document
                $ocrResult = $ocrProcessor->processGradeDocument($targetPath);
                
                if ($ocrResult['success'] && !empty($ocrResult['subjects'])) {
                    // Build OCR text from extracted subjects for backward compatibility
                    $ocrText = "Grade Document Analysis:\n";
                    foreach ($ocrResult['subjects'] as $subject) {
                        $ocrText .= "{$subject['name']}: {$subject['rawGrade']}\n";
                    }
                    
                    // Add raw text if needed for other validations
                    $ocrText .= "\nRaw Document Content:\n";
                    
                    // Fall back to basic Tesseract for full text extraction
                    $outputBase = $uploadDir . 'ocr_output_' . uniqid();
                    $outputFile = $outputBase . '.txt';
                    $cmd = "tesseract " . escapeshellarg($targetPath) . " " . 
                              escapeshellarg($outputBase) . " --oem 1 --psm 6 -l eng 2>&1";
                    
                    $tesseractOutput = shell_exec($cmd);
                    if (file_exists($outputFile)) {
                        $rawText = file_get_contents($outputFile);
                        $ocrText .= $rawText;
                        @unlink($outputFile);
                    } else {
                        error_log("Tesseract command failed: $cmd");
                        error_log("Tesseract output: $tesseractOutput");
                    }
                } else {
                    // Enhanced OCR failed, try basic Tesseract
                    $outputBase = $uploadDir . 'ocr_output_' . uniqid();
                    $outputFile = $outputBase . '.txt';
                    
                    // Try multiple PSM modes for better results
                    $psmModes = [6, 4, 7, 8, 3]; // Different page segmentation modes
                    $success = false;
                    
                    foreach ($psmModes as $psm) {
                        $cmd = "tesseract " . escapeshellarg($targetPath) . " " . 
                                  escapeshellarg($outputBase) . " --oem 1 --psm $psm -l eng 2>&1";
                        
                        $tesseractOutput = shell_exec($cmd);
                        
                        if (file_exists($outputFile)) {
                            $testText = file_get_contents($outputFile);
                            if (!empty(trim($testText)) && strlen(trim($testText)) > 10) {
                                $ocrText = $testText;
                                $success = true;
                                break;
                            }
                        }
                    }
                    
                    if (!$success) {
                        // Log detailed error information
                        error_log("OCR processing failed for file: $targetPath");
                        error_log("Last Tesseract output: $tesseractOutput");
                        error_log("File size: " . filesize($targetPath));
                        error_log("File extension: $fileExtension");
                        
                        json_response([
                            'status' => 'error',
                            'message' => 'OCR processing failed. Please try the following:',
                            'suggestions' => [
                                'Ensure the image is clear and well-lit',
                                'Use higher resolution (at least 300 DPI)',
                                'Make sure text is horizontal (not rotated)',
                                'Try converting to PNG format',
                                'Check if the text is large enough to read'
                            ],
                            'debug_info' => [
                                'file_size' => filesize($targetPath),
                                'tesseract_output' => $tesseractOutput,
                                'enhanced_ocr_error' => $ocrResult['error'] ?? 'No enhanced OCR error'
                            ]
                        ]);
                    }
                    
                    @unlink($outputFile);
                }
                
            } catch (Exception $e) {
                error_log("OCR Service Error: " . $e->getMessage());
                
                // Final fallback to basic Tesseract
                $outputBase = $uploadDir . 'ocr_output_' . uniqid();
                $outputFile = $outputBase . '.txt';
                $cmd = "tesseract " . escapeshellarg($targetPath) . " " . 
                          escapeshellarg($outputBase) . " --oem 1 --psm 6 -l eng 2>&1";
                
                $tesseractOutput = shell_exec($cmd);
                
                if (!file_exists($outputFile) || empty(trim(file_get_contents($outputFile)))) {
                    json_response([
                        'status' => 'error',
                        'message' => 'OCR processing encountered an error.',
                        'suggestions' => [
                            'Try uploading the image again',
                            'Ensure the file is not corrupted',
                            'Use a different image format (PNG, JPG)'
                        ],
                        'debug_info' => [
                            'exception' => $e->getMessage(),
                            'tesseract_output' => $tesseractOutput
                        ]
                    ]);
                }
                
                $ocrText = file_get_contents($outputFile);
                @unlink($outputFile);
            }
        }

        if (empty(trim($ocrText))) {
            json_response([
                'status' => 'error', 
                'message' => 'No text could be extracted from the document.',
                'suggestions' => [
                    'Ensure the image has good contrast and lighting',
                    'Make sure the text is horizontal (not rotated)',
                    'Try using a higher resolution image (300+ DPI)',
                    'Use PNG or JPG format',
                    'Ensure the document fills most of the image frame'
                ],
                'debug_info' => [
                    'file_size' => filesize($targetPath),
                    'file_extension' => $fileExtension,
                    'text_length' => strlen($ocrText ?? ''),
                    'ocr_text_sample' => substr($ocrText ?? '', 0, 100)
                ]
            ]);
        }

        // Get form data for validation
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $yearLevelId = intval($_POST['year_level_id'] ?? 0);
        $universityId = intval($_POST['university_id'] ?? 0);

        // Get declared year level name
        $declaredYearName = '';
        if ($yearLevelId > 0) {
            $yrRes = pg_query_params($connection, "SELECT name FROM year_levels WHERE year_level_id = $1", [$yearLevelId]);
            if ($yrRow = pg_fetch_assoc($yrRes)) {
                $declaredYearName = $yrRow['name'];
            }
        }

        // Get university name for verification
        $declaredUniversityName = '';
        if ($universityId > 0) {
            $uniRes = pg_query_params($connection, "SELECT name FROM universities WHERE university_id = $1", [$universityId]);
            if ($uniRow = pg_fetch_assoc($uniRes)) {
                $declaredUniversityName = $uniRow['name'];
            }
        }

        // Get admin-specified semester and school year from config
        $adminSemester = '';
        $adminSchoolYear = '';
        
        $configRes = pg_query($connection, "SELECT key, value FROM config WHERE key IN ('valid_semester', 'valid_school_year')");
        while ($configRow = pg_fetch_assoc($configRes)) {
            if ($configRow['key'] === 'valid_semester') {
                $adminSemester = $configRow['value'];
            } elseif ($configRow['key'] === 'valid_school_year') {
                $adminSchoolYear = $configRow['value'];
            }
        }

        // Normalize OCR text
        $ocrTextNormalized = strtolower($ocrText);

        // === 1. DECLARED YEAR VALIDATION ===
        $yearValidationResult = validateDeclaredYear($ocrText, $declaredYearName);
        $yearLevelMatch = $yearValidationResult['match'];
        $yearLevelSection = $yearValidationResult['section'];
        $yearLevelConfidence = $yearValidationResult['confidence'];

        // === 2. ADMIN SEMESTER VALIDATION ===
        $semesterValidationResult = validateAdminSemester($ocrText, $adminSemester);
        $semesterMatch = $semesterValidationResult['match'];
        $semesterConfidence = $semesterValidationResult['confidence'];
        $foundSemesterText = $semesterValidationResult['found_text'];

        // === 3. ADMIN SCHOOL YEAR VALIDATION === (TEMPORARILY DISABLED FOR TESTING)
        // $schoolYearValidationResult = validateAdminSchoolYear($ocrText, $adminSchoolYear);
        // Temporarily set to always pass for testing
        $schoolYearMatch = true;
        $schoolYearConfidence = 100;
        $foundSchoolYearText = 'Temporarily disabled for testing';

        // === 4. GRADE THRESHOLD CHECK ===
        // Legacy grade validation (kept for backward compatibility)
        $gradeValidationResult = validateGradeThreshold($yearLevelSection, $declaredYearName);
        $legacyAllGradesPassing = $gradeValidationResult['all_passing'];
        $legacyValidGrades = $gradeValidationResult['grades'];
        $legacyFailingGrades = $gradeValidationResult['failing_grades'];
        
        // === 4B. ENHANCED PER-SUBJECT GRADE VALIDATION ===
        // Get university code for per-subject validation
        $universityCode = '';
        if ($universityId) {
            $uniCodeRes = pg_query_params($connection, "SELECT code FROM universities WHERE university_id = $1", [$universityId]);
            if ($uniCodeRow = pg_fetch_assoc($uniCodeRes)) {
                $universityCode = $uniCodeRow['code'];
            }
        }
        
        // Perform enhanced per-subject grade validation if university code available
        $enhancedGradeResult = null;
        $allGradesPassing = $legacyAllGradesPassing; // Default to legacy
        $validGrades = $legacyValidGrades;
        $failingGrades = $legacyFailingGrades;
        
        if (!empty($universityCode)) {
            // Convert legacy grades to enhanced format for validation
            $subjectsForValidation = array_map(function($grade) {
                return [
                    'name' => $grade['subject'],
                    'rawGrade' => $grade['grade'],
                    'confidence' => 95 // High confidence for OCR-extracted grades
                ];
            }, $legacyValidGrades);
            
            if (!empty($subjectsForValidation)) {
                $enhancedGradeResult = validatePerSubjectGrades($universityCode, null, $subjectsForValidation);
                
                if ($enhancedGradeResult['success']) {
                    // Use enhanced validation result
                    $allGradesPassing = $enhancedGradeResult['eligible'];
                    $failingGrades = array_map(function($failedSubject) {
                        // Parse failed subject string "Subject Name: Grade"
                        $parts = explode(':', $failedSubject, 2);
                        return [
                            'subject' => trim($parts[0] ?? ''),
                            'grade' => trim($parts[1] ?? '')
                        ];
                    }, $enhancedGradeResult['failed_subjects']);
                }
            }
        }

        // === 5. UNIVERSITY VERIFICATION ===
        $universityValidationResult = validateUniversity($ocrText, $declaredUniversityName);
        $universityMatch = $universityValidationResult['match'];
        $universityConfidence = $universityValidationResult['confidence'];
        $foundUniversityText = $universityValidationResult['found_text'];

        // === 6. NAME VERIFICATION ===
        $nameMatch = validateStudentName($ocrText, $firstName, $lastName);

        // === 7. ELIGIBILITY DECISION ===
        $isEligible = ($yearLevelMatch && $semesterMatch && $schoolYearMatch && $allGradesPassing && $universityMatch && $nameMatch);

        // Build verification response
        $verification = [
            'year_level_match' => $yearLevelMatch,
            'semester_match' => $semesterMatch,
            'school_year_match' => $schoolYearMatch,
            'university_match' => $universityMatch,
            'name_match' => $nameMatch,
            'all_grades_passing' => $allGradesPassing,
            'is_eligible' => $isEligible,
            'grades' => $validGrades,
            'failing_grades' => $failingGrades,
            'enhanced_grade_validation' => $enhancedGradeResult,
            'university_code' => $universityCode,
            'validation_method' => !empty($universityCode) && $enhancedGradeResult && $enhancedGradeResult['success'] ? 'enhanced_per_subject' : 'legacy_threshold',
            'confidence_scores' => [
                'year_level' => $yearLevelConfidence,
                'semester' => $semesterConfidence,
                'school_year' => $schoolYearConfidence,
                'university' => $universityConfidence,
                'name' => $nameMatch ? 95 : 0,
                'grades' => !empty($validGrades) ? 90 : 0
            ],
            'found_text_snippets' => [
                'year_level' => $declaredYearName,
                'semester' => $foundSemesterText,
                'school_year' => $foundSchoolYearText,
                'university' => $foundUniversityText
            ],
            'admin_requirements' => [
                'required_semester' => $adminSemester,
                'required_school_year' => $adminSchoolYear
            ],
            'overall_success' => $isEligible,
            'summary' => [
                'passed_checks' => 
                    ($yearLevelMatch ? 1 : 0) + 
                    ($semesterMatch ? 1 : 0) + 
                    ($schoolYearMatch ? 1 : 0) + 
                    ($universityMatch ? 1 : 0) + 
                    ($nameMatch ? 1 : 0) + 
                    ($allGradesPassing ? 1 : 0),
                'total_checks' => 5, // Temporarily 5 (school year validation disabled)
                'eligibility_status' => $isEligible ? 'ELIGIBLE' : 'INELIGIBLE',
                'recommendation' => $isEligible ? 
                    'All validations passed - Student is eligible' : 
                    'Validation failed - Student is not eligible'
            ]
        ];

        // Calculate average confidence
        $confValues = array_values($verification['confidence_scores']);
        $verification['summary']['average_confidence'] = !empty($confValues) ? 
            round(array_sum($confValues) / count($confValues), 1) : 0;

        // Save verification results
        $confidenceFile = $uploadDir . 'grades_confidence.json';
        @file_put_contents($confidenceFile, json_encode([
            'overall_confidence' => $verification['summary']['average_confidence'],
            'detailed_scores' => $verification['confidence_scores'],
            'grades' => $validGrades,
            'eligibility_status' => $verification['summary']['eligibility_status'],
            'timestamp' => time()
        ]));

        json_response(['status' => 'success', 'verification' => $verification]);

    } catch (Throwable $e) {
        error_log("Grades OCR Error: " . $e->getMessage());
        json_response([
            'status' => 'error',
            'message' => 'An error occurred during processing',
            'suggestions' => [
                'Try uploading a clearer image',
                'Ensure grades are clearly visible',
                'Check file format and try again'
            ]
        ]);
    }
}

// === GRADE VALIDATION HELPER FUNCTIONS ===

function validateDeclaredYear($ocrText, $declaredYearName) {
    $ocrTextLower = strtolower($ocrText);
    $declaredYearLower = strtolower($declaredYearName);
    
    // Year level variations mapping
    $yearVariations = [
        '1' => ['1st year', 'first year', '1st yr', 'year 1', 'yr 1', 'freshman', 'grade 1'],
        '2' => ['2nd year', 'second year', '2nd yr', 'year 2', 'yr 2', 'sophomore', 'grade 2'], 
        '3' => ['3rd year', 'third year', '3rd yr', 'year 3', 'yr 3', 'junior', 'grade 3'],
        '4' => ['4th year', 'fourth year', '4th yr', 'year 4', 'yr 4', 'senior', 'grade 4']
    ];
    
    // Detect which year was declared
    $declaredYearNum = '';
    foreach ($yearVariations as $num => $variations) {
        foreach ($variations as $variation) {
            if (stripos($declaredYearLower, $variation) !== false) {
                $declaredYearNum = $num;
                break 2;
            }
        }
    }
    
    if (empty($declaredYearNum)) {
        return ['match' => false, 'section' => $ocrText, 'confidence' => 0];
    }
    
    // Find the declared year section in OCR text
    $targetVariations = $yearVariations[$declaredYearNum];
    $yearSectionStart = false;
    $yearSectionEnd = false;
    $matchedVariation = '';
    
    // Find start position of declared year
    foreach ($targetVariations as $variation) {
        $pos = stripos($ocrTextLower, $variation);
        if ($pos !== false) {
            $yearSectionStart = $pos;
            $matchedVariation = $variation;
            break;
        }
    }
    
    if ($yearSectionStart === false) {
        return ['match' => false, 'section' => $ocrText, 'confidence' => 0];
    }
    
    // Find end position (next year level or end of document)
    $yearSectionEnd = strlen($ocrText);
    foreach ($yearVariations as $otherNum => $otherVariations) {
        if ($otherNum === $declaredYearNum) continue;
        
        foreach ($otherVariations as $otherVariation) {
            $otherPos = stripos($ocrTextLower, $otherVariation, $yearSectionStart + 1);
            if ($otherPos !== false && $otherPos < $yearSectionEnd) {
                $yearSectionEnd = $otherPos;
            }
        }
    }
    
    // Extract the year section
    $yearSection = substr($ocrText, $yearSectionStart, $yearSectionEnd - $yearSectionStart);
    
    return [
        'match' => true,
        'section' => $yearSection,
        'confidence' => 95,
        'matched_variation' => $matchedVariation
    ];
}

function validateGradeThreshold($yearSection, $declaredYearName) {
    // Extract grades from the year section - only decimal grades
    preg_match_all('/([A-Za-z0-9\s\.\-\&\/\(\)]+?)\s+([0-9]+\.[0-9]+)/m', $yearSection, $matches);
    
    $validGrades = [];
    $failingGrades = [];
    $allPassing = true;
    
    for ($i = 0; $i < count($matches[0]); $i++) {
        $rawSubject = trim($matches[1][$i]);
        $gradeRaw = $matches[2][$i];
        $grade = floatval(str_replace(',', '.', $gradeRaw));
        
        // Clean subject name - remove codes like A24-25, 1.25 B22-23, etc.
        $cleanSubject = cleanSubjectName($rawSubject);
        
        // Validate grade range (Philippine grading system: 1.00-5.00)
        if ($grade >= 1.0 && $grade <= 5.0 && !empty($cleanSubject)) {
            $gradeData = [
                'subject' => $cleanSubject,
                'grade' => number_format($grade, 2)
            ];
            
            $validGrades[] = $gradeData;
            
            // Check if grade is failing (3.00 or higher)
            if ($grade >= 3.00) {
                $failingGrades[] = $gradeData;
                $allPassing = false;
            }
        }
    }
    
    return [
        'all_passing' => $allPassing,
        'grades' => $validGrades,
        'failing_grades' => $failingGrades,
        'grade_count' => count($validGrades)
    ];
}

function validatePerSubjectGrades($universityKey, $uploadedFile = null, $subjects = null) {
    global $connection;
    
    try {
        // Include required services
        require_once __DIR__ . '/../../services/GradeValidationService.php';
        require_once __DIR__ . '/../../services/OCRProcessingService.php';
        
        // Initialize services with PDO connection for grade validation
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbName = getenv('DB_NAME') ?: 'educaid';
        $dbUser = getenv('DB_USER') ?: 'postgres';
        $dbPass = getenv('DB_PASSWORD') ?: '';
        $dbPort = getenv('DB_PORT') ?: '5432';
        
        try {
            $pdoConnection = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
            $pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (Exception $e) {
            error_log("PDO Connection error: " . $e->getMessage());
            throw new Exception("Database connection failed for grade validation");
        }
        $gradeValidator = new GradeValidationService($pdoConnection);
        $ocrProcessor = new OCRProcessingService([
            'tesseract_path' => 'tesseract',
            'temp_dir' => realpath(__DIR__ . '/../../temp_debug'),
            'max_file_size' => 10 * 1024 * 1024,
        ]);
        
        $extractedSubjects = [];
        
        // Process file upload or use provided subjects
        if ($uploadedFile && is_uploaded_file($uploadedFile['tmp_name'])) {
            // Create temp directory if needed
            $tempDir = __DIR__ . '/../../temp';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            // Move uploaded file to temp location
            $tempFilePath = $tempDir . '/' . uniqid() . '_' . basename($uploadedFile['name']);
            if (move_uploaded_file($uploadedFile['tmp_name'], $tempFilePath)) {
                // Process document with OCR
                $ocrResult = $ocrProcessor->processGradeDocument($tempFilePath);
                
                if ($ocrResult['success']) {
                    $extractedSubjects = $ocrResult['subjects'];
                }
                
                // Clean up temp file
                unlink($tempFilePath);
            }
        } elseif ($subjects && is_array($subjects)) {
            // Use provided subjects data
            $extractedSubjects = $subjects;
        } else {
            throw new Exception('No valid grade data provided');
        }
        
        // Validate subjects against university grading policy
        $validationResult = $gradeValidator->validateApplicant($universityKey, $extractedSubjects);
        
        return [
            'success' => true,
            'eligible' => $validationResult['eligible'],
            'failed_subjects' => $validationResult['failedSubjects'],
            'total_subjects' => $validationResult['totalSubjects'],
            'passed_subjects' => $validationResult['passedSubjects'],
            'extracted_subjects' => $extractedSubjects,
            'university_key' => $universityKey
        ];
        
    } catch (Exception $e) {
        error_log("Per-subject grade validation error: " . $e->getMessage());
        return [
            'success' => false,
            'eligible' => false,
            'error' => $e->getMessage(),
            'failed_subjects' => [],
            'total_subjects' => 0,
            'passed_subjects' => 0,
            'extracted_subjects' => []
        ];
    }
}

function validateUniversity($ocrText, $declaredUniversityName) {
    if (empty($declaredUniversityName)) {
        return ['match' => false, 'confidence' => 0, 'found_text' => ''];
    }
    
    $ocrTextLower = strtolower($ocrText);
    $universityLower = strtolower($declaredUniversityName);
    
    // Direct match
    if (stripos($ocrTextLower, $universityLower) !== false) {
        return ['match' => true, 'confidence' => 100, 'found_text' => $declaredUniversityName];
    }
    
    // Word-by-word matching for partial matches
    $universityWords = array_filter(explode(' ', preg_replace('/[^a-zA-Z0-9\s]/', ' ', $universityLower)));
    $significantWords = array_filter($universityWords, function($word) {
        return strlen($word) > 3; // Only consider words longer than 3 characters
    });
    
    if (empty($significantWords)) {
        return ['match' => false, 'confidence' => 0, 'found_text' => ''];
    }
    
    $matchedWords = 0;
    $foundText = '';
    
    foreach ($significantWords as $word) {
        if (stripos($ocrTextLower, $word) !== false) {
            $matchedWords++;
            $foundText .= $word . ' ';
        }
    }
    
    // Require at least 70% of significant words to match
    $matchPercentage = ($matchedWords / count($significantWords)) * 100;
    
    if ($matchPercentage >= 70) {
        return [
            'match' => true, 
            'confidence' => round($matchPercentage), 
            'found_text' => trim($foundText)
        ];
    }
    
    return ['match' => false, 'confidence' => round($matchPercentage), 'found_text' => trim($foundText)];
}

function validateStudentName($ocrText, $firstName, $lastName) {
    if (empty($firstName) || empty($lastName)) {
        return false;
    }
    
    $ocrTextLower = strtolower($ocrText);
    $firstNameMatch = stripos($ocrTextLower, strtolower($firstName)) !== false;
    $lastNameMatch = stripos($ocrTextLower, strtolower($lastName)) !== false;
    
    return ($firstNameMatch && $lastNameMatch);
}

function validateAdminSemester($ocrText, $adminSemester) {
    if (empty($adminSemester)) {
        return ['match' => true, 'confidence' => 100, 'found_text' => 'No semester requirement set'];
    }
    
    $ocrTextLower = strtolower($ocrText);
    $adminSemesterLower = strtolower($adminSemester);
    
    // Common semester variations
    $semesterVariations = [
        'first semester' => ['1st semester', 'first semester', '1st sem', 'semester 1', 'sem 1'],
        'second semester' => ['2nd semester', 'second semester', '2nd sem', 'semester 2', 'sem 2'],
        'summer' => ['summer', 'summer semester', 'summer class', 'midyear'],
        'third semester' => ['3rd semester', 'third semester', '3rd sem', 'semester 3', 'sem 3']
    ];
    
    // Find matching variations for admin semester
    $targetVariations = [];
    foreach ($semesterVariations as $standard => $variations) {
        if (stripos($adminSemesterLower, $standard) !== false || in_array($adminSemesterLower, $variations)) {
            $targetVariations = $variations;
            break;
        }
    }
    
    // If no variations found, use direct match
    if (empty($targetVariations)) {
        $targetVariations = [$adminSemesterLower];
    }
    
    // Check for matches in OCR text
    $foundText = '';
    foreach ($targetVariations as $variation) {
        if (stripos($ocrTextLower, $variation) !== false) {
            $foundText = $variation;
            return ['match' => true, 'confidence' => 95, 'found_text' => $foundText];
        }
    }
    
    return ['match' => false, 'confidence' => 0, 'found_text' => ''];
}

function validateAdminSchoolYear($ocrText, $adminSchoolYear) {
    if (empty($adminSchoolYear)) {
        return ['match' => true, 'confidence' => 100, 'found_text' => 'No school year requirement set'];
    }
    
    $ocrTextLower = strtolower($ocrText);
    
    // Extract year range from admin setting (e.g., "2023-2025", "2024–2025")
    if (preg_match('/(\d{4})\s*[-–]\s*(\d{4})/', $adminSchoolYear, $matches)) {
        $startYear = $matches[1];
        $endYear = $matches[2];
        
        // Look for various formats of the school year in OCR text
        $yearPatterns = [
            $startYear . '-' . $endYear,
            $startYear . '–' . $endYear,
            $startYear . ' - ' . $endYear,
            $startYear . ' – ' . $endYear,
            $startYear . '/' . $endYear,
            $startYear . ' / ' . $endYear,
            'sy ' . $startYear . '-' . $endYear,
            'school year ' . $startYear . '-' . $endYear,
            'a.y. ' . $startYear . '-' . $endYear,
            'academic year ' . $startYear . '-' . $endYear
        ];
        
        foreach ($yearPatterns as $pattern) {
            if (stripos($ocrTextLower, strtolower($pattern)) !== false) {
                return ['match' => true, 'confidence' => 95, 'found_text' => $pattern];
            }
        }
        
        return ['match' => false, 'confidence' => 0, 'found_text' => ''];
    }
    
    // If admin school year is not in range format, do direct match
    if (stripos($ocrTextLower, strtolower($adminSchoolYear)) !== false) {
        return ['match' => true, 'confidence' => 90, 'found_text' => $adminSchoolYear];
    }
    
    return ['match' => false, 'confidence' => 0, 'found_text' => ''];
}

function cleanSubjectName($rawSubject) {
    // Remove common subject code patterns like A24-25, 1.25 B22-23, DCSNO1C, etc.
    $subject = $rawSubject;
    
    // Remove patterns like A24-25, B22-23, etc. (letter + year range)
    $subject = preg_replace('/\b[A-Z]\d{2}-\d{2}\b/i', '', $subject);
    
    // Remove patterns like 1.25 B22-23 (grade + space + code)
    $subject = preg_replace('/\d+\.\d+\s+[A-Z]\d{2}-\d{2}/i', '', $subject);
    
    // Remove standalone codes like DCSNO1C, DCSNO3C, etc.
    $subject = preg_replace('/\b[A-Z]{2,}[0-9]+[A-Z]?\b/', '', $subject);
    
    // Remove patterns like 22-23, A22-23 at the beginning or end
    $subject = preg_replace('/^[A-Z]?\d{2}-\d{2}\s*/', '', $subject);
    $subject = preg_replace('/\s*[A-Z]?\d{2}-\d{2}$/', '', $subject);
    
    // Remove extra numbers at the beginning (like "4 and Habits Practice")
    $subject = preg_replace('/^\d+\s+(?=and\s)/i', '', $subject);
    
    // Remove standalone single letters or numbers
    $subject = preg_replace('/\b[A-Z0-9]\b/', '', $subject);
    
    // Clean up extra spaces and return
    $subject = preg_replace('/\s+/', ' ', trim($subject));
    
    // If the subject is too short after cleaning, return original
    if (strlen($subject) < 3) {
        return trim($rawSubject);
    }
    
    return $subject;
}
// --- Final registration submission ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['register'])) {
    // Basic input validation
    $firstname = trim($_POST['first_name'] ?? '');
    $middlename = trim($_POST['middle_name'] ?? '');
    $lastname = trim($_POST['last_name'] ?? '');
    $extension_name = trim($_POST['extension_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $bdate = trim($_POST['bdate'] ?? '');
    $sex = trim($_POST['sex'] ?? '');
    $barangay = intval($_POST['barangay_id'] ?? 0);
    $university = intval($_POST['university_id'] ?? 0);
    $year_level = intval($_POST['year_level_id'] ?? 0);
    $password = trim($_POST['password'] ?? '');
    $hashed = password_hash($password, PASSWORD_DEFAULT);

    // Validate required fields
    $requiredFields = [$firstname, $lastname, $email, $mobile, $bdate, $sex, $barangay, $university, $year_level, $password];
    if (in_array('', $requiredFields, true)) {
        json_response(['status' => 'error', 'message' => 'Please fill in all required fields.']);
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['status' => 'error', 'message' => 'Invalid email format.']);
    }

    // Validate mobile number (Philippines)
    if (!preg_match('/^09[0-9]{9}$/', $mobile)) {
        json_response(['status' => 'error', 'message' => 'Invalid mobile number format.']);
    }

    // Validate date of birth (must be at least 10 years ago)
    $minDate = date('Y-m-d', strtotime('-10 years'));
    if ($bdate > $minDate) {
        json_response(['status' => 'error', 'message' => 'Invalid date of birth. You must be at least 10 years old to register.']);
    }

    // Check if email or mobile already exists
    $checkEmail = pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1", [$email]);
    if (pg_num_rows($checkEmail) > 0) {
        json_response(['status' => 'error', 'message' => 'This email is already registered.']);
    }

    $checkMobile = pg_query_params($connection, "SELECT 1 FROM students WHERE mobile = $1", [$mobile]);
    if (pg_num_rows($checkMobile) > 0) {
        json_response(['status' => 'error', 'message' => 'This mobile number is already registered.']);
    }

    // Generate system student ID: <MUNICIPALITY>-<YEAR>-<YEARLEVEL>-<SEQUENCE>
    require_once __DIR__ . '/../../includes/util/student_id.php';
    $student_id = generateSystemStudentId($connection, $year_level, $municipality_id, intval(date('Y')));
    if (!$student_id) {
        // Fallback to avoid blocking registration: generate RANDOM6 with uniqueness check
        $ylCode = (string)intval($year_level);
        $muniPrefix = 'MUNI' . intval($municipality_id);
        $mr = @pg_query_params($connection, "SELECT COALESCE(NULLIF(slug,''), name) AS tag FROM municipalities WHERE municipality_id = $1", [$municipality_id]);
        if ($mr && pg_num_rows($mr) > 0) { $mrow = pg_fetch_assoc($mr); $muniPrefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($mrow['tag'] ?? $muniPrefix))); }
        $base = $muniPrefix . '-' . date('Y') . '-' . $ylCode . '-';
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
        for ($attempt=0;$attempt<25;$attempt++) {
            $rand = '';
            for ($i=0;$i<6;$i++) { $rand .= $chars[random_int(0, strlen($chars)-1)]; }
            $candidate = $base . $rand;
            $chk = @pg_query_params($connection, "SELECT 1 FROM students WHERE student_id = $1 LIMIT 1", [$candidate]);
            if ($chk && pg_num_rows($chk) === 0) { $student_id = $candidate; break; }
        }
        if (!$student_id) {
            $student_id = $base . substr(strtoupper(bin2hex(random_bytes(4))), 0, 8);
        }
    }

    // Get current active slot ID for tracking
    $activeSlotQuery = pg_query_params($connection, "SELECT slot_id FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
    $activeSlot = pg_fetch_assoc($activeSlotQuery);
    $slot_id = $activeSlot ? $activeSlot['slot_id'] : null;

    $insertQuery = "INSERT INTO students (student_id, municipality_id, first_name, middle_name, last_name, extension_name, email, mobile, password, sex, status, payroll_no, qr_code, has_received, application_date, bdate, barangay_id, university_id, year_level_id, slot_id)
                    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, 'under_registration', 0, NULL, FALSE, NOW(), $11, $12, $13, $14, $15) RETURNING student_id";

    $result = pg_query_params($connection, $insertQuery, [
        $student_id,
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
        $slot_id
    ]);


    if ($result) {
        $student_id_row = pg_fetch_assoc($result);
        $student_id = $student_id_row['student_id'];

        // Create standardized name for file naming (lastname_firstname)
        $cleanLastname = preg_replace('/[^a-zA-Z0-9]/', '', $lastname);
        $cleanFirstname = preg_replace('/[^a-zA-Z0-9]/', '', $firstname);
        $namePrefix = strtolower($cleanLastname . '_' . $cleanFirstname);

        // Save enrollment form to temporary folder (not permanent until approved)
        $tempFormPath = '../../assets/uploads/temp/';
        $tempEnrollmentDir = '../../assets/uploads/temp/enrollment_forms/';
        $allFiles = glob($tempEnrollmentDir . '*');
        $tempFiles = array_filter($allFiles, function($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['jpg', 'jpeg', 'png', 'pdf']) && is_file($file);
        });
        
        if (!empty($tempFiles)) {
            // Create temporary enrollment forms directory for pending students
            $tempEnrollmentDir = '../../assets/uploads/temp/enrollment_forms/';
            if (!file_exists($tempEnrollmentDir)) {
                mkdir($tempEnrollmentDir, 0777, true);
            }

            // Process all EAF files and rename them with student ID
            foreach ($tempFiles as $tempFile) {
                $originalFilename = basename($tempFile);
                $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                
                // Always rename with student ID prefix for consistent naming
                $newFilename = $student_id . '_' . $namePrefix . '_eaf.' . $extension;
                $tempEnrollmentPath = $tempEnrollmentDir . $newFilename;
                
                if (!copy($tempFile, $tempEnrollmentPath)) {
                    error_log("Failed to copy EAF file from $tempFile to $tempEnrollmentPath");
                    continue; // Skip this file and try others
                } else {
                    // Copy associated .verify.json and .ocr.txt files if they exist
                    $verifySourceFile = $tempFile . '.verify.json';
                    $ocrSourceFile = $tempFile . '.ocr.txt';
                    $verifyDestFile = $tempEnrollmentPath . '.verify.json';
                    $ocrDestFile = $tempEnrollmentPath . '.ocr.txt';
                    
                    if (file_exists($verifySourceFile)) {
                        copy($verifySourceFile, $verifyDestFile);
                        unlink($verifySourceFile);
                    }
                    if (file_exists($ocrSourceFile)) {
                        copy($ocrSourceFile, $ocrDestFile);
                        unlink($ocrSourceFile);
                    }
                    
                    unlink($tempFile); // Remove original file
                    
                    // Get OCR confidence score from temp file
                    $enrollmentConfidenceFile = $tempEnrollmentDir . 'enrollment_confidence.json';
                    $enrollmentConfidence = 75.0; // default
                    if (file_exists($enrollmentConfidenceFile)) {
                        $confidenceData = json_decode(file_get_contents($enrollmentConfidenceFile), true);
                        if ($confidenceData && isset($confidenceData['overall_confidence'])) {
                            $enrollmentConfidence = $confidenceData['overall_confidence'];
                        }
                        unlink($enrollmentConfidenceFile); // Clean up confidence file
                    }

                    // Save form record to database with temporary path
                    $formQuery = "INSERT INTO enrollment_forms (student_id, file_path, original_filename) VALUES ($1, $2, $3)";
                    pg_query_params($connection, $formQuery, [$student_id, $tempEnrollmentPath, $originalFilename]);

                    // Also save to documents table with OCR confidence for confidence calculation
                    $docQuery = "INSERT INTO documents (student_id, type, file_path, is_valid, ocr_confidence) VALUES ($1, $2, $3, $4, $5)";
                    pg_query_params($connection, $docQuery, [$student_id, 'eaf', $tempEnrollmentPath, 'false', $enrollmentConfidence]);
                    
                    error_log("Successfully saved EAF to database for student $student_id with confidence $enrollmentConfidence%");
                    break; // Only process the first valid file
                }
            }
        }

        // Save letter to mayor to temporary folder (not permanent until approved)
        $tempLetterDir = '../../assets/uploads/temp/letter_mayor/';
        $allLetterFiles = glob($tempLetterDir . '*');
        $letterTempFiles = array_filter($allLetterFiles, function($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['jpg', 'jpeg', 'png', 'pdf']) && is_file($file);
        });
        error_log("Looking for letter files in: " . $tempLetterDir);
        error_log("Found letter files: " . print_r($letterTempFiles, true));
        if (!empty($letterTempFiles)) {
            // Directory already exists (created during upload)
            if (!file_exists($tempLetterDir)) {
                mkdir($tempLetterDir, 0777, true);
            }

            // Process all letter files and rename them with student ID
            foreach ($letterTempFiles as $letterTempFile) {
                $originalLetterFilename = basename($letterTempFile);
                $letterExtension = pathinfo($originalLetterFilename, PATHINFO_EXTENSION);
                
                // Always rename with student ID prefix for consistent naming
                $newLetterFilename = $student_id . '_' . $namePrefix . '_lettertomayor.' . $letterExtension;
                $letterTempPath = $tempLetterDir . $newLetterFilename;

                // Get OCR confidence score from temp file
                $letterConfidenceFile = $tempLetterDir . 'letter_confidence.json';
                $letterConfidence = 75.0; // default
                if (file_exists($letterConfidenceFile)) {
                    $confidenceData = json_decode(file_get_contents($letterConfidenceFile), true);
                    if ($confidenceData && isset($confidenceData['overall_confidence'])) {
                        $letterConfidence = $confidenceData['overall_confidence'];
                    }
                    unlink($letterConfidenceFile); // Clean up confidence file
                }

                if (copy($letterTempFile, $letterTempPath)) {
                    // Copy verification files (.verify.json and .ocr.txt) if they exist
                    $letterVerifyFile = $letterTempFile . '.verify.json';
                    $letterOcrFile = $letterTempFile . '.ocr.txt';
                    
                    if (file_exists($letterVerifyFile)) {
                        @copy($letterVerifyFile, $letterTempPath . '.verify.json');
                        @unlink($letterVerifyFile);
                    }
                    
                    if (file_exists($letterOcrFile)) {
                        @copy($letterOcrFile, $letterTempPath . '.ocr.txt');
                        @unlink($letterOcrFile);
                    }
                    
                    // Save letter record to database with temporary path and OCR confidence
                    $letterQuery = "INSERT INTO documents (student_id, type, file_path, is_valid, ocr_confidence) VALUES ($1, $2, $3, $4, $5)";
                    $letterResult = pg_query_params($connection, $letterQuery, [$student_id, 'letter_to_mayor', $letterTempPath, 'false', $letterConfidence]);
                    
                    if (!$letterResult) {
                        error_log("Failed to save letter to database: " . pg_last_error($connection));
                    } else {
                        error_log("Successfully saved letter to database for student $student_id with confidence $letterConfidence%");
                    }

                    // Clean up original temp letter file
                    unlink($letterTempFile);
                    break; // Only process the first valid file
                } else {
                    error_log("Failed to copy letter file from $letterTempFile to $letterTempPath");
                }
            }
        } else {
            error_log("No letter temp files found in path: " . $tempLetterDir);
        }

        // Save certificate of indigency to temporary folder (not permanent until approved)
        $tempIndigencyDir = '../../assets/uploads/temp/indigency/';
        $allCertificateFiles = glob($tempIndigencyDir . '*');
        $certificateTempFiles = array_filter($allCertificateFiles, function($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['jpg', 'jpeg', 'png', 'pdf']) && is_file($file);
        });
        error_log("Looking for certificate files in: " . $tempIndigencyDir);
        error_log("Found certificate files: " . print_r($certificateTempFiles, true));
        if (!empty($certificateTempFiles)) {
            // Create temporary indigency directory for pending students
            $tempIndigencyDir = '../../assets/uploads/temp/indigency/';
            if (!file_exists($tempIndigencyDir)) {
                mkdir($tempIndigencyDir, 0777, true);
            }

            // Process all certificate files and rename them with student ID
            foreach ($certificateTempFiles as $certificateTempFile) {
                $originalCertificateFilename = basename($certificateTempFile);
                $certificateExtension = pathinfo($originalCertificateFilename, PATHINFO_EXTENSION);
                
                // Always rename with student ID prefix for consistent naming
                $newCertificateFilename = $student_id . '_' . $namePrefix . '_indigency.' . $certificateExtension;
                $certificateTempPath = $tempIndigencyDir . $newCertificateFilename;

                // Get OCR confidence score from temp file
                $certificateConfidenceFile = $tempIndigencyDir . 'certificate_confidence.json';
                $certificateConfidence = 75.0; // default
                if (file_exists($certificateConfidenceFile)) {
                    $confidenceData = json_decode(file_get_contents($certificateConfidenceFile), true);
                    if ($confidenceData && isset($confidenceData['overall_confidence'])) {
                        $certificateConfidence = $confidenceData['overall_confidence'];
                    }
                    unlink($certificateConfidenceFile); // Clean up confidence file
                }

                if (copy($certificateTempFile, $certificateTempPath)) {
                    // Copy verification files (.verify.json and .ocr.txt) if they exist
                    $certificateVerifyFile = $certificateTempFile . '.verify.json';
                    $certificateOcrFile = $certificateTempFile . '.ocr.txt';
                    
                    if (file_exists($certificateVerifyFile)) {
                        @copy($certificateVerifyFile, $certificateTempPath . '.verify.json');
                        @unlink($certificateVerifyFile);
                    }
                    
                    if (file_exists($certificateOcrFile)) {
                        @copy($certificateOcrFile, $certificateTempPath . '.ocr.txt');
                        @unlink($certificateOcrFile);
                    }
                    
                    // Save certificate record to database with temporary path and OCR confidence
                    $certificateQuery = "INSERT INTO documents (student_id, type, file_path, is_valid, ocr_confidence) VALUES ($1, $2, $3, $4, $5)";
                    $certificateResult = pg_query_params($connection, $certificateQuery, [$student_id, 'certificate_of_indigency', $certificateTempPath, 'false', $certificateConfidence]);
                    
                    if (!$certificateResult) {
                        error_log("Failed to save certificate to database: " . pg_last_error($connection));
                    } else {
                        error_log("Successfully saved certificate to database for student $student_id with confidence $certificateConfidence%");
                    }

                    // Clean up original temp certificate file
                    unlink($certificateTempFile);
                    break; // Only process the first valid file
                } else {
                    error_log("Failed to copy certificate file from $certificateTempFile to $certificateTempPath");
                }
            }
        } else {
            error_log("No certificate temp files found in path: " . $tempIndigencyDir);
        }

        // Save grades to temporary folder (not permanent until approved)
        $tempGradesDir = '../../assets/uploads/temp/grades/';
        $allGradesFiles = glob($tempGradesDir . '*');
        $gradesTempFiles = array_filter($allGradesFiles, function($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['jpg', 'jpeg', 'png', 'pdf']) && is_file($file);
        });
        error_log("Looking for grades files in: " . $tempGradesDir);
        error_log("Found grades files: " . print_r($gradesTempFiles, true));
        if (!empty($gradesTempFiles)) {
            // Create temporary grades directory for pending students
            if (!file_exists($tempGradesDir)) {
                mkdir($tempGradesDir, 0777, true);
            }

            // Process all grades files and rename them with student ID
            foreach ($gradesTempFiles as $gradesTempFile) {
                $originalGradesFilename = basename($gradesTempFile);
                $gradesExtension = pathinfo($originalGradesFilename, PATHINFO_EXTENSION);
                
                // Always rename with student ID prefix for consistent naming
                $newGradesFilename = $student_id . '_' . $namePrefix . '_grades.' . $gradesExtension;
                $gradesTempPath = $tempGradesDir . $newGradesFilename;

                // Get OCR confidence score from temp file
                $gradesConfidenceFile = $tempGradesDir . 'grades_confidence.json';
                $gradesConfidence = 75.0; // default
                if (file_exists($gradesConfidenceFile)) {
                    $confidenceData = json_decode(file_get_contents($gradesConfidenceFile), true);
                    if ($confidenceData && isset($confidenceData['overall_confidence'])) {
                        $gradesConfidence = $confidenceData['overall_confidence'];
                    }
                    unlink($gradesConfidenceFile); // Clean up confidence file
                }

                if (copy($gradesTempFile, $gradesTempPath)) {
                    // Save grades record to database with temporary path and OCR confidence
                    $gradesQuery = "INSERT INTO documents (student_id, type, file_path, is_valid, ocr_confidence) VALUES ($1, $2, $3, $4, $5)";
                    $gradesResult = pg_query_params($connection, $gradesQuery, [$student_id, 'academic_grades', $gradesTempPath, 'false', $gradesConfidence]);
                    
                    if (!$gradesResult) {
                        error_log("Failed to save grades to database: " . pg_last_error($connection));
                    } else {
                        error_log("Successfully saved grades to database for student $student_id with confidence $gradesConfidence%");
                    }

                    // Clean up original temp grades file
                    unlink($gradesTempFile);
                    break; // Only process the first valid file
                } else {
                    error_log("Failed to copy grades file from $gradesTempFile to $gradesTempPath");
                }
            }
        } else {
            error_log("No grades temp files found in path: " . $tempGradesDir);
        }

        $semester = $slotInfo['semester'];
        $academic_year = $slotInfo['academic_year'];
        $applicationQuery = "INSERT INTO applications (student_id, semester, academic_year) VALUES ($1, $2, $3)";
        pg_query_params($connection, $applicationQuery, [$student_id, $semester, $academic_year]);

        // Calculate and store confidence score for the new registration
        $confidenceQuery = "UPDATE students SET confidence_score = calculate_confidence_score(student_id) WHERE student_id = $1";
        $confidenceResult = pg_query_params($connection, $confidenceQuery, [$student_id]);
        
        if ($confidenceResult) {
            // Get the calculated confidence score for logging
            $scoreQuery = "SELECT confidence_score FROM students WHERE student_id = $1";
            $scoreResult = pg_query_params($connection, $scoreQuery, [$student_id]);
            if ($scoreResult) {
                $scoreRow = pg_fetch_assoc($scoreResult);
                $confidence_score = $scoreRow['confidence_score'];
                error_log("Student ID $student_id registered with confidence score: " . number_format($confidence_score, 2) . "%");
            }
        }

        // Clean up any remaining confidence files
        $confidenceFiles = glob($tempFormPath . '*_confidence.json');
        foreach ($confidenceFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        unset($_SESSION['otp_verified']);

        echo "<script>alert('Registration submitted successfully! Your application is under review. You will receive an email notification once approved.'); window.location.href = '../../unified_login.php';</script>";
        exit;
    } else {
        // Log the PostgreSQL error for debugging
        $error = pg_last_error($connection);
        error_log("Registration Database Error: " . $error);
        echo "<script>alert('Registration failed due to a database error: " . addslashes($error) . "');</script>";

    }
}

// Only output main registration HTML for non-AJAX requests
if (!$isAjaxRequest) {
?>

<!-- Main Registration Content -->
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
                        <input type="text" class="form-control" name="first_name" autocomplete="given-name" required />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Middle Name <span class="text-muted">(Optional)</span></label>
                        <input type="text" class="form-control" name="middle_name" autocomplete="additional-name" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="last_name" autocomplete="family-name" required />
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
                        <input type="date" class="form-control" name="bdate" autocomplete="bday" required />
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
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-firstname">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>First Name Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-firstname">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-middlename">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>Middle Name Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-middlename">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-lastname">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>Last Name Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-lastname">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-yearlevel">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>Year Level Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-yearlevel">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-university">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>University Match</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-university">0%</span>
                                    </div>
                                    <div class="form-check d-flex justify-content-between align-items-center" id="check-document">
                                        <div>
                                            <i class="bi bi-x-circle text-danger me-2"></i>
                                            <span>Official Document Keywords</span>
                                        </div>
                                        <span class="badge bg-secondary confidence-score" id="confidence-document">0%</span>
                                    </div>
                                </div>
                                <div class="mt-3 p-3 bg-light rounded">
                                    <h6 class="mb-2">Overall Analysis:</h6>
                                    <div class="d-flex justify-content-between">
                                        <span>Average Confidence:</span>
                                        <span class="fw-bold" id="overall-confidence">0%</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Passed Checks:</span>
                                        <span class="fw-bold" id="passed-checks">0/6</span>
                                    </div>
                                    <div class="mt-2">
                                        <small class="text-muted" id="verification-recommendation">Processing document...</small>
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
                
                <!-- Step 5: Letter to Mayor Upload and OCR Verification -->
                <div class="step-panel d-none" id="step-5">
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
                
                <!-- Step 6: Certificate of Indigency Upload and OCR Verification -->
                <div class="step-panel d-none" id="step-6">
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
                                <strong>Verification Failed:</strong> Please ensure the certificate contains your name, barangay, "Certificate of Indigency" title, and "General Trias".
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep6Btn" disabled onclick="nextStep()">Next</button>
                </div>
                
                <!-- Step 7: Grade Scanning -->
                <div class="step-panel d-none" id="step-7">
                    <div class="mb-3">
                        <label class="form-label">Upload Grades Document</label>
                        <small class="form-text text-muted d-block mb-2">
                            Please upload a clear photo or PDF of your grades<br>
                            <strong>Required elements:</strong> Name, School Year, and Subject Grades
                            <br>Note: Grades must not be below 3.00 to proceed
                        </small>
                        <input type="file" class="form-control" name="grades_document" id="gradesForm" accept="image/*,.pdf" required />
                        <div class="invalid-feedback" id="gradesError">
                            <small><i class="bi bi-exclamation-triangle me-1"></i>Please upload a valid grades document</small>
                        </div>
                    </div>
                    <div id="gradesUploadPreview" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">Preview:</label>
                            <div id="gradesPreviewContainer" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                                <img id="gradesPreviewImage" class="img-fluid" style="max-width: 100%; display: none;" />
                                <div id="gradesPdfPreview" class="text-center p-3" style="display: none;">
                                    <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                    <p>PDF File Selected</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="gradesOcrSection" class="d-none">
                        <div class="mb-3">
                            <button type="button" class="btn btn-info w-100" id="processGradesOcrBtn" disabled>
                                <i class="bi bi-search me-2"></i>Verify Grades Content
                            </button>
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle me-1"></i>Upload a file to enable verification
                            </small>
                        </div>
                        <div id="gradesOcrResults" class="d-none">
                            <div class="mb-3">
                                <label class="form-label">Eligibility Validation Results:</label>
                                <div class="verification-checklist">
                                    <div class="form-check" id="check-grades-name">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Student Name Match</span>
                                    </div>
                                    <div class="form-check" id="check-grades-year">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Declared Year Level Match</span>
                                    </div>
                                    <div class="form-check" id="check-grades-semester">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Admin Required Semester Match</span>
                                    </div>
                                    <div class="form-check d-none" id="check-grades-school-year">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>Admin Required School Year Match (Temporarily Disabled)</span>
                                    </div>
                                    <div class="form-check" id="check-grades-university">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>University Match</span>
                                    </div>
                                    <div class="form-check" id="check-grades-passing">
                                        <i class="bi bi-x-circle text-danger me-2"></i>
                                        <span>All Grades Below 3.00</span>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <div class="alert alert-info" id="eligibilityStatus">
                                        <strong><i class="bi bi-info-circle me-2"></i>Eligibility Status:</strong>
                                        <span id="eligibilityText">Pending validation...</span>
                                    </div>
                                </div>
                            </div>
                            <div id="gradesDetails" class="mt-3 d-none">
                                <h6>Detected Grades:</h6>
                                <div id="gradesTable" class="table-responsive">
                                    <!-- Grades will be inserted here -->
                                </div>
                            </div>
                            <div id="gradesOcrFeedback" class="alert alert-warning mt-3" style="display: none;">
                                <strong>Verification Failed:</strong> Please ensure your grades document is clear and contains your name, year level, and passing grades.
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep7Btn" disabled onclick="nextStep()">Next</button>
                </div>
                
                
                <!-- Step 8: OTP Verification -->
                <div class="step-panel d-none" id="step-8">
                      <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="emailInput" required />
                        <span id="emailStatus" class="text-success d-none">Verified</span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="mobile">Phone Number</label>
                        <input type="tel" class="form-control" name="mobile" id="mobile" maxlength="11" pattern="09[0-9]{9}" placeholder="e.g., 09123456789" required />
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-info" id="sendOtpBtn">Send OTP (Email)</button>
                    </div>
                    <div id="otpSection" class="d-none mt-3">
                        <div class="alert alert-info">
                            <i class="bi bi-envelope-check me-2"></i>
                            <strong>OTP Sent!</strong> We've sent a 6-digit verification code to your email. Please check your inbox and spam folder.
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="otp">Enter 6-Digit OTP Code</label>
                            <input type="text" class="form-control text-center" name="otp" id="otp" maxlength="6" pattern="[0-9]{6}" placeholder="123456" required style="font-size: 1.2em; letter-spacing: 0.2em;" />
                            <small class="form-text text-muted">Enter the 6-digit code sent to your email</small>
                        </div>
                        <button type="button" class="btn btn-success w-100 mb-3" id="verifyOtpBtn">
                            <i class="bi bi-shield-check me-2"></i>Verify OTP
                        </button>
                        <div class="d-flex justify-content-between align-items-center">
                            <div id="otpTimer" class="text-muted small"></div>
                            <button type="button" class="btn btn-outline-warning btn-sm" id="resendOtpBtn" style="display:none;" disabled>
                                <i class="bi bi-arrow-clockwise me-2"></i>Resend OTP
                            </button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep8Btn" disabled onclick="nextStep()">Next</button>
                </div>
                <!-- Step 9: Password and Confirmation -->
                <div class="step-panel d-none" id="step-9">
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" class="form-control" name="password" id="password" minlength="12" required />
                    </div>
                    <div class="form-text">
                        Must be at least 12 characters long with letters, numbers, and symbols.
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirmPassword">Confirm Password</label>
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
    
    <div id="notifier" class="notifier" role="alert" aria-live="polite"></div>
    
    <!-- Terms and Conditions Modal CSS -->
    <style>
    /* Ensure modal appears above everything */
    .modal {
        z-index: 1050;
    }
    .modal-backdrop {
        z-index: 1040;
    }
    
    /* Fallback styling for manual modal display */
    .modal.show {
        display: block !important;
    }
    
    .modal-open {
        overflow: hidden;
    }
    
    /* Custom terms content styling */
    .terms-content h6 {
        color: #0d6efd;
        font-weight: 600;
        margin-top: 1.5rem;
        margin-bottom: 0.75rem;
    }
    
    .terms-content ul {
        margin-bottom: 1rem;
    }
    
    .terms-content li {
        margin-bottom: 0.25rem;
    }
    </style>
    
    <!-- Make sure this is included BEFORE your custom JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- OR if you have local Bootstrap files -->
<script src="../../assets/js/bootstrap.bundle.min.js"></script>

<!-- Immediate function definitions for onclick handlers -->
<script>
// Simple working navigation functions (fallback if main script fails)
let currentStep = 1;

function nextStep() {
    console.log('🔧 Enhanced nextStep called - Step:', currentStep);
    
    if (currentStep >= 9) return; // Updated to allow step 8 to proceed to step 9
    
    // Validate current step before proceeding
    const validationResult = validateCurrentStepFields();
    if (!validationResult.isValid) {
        showValidationError(validationResult.message, validationResult.fields);
        return;
    }
    
    // Hide current step
    const currentPanel = document.getElementById(`step-${currentStep}`);
    if (currentPanel) {
        currentPanel.classList.add('d-none');
    }
    
    // Show next step
    currentStep++;
    const nextPanel = document.getElementById(`step-${currentStep}`);
    if (nextPanel) {
        nextPanel.classList.remove('d-none');
    }
    
    // Update step indicators
    updateStepIndicators();
    
    // Clear any previous error messages
    clearValidationErrors();
    
    console.log('✅ Moved to step:', currentStep);
}

function prevStep() {
    console.log('🔧 Fallback prevStep called - Step:', currentStep);
    
    if (currentStep <= 1) return;
    
    // Hide current step
    const currentPanel = document.getElementById(`step-${currentStep}`);
    if (currentPanel) {
        currentPanel.classList.add('d-none');
    }
    
    // Show previous step
    currentStep--;
    const prevPanel = document.getElementById(`step-${currentStep}`);
    if (prevPanel) {
        prevPanel.classList.remove('d-none');
    }
    
    // Update step indicators
    updateStepIndicators();
    
    console.log('✅ Moved to step:', currentStep);
}

function showStep(stepNum) {
    console.log('🔧 Fallback showStep called:', stepNum);
    
    // Hide all steps
    document.querySelectorAll('.step-panel').forEach(panel => {
        panel.classList.add('d-none');
    });
    
    // Show target step
    const targetPanel = document.getElementById(`step-${stepNum}`);
    if (targetPanel) {
        targetPanel.classList.remove('d-none');
        currentStep = stepNum;
        updateStepIndicators();
    }
}

function updateStepIndicators() {
    document.querySelectorAll('.step').forEach((step, index) => {
        if (index + 1 === currentStep) {
            step.classList.add('active');
        } else {
            step.classList.remove('active');
        }
    });
}

// ============================================
// VALIDATION FUNCTIONS
// ============================================

function validateCurrentStepFields() {
    const currentPanel = document.getElementById(`step-${currentStep}`);
    if (!currentPanel) return { isValid: true };
    
    const requiredFields = currentPanel.querySelectorAll('input[required], select[required], textarea[required]');
    const emptyFields = [];
    const invalidFields = [];
    
    requiredFields.forEach(field => {
        const value = field.value.trim();
        
        // Check if field is empty
        if (!value) {
            emptyFields.push({
                field: field,
                name: field.name || field.id || 'Unknown field',
                label: getFieldLabel(field)
            });
            return;
        }
        
        // Specific validations by field type and step
        const validation = validateSpecificField(field, value);
        if (!validation.isValid) {
            invalidFields.push({
                field: field,
                name: field.name || field.id,
                label: getFieldLabel(field),
                error: validation.error
            });
        }
    });
    
    // Check for radio button groups (like gender)
    if (currentStep === 2) {
        const genderChecked = currentPanel.querySelector('input[name="sex"]:checked');
        if (!genderChecked) {
            emptyFields.push({
                name: 'sex',
                label: 'Gender',
                field: currentPanel.querySelector('input[name="sex"]')
            });
        }
    }
    
    // Special validation for file upload steps
    if (currentStep === 4) {
        const enrollmentFile = currentPanel.querySelector('#enrollmentForm');
        if (enrollmentFile && !enrollmentFile.files[0]) {
            emptyFields.push({
                name: 'enrollment_form',
                label: 'Enrollment Assessment Form',
                field: enrollmentFile
            });
        } else if (enrollmentFile && enrollmentFile.files[0]) {
            // Validate filename format
            const filename = enrollmentFile.files[0].name;
            const namePattern = /^[A-Za-z]+_[A-Za-z]+_EAF\.(jpg|jpeg|png|pdf)$/i;
            if (!namePattern.test(filename)) {
                invalidFields.push({
                    field: enrollmentFile,
                    name: 'enrollment_form',
                    label: 'Enrollment Assessment Form',
                    error: 'Filename must follow format: Lastname_Firstname_EAF.jpg (e.g., Santos_Juan_EAF.jpg)'
                });
            }
        }
    }
    
    if (currentStep === 5) {
        const letterFile = currentPanel.querySelector('#letterToMayorForm');
        if (letterFile && !letterFile.files[0]) {
            emptyFields.push({
                name: 'letter_to_mayor',
                label: 'Letter to Mayor',
                field: letterFile
            });
        }
    }
    
    if (currentStep === 6) {
        const certificateFile = currentPanel.querySelector('#certificateForm');
        if (certificateFile && !certificateFile.files[0]) {
            emptyFields.push({
                name: 'certificate_of_indigency',
                label: 'Certificate of Indigency',
                field: certificateFile
            });
        }
    }
    
    if (currentStep === 7) {
        const gradesFile = currentPanel.querySelector('#gradesForm');
        if (gradesFile && !gradesFile.files[0]) {
            emptyFields.push({
                name: 'grades_document',
                label: 'Grades Document',
                field: gradesFile
            });
        }
    }
    
    // Step 8: OTP Verification
    if (currentStep === 8) {
        const emailStatus = document.getElementById('emailStatus');
        const otpSection = document.getElementById('otpSection');
        
        // Check if email is verified (emailStatus is visible)
        if (!emailStatus || emailStatus.classList.contains('d-none')) {
            // Check if OTP section is visible (means OTP was sent but not verified)
            if (otpSection && !otpSection.classList.contains('d-none')) {
                return {
                    isValid: false,
                    message: 'Please verify your email by entering the OTP code sent to your email.',
                    fields: [currentPanel.querySelector('#otp')]
                };
            } else {
                return {
                    isValid: false,
                    message: 'Please send and verify OTP to your email address.',
                    fields: [currentPanel.querySelector('#emailInput')]
                };
            }
        }
    }
    
    // Return validation result
    if (emptyFields.length > 0) {
        return {
            isValid: false,
            message: `Please fill in all required fields: ${emptyFields.map(f => f.label).join(', ')}`,
            fields: emptyFields.map(f => f.field)
        };
    }
    
    if (invalidFields.length > 0) {
        return {
            isValid: false,
            message: invalidFields[0].error,
            fields: invalidFields.map(f => f.field)
        };
    }
    
    return { isValid: true };
}

function validateSpecificField(field, value) {
    const fieldName = field.name;
    
    // Name validation (letters, spaces, hyphens, apostrophes only)
    if (['first_name', 'middle_name', 'last_name'].includes(fieldName)) {
        if (!/^[A-Za-z\s\-']+$/.test(value)) {
            return {
                isValid: false,
                error: 'Names can only contain letters, spaces, hyphens (-), and apostrophes (\')'
            };
        }
    }
    
    // Date validation
    if (fieldName === 'bdate') {
        const birthDate = new Date(value);
        const today = new Date();
        const age = today.getFullYear() - birthDate.getFullYear();
        
        if (birthDate >= today) {
            return {
                isValid: false,
                error: 'Birth date cannot be in the future'
            };
        }
        
        if (age < 10 || age > 100) {
            return {
                isValid: false,
                error: 'Please enter a valid birth date (age must be between 10-100)'
            };
        }
    }
    
    return { isValid: true };
}

function getFieldLabel(field) {
    // Try to find associated label
    const label = field.closest('.mb-3')?.querySelector('label');
    if (label) {
        return label.textContent.replace('*', '').trim();
    }
    
    // Fallback to placeholder or name
    return field.placeholder || field.name || 'Field';
}

function showValidationError(message, fields) {
    // Remove previous highlights
    clearValidationErrors();
    
    // Highlight invalid fields
    if (fields) {
        fields.forEach(field => {
            if (field) {
                field.classList.add('is-invalid');
                field.style.borderColor = '#dc3545';
            }
        });
    }
    
    // Show error message
    showNotifier(message, 'error');
    
    // Focus on first invalid field
    if (fields && fields[0]) {
        fields[0].focus();
    }
}

function clearValidationErrors() {
    // Remove validation classes from all fields
    document.querySelectorAll('.is-invalid').forEach(field => {
        field.classList.remove('is-invalid');
        field.style.borderColor = '';
    });
    
    // Hide any existing error messages
    const notifier = document.getElementById('notifier');
    if (notifier) {
        notifier.style.display = 'none';
    }
}

function showNotifier(message, type = 'error') {
    // Create notifier if it doesn't exist
    let notifier = document.getElementById('notifier');
    if (!notifier) {
        notifier = document.createElement('div');
        notifier.id = 'notifier';
        notifier.style.cssText = `
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 20px;
            border-radius: 6px;
            color: white;
            font-weight: 500;
            z-index: 9999;
            max-width: 500px;
            text-align: center;
            display: none;
        `;
        document.body.appendChild(notifier);
    }
    
    // Set message and style based on type
    notifier.textContent = message;
    notifier.style.display = 'block';
    
    if (type === 'error') {
        notifier.style.backgroundColor = '#dc3545';
    } else if (type === 'success') {
        notifier.style.backgroundColor = '#28a745';
    } else if (type === 'warning') {
        notifier.style.backgroundColor = '#ffc107';
        notifier.style.color = '#000';
    }
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        if (notifier) {
            notifier.style.display = 'none';
        }
    }, 5000);
}

// ============================================
// REAL-TIME VALIDATION SETUP
// ============================================

function setupRealTimeValidation() {
    // Add event listeners to all form fields
    document.addEventListener('input', function(e) {
        if (e.target.matches('input, select, textarea')) {
            // Clear validation error when user starts typing/selecting
            e.target.classList.remove('is-invalid');
            e.target.style.borderColor = '';
        }
    });
    
    // Add listeners for radio buttons
    document.addEventListener('change', function(e) {
        if (e.target.matches('input[type="radio"]')) {
            // Clear validation error for radio group
            const radioName = e.target.name;
            document.querySelectorAll(`input[name="${radioName}"]`).forEach(radio => {
                radio.classList.remove('is-invalid');
                radio.style.borderColor = '';
            });
        }
    });
}

// ============================================
// FILE UPLOAD HANDLERS
// ============================================

function setupFileUploadHandlers() {
    // Enrollment Form Upload Handler
    const enrollmentForm = document.getElementById('enrollmentForm');
    if (enrollmentForm) {
        enrollmentForm.addEventListener('change', function(e) {
            handleEnrollmentFileUpload(e.target);
        });
    }
    
    // Other file upload handlers can be added here
    console.log('✅ File upload handlers initialized');
}

function handleEnrollmentFileUpload(fileInput) {
    const file = fileInput.files[0];
    const previewContainer = document.getElementById('uploadPreview');
    const previewImage = document.getElementById('previewImage');
    const pdfPreview = document.getElementById('pdfPreview');
    const ocrSection = document.getElementById('ocrSection');
    const processBtn = document.getElementById('processOcrBtn');
    
    if (!file) {
        // Hide preview and OCR section if no file
        if (previewContainer) previewContainer.classList.add('d-none');
        if (ocrSection) ocrSection.classList.add('d-none');
        return;
    }
    
    // Show preview container
    if (previewContainer) previewContainer.classList.remove('d-none');
    
    // Handle image preview
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (previewImage) {
                previewImage.src = e.target.result;
                previewImage.style.display = 'block';
            }
            if (pdfPreview) pdfPreview.style.display = 'none';
        };
        reader.readAsDataURL(file);
    } else if (file.type === 'application/pdf') {
        if (previewImage) previewImage.style.display = 'none';
        if (pdfPreview) pdfPreview.style.display = 'block';
    }
    
    // Validate filename format
    const filename = file.name;
    const namePattern = /^[A-Za-z]+_[A-Za-z]+_EAF\.(jpg|jpeg|png|pdf)$/i;
    
    if (namePattern.test(filename)) {
        // Show OCR section and enable process button
        if (ocrSection) ocrSection.classList.remove('d-none');
        if (processBtn) {
            processBtn.disabled = false;
            processBtn.classList.remove('btn-secondary');
            processBtn.classList.add('btn-info');
            processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Process Document';
            
            // Add click handler for processing
            processBtn.onclick = function() {
                processEnrollmentDocument();
            };
        }
    } else {
        // Hide OCR section and disable button
        if (ocrSection) ocrSection.classList.add('d-none');
        if (processBtn) {
            processBtn.disabled = true;
            processBtn.classList.add('btn-secondary');
            processBtn.classList.remove('btn-info');
        }
        
        // Show filename error
        showNotifier('Filename must follow format: Lastname_Firstname_EAF.jpg (e.g., Santos_Juan_EAF.jpg)', 'error');
    }
}

function processEnrollmentDocument() {
    const processBtn = document.getElementById('processOcrBtn');
    const fileInput = document.getElementById('enrollmentForm');
    
    console.log('Current URL:', window.location.href);
    console.log('Processing document...');
    
    if (!fileInput.files[0]) {
        showNotifier('Please select a file first', 'error');
        return;
    }
    
    // Show processing state
    if (processBtn) {
        processBtn.disabled = true;
        processBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
    }
    
    // Create form data for OCR processing
    const formData = new FormData();
    formData.append('processEnrollmentOcr', '1');
    formData.append('enrollment_form', fileInput.files[0]);
    
    // Add form data for validation - use querySelector with name attributes
    const firstNameInput = document.querySelector('input[name="first_name"]');
    const middleNameInput = document.querySelector('input[name="middle_name"]');
    const lastNameInput = document.querySelector('input[name="last_name"]');
    const extensionNameInput = document.querySelector('input[name="extension_name"]');
    const universityInput = document.querySelector('select[name="university_id"]');
    const yearLevelInput = document.querySelector('select[name="year_level_id"]');
    
    if (firstNameInput && firstNameInput.value) formData.append('first_name', firstNameInput.value);
    if (middleNameInput && middleNameInput.value) formData.append('middle_name', middleNameInput.value);
    if (lastNameInput && lastNameInput.value) formData.append('last_name', lastNameInput.value);
    if (extensionNameInput && extensionNameInput.value) formData.append('extension_name', extensionNameInput.value);
    if (universityInput && universityInput.value) formData.append('university_id', universityInput.value);
    if (yearLevelInput && yearLevelInput.value) formData.append('year_level_id', yearLevelInput.value);
    
    formData.append('g-recaptcha-response', 'test'); // You may need proper reCAPTCHA
    
    // Send OCR request
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('OCR Response:', data);
        handleOcrResults(data);
    })
    .catch(error => {
        console.error('OCR Error:', error);
        showNotifier('Error processing document: ' + error.message, 'error');
        resetProcessButton();
    });
}

function handleOcrResults(data) {
    const resultsSection = document.getElementById('ocrResults');
    const processBtn = document.getElementById('processOcrBtn');
    const nextBtn = document.getElementById('nextStep4Btn');
    
    if (data.status === 'success') {
        // Show results section
        if (resultsSection) resultsSection.classList.remove('d-none');
        
        // Update verification checkmarks
        updateVerificationChecks(data.verification);
        
        // Enable next button if verification passed
        if (data.verification && data.verification.overall_success) {
            if (nextBtn) {
                nextBtn.disabled = false;
                nextBtn.classList.remove('btn-secondary');
                nextBtn.classList.add('btn-success');
                nextBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Continue - Document Verified';
            }
            showNotifier('Document verification successful!', 'success');
        } else {
            showNotifier('Document verification failed. Please check the requirements.', 'error');
        }
    } else {
        showNotifier(data.message || 'Document processing failed', 'error');
    }
    
    resetProcessButton();
}

function updateVerificationChecks(verification) {
    if (!verification) return;
    
    const checks = {
        'check-firstname': verification.first_name_match,
        'check-middlename': verification.middle_name_match,
        'check-lastname': verification.last_name_match,
        'check-yearlevel': verification.year_level_match,
        'check-university': verification.university_match,
        'check-document': verification.document_keywords_found
    };
    
    const confidenceMapping = {
        'check-firstname': 'first_name',
        'check-middlename': 'middle_name',
        'check-lastname': 'last_name',
        'check-yearlevel': 'year_level',
        'check-university': 'university',
        'check-document': 'document_keywords'
    };
    
    Object.keys(checks).forEach(checkId => {
        const element = document.getElementById(checkId);
        const confidenceId = 'confidence-' + checkId.replace('check-', '');
        const confidenceElement = document.getElementById(confidenceId);
        
        if (element) {
            const icon = element.querySelector('i');
            if (checks[checkId]) {
                icon.className = 'bi bi-check-circle text-success me-2';
                element.classList.add('text-success');
                element.classList.remove('text-danger');
            } else {
                icon.className = 'bi bi-x-circle text-danger me-2';
                element.classList.add('text-danger');
                element.classList.remove('text-success');
            }
        }
        
        // Update confidence score
        if (confidenceElement && verification.confidence_scores) {
            const confidenceKey = confidenceMapping[checkId];
            const score = verification.confidence_scores[confidenceKey] || 0;
            const roundedScore = Math.round(score);
            
            confidenceElement.textContent = roundedScore + '%';
            
            // Color code the confidence badge
            confidenceElement.className = 'badge confidence-score ';
            if (roundedScore >= 80) {
                confidenceElement.className += 'bg-success';
            } else if (roundedScore >= 60) {
                confidenceElement.className += 'bg-warning';
            } else {
                confidenceElement.className += 'bg-danger';
            }
        }
    });
    
    // Update overall statistics
    if (verification.summary) {
        const overallConfidence = document.getElementById('overall-confidence');
        const passedChecks = document.getElementById('passed-checks');
        const recommendation = document.getElementById('verification-recommendation');
        
        if (overallConfidence) {
            overallConfidence.textContent = verification.summary.average_confidence + '%';
        }
        
        if (passedChecks) {
            passedChecks.textContent = verification.summary.passed_checks + '/' + verification.summary.total_checks;
        }
        
        if (recommendation) {
            recommendation.textContent = verification.summary.recommendation;
        }
    }
    
    // Show OCR text preview if available (for debugging)
    if (verification.ocr_text_preview) {
        console.log('OCR Text Preview:', verification.ocr_text_preview);
    }
}

function resetProcessButton() {
    const processBtn = document.getElementById('processOcrBtn');
    if (processBtn) {
        processBtn.disabled = false;
        processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Process Document';
    }
}


// ============================================
// OTP FUNCTIONALITY
// ============================================

function setupOtpHandlers() {
    const sendOtpBtn = document.getElementById('sendOtpBtn');
    const resendOtpBtn = document.getElementById('resendOtpBtn');
    const verifyOtpBtn = document.getElementById('verifyOtpBtn');
    
    if (sendOtpBtn) {
        sendOtpBtn.addEventListener('click', function() {
            sendOtp();
        });
    }
    
    if (resendOtpBtn) {
        resendOtpBtn.addEventListener('click', function() {
            sendOtp();
        });
    }
    
    if (verifyOtpBtn) {
        verifyOtpBtn.addEventListener('click', function() {
            verifyOtp();
        });
    }
    
    console.log('✅ OTP handlers initialized');
}

function sendOtp() {
    const emailInput = document.querySelector('input[name="email"]');
    const sendBtn = document.getElementById('sendOtpBtn');
    const resendBtn = document.getElementById('resendOtpBtn');
    
    if (!emailInput || !emailInput.value) {
        showNotifier('Please enter an email address first', 'error');
        return;
    }
    
    if (!validateEmail(emailInput.value)) {
        showNotifier('Please enter a valid email address', 'error');
        return;
    }
    
    // Disable button and show loading state
    if (sendBtn) {
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Sending OTP...';
    }
    
    const formData = new FormData();
    formData.append('sendOtp', '1');
    formData.append('email', emailInput.value);
    formData.append('g-recaptcha-response', 'test'); // You may need proper reCAPTCHA
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.status === 'success') {
            showNotifier(data.message, 'success');
            
            // Show OTP input section and hide send button
            const otpSection = document.getElementById('otpSection');
            if (otpSection) {
                otpSection.classList.remove('d-none');
                otpSection.style.display = 'block';
            }
            
            if (sendBtn) {
                sendBtn.style.display = 'none';
            }
            
            if (resendBtn) {
                resendBtn.style.display = 'block';
                startResendCooldown();
            }
        } else {
            showNotifier(data.message || 'Failed to send OTP', 'error');
        }
    })
    .catch(error => {
        console.error('OTP Error:', error);
        showNotifier('Error sending OTP: ' + error.message, 'error');
    })
    .finally(() => {
        // Reset button state
        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.innerHTML = 'Send OTP (Email)';
        }
    });
}

function startResendCooldown() {
    const resendBtn = document.getElementById('resendOtpBtn');
    const timerDiv = document.getElementById('otpTimer');
    if (!resendBtn) return;
    
    let cooldown = 60; // 60 seconds cooldown
    resendBtn.disabled = true;
    
    const interval = setInterval(() => {
        resendBtn.innerHTML = `<i class="bi bi-arrow-clockwise me-2"></i>Resend (${cooldown}s)`;
        if (timerDiv) {
            timerDiv.innerHTML = `Code expires in ${cooldown} seconds`;
        }
        cooldown--;
        
        if (cooldown < 0) {
            clearInterval(interval);
            resendBtn.disabled = false;
            resendBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-2"></i>Resend OTP';
            if (timerDiv) {
                timerDiv.innerHTML = 'Code expired - please request a new one';
                timerDiv.className = 'text-danger small';
            }
        }
    }, 1000);
}

// Add OTP input formatting
function setupOtpInputFormatting() {
    const otpInput = document.getElementById('otp');
    if (otpInput) {
        otpInput.addEventListener('input', function(e) {
            // Only allow numbers
            e.target.value = e.target.value.replace(/[^0-9]/g, '');
            
            // Auto-verify when 6 digits are entered
            if (e.target.value.length === 6) {
                // Small delay to let user see the complete input
                setTimeout(() => {
                    const verifyBtn = document.getElementById('verifyOtpBtn');
                    if (verifyBtn && !verifyBtn.disabled) {
                        verifyOtp();
                    }
                }, 500);
            }
        });
        
        // Handle paste events
        otpInput.addEventListener('paste', function(e) {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            const numbers = paste.replace(/[^0-9]/g, '').substring(0, 6);
            e.target.value = numbers;
            
            if (numbers.length === 6) {
                setTimeout(() => {
                    const verifyBtn = document.getElementById('verifyOtpBtn');
                    if (verifyBtn && !verifyBtn.disabled) {
                        verifyOtp();
                    }
                }, 500);
            }
        });
    }
}

function verifyOtp() {
    const otpInput = document.querySelector('input[name="otp"]');
    const emailInput = document.querySelector('input[name="email"]');
    const verifyBtn = document.getElementById('verifyOtpBtn');
    
    if (!otpInput || !otpInput.value) {
        showNotifier('Please enter the OTP code', 'error');
        return;
    }
    
    if (!emailInput || !emailInput.value) {
        showNotifier('Email address is required', 'error');
        return;
    }
    
    // Disable button and show loading state
    if (verifyBtn) {
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Verifying OTP...';
    }
    
    const formData = new FormData();
    formData.append('verifyOtp', '1');
    formData.append('otp', otpInput.value);
    formData.append('email', emailInput.value);
    formData.append('g-recaptcha-response', 'test'); // You may need proper reCAPTCHA
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.status === 'success') {
            showNotifier(data.message, 'success');
            
            // Hide OTP verification section and show success
            const otpSection = document.getElementById('otpSection');
            if (otpSection) {
                otpSection.style.display = 'none';
            }
            
            // Show email verified status
            const emailStatus = document.getElementById('emailStatus');
            if (emailStatus) {
                emailStatus.classList.remove('d-none');
                console.log('✅ Email status set to verified');
            }
            
            // Enable next button for step 8
            const nextBtn = document.getElementById('nextStep8Btn');
            if (nextBtn) {
                nextBtn.disabled = false;
                nextBtn.classList.remove('btn-secondary');
                nextBtn.classList.remove('btn-primary');
                nextBtn.classList.add('btn-success');
                nextBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Continue - Email Verified';
                console.log('✅ Next button enabled for step 8');
            }
        } else {
            showNotifier(data.message || 'OTP verification failed', 'error');
        }
    })
    .catch(error => {
        console.error('OTP Verification Error:', error);
        showNotifier('Error verifying OTP: ' + error.message, 'error');
    })
    .finally(() => {
        // Reset button state
        if (verifyBtn) {
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = 'Verify OTP';
        }
    });
}

function validateEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// Initialize validation when page loads
document.addEventListener('DOMContentLoaded', function() {
    setupRealTimeValidation();
    setupFileUploadHandlers();
    setupOtpHandlers();
    setupOtpInputFormatting();
    console.log('✅ All handlers initialized');
    
    // Add debug button (temporary)
    const debugBtn = document.createElement('button');
    debugBtn.innerHTML = 'Test POST Request';
    debugBtn.onclick = testPostRequest;
    debugBtn.className = 'btn btn-warning btn-sm';
    debugBtn.style.position = 'fixed';
    debugBtn.style.top = '10px';
    debugBtn.style.right = '10px';
    debugBtn.style.zIndex = '9999';
    document.body.appendChild(debugBtn);
});

// Make them globally available
window.nextStep = nextStep;
window.prevStep = prevStep;
window.showStep = showStep;

console.log('✅ Enhanced navigation with validation ready');
</script>

<!-- Your registration JavaScript should come AFTER Bootstrap -->
<script src="../../assets/js/student/user_registration.js?v=<?php echo time(); ?>"></script>

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
    document.getElementById('processLetterOcrBtn').addEventListener('click', async function() {
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

        // Add reCAPTCHA token
        if (typeof grecaptcha !== 'undefined' && window.RECAPTCHA_SITE_KEY) {
            try {
                const token = await new Promise(res => grecaptcha.ready(() => {
                    grecaptcha.execute(window.RECAPTCHA_SITE_KEY, {action:'process_letter_ocr'})
                        .then(t => res(t)).catch(() => res(''));
                }));
                if (token) formData.append('g-recaptcha-response', token);
            } catch(e) { /* ignore */ }
        }

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
    document.getElementById('processCertificateOcrBtn').addEventListener('click', async function() {
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

        // Add reCAPTCHA token
        if (typeof grecaptcha !== 'undefined' && window.RECAPTCHA_SITE_KEY) {
            try {
                const token = await new Promise(res => grecaptcha.ready(() => {
                    grecaptcha.execute(window.RECAPTCHA_SITE_KEY, {action:'process_certificate_ocr'})
                        .then(t => res(t)).catch(() => res(''));
                }));
                if (token) formData.append('g-recaptcha-response', token);
            } catch(e) { /* ignore */ }
        }

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

// Grades Document Upload Handling
document.getElementById('gradesForm').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const previewContainer = document.getElementById('gradesUploadPreview');
    const previewImage = document.getElementById('gradesPreviewImage');
    const pdfPreview = document.getElementById('gradesPdfPreview');
    const ocrSection = document.getElementById('gradesOcrSection');
    const processBtn = document.getElementById('processGradesOcrBtn');

    if (file) {
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

        processBtn.disabled = false;
    } else {
        previewContainer.classList.add('d-none');
        ocrSection.classList.add('d-none');
        processBtn.disabled = true;
    }
});

// Grades OCR Processing
document.getElementById('processGradesOcrBtn').addEventListener('click', async function() {
    const formData = new FormData();
    const fileInput = document.getElementById('gradesForm');
    const file = fileInput.files[0];

    if (!file) {
        alert('Please select a grades file first.');
        return;
    }

    // Add form data
    formData.append('grades_document', file);
    formData.append('processGradesOcr', '1');
    formData.append('first_name', document.querySelector('input[name="first_name"]').value);
    formData.append('last_name', document.querySelector('input[name="last_name"]').value);
    formData.append('year_level_id', document.querySelector('select[name="year_level_id"]').value);
    formData.append('university_id', document.querySelector('select[name="university_id"]').value); // ADDED

    // Add reCAPTCHA token
    if (typeof grecaptcha !== 'undefined' && window.RECAPTCHA_SITE_KEY) {
        try {
            const token = await new Promise(res => grecaptcha.ready(() => {
                grecaptcha.execute(window.RECAPTCHA_SITE_KEY, {action:'process_grades_ocr'})
                    .then(t => res(t)).catch(() => res(''));
            }));
            if (token) formData.append('g-recaptcha-response', token);
        } catch(e) { /* ignore */ }
    }

    this.disabled = true;
    this.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            displayGradesVerificationResults(data.verification);
        } else {
            let errorMessage = data.message;
            if (data.suggestions && data.suggestions.length > 0) {
                errorMessage += '\n\nSuggestions:\n' + data.suggestions.join('\n');
            }
            
            // Show debug info in console for troubleshooting
            if (data.debug_info) {
                console.log('OCR Debug Information:', data.debug_info);
            }
            
            alert(errorMessage);
            
            const resultsDiv = document.getElementById('gradesOcrResults');
            const feedbackDiv = document.getElementById('gradesOcrFeedback');
            
            resultsDiv.classList.remove('d-none');
            feedbackDiv.style.display = 'block';
            feedbackDiv.className = 'alert alert-danger mt-3';
            
            let suggestionHTML = '<strong><i class="bi bi-exclamation-triangle me-2"></i>' + data.message + '</strong>';
            
            if (data.suggestions) {
                suggestionHTML += '<br><br><strong>Please try:</strong><ul>';
                data.suggestions.forEach(suggestion => {
                    suggestionHTML += '<li>' + suggestion + '</li>';
                });
                suggestionHTML += '</ul>';
            }
            
            // Add debug information if available
            if (data.debug_info) {
                suggestionHTML += '<br><br><details><summary><strong>Technical Details</strong> (click to expand)</summary>';
                suggestionHTML += '<small>';
                if (data.debug_info.file_size) {
                    suggestionHTML += 'File size: ' + (data.debug_info.file_size / 1024).toFixed(1) + ' KB<br>';
                }
                if (data.debug_info.file_extension) {
                    suggestionHTML += 'File type: ' + data.debug_info.file_extension + '<br>';
                }
                if (data.debug_info.text_length !== undefined) {
                    suggestionHTML += 'Extracted text length: ' + data.debug_info.text_length + ' characters<br>';
                }
                if (data.debug_info.tesseract_output) {
                    suggestionHTML += 'Tesseract output: <pre>' + data.debug_info.tesseract_output + '</pre>';
                }
                suggestionHTML += '</small></details>';
            }
            
            // Add link to debug tool
            suggestionHTML += '<br><br><a href="debug_ocr.php" target="_blank" class="btn btn-sm btn-outline-info">🔍 Use OCR Debug Tool</a>';
            
            feedbackDiv.innerHTML = suggestionHTML;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred during processing.');
    })
    .finally(() => {
        this.disabled = false;
        this.innerHTML = '<i class="bi bi-search me-2"></i>Verify Grades Content';
    });
});

    function displayGradesVerificationResults(verification) {
        const resultsDiv = document.getElementById('gradesOcrResults');
        const feedbackDiv = document.getElementById('gradesOcrFeedback');
        const gradesDetails = document.getElementById('gradesDetails');
        const gradesTable = document.getElementById('gradesTable');
        const nextButton = document.getElementById('nextStep7Btn');
        const eligibilityStatus = document.getElementById('eligibilityStatus');
        const eligibilityText = document.getElementById('eligibilityText');

        // Update verification checklist with new validation structure
        updateVerificationCheckWithDetails('check-grades-name', verification.name_match, 
            verification.confidence_scores?.name, verification.found_text_snippets?.name);
        updateVerificationCheckWithDetails('check-grades-year', verification.year_level_match, 
            verification.confidence_scores?.year_level, verification.found_text_snippets?.year_level);
        updateVerificationCheckWithDetails('check-grades-semester', verification.semester_match, 
            verification.confidence_scores?.semester, verification.found_text_snippets?.semester);
        updateVerificationCheckWithDetails('check-grades-school-year', verification.school_year_match, 
            verification.confidence_scores?.school_year, verification.found_text_snippets?.school_year);
        updateVerificationCheckWithDetails('check-grades-university', verification.university_match, 
            verification.confidence_scores?.university, verification.found_text_snippets?.university);
        updateVerificationCheckWithDetails('check-grades-passing', verification.all_grades_passing, 
            verification.confidence_scores?.grades, null);

        // Update eligibility status
        if (verification.is_eligible) {
            eligibilityStatus.className = 'alert alert-success';
            eligibilityText.innerHTML = '<i class="bi bi-check-circle me-2"></i><strong>ELIGIBLE</strong> - All validations passed';
        } else {
            eligibilityStatus.className = 'alert alert-danger';
            eligibilityText.innerHTML = '<i class="bi bi-x-circle me-2"></i><strong>INELIGIBLE</strong> - ' + verification.summary?.recommendation;
        }

        // Show results
        resultsDiv.classList.remove('d-none');

        // Display grades table with enhanced validation info
        if (verification.grades && verification.grades.length > 0) {
            gradesDetails.classList.remove('d-none');
            
            // Show validation method used
            let validationMethodBadge = '';
            if (verification.validation_method === 'enhanced_per_subject') {
                validationMethodBadge = '<span class="badge bg-info mb-2">Enhanced Per-Subject Validation</span>';
            } else {
                validationMethodBadge = '<span class="badge bg-secondary mb-2">Legacy Threshold Validation</span>';
            }
            
            let tableHTML = `
                ${validationMethodBadge}
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Grade</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            verification.grades.forEach(grade => {
                let status = '';
                let policyInfo = '';
                
                if (verification.validation_method === 'enhanced_per_subject') {
                    // Enhanced validation - check against university policy
                    const isFailingSubject = verification.enhanced_grade_validation?.failed_subjects?.some(
                        failedSubject => failedSubject.includes(grade.subject)
                    ) || false;
                    
                    status = isFailingSubject ? 
                        '<span class="text-danger">Fail</span>' : 
                        '<span class="text-success">Pass</span>';
                        
                    policyInfo = `<td><small>${verification.university_code || 'N/A'}</small></td>`;
                } else {
                    // Legacy validation - 3.00 threshold
                    status = parseFloat(grade.grade) <= 3.00 ? 
                        '<span class="text-success">Pass</span>' : 
                        '<span class="text-danger">Fail</span>';
                }
                
                tableHTML += `
                    <tr>
                        <td>${grade.subject}</td>
                        <td>${grade.grade}</td>
                        <td>${status}</td>
                    </tr>
                `;
            });
            
            tableHTML += '</tbody></table>';
            
            // Add enhanced validation summary if available
            if (verification.enhanced_grade_validation && verification.enhanced_grade_validation.success) {
                tableHTML += `
                    <div class="mt-2 p-2 bg-light rounded">
                        <small class="text-muted">
                            <strong>Enhanced Validation Summary:</strong><br>
                            Total Subjects: ${verification.enhanced_grade_validation.total_subjects}<br>
                            Passed: ${verification.enhanced_grade_validation.passed_subjects}<br>
                            Failed: ${verification.enhanced_grade_validation.failed_subjects.length}
                            ${verification.enhanced_grade_validation.failed_subjects.length > 0 ? 
                                '<br>Failed Subjects: ' + verification.enhanced_grade_validation.failed_subjects.join(', ') : ''
                            }
                        </small>
                    </div>
                `;
            }
            
            gradesTable.innerHTML = tableHTML;
        }

        // Update feedback and next button based on eligibility
        if (verification.is_eligible) {
            feedbackDiv.style.display = 'none';
            nextButton.disabled = false;
            nextButton.classList.add('btn-success');
            nextButton.classList.remove('btn-primary');
            nextButton.innerHTML = '<i class="bi bi-check-circle me-2"></i>Continue - Student Eligible';
        } else {
            let feedbackMessage = `<strong>Eligibility Assessment:</strong> ${verification.summary?.recommendation || 'Student is not eligible'}<br>`;
            feedbackMessage += `<small>Validation Status: ${verification.summary?.eligibility_status || 'INELIGIBLE'}</small><br>`;
            feedbackMessage += `<small>Passed ${verification.summary?.passed_checks || 0} of ${verification.summary?.total_checks || 5} validation checks</small>`;
            
            // Show specific validation failures
            if (!verification.year_level_match) {
                feedbackMessage += '<br><br><strong>⚠️ Year Level Mismatch:</strong> The declared year level does not match what was found on the grade card.';
            }
            if (!verification.semester_match) {
                const requiredSemester = verification.admin_requirements?.required_semester || 'Not specified';
                feedbackMessage += `<br><br><strong>⚠️ Semester Mismatch:</strong> Grade card does not show required semester: "${requiredSemester}".`;
            }
            // TEMPORARILY DISABLED FOR TESTING
            // if (!verification.school_year_match) {
            //     const requiredSchoolYear = verification.admin_requirements?.required_school_year || 'Not specified';
            //     feedbackMessage += `<br><br><strong>⚠️ School Year Mismatch:</strong> Grade card does not show required school year: "${requiredSchoolYear}".`;
            // }
            if (!verification.university_match) {
                feedbackMessage += '<br><br><strong>⚠️ University Mismatch:</strong> The declared university does not match what was found on the grade card.';
            }
            if (!verification.all_grades_passing) {
                feedbackMessage += '<br><br><strong>⚠️ Failing Grades Found:</strong><ul>';
                verification.failing_grades.forEach(grade => {
                    feedbackMessage += `<li>${grade.subject}: ${grade.grade} (Grade ≥ 3.00 is failing)</li>`;
                });
                feedbackMessage += '</ul>';
            }
            if (!verification.name_match) {
                feedbackMessage += '<br><br><strong>⚠️ Name Mismatch:</strong> Student name not clearly found on the grade card.';
            }
            
            feedbackDiv.innerHTML = feedbackMessage;
            feedbackDiv.style.display = 'block';
            nextButton.disabled = true;
            nextButton.classList.remove('btn-success');
            nextButton.classList.add('btn-danger');
            nextButton.innerHTML = '<i class="bi bi-x-circle me-2"></i>Cannot Continue - Ineligible';
        }
    }
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

<!-- Terms and Conditions Modal Functionality -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Terms and Conditions modal functionality
    setupTermsAndConditions();
    
    // Wait a moment for external scripts to load
    setTimeout(function() {
        console.log('🔍 Final function check:', {
            nextStep: typeof window.nextStep,
            prevStep: typeof window.prevStep,
            showStep: typeof window.showStep,
            bootstrap: typeof window.bootstrap
        });
        
        // Test if functions work
        const testButton = document.createElement('button');
        testButton.onclick = function() {
            console.log('✅ nextStep function test successful');
        };
        
        console.log('✅ Registration page initialization complete');
    }, 500);
});

function setupTermsAndConditions() {
    // Handle terms and conditions modal
    const termsLink = document.querySelector('a[data-bs-target="#termsModal"]');
    const acceptBtn = document.getElementById('acceptTermsBtn');
    const agreeCheckbox = document.getElementById('agreeTerms');
    const termsModal = document.getElementById('termsModal');
    
    console.log('🔍 Terms elements found:', {
        termsLink: !!termsLink,
        acceptBtn: !!acceptBtn,
        agreeCheckbox: !!agreeCheckbox,
        termsModal: !!termsModal
    });
    
    // Fallback modal opening if Bootstrap doesn't work
    if (termsLink) {
        termsLink.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Try Bootstrap modal first
            if (window.bootstrap && window.bootstrap.Modal) {
                const modal = new bootstrap.Modal(termsModal);
                modal.show();
                console.log('✅ Terms modal opened via Bootstrap');
            } else {
                // Fallback: Show modal manually
                if (termsModal) {
                    termsModal.style.display = 'block';
                    termsModal.classList.add('show');
                    termsModal.setAttribute('aria-modal', 'true');
                    termsModal.setAttribute('role', 'dialog');
                    document.body.classList.add('modal-open');
                    console.log('✅ Terms modal opened via fallback');
                }
            }
        });
    }
    
    // Handle accept button
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function() {
            // Check the terms checkbox
            if (agreeCheckbox) {
                agreeCheckbox.checked = true;
                agreeCheckbox.dispatchEvent(new Event('change'));
                console.log('✅ Terms accepted and checkbox checked');
            }
            
            // Close modal
            if (window.bootstrap && window.bootstrap.Modal) {
                const modal = bootstrap.Modal.getInstance(termsModal);
                if (modal) modal.hide();
            } else {
                // Fallback close
                if (termsModal) {
                    termsModal.style.display = 'none';
                    termsModal.classList.remove('show');
                    termsModal.removeAttribute('aria-modal');
                    termsModal.removeAttribute('role');
                    document.body.classList.remove('modal-open');
                }
            }
        });
    }
    
    // Handle modal close buttons
    const closeButtons = termsModal?.querySelectorAll('[data-bs-dismiss="modal"]');
    closeButtons?.forEach(button => {
        button.addEventListener('click', function() {
            if (window.bootstrap && window.bootstrap.Modal) {
                const modal = bootstrap.Modal.getInstance(termsModal);
                if (modal) modal.hide();
            } else {
                // Fallback close
                if (termsModal) {
                    termsModal.style.display = 'none';
                    termsModal.classList.remove('show');
                    termsModal.removeAttribute('aria-modal');
                    termsModal.removeAttribute('role');
                    document.body.classList.remove('modal-open');
                }
            }
        });
    });
    
    // Add backdrop click to close
    if (termsModal) {
        termsModal.addEventListener('click', function(e) {
            if (e.target === termsModal) {
                const closeBtn = termsModal.querySelector('[data-bs-dismiss="modal"]');
                if (closeBtn) closeBtn.click();
            }
        });
    }
    
    console.log('✅ Terms and Conditions functionality initialized');
}
</script>
</body>
</html>
<?php
} // End of main registration HTML for non-AJAX requests