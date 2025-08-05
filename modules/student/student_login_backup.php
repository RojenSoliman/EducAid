<?php
// Original student_login.php - Backed up on unification

include __DIR__ . '/../../config/database.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

// Always return JSON
header('Content-Type: application/json');

// — — — LOGIN PHASE 1: credentials → send login-OTP — — —
if (
    isset($_POST['firstname'], $_POST['lastname'], $_POST['email'], $_POST['password'])
    && !isset($_POST['login_action'])
    && !isset($_POST['forgot_action'])
) {
    $fn = trim($_POST['firstname']);
    $ln = trim($_POST['lastname']);
    $em = trim($_POST['email']);
    $pw = $_POST['password'];

    $res = pg_query_params($connection,
        "SELECT student_id, password FROM students
         WHERE first_name = $1 AND last_name = $2 AND email = $3",
        [$fn, $ln, $em]
    );
    if (!($row = pg_fetch_assoc($res))) {
        echo json_encode(['status'=>'error','message'=>'User not found.']);
        exit;
    }
    if (!password_verify($pw, $row['password'])) {
        echo json_encode(['status'=>'error','message'=>'Invalid password.']);
        exit;
    }

    // Credentials OK → generate OTP
    $otp = rand(100000,999999);
    $_SESSION['login_otp']      = $otp;
    $_SESSION['login_otp_time'] = time();
    $_SESSION['login_pending']  = [
      'student_id' => $row['student_id'],
      'name'       => "$fn $ln"
    ];

    // Send via email
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'dilucayaka02@gmail.com';  // your SMTP username
        $mail->Password   = 'jlld eygl hksj flvg';      // your SMTP password/app-password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('dilucayaka02@gmail.com','EducAid');
        $mail->addAddress($em);
        $mail->isHTML(true);
        $mail->Subject = 'Your EducAid Login OTP';
        $mail->Body    = "Your one-time login code is: <strong>$otp</strong><br>Valid for 5 minutes.";

        $mail->send();
        echo json_encode(['status'=>'otp_sent','message'=>'OTP sent to your email.']);
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>'Could not send OTP.']);
    }
    exit;
}

// — — — LOGIN PHASE 2: verify login-OTP — — —
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

    // OTP OK → finalize login
    $_SESSION['student_id']       = $_SESSION['login_pending']['student_id'];
    $_SESSION['student_username'] = $_SESSION['login_pending']['name'];
    unset($_SESSION['login_otp'], $_SESSION['login_pending']);

    echo json_encode([
      'status'   => 'success',
      'message'  => 'Logged in!',
      'redirect' => 'student_homepage.php'
    ]);
    exit;
}

// — — — FORGOT-PASSWORD OTP FLOW — — —
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_action'])) {
    // SEND OTP for Forgot-Password
    if ($_POST['forgot_action'] === 'send_otp' && !empty($_POST['forgot_email'])) {
        $email = trim($_POST['forgot_email']);
        $res = pg_query_params($connection,
            "SELECT student_id FROM students WHERE email = $1",
            [$email]
        );
        if (!$res || pg_num_rows($res) === 0) {
            echo json_encode(['status'=>'error','message'=>'Email not found.']);
            exit;
        }
        $otp = rand(100000,999999);
        $_SESSION['forgot_otp']        = $otp;
        $_SESSION['forgot_otp_email']  = $email;
        $_SESSION['forgot_otp_time']   = time();

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'dilucayaka02@gmail.com';
            $mail->Password   = 'jlld eygl hksj flvg';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('dilucayaka02@gmail.com','EducAid');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'EducAid Password Reset OTP';
            $mail->Body    = "Your OTP is: <strong>$otp</strong><br>This is valid for 5 minutes.";

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
        if (!isset($_SESSION['forgot_otp_verified'], $_SESSION['forgot_otp_email'])
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
        $update = pg_query_params($connection,
            "UPDATE students SET password = $1 WHERE email = $2",
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

// If no route matched:
echo json_encode(['status'=>'error','message'=>'Invalid request.']);
exit;
?>
