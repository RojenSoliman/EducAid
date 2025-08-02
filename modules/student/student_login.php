<?php
include __DIR__ . '/../../config/database.php';
session_start();

// PHPMailer setup for OTP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

// ---- AJAX Forgot Password (OTP) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_action'])) {
    header('Content-Type: application/json');
    // Step 1: Send OTP
    if ($_POST['forgot_action'] === 'send_otp' && isset($_POST['forgot_email'])) {
        $forgot_email = trim($_POST['forgot_email']);
        $res = pg_query_params($connection, "SELECT student_id FROM students WHERE email = $1", [$forgot_email]);
        if (!$res || pg_num_rows($res) === 0) {
            echo json_encode(['status'=>'error', 'message'=>'Email not found.']);
            exit;
        }
        $otp = rand(100000,999999);
        $_SESSION['forgot_otp'] = $otp;
        $_SESSION['forgot_otp_email'] = $forgot_email;
        $_SESSION['forgot_otp_time'] = time();
        // Send OTP
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'dilucayaka02@gmail.com';
            $mail->Password   = 'jlld eygl hksj flvg';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->setFrom('dilucayaka02@gmail.com', 'EducAid');
            $mail->addAddress($forgot_email);
            $mail->isHTML(true);
            $mail->Subject = 'EducAid Password Reset OTP';
            $mail->Body    = "Your OTP for resetting your EducAid password is: <strong>$otp</strong><br><br>This OTP is valid for 5 minutes.";
            $mail->AltBody = "Your OTP for resetting your EducAid password is: $otp. This OTP is valid for 5 minutes.";
            $mail->send();
            echo json_encode(['status'=>'success', 'message'=>'OTP sent! Please check your email.']);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error', 'message'=>'Failed to send OTP. Please try again.']);
        }
        exit;
    }
    // Step 2: Verify OTP
    if ($_POST['forgot_action'] === 'verify_otp' && isset($_POST['forgot_otp'])) {
        $entered = trim($_POST['forgot_otp']);
        if (!isset($_SESSION['forgot_otp'], $_SESSION['forgot_otp_email'], $_SESSION['forgot_otp_time'])) {
            echo json_encode(['status'=>'error','message'=>'No OTP session found.']);
            exit;
        }
        if ((time() - $_SESSION['forgot_otp_time']) > 300) { // 5 min
            unset($_SESSION['forgot_otp'], $_SESSION['forgot_otp_email'], $_SESSION['forgot_otp_time']);
            echo json_encode(['status'=>'error','message'=>'OTP expired.']);
            exit;
        }
        if ((string)$_SESSION['forgot_otp'] === (string)$entered) {
            $_SESSION['forgot_otp_verified'] = true;
            echo json_encode(['status'=>'success', 'message'=>'OTP verified!']);
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Invalid OTP.']);
        }
        exit;
    }
    // Step 3: Set new password
    if ($_POST['forgot_action'] === 'set_new_password' && isset($_POST['forgot_new_password'])) {
        $new_pwd = $_POST['forgot_new_password'];
        if (!isset($_SESSION['forgot_otp_verified'], $_SESSION['forgot_otp_email']) || !$_SESSION['forgot_otp_verified']) {
            echo json_encode(['status'=>'error','message'=>'OTP verification required.']);
            exit;
        }
        if (strlen($new_pwd) < 12) {
            echo json_encode(['status'=>'error','message'=>'Password must be at least 12 characters.']);
            exit;
        }
        // ---- Password currently used check ----
        $res = pg_query_params($connection, "SELECT password FROM students WHERE email = $1", [$_SESSION['forgot_otp_email']]);
        $row = pg_fetch_assoc($res);
        if ($row && password_verify($new_pwd, $row['password'])) {
            echo json_encode(['status'=>'error','message'=>'This password is currently used. Choose a different one.']);
            exit;
        }
        // ---- End check ----
        $hashed = password_hash($new_pwd, PASSWORD_ARGON2ID);
        $update = pg_query_params($connection, "UPDATE students SET password=$1 WHERE email=$2", [$hashed, $_SESSION['forgot_otp_email']]);
        if ($update) {
            unset($_SESSION['forgot_otp'], $_SESSION['forgot_otp_email'], $_SESSION['forgot_otp_time'], $_SESSION['forgot_otp_verified']);
            echo json_encode(['status'=>'success','message'=>'Password updated!']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Failed to update password.']);
        }
        exit;
    }
}

// ---- Login Flow ----
if (isset($_SESSION['student_username'])) {
    header("Location: student_homepage.php");
    exit;
}
if (isset($_POST['student_login'])) {
    $firstname = $_POST['firstname'];
    $lastname = $_POST['lastname'];
    $email = $_POST['email'];
    $password = $_POST['password'];

    if (!isset($connection) || !$connection) {
        echo "<p style='color:red;'>" . htmlspecialchars("Connection failed.") . "</p>";
        exit;
    }
    $result = pg_query_params(
        $connection,
        "SELECT * FROM students WHERE first_name =$1 AND last_name = $2 and email = $3",
        [$firstname, $lastname, $email]
    );
    if ($result === false) {
        echo "<p style='color:red;'>Database query error.</p>";
    } elseif ($row = pg_fetch_assoc($result)) {
        if (password_verify($password, $row['password'])) {
            $_SESSION['student_username'] = $firstname . ' ' . $lastname;
            $_SESSION['student_id'] = $row['student_id'];
            header("Location: student_homepage.php");
            exit;
        } else {
            // Invalid password
            echo "<script>alert('Invalid password.');window.location.href='student_login.html';</script>";
            exit;
        }
    } else {
        // User not found
        echo "<script>alert('User not found.');window.location.href='student_login.html';</script>";
        exit;
    }
    if (isset($connection) && $connection) {
        pg_close($connection);
    }
}
?>
