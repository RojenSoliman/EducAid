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
    if ($slotInfo) {
        $countRes = pg_query_params($connection, "
            SELECT COUNT(*) AS total FROM students
            WHERE (status = 'under_registration' OR status = 'applicant' OR status = 'active')
            AND application_date >= $1
        ", [$slotInfo['created_at']]);
        $countRow = pg_fetch_assoc($countRes);
        $slotsLeft = intval($slotInfo['slot_count']) - intval($countRow['total']);
    }
    if ($slotsLeft <= 0) {
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <title>EducAid – Registration Closed</title>
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
                    <svg class="spinner text-danger" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
                        <circle cx="50" cy="50" fill="none" stroke="currentColor" stroke-width="10" r="35" stroke-dasharray="164.93361431346415 56.97787143782138">
                            <animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="1s" values="0 50 50;360 50 50" keyTimes="0;1"/>
                        </circle>
                    </svg>
                    <h4 class="text-danger">Slots are full.</h4>
                    <p>Please wait for the next announcement before registering again.</p>
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

    if ((time() - $_SESSION['otp_timestamp']) > 40) {
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
        echo "<script>alert('No active slot found for your municipality.'); window.location.href = '../../unified_login.php';</script>";
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
        echo "<script>alert('Registration slots are full. Please wait for the next round.'); window.location.href = '../../unified_login.php';</script>";
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

    $insertQuery = "INSERT INTO students (municipality_id, first_name, middle_name, last_name, email, mobile, password, sex, status, payroll_no, qr_code, has_received, application_date, bdate, barangay_id, university_id, year_level_id, unique_student_id)
                    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'under_registration', 0, 0, FALSE, NOW(), $9, $10, $11, $12, $13) RETURNING student_id";

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
        $unique_student_id
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
                        <label class="form-label">First Name</label>
                        <input type="text" class="form-control" name="first_name" required />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Middle Name</label>
                        <input type="text" class="form-control" name="middle_name" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Last Name</label>
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
                    <button type="button" class="btn btn-primary w-100" id="nextStep5Btn">Next</button>
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
                        <input type="checkbox" class="form-check-input" name="agree_terms" required />
                        <label class="form-check-label">I agree to the Terms</label>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="submit" name="register" class="btn btn-success w-100">Submit</button>
                </div>
            </form>
        </div>
    </div>
    <div id="notifier" class="notifier"></div>
    <script>
        let countdown;
        let currentStep = 1;
        let otpVerified = false;

        function updateRequiredFields() {
            // Disable all required fields initially
            document.querySelectorAll('.step-panel input[required], .step-panel select[required], .step-panel textarea[required]').forEach(el => {
                el.disabled = true;
            });
            // Enable required fields in the visible panel only
            document.querySelectorAll(`#step-${currentStep} input[required], #step-${currentStep} select[required], #step-${currentStep} textarea[required]`).forEach(el => {
                el.disabled = false;
            });
        }

        function showStep(stepNumber) {
            document.querySelectorAll('.step-panel').forEach(panel => {
                panel.classList.add('d-none');
            });
            document.getElementById(`step-${stepNumber}`).classList.remove('d-none');

            document.querySelectorAll('.step').forEach((step, index) => {
                if (index + 1 === stepNumber) {
                    step.classList.add('active');
                } else {
                    step.classList.remove('active');
                }
            });
            currentStep = stepNumber;
            updateRequiredFields();
        }

        function showNotifier(message, type = 'error') {
            const notifier = document.getElementById('notifier');
            notifier.textContent = message;
            notifier.classList.remove('success', 'error');
            notifier.classList.add(type);
            notifier.style.display = 'block';

            setTimeout(() => {
                notifier.style.display = 'none';
            }, 3000);
        }

        function nextStep() {
            if (currentStep === 6) return;

            let isValid = true;
            const currentPanel = document.getElementById(`step-${currentStep}`);
            const inputs = currentPanel.querySelectorAll('input[required], select[required], textarea[required]');

            inputs.forEach(input => {
                if (input.type === 'radio') {
                    const radioGroupName = input.name;
                    if (!document.querySelector(`input[name="${radioGroupName}"]:checked`)) {
                        isValid = false;
                    }
                } else if (input.type === 'checkbox') {
                    if (!input.checked) {
                        isValid = false;
                    }
                } else if (!input.value.trim()) {
                    isValid = false;
                }
            });

            if (!isValid) {
                showNotifier('Please fill in all required fields for the current step.', 'error');
                return;
            }

            if (currentStep === 5) {
                if (!otpVerified) {
                    showNotifier('Please verify your OTP before proceeding.', 'error');
                    return;
                }
                showStep(currentStep + 1);
            } else if (currentStep < 6) {
                showStep(currentStep + 1);
            }
        }

        function prevStep() {
            if (currentStep > 1) {
                showStep(currentStep - 1);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            showStep(1);
            updateRequiredFields();
            document.getElementById('nextStep5Btn').disabled = true;
            document.getElementById('nextStep5Btn').addEventListener('click', nextStep);
            
            // Add listeners to name fields to re-validate filename if changed
            document.querySelector('input[name="first_name"]').addEventListener('input', function() {
                if (document.getElementById('enrollmentForm').files.length > 0) {
                    // Trigger filename re-validation if file is already selected
                    const event = new Event('change');
                    document.getElementById('enrollmentForm').dispatchEvent(event);
                }
            });
            
            document.querySelector('input[name="last_name"]').addEventListener('input', function() {
                if (document.getElementById('enrollmentForm').files.length > 0) {
                    // Trigger filename re-validation if file is already selected
                    const event = new Event('change');
                    document.getElementById('enrollmentForm').dispatchEvent(event);
                }
            });
        });

        // ---- OTP BUTTON HANDLING ----

        document.getElementById("sendOtpBtn").addEventListener("click", function() {
            const emailInput = document.getElementById('emailInput');
            const email = emailInput.value;

            if (!email || !/\S+@\S+\.\S+/.test(email)) {
                showNotifier('Please enter a valid email address before sending OTP.', 'error');
                return;
            }

            const sendOtpBtn = this;
            sendOtpBtn.disabled = true;
            sendOtpBtn.textContent = 'Sending OTP...';
            document.getElementById("resendOtpBtn").disabled = true;

            const formData = new FormData();
            formData.append('sendOtp', 'true');
            formData.append('email', email);

            fetch('student_register.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showNotifier(data.message, 'success');
                    document.getElementById("otpSection").classList.remove("d-none");
                    document.getElementById("sendOtpBtn").classList.add("d-none");
                    document.getElementById("resendOtpBtn").style.display = 'block';
                    startOtpTimer();
                } else {
                    showNotifier(data.message, 'error');
                    sendOtpBtn.disabled = false;
                    sendOtpBtn.textContent = "Send OTP (Email)";
                    document.getElementById("resendOtpBtn").disabled = true;
                }
            })
            .catch(error => {
                console.error('Error sending OTP:', error);
                showNotifier('Failed to send OTP. Please try again.', 'error');
                sendOtpBtn.disabled = false;
                sendOtpBtn.textContent = "Send OTP (Email)";
                document.getElementById("resendOtpBtn").disabled = true;
            });
        });

        document.getElementById("resendOtpBtn").addEventListener("click", function() {
            const emailInput = document.getElementById('emailInput');
            const email = emailInput.value;

            if (document.getElementById('timer').textContent !== "OTP expired. Please request a new OTP.") {
                return;
            }

            const resendOtpBtn = this;
            resendOtpBtn.disabled = true;
            resendOtpBtn.textContent = 'Resending OTP...';

            const formData = new FormData();
            formData.append('sendOtp', 'true');
            formData.append('email', email);

            fetch('student_register.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showNotifier(data.message, 'success');
                    startOtpTimer();
                } else {
                    showNotifier(data.message, 'error');
                    resendOtpBtn.disabled = false;
                    resendOtpBtn.textContent = "Resend OTP";
                }
            })
            .catch(error => {
                console.error('Error sending OTP:', error);
                showNotifier('Failed to send OTP. Please try again.', 'error');
                resendOtpBtn.disabled = false;
                resendOtpBtn.textContent = "Resend OTP";
            });
        });

        document.getElementById("verifyOtpBtn").addEventListener("click", function() {
            const enteredOtp = document.getElementById('otp').value;
            const emailForOtpVerification = document.getElementById('emailInput').value;

            if (!enteredOtp) {
                showNotifier('Please enter the OTP.', 'error');
                return;
            }

            const verifyOtpBtn = this;
            verifyOtpBtn.disabled = true;
            verifyOtpBtn.textContent = 'Verifying...';

            const formData = new FormData();
            formData.append('verifyOtp', 'true');
            formData.append('otp', enteredOtp);
            formData.append('email', emailForOtpVerification);

            fetch('student_register.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showNotifier(data.message, 'success');
                    otpVerified = true;
                    document.getElementById('otp').disabled = true;
                    verifyOtpBtn.classList.add('btn-success');
                    verifyOtpBtn.textContent = 'Verified!';
                    verifyOtpBtn.disabled = true;
                    clearInterval(countdown);
                    document.getElementById('timer').textContent = '';
                    document.getElementById('resendOtpBtn').style.display = 'none';
                    document.getElementById('nextStep5Btn').disabled = false;
                    document.getElementById('emailInput').disabled = true;
                    document.getElementById('emailInput').classList.add('verified-email');
                } else {
                    showNotifier(data.message, 'error');
                    verifyOtpBtn.disabled = false;
                    verifyOtpBtn.textContent = "Verify OTP";
                    otpVerified = false;
                }
            })
            .catch(error => {
                console.error('Error verifying OTP:', error);
                showNotifier('Failed to verify OTP. Please try again.', 'error');
                verifyOtpBtn.disabled = false;
                verifyOtpBtn.textContent = "Verify OTP";
                otpVerified = false;
            });
        });

        function startOtpTimer() {
            let timeLeft = 40;
            clearInterval(countdown);
            document.getElementById('timer').textContent = `Time left: ${timeLeft} seconds`;

            countdown = setInterval(function() {
                timeLeft--;
                document.getElementById('timer').textContent = `Time left: ${timeLeft} seconds`;

                if (timeLeft <= 0) {
                    clearInterval(countdown);
                    document.getElementById('timer').textContent = "OTP expired. Please request a new OTP.";
                    document.getElementById('otp').disabled = false;
                    document.getElementById('verifyOtpBtn').disabled = false;
                    document.getElementById('verifyOtpBtn').textContent = 'Verify OTP';
                    document.getElementById('verifyOtpBtn').classList.remove('btn-success');
                    document.getElementById('resendOtpBtn').disabled = false;
                    document.getElementById('resendOtpBtn').style.display = 'block';
                    document.getElementById('sendOtpBtn').classList.add('d-none');
                    otpVerified = false;
                    document.getElementById('nextStep5Btn').disabled = true;
                }
            }, 1000);
        }

        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirmPassword');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');

        function updatePasswordStrength() {
            const password = passwordInput.value;
            let strength = 0;

            if (password.length >= 12) strength += 25;
            if (/[A-Z]/.test(password)) strength += 25;
            if (/[a-z]/.test(password)) strength += 25;
            if (/[0-9]/.test(password)) strength += 25;
            if (/[^A-Za-z0-9]/.test(password)) strength += 25;

            strength = Math.min(strength, 100);

            strengthBar.style.width = strength + '%';
            strengthBar.className = 'progress-bar';

            if (strength < 50) {
                strengthBar.classList.add('bg-danger');
                strengthText.textContent = 'Weak';
            } else if (strength < 75) {
                strengthBar.classList.add('bg-warning');
                strengthText.textContent = 'Medium';
            } else {
                strengthBar.classList.add('bg-success');
                strengthText.textContent = 'Strong';
            }

            if (password.length === 0) {
                strengthBar.style.width = '0%';
                strengthText.textContent = '';
            }
        }

        passwordInput.addEventListener('input', updatePasswordStrength);

        confirmPasswordInput.addEventListener('input', function() {
            if (passwordInput.value !== confirmPasswordInput.value) {
                confirmPasswordInput.setCustomValidity('Passwords do not match');
            } else {
                confirmPasswordInput.setCustomValidity('');
            }
        });

        // ----- FIX FOR REQUIRED FIELD ERROR -----
        document.getElementById('multiStepForm').addEventListener('submit', function(e) {
            if (currentStep !== 6) {
                e.preventDefault();
                showNotifier('Please complete all steps first.', 'error');
                return;
            }
            // Show all panels and enable all fields for browser validation
            document.querySelectorAll('.step-panel').forEach(panel => {
                panel.classList.remove('d-none');
                panel.style.display = '';
            });
            document.querySelectorAll('input, select, textarea').forEach(el => {
                el.disabled = false;
            });
        });

        // ----- DOCUMENT UPLOAD AND OCR FUNCTIONALITY -----
        let documentVerified = false;
        let filenameValid = false;

        function validateFilename(filename, firstName, lastName) {
            // Remove file extension for validation
            const nameWithoutExt = filename.replace(/\.[^/.]+$/, '');
            
            // Expected format: Lastname_Firstname_EAF
            const expectedFormat = `${lastName}_${firstName}_EAF`;
            
            // Case-insensitive comparison
            return nameWithoutExt.toLowerCase() === expectedFormat.toLowerCase();
        }

        function updateProcessButtonState() {
            const processBtn = document.getElementById('processOcrBtn');
            const fileInput = document.getElementById('enrollmentForm');
            
            if (fileInput.files.length > 0 && filenameValid) {
                processBtn.disabled = false;
            } else {
                processBtn.disabled = true;
            }
        }

        document.getElementById('enrollmentForm').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const filenameError = document.getElementById('filenameError');
            
            if (file) {
                // Get form data for filename validation
                const firstName = document.querySelector('input[name="first_name"]').value.trim();
                const lastName = document.querySelector('input[name="last_name"]').value.trim();
                
                if (!firstName || !lastName) {
                    showNotifier('Please fill in your first and last name first.', 'error');
                    this.value = '';
                    return;
                }
                
                // Validate filename format
                filenameValid = validateFilename(file.name, firstName, lastName);
                
                if (!filenameValid) {
                    filenameError.style.display = 'block';
                    filenameError.innerHTML = `
                        <small><i class="bi bi-exclamation-triangle me-1"></i>
                        Filename must be: <strong>${lastName}_${firstName}_EAF.${file.name.split('.').pop()}</strong>
                        </small>
                    `;
                    document.getElementById('uploadPreview').classList.add('d-none');
                    document.getElementById('ocrSection').classList.add('d-none');
                } else {
                    filenameError.style.display = 'none';
                    
                    const previewContainer = document.getElementById('uploadPreview');
                    const previewImage = document.getElementById('previewImage');
                    const pdfPreview = document.getElementById('pdfPreview');
                    
                    previewContainer.classList.remove('d-none');
                    document.getElementById('ocrSection').classList.remove('d-none');
                    
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
                }
                
                // Reset verification status
                documentVerified = false;
                document.getElementById('nextStep4Btn').disabled = true;
                document.getElementById('ocrResults').classList.add('d-none');
                updateProcessButtonState();
            } else {
                filenameError.style.display = 'none';
                filenameValid = false;
                updateProcessButtonState();
            }
        });

        document.getElementById('processOcrBtn').addEventListener('click', function() {
            const fileInput = document.getElementById('enrollmentForm');
            const file = fileInput.files[0];
            
            if (!file) {
                showNotifier('Please select a file first.', 'error');
                return;
            }

            if (!filenameValid) {
                showNotifier('Please rename your file to follow the required format: Lastname_Firstname_EAF', 'error');
                return;
            }

            const processBtn = this;
            processBtn.disabled = true;
            processBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
            
            // Get form data for verification
            const formData = new FormData();
            formData.append('processOcr', 'true');
            formData.append('enrollment_form', file);
            formData.append('first_name', document.querySelector('input[name="first_name"]').value);
            formData.append('middle_name', document.querySelector('input[name="middle_name"]').value);
            formData.append('last_name', document.querySelector('input[name="last_name"]').value);
            formData.append('university_id', document.querySelector('select[name="university_id"]').value);
            formData.append('year_level_id', document.querySelector('select[name="year_level_id"]').value);

            fetch('student_register.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                processBtn.disabled = false;
                processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Process Document';
                
                if (data.status === 'success') {
                    displayVerificationResults(data.verification);
                } else {
                    showNotifier(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error processing OCR:', error);
                showNotifier('Failed to process document. Please try again.', 'error');
                processBtn.disabled = false;
                processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Process Document';
            });
        });

        function displayVerificationResults(verification) {
            const resultsContainer = document.getElementById('ocrResults');
            const feedbackContainer = document.getElementById('ocrFeedback');
            
            resultsContainer.classList.remove('d-none');
            
            // Update checklist items
            const checks = ['firstname', 'middlename', 'lastname', 'yearlevel', 'university', 'document'];
            const checkMap = {
                'firstname': 'first_name',
                'middlename': 'middle_name', 
                'lastname': 'last_name',
                'yearlevel': 'year_level',
                'university': 'university',
                'document': 'document_keywords'
            };
            
            checks.forEach(check => {
                const element = document.getElementById(`check-${check}`);
                const icon = element.querySelector('i');
                const isValid = verification[checkMap[check]];
                
                if (isValid) {
                    icon.className = 'bi bi-check-circle text-success me-2';
                } else {
                    icon.className = 'bi bi-x-circle text-danger me-2';
                }
            });
            
            if (verification.overall_success) {
                feedbackContainer.style.display = 'none';
                feedbackContainer.className = 'alert alert-success mt-3';
                feedbackContainer.innerHTML = '<strong>Verification Successful!</strong> Your document has been validated.';
                feedbackContainer.style.display = 'block';
                documentVerified = true;
                document.getElementById('nextStep4Btn').disabled = false;
                showNotifier('Document verification successful!', 'success');
            } else {
                feedbackContainer.style.display = 'none';
                feedbackContainer.className = 'alert alert-warning mt-3';
                feedbackContainer.innerHTML = '<strong>Verification Failed:</strong> Please ensure your document is clear and contains all required information. Upload a clearer image or check that the document matches your registration details.';
                feedbackContainer.style.display = 'block';
                documentVerified = false;
                document.getElementById('nextStep4Btn').disabled = true;
                showNotifier('Document verification failed. Please try again with a clearer document.', 'error');
            }
        }

        // Add CSS for verification checklist
        const style = document.createElement('style');
        style.textContent = `
            .verification-checklist .form-check {
                display: flex;
                align-items: center;
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }
            .verification-checklist .form-check:last-child {
                border-bottom: none;
            }
            .verification-checklist .form-check span {
                font-size: 14px;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
