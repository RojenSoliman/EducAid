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
            $logo_path = trim($muni_data['preset_logo_image']);
            // Remove leading slash if present to make it relative to root
            $municipality_logo = ltrim($logo_path, '/');
        }
        pg_free_result($muni_result);
    }
}

// CMS System - Load content blocks for login page
$LOGIN_SAVED_BLOCKS = [];
if (isset($connection)) {
    $resBlocksLogin = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM landing_content_blocks WHERE municipality_id=1 AND block_key LIKE 'login_%'");
    if ($resBlocksLogin) {
        while($r = pg_fetch_assoc($resBlocksLogin)) { 
            $LOGIN_SAVED_BLOCKS[$r['block_key']] = $r; 
        }
        pg_free_result($resBlocksLogin);
    }
}

// CMS Helper functions for login page
function login_block($key, $defaultHtml){
    global $LOGIN_SAVED_BLOCKS;
    if(isset($LOGIN_SAVED_BLOCKS[$key])){ 
        $h = $LOGIN_SAVED_BLOCKS[$key]['html'];
        $h = strip_tags($h, '<p><br><b><strong><i><em><u><a><span><div><h1><h2><h3><h4><h5><h6><ul><ol><li>');
        return $h !== '' ? $h : $defaultHtml;
    }
    return $defaultHtml;
}

function login_block_style($key){
    global $LOGIN_SAVED_BLOCKS;
    if(!isset($LOGIN_SAVED_BLOCKS[$key])) return '';
    $r = $LOGIN_SAVED_BLOCKS[$key];
    $s = [];
    if(!empty($r['text_color'])) $s[] = 'color:'.$r['text_color'];
    if(!empty($r['bg_color'])) $s[] = 'background-color:'.$r['bg_color'];
    return $s ? ' style="'.implode(';', $s).'"' : '';
}

// Check if in edit mode (super admin with ?edit=1)
$IS_LOGIN_EDIT_MODE = false;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin' && isset($_GET['edit']) && $_GET['edit'] == '1') {
    $IS_LOGIN_EDIT_MODE = true;
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
    <title>EducAid - Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/universal.css" rel="stylesheet">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/website/landing_page.css" rel="stylesheet">
    
    <!-- Google reCAPTCHA v3 -->
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo RECAPTCHA_SITE_KEY; ?>"></script>
    
    <style>
        /* Navbar enabled with isolation fixes applied */
        :root {
            --topbar-height: 0px;
            --navbar-height: 0px;
            --thm-primary: #0051f8;
            --thm-green: #18a54a;
        }
        
        /* FIX 1: Unique body class to isolate from navbar */
        body.login-page-isolated {
            padding-top: var(--navbar-height);
            overflow-x: hidden;
            font-family: "Manrope", sans-serif;
        }
        
        /* FIX 2: Unique page wrapper to prevent container-fluid conflicts */
        .login-page-isolated .login-main-wrapper {
            min-height: calc(100vh - var(--navbar-height));
            max-width: 100vw;
            overflow-x: hidden;
        }
        
        /* FIX 3: Separate content container that won't affect navbar */
        .login-page-isolated .login-content-container {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 0;
        }
        
        /* FIX 4: CSS isolation for navbar to prevent page CSS bleed */
        .login-page-isolated nav.navbar.fixed-header {
            isolation: isolate;
            contain: layout style;
        }
        
        /* Match landing page navbar button styles */
        .login-page-isolated .navbar .btn-outline-primary {
            border: 2px solid var(--thm-primary) !important;
            color: var(--thm-primary) !important;
            background: #fff !important;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
        }
        
        .login-page-isolated .navbar .btn-outline-primary:hover {
            background: var(--thm-primary) !important;
            color: #fff !important;
            border-color: var(--thm-primary) !important;
            transform: translateY(-1px);
        }
        
        .login-page-isolated .navbar .btn-primary {
            background: var(--thm-primary) !important;
            color: #fff !important;
            border: 2px solid var(--thm-primary) !important;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
        }
        
        .login-page-isolated .navbar .btn-primary:hover {
            background: var(--thm-green) !important;
            border-color: var(--thm-green) !important;
            color: #fff !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(24, 165, 74, 0.3);
        }
        
        /* Match landing page navbar font */
        .login-page-isolated .navbar {
            font-family: "Manrope", sans-serif;
        }
        
        .login-page-isolated .navbar-brand {
            font-weight: 700;
        }
        
        .login-page-isolated .nav-link {
            font-weight: 500;
        }
        
        /* Topbar integration */
        .login-page-isolated .landing-topbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1050;
        }
        
        /* Adjust brand section height to account for navbar and topbar */
        .brand-section {
            min-height: calc(100vh - var(--navbar-height) - var(--topbar-height));
            padding-top: 2rem;
            padding-bottom: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Ensure login form section adjusts properly */
        .col-lg-6:not(.brand-section) {
            min-height: calc(100vh - var(--navbar-height) - var(--topbar-height));
            display: flex;
            align-items: center;
            padding-top: 2rem;
            padding-bottom: 2rem;
        }
        
        /* Mobile adjustments */
        @media (max-width: 991.98px) {
            .login-page-isolated .login-main-wrapper {
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
        
        /* ==== MODERN BRAND SECTION STYLES ==== */
        .brand-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow: hidden;
        }
        
        .brand-content {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 520px;
            padding: 3rem 2.5rem;
        }
        
        /* Hero Badge */
        .login-hero-badge {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            color: white;
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        /* Hero Title */
        .login-hero-title {
            font-size: 3rem;
            font-weight: 800;
            color: white;
            margin-bottom: 1rem;
            line-height: 1.1;
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #fff 0%, #e0e7ff 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Hero Subtitle */
        .login-hero-subtitle {
            font-size: 1.125rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2.5rem;
            line-height: 1.6;
        }
        
        /* Feature Cards */
        .feature-cards-grid {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .feature-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            transition: all 0.3s ease;
        }
        
        .feature-card:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(8px);
        }
        
        .feature-icon {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .feature-icon i {
            font-size: 1.5rem;
            color: white;
        }
        
        .feature-title {
            font-size: 1rem;
            font-weight: 700;
            color: white;
            margin-bottom: 0.25rem;
        }
        
        .feature-desc {
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.8);
            line-height: 1.5;
        }
        
        /* Decorative Circles */
        .brand-decorative-circles {
            position: absolute;
            inset: 0;
            z-index: 1;
            pointer-events: none;
        }
        
        .circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            animation: float 6s ease-in-out infinite;
        }
        
        .circle-1 {
            width: 400px;
            height: 400px;
            top: -200px;
            right: -100px;
            animation-delay: 0s;
        }
        
        .circle-2 {
            width: 300px;
            height: 300px;
            bottom: -150px;
            left: -75px;
            animation-delay: 2s;
        }
        
        .circle-3 {
            width: 200px;
            height: 200px;
            top: 50%;
            left: 10%;
            animation-delay: 4s;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) scale(1); }
            50% { transform: translateY(-20px) scale(1.05); }
        }
        
        /* Responsive adjustments */
        @media (max-width: 1199.98px) {
            .login-hero-title { font-size: 2.5rem; }
        }
        
        @media (max-width: 991.98px) {
            .brand-section { display: none !important; }
        }
    </style>
    
    <?php if ($IS_LOGIN_EDIT_MODE): ?>
    <!-- ContentTools for Super Admin Editing -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ContentTools@1.6.20/build/content-tools.min.css">
    <script src="https://cdn.jsdelivr.net/npm/ContentTools@1.6.20/build/content-tools.min.js"></script>
    <style>
        [data-login-key] {
            cursor: pointer;
            position: relative;
            transition: outline 0.2s ease;
        }
        [data-login-key]:hover {
            outline: 2px dashed rgba(255, 255, 255, 0.5);
            outline-offset: 4px;
        }
        .edit-mode-banner {
            position: fixed;
            top: calc(var(--topbar-height) + var(--navbar-height));
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 0.75rem 1rem;
            text-align: center;
            font-weight: 600;
            z-index: 9998;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
    </style>
    <?php endif; ?>

</head>
<body class="login-page-isolated has-header-offset<?php echo $IS_LOGIN_EDIT_MODE ? ' edit-mode' : ''; ?>">
    <?php
    // Include topbar
    include 'includes/website/topbar.php';
    
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
    
    // Include navbar with custom configuration and isolation fixes
    include 'includes/website/navbar.php';
    ?>
    
    <!-- Main Login Container - Using unique classes to isolate from navbar -->
    <div class="login-content-container">
        <div class="login-main-wrapper">
            <div class="container-fluid p-0">
        <div class="row g-0 h-100">
            <!-- Brand Section - Hidden on mobile, visible on tablet+ -->
            <div class="col-lg-6 d-none d-lg-flex brand-section">
                <div class="brand-content">
                    <!-- Hero Badge -->
                    <?php echo '<div class="login-hero-badge" data-login-key="login_hero_badge"'.login_block_style('login_hero_badge').'>'.login_block('login_hero_badge','<i class="bi bi-shield-check-fill me-2"></i>Trusted by 10,000+ Students').'</div>'; ?>
                    
                    <!-- Main Title -->
                    <?php echo '<h1 class="login-hero-title" data-login-key="login_hero_title"'.login_block_style('login_hero_title').'>'.login_block('login_hero_title','Welcome to<br><span class="gradient-text">EducAid</span>').'</h1>'; ?>
                    
                    <!-- Subtitle -->
                    <?php echo '<p class="login-hero-subtitle" data-login-key="login_hero_subtitle"'.login_block_style('login_hero_subtitle').'>'.login_block('login_hero_subtitle','Your gateway to accessible educational financial assistance in General Trias.').'</p>'; ?>
                    
                    <!-- Feature Cards -->
                    <div class="feature-cards-grid">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="bi bi-lightning-charge-fill"></i>
                            </div>
                            <?php echo '<div class="feature-title" data-login-key="login_feature1_title"'.login_block_style('login_feature1_title').'>'.login_block('login_feature1_title','Fast Processing').'</div>'; ?>
                            <?php echo '<div class="feature-desc" data-login-key="login_feature1_desc"'.login_block_style('login_feature1_desc').'>'.login_block('login_feature1_desc','Get your application reviewed within 48 hours').'</div>'; ?>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="bi bi-shield-fill-check"></i>
                            </div>
                            <?php echo '<div class="feature-title" data-login-key="login_feature2_title"'.login_block_style('login_feature2_title').'>'.login_block('login_feature2_title','Secure & Safe').'</div>'; ?>
                            <?php echo '<div class="feature-desc" data-login-key="login_feature2_desc"'.login_block_style('login_feature2_desc').'>'.login_block('login_feature2_desc','Your data is protected with enterprise-level security').'</div>'; ?>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="bi bi-phone-fill"></i>
                            </div>
                            <?php echo '<div class="feature-title" data-login-key="login_feature3_title"'.login_block_style('login_feature3_title').'>'.login_block('login_feature3_title','24/7 Support').'</div>'; ?>
                            <?php echo '<div class="feature-desc" data-login-key="login_feature3_desc"'.login_block_style('login_feature3_desc').'>'.login_block('login_feature3_desc','We\'re here to help anytime you need assistance').'</div>'; ?>
                        </div>
                    </div>
                    
                    <!-- Decorative Elements -->
                    <div class="brand-decorative-circles">
                        <div class="circle circle-1"></div>
                        <div class="circle circle-2"></div>
                        <div class="circle circle-3"></div>
                    </div>
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

                                <!-- Step Indicators -->
                                <div class="step-indicators justify-content-center mb-4" style="display: none;">
                                    <div class="step-indicator-item">
                                        <div class="step-indicator" id="indicator1"></div>
                                        <span class="step-label d-none d-sm-block" id="label1">Email</span>
                                    </div>
                                    <div class="step-indicator-item">
                                        <div class="step-indicator" id="indicator2"></div>
                                        <span class="step-label d-none d-sm-block" id="label2">Verify</span>
                                    </div>
                                    <div class="step-indicator-item">
                                        <div class="step-indicator" id="indicator3"></div>
                                        <span class="step-label d-none d-sm-block" id="label3">Password</span>
                                    </div>
                                </div>

                                <!-- Messages Container -->
                                <div id="messages" class="message-container mb-3"></div>

                                <!-- Logout Success Message -->
                                <?php if (isset($_SESSION['logout_message'])): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="bi bi-check-circle-fill me-2"></i>
                                    <?= htmlspecialchars($_SESSION['logout_message']) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php unset($_SESSION['logout_message']); ?>
                                <?php endif; ?>

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
                                    <div class="text-center mt-3">
                                        <a href="#" onclick="showForgotPassword()" class="text-decoration-none">
                                            <small>Forgot your password?</small>
                                        </a>
                                    </div>
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
                                    <div class="text-center mt-3">
                                        <a href="#" onclick="showStep1()" class="text-decoration-none">
                                            <i class="bi bi-arrow-left me-2"></i><small>Back to login</small>
                                        </a>
                                    </div>
                                </div>

                                <!-- Forgot Password Step 1: Email -->
                                <div id="forgotStep1" class="step">
                                    <div class="text-center mb-4">
                                        <i class="bi bi-key text-primary" style="font-size: 3rem;"></i>
                                        <h5 class="mt-3">Reset Your Password</h5>
                                        <p class="text-muted">Enter your email address to receive a reset code</p>
                                    </div>
                                    <form id="forgotForm">
                                        <div class="form-group mb-4">
                                            <label class="form-label">Email Address</label>
                                            <input type="email" class="form-control form-control" id="forgot_email" name="forgot_email" required>
                                        </div>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="bi bi-envelope me-2"></i>Send Reset Code
                                            </button>
                                        </div>
                                    </form>
                                    <div class="text-center mt-3">
                                        <a href="#" onclick="showStep1()" class="text-decoration-none">
                                            <i class="bi bi-arrow-left me-2"></i><small>Back to login</small>
                                        </a>
                                    </div>
                                </div>

                                <!-- Forgot Password Step 2: OTP Verification -->
                                <div id="forgotStep2" class="step">
                                    <div class="text-center mb-4">
                                        <i class="bi bi-check-circle text-primary" style="font-size: 3rem;"></i>
                                        <h5 class="mt-3">Enter Reset Code</h5>
                                        
                                        <p class="text-muted">Enter the 6-digit code sent to your email</p>
                                    </div>
                                    <form id="forgotOtpForm">
                                        <div class="form-group mb-4">
                                            <input type="text" class="form-control form-control otp-input text-center" 
                                                   id="forgot_otp" name="forgot_otp" placeholder="000000" maxlength="6" required
                                                   style="font-size: 1.5rem; letter-spacing: 0.5em;">
                                        </div>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="bi bi-check-circle me-2"></i>Verify Code
                                            </button>
                                        </div>
                                    </form>
                                    <div class="text-center mt-3">
                                        <a href="#" onclick="showForgotPassword()" class="text-decoration-none">
                                            <i class="bi bi-arrow-left me-2"></i><small>Back to email</small>
                                        </a>
                                    </div>
                                </div>

                                <!-- Forgot Password Step 3: New Password -->
                                <div id="forgotStep3" class="step">
                                    <div class="text-center mb-4">
                                        <i class="bi bi-lock text-primary" style="font-size: 3rem;"></i>
                                        <h5 class="mt-3">Set New Password</h5>
                                        <p class="text-muted">Choose a strong password for your account</p>
                                    </div>
                                    <form id="newPasswordForm">
                                        <div class="form-group mb-3">
                                            <label class="form-label">New Password</label>
                                            <input type="password" class="form-control form-control" id="forgot_new_password" 
                                                   name="forgot_new_password" placeholder="Minimum 12 characters" minlength="12" required>
                                            <div class="form-text">Password must be at least 12 characters long</div>
                                        </div>
                                        <div class="form-group mb-4">
                                            <label class="form-label">Confirm New Password</label>
                                            <input type="password" class="form-control form-control" id="confirm_password" 
                                                   name="confirm_password" placeholder="Re-enter your password" required>
                                        </div>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="bi bi-key me-2"></i>Update Password
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
    <!-- Close wrapper divs for isolation -->
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

                            fetch('unified_login.php', {
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
    
    <?php if ($IS_LOGIN_EDIT_MODE): ?>
    <!-- Edit Mode Banner -->
    <div class="edit-mode-banner">
        <i class="bi bi-pencil-square me-2"></i>
        <strong>EDIT MODE ACTIVE</strong> - Click on any text to edit. Changes will be saved automatically.
        <a href="unified_login.php" style="color: white; text-decoration: underline; margin-left: 1rem;">Exit Edit Mode</a>
    </div>
    
    <!-- ContentTools Editor Script -->
    <script>
        window.addEventListener('load', function() {
            var editor;
            
            ContentTools.StylePalette.add([
                new ContentTools.Style('Highlight', 'highlight', ['p', 'span', 'div']),
                new ContentTools.Style('Bold', 'bold', ['p', 'span', 'div'])
            ]);
            
            editor = ContentTools.EditorApp.get();
            editor.init('[data-login-key]', 'data-login-key');
            
            editor.addEventListener('saved', function(ev) {
                var name, payload, regions, xhr;
                
                regions = ev.detail().regions;
                
                if (Object.keys(regions).length == 0) {
                    return;
                }
                
                payload = new FormData();
                payload.append('municipality_id', '1');
                
                for (name in regions) {
                    if (regions.hasOwnProperty(name)) {
                        payload.append(name, regions[name]);
                    }
                }
                
                xhr = new XMLHttpRequest();
                xhr.addEventListener('readystatechange', function() {
                    if (xhr.readyState == 4) {
                        if (xhr.status == 200) {
                            new ContentTools.FlashUI('ok');
                        } else {
                            new ContentTools.FlashUI('no');
                        }
                    }
                });
                
                xhr.open('POST', 'services/save_login_content.php', true);
                xhr.send(payload);
            });
        });
    </script>
    <?php endif; ?>

</body>
</html>