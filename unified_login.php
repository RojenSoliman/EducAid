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

// LOGIN PHASE 1: credentials → send login-OTP
if (
    isset($_POST['firstname'], $_POST['lastname'], $_POST['email'], $_POST['password'])
    && !isset($_POST['login_action'])
    && !isset($_POST['forgot_action'])
) {
    $fn = trim($_POST['firstname']);
    $ln = trim($_POST['lastname']);
    $em = trim($_POST['email']);
    $pw = $_POST['password'];

    // Check if user is a student
    $studentRes = pg_query_params($connection,
        "SELECT student_id, password, status, 'student' as role FROM students
         WHERE first_name = $1 AND last_name = $2 AND email = $3",
        [$fn, $ln, $em]
    );
    
    // Check if user is an admin
    $adminRes = pg_query_params($connection,
        "SELECT admin_id, password, 'admin' as role FROM admins
         WHERE first_name = $1 AND last_name = $2 AND email = $3",
        [$fn, $ln, $em]
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
        echo json_encode(['status'=>'error','message'=>'User not found.']);
        exit;
    }
    
    if (!password_verify($pw, $user['password'])) {
        echo json_encode(['status'=>'error','message'=>'Invalid password.']);
        exit;
    }

    // Check if student is under registration (not yet approved)
    if ($user['role'] === 'student' && isset($user['status']) && $user['status'] === 'under_registration') {
        echo json_encode([
            'status'=>'error',
            'message'=>'Your registration is still under review. Please wait for admin approval before logging in. You will receive an email notification once approved.'
        ]);
        exit;
    }

    // Check if student registration was rejected
    if ($user['role'] === 'student' && isset($user['status']) && $user['status'] === 'disabled') {
        echo json_encode([
            'status'=>'error',
            'message'=>'Your registration has been declined. Please contact the administrator for more information.'
        ]);
        exit;
    }

    // Credentials OK → generate OTP
    $otp = rand(100000,999999);
    $_SESSION['login_otp'] = $otp;
    $_SESSION['login_otp_time'] = time();
    $_SESSION['login_pending'] = [
        'user_id' => $user['id'],
        'role' => $user['role'],
        'name' => "$fn $ln"
    ];

    // Send via email
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
        $mail->Body = "Your one-time login code is: <strong>$otp</strong><br>Valid for 5 minutes.";

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
        
        // Check both tables
        $studentRes = pg_query_params($connection, "SELECT student_id, 'student' as role FROM students WHERE email = $1", [$email]);
        $adminRes = pg_query_params($connection, "SELECT admin_id, 'admin' as role FROM admins WHERE email = $1", [$email]);
        
        $user = null;
        if ($studentRow = pg_fetch_assoc($studentRes)) {
            $user = $studentRow;
        } elseif ($adminRow = pg_fetch_assoc($adminRes)) {
            $user = $adminRow;
        }
        
        if (!$user) {
            echo json_encode(['status'=>'error','message'=>'Email not found.']);
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
            $mail->Body = "Your OTP is: <strong>$otp</strong><br>This is valid for 5 minutes.";

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
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .login-container { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { background: white; border-radius: 15px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); padding: 2rem; max-width: 400px; width: 100%; }
        .logo { width: 80px; height: 80px; margin: 0 auto 1rem; display: block; }
        .btn-primary { background: linear-gradient(45deg, #667eea, #764ba2); border: none; }
        .form-control:focus { border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25); }
        .step { display: none; }
        .step.active { display: block; }
        .signup-link { 
            padding: 0.5rem; 
            border: 1px solid #e0e0e0; 
            border-radius: 8px; 
            background-color: #f8f9fa; 
            margin-top: 0.5rem;
        }
        .signup-link a { 
            color: #667eea; 
            font-weight: 600; 
        }
        .signup-link a:hover { 
            color: #764ba2; 
            text-decoration: underline !important; 
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <img src="assets/images/logo.png" alt="EducAid Logo" class="logo">
            <h2 class="text-center mb-4">EducAid Login</h2>
            
            <!-- Step 1: Credentials -->
            <div id="step1" class="step active">
                <form id="loginForm">
                    <div class="mb-3">
                        <label class="form-label">First Name</label>
                        <input type="text" class="form-control" id="firstname" name="firstname" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Last Name</label>
                        <input type="text" class="form-control" id="lastname" name="lastname" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Send OTP</button>
                </form>
                <div class="text-center mt-3">
                    <a href="#" onclick="showForgotPassword()">Forgot Password?</a>
                </div>
                <div class="signup-link text-center">
                    <small class="text-muted">Don't have an account?</small><br>
                    <a href="modules/student/student_register.php" class="text-decoration-none">
                        <i class="bi bi-person-plus"></i> Sign up as Student
                    </a>
                </div>
            </div>

            <!-- Step 2: OTP Verification -->
            <div id="step2" class="step">
                <div class="text-center mb-3">
                    <h5>Enter OTP</h5>
                    <p class="text-muted">Check your email for the verification code</p>
                </div>
                <form id="otpForm">
                    <div class="mb-3">
                        <input type="text" class="form-control text-center" id="login_otp" name="login_otp" placeholder="000000" maxlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Verify & Login</button>
                </form>
                <div class="text-center mt-3">
                    <a href="#" onclick="showStep1()">Back to Login</a>
                </div>
            </div>

            <!-- Forgot Password Steps -->
            <div id="forgotStep1" class="step">
                <div class="text-center mb-3">
                    <h5>Reset Password</h5>
                    <p class="text-muted">Enter your email to receive OTP</p>
                </div>
                <form id="forgotForm">
                    <div class="mb-3">
                        <input type="email" class="form-control" id="forgot_email" name="forgot_email" placeholder="Enter your email" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Send OTP</button>
                </form>
                <div class="text-center mt-3">
                    <a href="#" onclick="showStep1()">Back to Login</a>
                </div>
            </div>

            <div id="forgotStep2" class="step">
                <div class="text-center mb-3">
                    <h5>Verify OTP</h5>
                    <p class="text-muted">Enter the OTP sent to your email</p>
                </div>
                <form id="forgotOtpForm">
                    <div class="mb-3">
                        <input type="text" class="form-control text-center" id="forgot_otp" name="forgot_otp" placeholder="000000" maxlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Verify OTP</button>
                </form>
            </div>

            <div id="forgotStep3" class="step">
                <div class="text-center mb-3">
                    <h5>New Password</h5>
                    <p class="text-muted">Enter your new password</p>
                </div>
                <form id="newPasswordForm">
                    <div class="mb-3">
                        <input type="password" class="form-control" id="forgot_new_password" name="forgot_new_password" placeholder="New Password" minlength="12" required>
                        <div class="form-text">Must be at least 12 characters</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Update Password</button>
                </form>
            </div>

            <div id="messages" class="mt-3"></div>
        </div>
    </div>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        function showStep1() { hideAllSteps(); document.getElementById('step1').classList.add('active'); }
        function showStep2() { hideAllSteps(); document.getElementById('step2').classList.add('active'); }
        function showForgotPassword() { hideAllSteps(); document.getElementById('forgotStep1').classList.add('active'); }
        function showForgotStep2() { hideAllSteps(); document.getElementById('forgotStep2').classList.add('active'); }
        function showForgotStep3() { hideAllSteps(); document.getElementById('forgotStep3').classList.add('active'); }
        function hideAllSteps() { document.querySelectorAll('.step').forEach(s => s.classList.remove('active')); }
        function showMessage(msg, type='danger') {
            document.getElementById('messages').innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
        }

        // Login Form
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('unified_login.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'otp_sent') {
                    showStep2();
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.message);
                }
            });
        });

        // OTP Verification
        document.getElementById('otpForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData();
            formData.append('login_action', 'verify_otp');
            formData.append('login_otp', document.getElementById('login_otp').value);
            
            fetch('unified_login.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    window.location.href = data.redirect;
                } else {
                    showMessage(data.message);
                }
            });
        });

        // Forgot Password Forms
        document.getElementById('forgotForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData();
            formData.append('forgot_action', 'send_otp');
            formData.append('forgot_email', document.getElementById('forgot_email').value);
            
            fetch('unified_login.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showForgotStep2();
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.message);
                }
            });
        });

        document.getElementById('forgotOtpForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData();
            formData.append('forgot_action', 'verify_otp');
            formData.append('forgot_otp', document.getElementById('forgot_otp').value);
            
            fetch('unified_login.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showForgotStep3();
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.message);
                }
            });
        });

        document.getElementById('newPasswordForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData();
            formData.append('forgot_action', 'set_new_password');
            formData.append('forgot_new_password', document.getElementById('forgot_new_password').value);
            
            fetch('unified_login.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showStep1();
                    showMessage('Password updated successfully! Please login.', 'success');
                } else {
                    showMessage(data.message);
                }
            });
        });
    </script>
</body>
</html>
