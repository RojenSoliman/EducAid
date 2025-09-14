<?php
include __DIR__ . '/config/database.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

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
    $em = trim($_POST['email']);
    $pw = $_POST['password'];

    // Check if user is a student (only allow active students to login)
    $studentRes = pg_query_params($connection,
        "SELECT student_id, password, first_name, last_name, status, 'student' as role FROM students
         WHERE email = $1 AND status != 'under_registration'",
        [$em]
    );
    
    // Check if user is an admin (simplified query)
    $adminRes = pg_query_params($connection,
        "SELECT admin_id, password, first_name, last_name, 'admin' as role FROM admins
         WHERE email = $1",
        [$em]
    );

    $user = null;
    if ($studentRow = pg_fetch_assoc($studentRes)) {
        $user = $studentRow;
        $user['id'] = $user['student_id'];
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

    // Send via email (same email logic as before)
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'dilucayaka02@gmail.com';
        $mail->Password = 'jlld eygl hksj flvg';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('dilucayaka02@gmail.com','EducAid');
        $mail->addAddress($em);
        $mail->isHTML(true);
        $mail->Subject = 'Your EducAid Login OTP';
        $mail->Body = "Hello {$user['first_name']},<br><br>Your one-time login code is: <strong>$otp</strong><br>Valid for 5 minutes.";

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
        $redirect = 'modules/student/student_homepage.php';
    } else {
        $_SESSION['admin_id'] = $pending['user_id'];
        $_SESSION['admin_username'] = $pending['name'];
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
        
        // Check both tables (exclude students under registration)
        $studentRes = pg_query_params($connection, "SELECT student_id, 'student' as role FROM students WHERE email = $1 AND status != 'under_registration'", [$email]);
        $adminRes = pg_query_params($connection, "SELECT admin_id, 'admin' as role FROM admins WHERE email = $1", [$email]);
        
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
            
            if (pg_fetch_assoc($underRegistrationCheck)) {
                echo json_encode([
                    'status'=>'error',
                    'message'=>'Your account is still under review. Password reset is not available until your account is approved.'
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

            $mail->setFrom('dilucayaka02@gmail.com','EducAid');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'EducAid Password Reset OTP';
            $mail->Body = "Hello,<br><br>You requested a password reset for your EducAid account.<br><br>Your OTP is: <strong>$otp</strong><br><br>This code is valid for 5 minutes.<br><br>If you didn't request this reset, please ignore this email.";

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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/universal.css" rel="stylesheet">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">

</head>
<body class = "login-page"> 
    <div class="container-fluid p-0">
        <div class="row g-0 min-vh-100">
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
                <div class="container h-100">
                    <div class="row justify-content-center align-items-center h-100">
                        <div class="col-12 col-sm-10 col-md-8 col-lg-12 col-xl-10 col-xxl-8">
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
                                            <button type="submit" class="btn btn-primary btn-lg">
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

    <?php
    include_once 'includes/footer.php';
    ?>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/login.js"></script>

</body>
</html>