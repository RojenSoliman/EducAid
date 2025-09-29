<?php
include_once '../../config/database.php';
// Include reCAPTCHA v3 configuration (site key + secret key constants)
include_once __DIR__ . '/../../config/recaptcha_config.php';
session_start();

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

$municipality_id = 1;

// Check if this is an AJAX request (OCR, OTP processing, cleanup, or duplicate check)
$isAjaxRequest = isset($_POST['sendOtp']) || isset($_POST['verifyOtp']) || 
                 isset($_POST['processOcr']) || isset($_POST['processLetterOcr']) || 
                 isset($_POST['processCertificateOcr']) || isset($_POST['cleanup_temp']) ||
                 isset($_POST['check_existing']) || isset($_POST['test_db']);

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
<body class="registration-page">
    <!-- Top Info Bar -->
    <div class="topbar py-2 d-none d-md-block">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="d-flex align-items-center gap-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-telephone"></i>
                            <span>(046) 509-5555</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-envelope"></i>
                            <span>educaid@generaltrias.gov.ph</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 text-end">
                    <div class="d-flex align-items-center justify-content-end gap-3">
                        <span>🏛️ Official City Portal</span>
                        <div class="d-flex gap-2">
                            <a href="#" class="text-white"><i class="bi bi-facebook"></i></a>
                            <a href="#" class="text-white"><i class="bi bi-twitter"></i></a>
                            <a href="#" class="text-white"><i class="bi bi-instagram"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php 
    // Configure navbar for registration page
    $custom_brand_config = [
        'href' => '../../website/landingpage.php'
    ];
    $custom_nav_links = [
        ['href' => '../../website/landingpage.php', 'label' => '<i class="bi bi-house me-1"></i>Back to Home', 'active' => false]
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
    $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'send_otp');
    if (!$captcha['ok']) {
        json_response(['status'=>'error','message'=>'Security verification failed (captcha).']);
    }
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

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        // SMTP settings from environment
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USERNAME') ?: 'example@email.test';
        $mail->Password   = getenv('SMTP_PASSWORD') ?: ''; // Ensure this is set in .env on production
        $encryption       = getenv('SMTP_ENCRYPTION') ?: 'tls';
        if (strtolower($encryption) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);

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
        error_log("PHPMailer Error: {$mail->ErrorInfo}");
        json_response(['status' => 'error', 'message' => 'Message could not be sent. Please check your email address and try again.']);
    }
}

// --- OTP verify ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verifyOtp'])) {
    $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'verify_otp');
    if (!$captcha['ok']) {
        json_response(['status'=>'error','message'=>'Security verification failed (captcha).']);
    }
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
        json_response(['status' => 'success', 'message' => 'OTP verified successfully!']);
    } else {
        $_SESSION['otp_verified'] = false;
        error_log("OTP verification failed - entered: " . $enteredOtp . ", expected: " . $_SESSION['otp']);
        json_response(['status' => 'error', 'message' => 'Invalid OTP. Please try again.']);
    }
}

// --- Document OCR Processing ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['processOcr'])) {
    $captcha = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'process_ocr');
    if (!$captcha['ok']) {
        json_response(['status'=>'error','message'=>'Security verification failed (captcha).']);
    }
    if (!isset($_FILES['enrollment_form']) || $_FILES['enrollment_form']['error'] !== UPLOAD_ERR_OK) {
        json_response(['status' => 'error', 'message' => 'No file uploaded or upload error.']);
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
        json_response(['status' => 'error', 'message' => 'First name and last name are required for filename validation.']);
    }

    // Remove file extension and validate format
    $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
    $expectedFormat = $formLastName . '_' . $formFirstName . '_EAF';

    if (strcasecmp($nameWithoutExt, $expectedFormat) !== 0) {
        json_response([
            'status' => 'error', 
            'message' => "Filename must follow format: {$formLastName}_{$formFirstName}_EAF.{file_extension}"
        ]);
    }

    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        json_response(['status' => 'error', 'message' => 'Failed to save uploaded file.']);
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
    
    // Clean up temporary OCR files
    if (file_exists($outputFile)) {
        unlink($outputFile);
    }
    
    if (empty(trim($ocrText))) {
        json_response([
            'status' => 'error', 
            'message' => 'No text could be extracted from the document. Please ensure the image is clear and contains readable text.'
        ]);
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

    // Save OCR confidence score to temp file for later use during registration
    $confidenceFile = $uploadDir . 'enrollment_confidence.json';
    $confidenceData = [
        'overall_confidence' => $averageConfidence,
        'detailed_scores' => $verification['confidence_scores'],
        'timestamp' => time()
    ];
    file_put_contents($confidenceFile, json_encode($confidenceData));

    json_response(['status' => 'success', 'verification' => $verification]);
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
    
    // Note: Certificate file is kept in temp directory for final registration step
    // It will be cleaned up during registration completion
    
    json_response(['status' => 'success', 'verification' => $verification]);
}

// --- Registration logic ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['register'])) {
    // Debug: Log registration attempt
    error_log("Registration attempt started");
    error_log("POST data keys: " . implode(', ', array_keys($_POST)));
    error_log("Session state: " . print_r($_SESSION, true));
    
    if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
        error_log("OTP verification failed - Session otp_verified: " . (isset($_SESSION['otp_verified']) ? $_SESSION['otp_verified'] : 'not set'));
        echo "<script>
            console.log('Session state:', " . json_encode($_SESSION) . ");
            alert('OTP not verified. Please verify your email first.'); 
            history.back();
        </script>";
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
    $agree_terms = isset($_POST['agree_terms']) ? $_POST['agree_terms'] : '';

    // Validate terms agreement
    if (empty($agree_terms)) {
        error_log("Terms agreement validation failed");
        echo "<script>alert('You must agree to the terms and conditions.'); history.back();</script>";
        exit;
    }

    if (empty($firstname) || empty($lastname) || empty($bdate) || empty($sex) || empty($barangay) || empty($university) || empty($year_level) || empty($mobile) || empty($email) || empty($pass) || empty($confirm)) {
        error_log("Required field validation failed");
        error_log("Missing fields - firstname: " . ($firstname ? 'OK' : 'MISSING') . 
                 ", lastname: " . ($lastname ? 'OK' : 'MISSING') . 
                 ", bdate: " . ($bdate ? 'OK' : 'MISSING') . 
                 ", sex: " . ($sex ? 'OK' : 'MISSING') . 
                 ", barangay: " . ($barangay ? 'OK' : 'MISSING') . 
                 ", university: " . ($university ? 'OK' : 'MISSING') . 
                 ", year_level: " . ($year_level ? 'OK' : 'MISSING') . 
                 ", mobile: " . ($mobile ? 'OK' : 'MISSING') . 
                 ", email: " . ($email ? 'OK' : 'MISSING') . 
                 ", pass: " . ($pass ? 'OK' : 'MISSING') . 
                 ", confirm: " . ($confirm ? 'OK' : 'MISSING'));
        echo "<script>alert('Please fill in all required fields.'); history.back();</script>";
        exit;
    }

    // --- reCAPTCHA v3 server-side verification (central helper + logging) ---
    $captchaResult = verify_recaptcha_v3($_POST['g-recaptcha-response'] ?? '', 'register', 0.5);
    if (!$captchaResult['ok']) {
        error_log('Registration blocked by reCAPTCHA v3. Score=' . ($captchaResult['score'] ?? 0) . ' Reason=' . ($captchaResult['reason'] ?? 'n/a'));
        echo "<script>alert('Security verification failed (captcha). Please refresh the page and try again.'); history.back();</script>";
        exit;
    }
    $captchaScore = $captchaResult['score'];

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
            $check_query = pg_query_params($connection, "SELECT 1 FROM students WHERE student_id = $1", [$unique_id]);
            $exists = pg_num_rows($check_query) > 0;

            $attempts++;

        } while ($exists && $attempts < $max_attempts);

        if ($attempts >= $max_attempts) {
            return false; // Could not generate unique ID
        }

        return $unique_id;
    }

    // Generate unique student ID
    $student_id = generateUniqueStudentId($connection, $year_level);
    if (!$student_id) {
        echo "<script>alert('Failed to generate unique student ID. Please try again.'); history.back();</script>";
        exit;
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
                                <strong>Verification Failed:</strong> Please ensure your certificate contains your name, barangay, "Certificate of Indigency" title, and "General Trias".
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep6Btn" disabled onclick="nextStep()">Next</button>
                </div>
                
                <!-- Step 7: OTP Verification -->
                <div class="step-panel d-none" id="step-7">
                      <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="emailInput" required />
                        <span id="emailStatus" class="text-success d-none">Verified</span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="phone">Phone Number</label>
                        <input type="tel" class="form-control" name="phone" id="phone" maxlength="11" pattern="09[0-9]{9}" placeholder="e.g., 09123456789" required />
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-info" id="sendOtpBtn">Send OTP (Email)</button>
                    </div>
                    <div id="otpSection" class="d-none mt-3">
                        <div class="mb-3">
                            <label class="form-label" for="otp">Enter OTP</label>
                            <input type="text" class="form-control" name="otp" id="otp" required />
                        </div>
                        <button type="button" class="btn btn-success w-100 mb-2" id="verifyOtpBtn">Verify OTP</button>
                        <div id="timer" class="text-danger mt-2"></div>
                        <button type="button" class="btn btn-warning w-100 mt-3" id="resendOtpBtn" style="display:none;" disabled>Resend OTP</button>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep7Btn" onclick="nextStep()">Next</button>
                </div>
                <!-- Step 8: Password and Confirmation -->
                <div class="step-panel d-none" id="step-8">
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
    <!-- Make sure this is included BEFORE your custom JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- OR if you have local Bootstrap files -->
<script src="../../assets/js/bootstrap.bundle.min.js"></script>

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
<?php
} // End of main registration HTML for non-AJAX requests
?>