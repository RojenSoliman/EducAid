<?php
include __DIR__ . '/config/database.php';
include __DIR__ . '/config/recaptcha_config.php';
session_start();

// Fetch municipality data for navbar (General Trias as default)
$municipality_logo = null;
$municipality_name = 'General Trias';

if (isset($connection)) {
    // Fetch General Trias municipality data (assuming municipality_id = 1 or name = 'General Trias')
    $muni_result = pg_query_params(
        $connection,
        "SELECT name, preset_logo_image 
         FROM municipalities 
         WHERE LOWER(name) LIKE LOWER($1)
         LIMIT 1",
        ['%general trias%']
    );
    
    if ($muni_result && pg_num_rows($muni_result) > 0) {
        $muni_data = pg_fetch_assoc($muni_result);
        $municipality_name = $muni_data['name'];
        
        if (!empty($muni_data['preset_logo_image'])) {
            $municipality_logo = trim($muni_data['preset_logo_image']);
        }
        pg_free_result($muni_result);
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

// Include our professional email template
require_once __DIR__ . '/includes/email_templates/otp_email_template.php';

// Function to verify reCAPTCHA v3
function verifyRecaptcha($recaptchaResponse, $action = 'login') {
    $secretKey = RECAPTCHA_SECRET_KEY;
    
    if (empty($recaptchaResponse)) {
        return ['success' => false, 'message' => 'No CAPTCHA response provided'];
    }
    
    $url = RECAPTCHA_VERIFY_URL;
    $data = [
        'secret' => $secretKey,
        'response' => $recaptchaResponse,
        'remoteip' => $_SERVER['REMOTE_ADDR']
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    if ($result === false) {
        return ['success' => false, 'message' => 'CAPTCHA verification failed'];
    }
    
    $resultJson = json_decode($result, true);
    
    // For v3, check success, score, and action
    if (isset($resultJson['success']) && $resultJson['success'] === true) {
        $score = $resultJson['score'] ?? 0;
        $actionReceived = $resultJson['action'] ?? '';
        
        // Score threshold (0.5 is recommended, lower = more likely bot)
        if ($score >= 0.5 && $actionReceived === $action) {
            return ['success' => true, 'score' => $score];
        } else {
            return ['success' => false, 'message' => 'CAPTCHA score too low or action mismatch', 'score' => $score];
        }
    }
    
    return ['success' => false, 'message' => 'CAPTCHA verification failed'];
}

// Always return JSON for AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
}

// LOGIN PHASE 1: credentials → send login-OTP (SIMPLIFIED)
if (
    isset($_POST['email'], $_POST['password'])
    && !isset($_POST['login_action'])
    && !isset($_POST['forgot_action'])
) {
    // Verify reCAPTCHA v3 first
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
    $captchaResult = verifyRecaptcha($recaptchaResponse, 'login');
    if (!$captchaResult['success']) {
        echo json_encode(['status'=>'error','message'=>'Security verification failed. Please try again.']);
        exit;
    }
    
    $em = trim($_POST['email']);
    $pw = $_POST['password'];

    // Check if user is a student (only allow active students to login)
    $studentRes = pg_query_params($connection,
        "SELECT student_id, password, first_name, last_name, status, 'student' as role FROM students
         WHERE email = $1 AND status != 'under_registration'",
        [$em]
    );
    
    // Check if user is an admin (get actual role from database)
    $adminRes = pg_query_params($connection,
        "SELECT admin_id, password, first_name, last_name, role FROM admins
         WHERE email = $1",
        [$em]
    );

    $user = null;
    if ($studentRow = pg_fetch_assoc($studentRes)) {
        $user = $studentRow;
        $user['id'] = $user['student_id'];
        
        // Check if student is blacklisted
        if ($user['status'] === 'blacklisted') {
            // Get blacklist reason
            $blacklistQuery = pg_query_params($connection,
                "SELECT reason_category, detailed_reason FROM blacklisted_students WHERE student_id = $1",
                [$user['id']]
            );
            $blacklistInfo = pg_fetch_assoc($blacklistQuery);
            
            $reasonText = 'violation of terms';
            if ($blacklistInfo) {
                switch($blacklistInfo['reason_category']) {
                    case 'fraudulent_activity': $reasonText = 'fraudulent activity'; break;
                    case 'academic_misconduct': $reasonText = 'academic misconduct'; break;
                    case 'system_abuse': $reasonText = 'system abuse'; break;
                    case 'other': $reasonText = 'policy violation'; break;
                }
            }
            
            echo json_encode([
                'status' => 'error',
                'message' => "Account permanently suspended due to {$reasonText}. Please contact the Office of the Mayor for assistance.",
                'is_blacklisted' => true
            ]);
            exit;
        }
    } elseif ($adminRow = pg_fetch_assoc($adminRes)) {
        $user = $adminRow;
        $user['id'] = $user['admin_id'];
    }

    if (!$user) {
        // Check if the email exists but with 'under_registration' status
        $underRegistrationCheck = pg_query_params($connection,
            "SELECT student_id FROM students WHERE email = $1 AND status = 'under_registration'",
            [$em]
        );
        
        if (pg_fetch_assoc($underRegistrationCheck)) {
            echo json_encode([
                'status'=>'error',
                'message'=>'Your account is still under review. Please wait for admin approval before logging in.'
            ]);
        } else {
            echo json_encode(['status'=>'error','message'=>'Email not found.']);
        }
        exit;
    }
    
    if (!password_verify($pw, $user['password'])) {
        echo json_encode(['status'=>'error','message'=>'Invalid password.']);
        exit;
    }

    // Credentials OK → generate OTP
    $otp = rand(100000,999999);
    $_SESSION['login_otp'] = $otp;
    $_SESSION['login_otp_time'] = time();
    $_SESSION['login_pending'] = [
        'user_id' => $user['id'],
        'role' => $user['role'],
        'name' => trim($user['first_name'] . ' ' . $user['last_name'])
    ];

    // Send via email (using professional template)
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'dilucayaka02@gmail.com';
        $mail->Password = 'jlld eygl hksj flvg';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('dilucayaka02@gmail.com','EducAid System');
        $mail->addAddress($em);
        $mail->isHTML(true);
        $mail->Subject = 'EducAid Verification Code - ' . $otp;
        
        // Get user's full name for personalization
        $recipient_name = trim($user['first_name'] . ' ' . $user['last_name']) ?: 'User';
        
        // Use professional email template for login OTP
        $mail->Body = generateOTPEmailTemplate($otp, $recipient_name, 'login');

        $mail->send();
        echo json_encode(['status'=>'otp_sent','message'=>'OTP sent to your email.']);
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>'Could not send OTP.']);
    }
    exit;
}

// LOGIN PHASE 2: verify login-OTP
if (isset($_POST['login_action']) && $_POST['login_action'] === 'verify_otp') {
    $userOtp = $_POST['login_otp'] ?? '';
    if (!isset($_SESSION['login_otp'], $_SESSION['login_otp_time'], $_SESSION['login_pending'])) {
        echo json_encode(['status'=>'error','message'=>'No login in progress.']);
        exit;
    }
    if (time() - $_SESSION['login_otp_time'] > 300) {
        session_unset();
        echo json_encode(['status'=>'error','message'=>'OTP expired.']);
        exit;
    }
    if ($userOtp != $_SESSION['login_otp']) {
        echo json_encode(['status'=>'error','message'=>'Incorrect OTP.']);
        exit;
    }

    // OTP OK → finalize login based on role
    $pending = $_SESSION['login_pending'];
    
    if ($pending['role'] === 'student') {
        $_SESSION['student_id'] = $pending['user_id'];
        $_SESSION['student_username'] = $pending['name'];
        
        // Get the previous login time before updating (for display purposes)
        $prev_login_result = pg_query_params($connection,
            "SELECT last_login FROM students WHERE student_id = $1",
            [$pending['user_id']]
        );
        $prev_login = pg_fetch_assoc($prev_login_result);
        $_SESSION['previous_login'] = $prev_login['last_login'] ?? null;
        
        // Update last_login timestamp for student
        pg_query_params($connection, 
            "UPDATE students SET last_login = NOW() WHERE student_id = $1", 
            [$pending['user_id']]
        );
        
        $redirect = 'modules/student/student_homepage.php';
    } else {
        $_SESSION['admin_id'] = $pending['user_id'];
        $_SESSION['admin_username'] = $pending['name'];
        $_SESSION['admin_role'] = $pending['role']; // Store the actual admin role
        
        // Update last_login timestamp for admin
        pg_query_params($connection, 
            "UPDATE admins SET last_login = NOW() WHERE admin_id = $1", 
            [$pending['user_id']]
        );
        
        $redirect = 'modules/admin/homepage.php';
    }
    
    unset($_SESSION['login_otp'], $_SESSION['login_pending']);

    echo json_encode([
        'status' => 'success',
        'message' => 'Logged in!',
        'redirect' => $redirect
    ]);
    exit;
}

// FORGOT-PASSWORD OTP FLOW (similar logic for both roles)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_action'])) {
    // SEND OTP for Forgot-Password
    if ($_POST['forgot_action'] === 'send_otp' && !empty($_POST['forgot_email'])) {
        $email = trim($_POST['forgot_email']);
        
        // Check both tables (exclude students under registration and blacklisted)
        $studentRes = pg_query_params($connection, "SELECT student_id, 'student' as role FROM students WHERE email = $1 AND status NOT IN ('under_registration', 'blacklisted')", [$email]);
        $adminRes = pg_query_params($connection, "SELECT admin_id, role FROM admins WHERE email = $1", [$email]);
        
        $user = null;
        if ($studentRow = pg_fetch_assoc($studentRes)) {
            $user = $studentRow;
        } elseif ($adminRow = pg_fetch_assoc($adminRes)) {
            $user = $adminRow;
        }
        
        if (!$user) {
            // Check if it's a student under registration
            $underRegistrationCheck = pg_query_params($connection,
                "SELECT student_id FROM students WHERE email = $1 AND status = 'under_registration'",
                [$email]
            );
            
            // Check if it's a blacklisted student
            $blacklistedCheck = pg_query_params($connection,
                "SELECT student_id FROM students WHERE email = $1 AND status = 'blacklisted'",
                [$email]
            );
            
            if (pg_fetch_assoc($underRegistrationCheck)) {
                echo json_encode([
                    'status'=>'error',
                    'message'=>'Your account is still under review. Password reset is not available until your account is approved.'
                ]);
            } elseif (pg_fetch_assoc($blacklistedCheck)) {
                echo json_encode([
                    'status'=>'error',
                    'message'=>'Account suspended. Please contact the Office of the Mayor for assistance.'
                ]);
            } else {
                echo json_encode(['status'=>'error','message'=>'Email not found.']);
            }
            exit;
        }
        
        $otp = rand(100000,999999);
        $_SESSION['forgot_otp'] = $otp;
        $_SESSION['forgot_otp_email'] = $email;
        $_SESSION['forgot_otp_role'] = $user['role'];
        $_SESSION['forgot_otp_time'] = time();

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'dilucayaka02@gmail.com';
            $mail->Password = 'jlld eygl hksj flvg';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('dilucayaka02@gmail.com','EducAid System');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'EducAid Password Reset Code - ' . $otp;
            
            // Get user's name for personalization
            $recipient_name = 'User';
            if ($_SESSION['forgot_otp_role'] === 'admin') {
                $nameQuery = "SELECT firstname, lastname FROM admin_accounts WHERE email = $1";
            } else {
                $nameQuery = "SELECT first_name, last_name FROM students WHERE email = $1";
            }
            
            $nameResult = pg_query_params($connection, $nameQuery, [$email]);
            if ($nameResult && pg_num_rows($nameResult) > 0) {
                $nameRow = pg_fetch_assoc($nameResult);
                if ($_SESSION['forgot_otp_role'] === 'admin') {
                    $recipient_name = trim($nameRow['firstname'] . ' ' . $nameRow['lastname']) ?: 'User';
                } else {
                    $recipient_name = trim($nameRow['first_name'] . ' ' . $nameRow['last_name']) ?: 'User';
                }
            }
            
            // Use professional email template
            $mail->Body = generateOTPEmailTemplate($otp, $recipient_name, 'password_reset');

            $mail->send();
            echo json_encode(['status'=>'success','message'=>'OTP sent to your email.']);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error','message'=>'Failed to send OTP.']);
        }
        exit;
    }

    // VERIFY Forgot-Password OTP
    if ($_POST['forgot_action'] === 'verify_otp' && isset($_POST['forgot_otp'])) {
        if (!isset($_SESSION['forgot_otp'], $_SESSION['forgot_otp_time'], $_SESSION['forgot_otp_email'])) {
            echo json_encode(['status'=>'error','message'=>'Session expired.']);
            exit;
        }
        if (time() - $_SESSION['forgot_otp_time'] > 300) {
            session_unset();
            echo json_encode(['status'=>'error','message'=>'OTP expired.']);
            exit;
        }
        if ($_POST['forgot_otp'] == $_SESSION['forgot_otp']) {
            $_SESSION['forgot_otp_verified'] = true;
            echo json_encode(['status'=>'success','message'=>'OTP verified.']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Incorrect OTP.']);
        }
        exit;
    }

    // SET NEW PASSWORD
    if ($_POST['forgot_action'] === 'set_new_password' && isset($_POST['forgot_new_password'])) {
        if (!isset($_SESSION['forgot_otp_verified'], $_SESSION['forgot_otp_email'], $_SESSION['forgot_otp_role'])
            || !$_SESSION['forgot_otp_verified']
        ) {
            echo json_encode(['status'=>'error','message'=>'OTP verification required.']);
            exit;
        }
        
        $newPwd = $_POST['forgot_new_password'];
        if (strlen($newPwd) < 12) {
            echo json_encode(['status'=>'error','message'=>'Password must be at least 12 characters.']);
            exit;
        }
        
        $hashed = password_hash($newPwd, PASSWORD_ARGON2ID);
        $table = $_SESSION['forgot_otp_role'] === 'student' ? 'students' : 'admins';
        
        $update = pg_query_params($connection,
            "UPDATE $table SET password = $1 WHERE email = $2",
            [$hashed, $_SESSION['forgot_otp_email']]
        );
        
        if ($update) {
            session_unset();
            echo json_encode(['status'=>'success','message'=>'Password updated successfully.']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Update failed.']);
        }
        exit;
    }
}

// If no AJAX route matched and it's a regular page load, show the login form
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EducAid - Login (Experimental)</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link href="assets/css/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/universal.css" rel="stylesheet">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/website/landing_page.css" rel="stylesheet">
    
    <!-- Google reCAPTCHA v3 -->
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo RECAPTCHA_SITE_KEY; ?>"></script>
    
    <style>
        /* EXPERIMENTAL FIX 1: Isolate page layout from navbar with unique class */
        :root {
            --topbar-height: 0px;
            --navbar-height: 0px;
        }
        
        /* FIX 2: Use unique class for page container to avoid conflicts */
        body.login-page-exp {
            padding-top: var(--navbar-height);
            overflow-x: hidden;
        }
        
        /* FIX 3: Page-specific container with unique class - won't affect navbar */
        .login-page-exp .login-main-wrapper {
            min-height: calc(100vh - var(--navbar-height));
            max-width: 100vw;
            overflow-x: hidden;
        }
        
        /* FIX 4: Ensure page containers don't inherit navbar width constraints */
        .login-page-exp .login-content-container {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 0;
        }
        
        /* FIX 5: Add isolation for navbar to prevent page CSS bleed */
        .login-page-exp nav.navbar.fixed-header {
            isolation: isolate;
            contain: layout style;
        }
        
        /* Adjust brand section height to account for navbar */
        .brand-section {
            min-height: calc(100vh - var(--navbar-height));
            padding-top: 2rem;
            padding-bottom: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Ensure login form section adjusts properly */
        .col-lg-6:not(.brand-section) {
            min-height: calc(100vh - var(--navbar-height));
            display: flex;
            align-items: center;
            padding-top: 2rem;
            padding-bottom: 2rem;
        }
        
        /* Mobile adjustments */
        @media (max-width: 991.98px) {
            .login-page-exp .login-main-wrapper {
                min-height: auto;
            }
            
            .brand-section,
            .col-lg-6:not(.brand-section) {
                min-height: auto;
                padding-top: 1.5rem;
                padding-bottom: 1.5rem;
            }
        }
        
        /* reCAPTCHA v3 badge positioning */
        .grecaptcha-badge {
            z-index: 9999 !important;
            position: fixed !important;
            bottom: 14px !important;
            right: 14px !important;
        }
        
        /* Experimental banner */
        .experimental-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.5rem 1rem;
            text-align: center;
            font-size: 0.875rem;
            font-weight: 600;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 9999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        body.has-experimental-banner {
            padding-top: calc(var(--navbar-height) + 36px) !important;
        }
        
        nav.navbar.fixed-header.with-banner {
            top: calc(var(--topbar-height) + 36px);
        }
        
        /* ===== LANDING PAGE NAVBAR STYLE MATCHING ===== */
        /* Override Bootstrap button styles to match landing page exactly */
        
        /* Primary Button (Sign Up) - Landing page style */
        .navbar .btn-primary {
            background-color: var(--thm-primary) !important;
            border: 2px solid var(--thm-primary) !important;
            color: white !important;
            font-weight: 500 !important;
            padding: 8px 24px !important;
            border-radius: 8px !important;
            transition: all 0.3s ease !important;
        }
        
        .navbar .btn-primary:hover {
            background-color: var(--thm-green) !important;
            border-color: var(--thm-green) !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 12px rgba(24, 165, 74, 0.3) !important;
        }
        
        /* Outline Button (Log in) - Landing page style */
        .navbar .btn-outline-primary {
            background-color: transparent !important;
            border: 2px solid var(--thm-primary) !important;
            color: var(--thm-primary) !important;
            font-weight: 500 !important;
            padding: 8px 24px !important;
            border-radius: 8px !important;
            transition: all 0.3s ease !important;
        }
        
        .navbar .btn-outline-primary:hover {
            background-color: var(--thm-primary) !important;
            border-color: var(--thm-primary) !important;
            color: white !important;
            transform: translateY(-1px) !important;
        }
        
        /* Brand font weight to match landing */
        .navbar .navbar-brand {
            font-weight: 700 !important;
        }
        
        /* Nav link styles to match landing */
        .navbar .nav-link {
            font-weight: 500 !important;
            transition: color 0.3s ease !important;
        }
    </style>

</head>
<body class="login-page-exp has-experimental-banner">
    <!-- Experimental Banner -->
    <div class="experimental-banner">
        🧪 EXPERIMENTAL VERSION - Testing Navbar Isolation Fixes
    </div>
    
    <?php
    // Configure navbar for login page
    // Display municipality name with logo badge
    $custom_brand_config = [
        'href' => 'website/landingpage.php',
        'name' => 'EducAid • ' . $municipality_name,
        'hide_educaid_logo' => true, // Flag to hide the EducAid logo in navbar
        'show_municipality' => true,
        'municipality_logo' => $municipality_logo,
        'municipality_name' => $municipality_name
    ];
    
    // Empty nav links array - no navigation menu items
    $custom_nav_links = [];
    
    // Include navbar with custom configuration
    include 'includes/website/navbar.php';
    ?>
    
    <!-- Main Login Container - Using unique class to isolate from navbar -->
    <div class="login-content-container">
        <div class="login-main-wrapper">
            <div class="container-fluid p-0">
                <div class="row g-0 h-100">
                    <!-- Brand Section - Hidden on mobile, visible on tablet+ -->
                    <div class="col-lg-6 d-none d-lg-flex brand-section">
                        <div class="brand-content">
                            <div class="brand-logo">
                                <img src="assets/images/logo.png" alt="EducAid Logo" class="img-fluid">
                            </div>
                            <h1 class="brand-title">EducAid</h1>
                            <p class="brand-subtitle">
                                Empowering students through accessible financial assistance programs in General Trias.
                            </p>
                            <ul class="feature-list d-none d-xl-block">
                                <li class="feature-item">
                                    <i class="bi bi-shield-check"></i>
                                    <span>Secure Application Process</span>
                                </li>
                                <li class="feature-item">
                                    <i class="bi bi-clock-history"></i>
                                    <span>Fast Processing Time</span>
                                </li>
                                <li class="feature-item">
                                    <i class="bi bi-people"></i>
                                    <span>Community Support</span>
                                </li>
                                <li class="feature-item">
                                    <i class="bi bi-award"></i>
                                    <span>Merit-Based Awards</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Mobile Brand Header - Only visible on mobile -->
                    <div class="col-12 d-lg-none mobile-brand-header">
                        <div class="container py-4">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <div class="mobile-logo">
                                        <img src="assets/images/logo.png" alt="EducAid Logo" class="img-fluid">
                                    </div>
                                </div>
                                <div class="col">
                                    <h4 class="mobile-brand-title mb-0">EducAid</h4>
                                    <small class="text-muted">General Trias Financial Assistance</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Section -->
                    <div class="col-12 col-lg-6 form-section">
                        <div class="container h-100 py-4 py-lg-0">
                            <div class="row justify-content-center align-items-center h-100">
                                <div class="col-12 col-sm-11 col-md-9 col-lg-11 col-xl-9 col-xxl-8">
                                    <div class="login-card">
                                        <div class="login-header">
                                            <h2 class="login-title">Welcome Back</h2>
                                            <p class="login-subtitle">Sign in to access your EducAid account</p>
                                        </div>

                                        <!-- Messages Container -->
                                        <div id="messages" class="message-container mb-3"></div>

                                        <!-- Step 1: Simplified Credentials (Email + Password Only) -->
                                        <div id="step1" class="step active">
                                            <form id="loginForm">
                                                <div class="form-group mb-3">
                                                    <label class="form-label">Email Address</label>
                                                    <input type="email" class="form-control form-control" id="email" name="email" 
                                                           placeholder="Enter your email address" required autocomplete="email">
                                                </div>
                                                <div class="form-group mb-4">
                                                    <label class="form-label">Password</label>
                                                    <div class="position-relative">
                                                        <input type="password" class="form-control form-control" id="password" name="password" 
                                                               placeholder="Enter your password" required autocomplete="current-password">
                                                        <button type="button" class="btn btn-link position-absolute end-0 top-50 translate-middle-y" 
                                                                onclick="togglePassword('password')" style="text-decoration: none; padding: 0 15px;">
                                                            <i class="bi bi-eye" id="password-toggle-icon"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <div class="d-grid">
                                                    <button type="submit" class="btn btn-primary btn-lg" id="loginSubmitBtn">
                                                        <i class="bi bi-envelope me-2"></i>Send Verification Code
                                                    </button>
                                                </div>
                                            </form>
                                        </div>

                                        <!-- Step 2: OTP Verification -->
                                        <div id="step2" class="step">
                                            <div class="text-center mb-4">
                                                <i class="bi bi-shield-check text-primary" style="font-size: 3rem;"></i>
                                                <h5 class="mt-3">Verify Your Identity</h5>
                                                <p class="text-muted">Enter the 6-digit code sent to your email</p>
                                            </div>
                                            <form id="otpForm">
                                                <div class="form-group mb-4">
                                                    <input type="text" class="form-control form-control otp-input text-center" 
                                                           id="login_otp" name="login_otp" placeholder="000000" maxlength="6" required
                                                           style="font-size: 1.5rem; letter-spacing: 0.5em;">
                                                </div>
                                                <div class="d-grid">
                                                    <button type="submit" class="btn btn-primary btn-lg">
                                                        <i class="bi bi-shield-check me-2"></i>Verify & Sign In
                                                    </button>
                                                </div>
                                            </form>
                                        </div>

                                        <!-- Registration Section -->
                                        <div class="signup-section mt-4">
                                            <div class="text-center">
                                                <p class="mb-2">Don't have an account yet?</p>
                                                <a href="modules/student/student_register.php" class="btn btn-outline-primary">
                                                    <i class="bi bi-person-plus me-2"></i>Create Account
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    include_once 'includes/footer.php';
    ?>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/login.js"></script>
    
    <!-- reCAPTCHA v3 Integration -->
    <script>
        // Email validation function
        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }
        
        // Message display function
        function showMessage(message, type) {
            const messagesContainer = document.getElementById('messages');
            messagesContainer.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                const alert = messagesContainer.querySelector('.alert');
                if (alert) {
                    alert.remove();
                }
            }, 5000);
        }
        
        // Button loading state function
        function setButtonLoading(button, loading, originalText = '') {
            if (loading) {
                button.disabled = true;
                button.innerHTML = `
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <span>Processing...</span>
                `;
            } else {
                button.disabled = false;
                button.innerHTML = originalText || button.innerHTML;
            }
        }
        
        // Override the login form submission to include reCAPTCHA v3
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            if (loginForm) {
                // Remove existing event listeners and add our own
                const newForm = loginForm.cloneNode(true);
                loginForm.parentNode.replaceChild(newForm, loginForm);
                
                newForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const email = document.getElementById('email').value.trim();
                    const password = document.getElementById('password').value;
                    
                    // Basic validation
                    if (!email || !password) {
                        showMessage('Please fill in all fields.', 'danger');
                        return;
                    }
                    
                    if (!isValidEmail(email)) {
                        showMessage('Please enter a valid email address.', 'danger');
                        return;
                    }
                    
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    
                    setButtonLoading(submitBtn, true);

                    // Get reCAPTCHA v3 token
                    grecaptcha.ready(function() {
                        grecaptcha.execute('<?php echo RECAPTCHA_SITE_KEY; ?>', {action: 'login'}).then(function(token) {
                            const formData = new FormData();
                            formData.append('email', email);
                            formData.append('password', password);
                            formData.append('g-recaptcha-response', token);

                            fetch('unified_login_experiment.php', {
                                method: 'POST',
                                body: formData,
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            })
                            .then(response => response.json())
                            .then(data => {
                                setButtonLoading(submitBtn, false, originalText);
                                
                                if (data.status === 'otp_sent') {
                                    showStep2();
                                    showMessage('Verification code sent to your email!', 'success');
                                } else {
                                    showMessage(data.message, 'danger');
                                }
                            })
                            .catch(error => {
                                setButtonLoading(submitBtn, false, originalText);
                                showMessage('Connection error. Please try again.', 'danger');
                            });
                        });
                    });
                });
            }
        });
    </script>

</body>
</html>
