<?php
include_once '../../config/database.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

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

    // Process with Tesseract OCR
    $outputBase = $uploadDir . 'ocr_' . pathinfo($fileName, PATHINFO_FILENAME);
    $command = "tesseract " . escapeshellarg($targetPath) . " " . escapeshellarg($outputBase) .
               " --oem 1 --psm 6 -l eng 2>&1";

    $tesseractOutput = shell_exec($command);
    $outputFile = $outputBase . ".txt";

    if (!file_exists($outputFile)) {
        echo json_encode(['status' => 'error', 'message' => 'OCR processing failed. Please ensure the document is clear and readable.']);
        exit;
    }

    $ocrText = file_get_contents($outputFile);
    $ocrTextLower = strtolower($ocrText);

    // Verification results
    $verification = [
        'first_name' => false,
        'middle_name' => false,
        'last_name' => false,
        'year_level' => false,
        'university' => false,
        'document_keywords' => false
    ];

    // Check first name
    if (!empty($formData['first_name']) && stripos($ocrText, $formData['first_name']) !== false) {
        $verification['first_name'] = true;
    }

    // Check middle name (optional)
    if (empty($formData['middle_name']) || stripos($ocrText, $formData['middle_name']) !== false) {
        $verification['middle_name'] = true;
    }

    // Check last name
    if (!empty($formData['last_name']) && stripos($ocrText, $formData['last_name']) !== false) {
        $verification['last_name'] = true;
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

    // Check university name (partial matches)
    if (!empty($universityName)) {
        $universityWords = explode(' ', strtolower($universityName));
        $foundWords = 0;
        foreach ($universityWords as $word) {
            if (strlen($word) > 3 && stripos($ocrText, $word) !== false) {
                $foundWords++;
            }
        }
        if ($foundWords >= 2 || (count($universityWords) <= 2 && $foundWords >= 1)) {
            $verification['university'] = true;
        }
    }

    // Check document keywords
    $documentKeywords = [
        'enrollment', 'assessment', 'form', 'official', 'academic', 'student',
        'tuition', 'fees', 'semester', 'registration', 'course', 'subject',
        'grade', 'transcript', 'record', 'university', 'college', 'school'
    ];

    $keywordMatches = 0;
    foreach ($documentKeywords as $keyword) {
        if (stripos($ocrText, $keyword) !== false) {
            $keywordMatches++;
        }
    }

    if ($keywordMatches >= 3) {
        $verification['document_keywords'] = true;
    }

    // Calculate overall success
    $requiredChecks = ['first_name', 'last_name', 'year_level', 'university', 'document_keywords'];
    $passedChecks = 0;
    foreach ($requiredChecks as $check) {
        if ($verification[$check]) {
            $passedChecks++;
        }
    }

    $verification['overall_success'] = $passedChecks >= 4; // At least 4 out of 5 required checks
    $verification['ocr_text'] = $ocrText; // For debugging

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

    $insertQuery = "INSERT INTO students (municipality_id, first_name, middle_name, last_name, email, mobile, password, sex, status, payroll_no, qr_code, has_received, application_date, bdate, barangay_id, university_id, year_level_id, unique_student_id, slot_id)
                    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'under_registration', 0, 0, FALSE, NOW(), $9, $10, $11, $12, $13, $14) RETURNING student_id";

    $result = pg_query_params($connection, $insertQuery, [
        $municipality_id,
        $firstname,
        $middlename,
        $lastname,
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

        // Save enrollment form if it exists in temp folder
        $tempFormPath = 'assets/uploads/temp/';
        $tempFiles = glob($tempFormPath . '*');
        if (!empty($tempFiles)) {
            // Create permanent upload directory
            $permanentDir = '../../assets/uploads/enrollment_forms/';
            if (!file_exists($permanentDir)) {
                mkdir($permanentDir, 0777, true);
            }

            // Move the file to permanent location
            $tempFile = $tempFiles[0]; // Get the first (and should be only) file
            $filename = basename($tempFile);
            $permanentPath = $permanentDir . $student_id . '_' . $filename;

            if (copy($tempFile, $permanentPath)) {
                // Save form record to database
                $formQuery = "INSERT INTO enrollment_forms (student_id, file_path, original_filename) VALUES ($1, $2, $3)";
                pg_query_params($connection, $formQuery, [$student_id, $permanentPath, $filename]);

                // Clean up temp file
                unlink($tempFile);
            }
        }

        $semester = $slotInfo['semester'];
        $academic_year = $slotInfo['academic_year'];
        $applicationQuery = "INSERT INTO applications (student_id, semester, academic_year) VALUES ($1, $2, $3)";
        pg_query_params($connection, $applicationQuery, [$student_id, $semester, $academic_year]);

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
                <!-- Step 5: OTP Verification -->
                <div class="step-panel d-none" id="step-5">
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
                    <button type="button" class="btn btn-primary w-100" id="nextStep5Btn" onclick="nextStep()">Next</button>
                </div>
                <!-- Step 6: Password and Confirmation -->
                <div class="step-panel d-none" id="step-6">
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