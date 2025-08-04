<?php
include __DIR__ . '/../../config/database.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

// Handle AJAX Forgot Password Requests First
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_action'])) {
    header('Content-Type: application/json');

    // SEND OTP
    if ($_POST['forgot_action'] === 'send_otp' && isset($_POST['forgot_email'])) {
        $email = trim($_POST['forgot_email']);
        $res = pg_query_params($connection, "SELECT student_id FROM students WHERE email = $1", [$email]);
        if (!$res || pg_num_rows($res) === 0) {
            echo json_encode(['status'=>'error', 'message'=>'Email not found.']); exit;
        }
        $otp = rand(100000,999999);
        $_SESSION['forgot_otp'] = $otp;
        $_SESSION['forgot_otp_email'] = $email;
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

            $mail->setFrom('dilucayaka02@gmail.com', 'EducAid');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'EducAid Password Reset OTP';
            $mail->Body = "Your OTP is: <strong>$otp</strong><br><br>This is valid for 5 minutes.";

            $mail->send();
            echo json_encode(['status'=>'success', 'message'=>'OTP sent to your email.']);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error', 'message'=>'Failed to send OTP.']);
        }
        exit;
    }

    // VERIFY OTP
    if ($_POST['forgot_action'] === 'verify_otp' && isset($_POST['forgot_otp'])) {
        if (!isset($_SESSION['forgot_otp'], $_SESSION['forgot_otp_time'], $_SESSION['forgot_otp_email'])) {
            echo json_encode(['status'=>'error', 'message'=>'Session expired.']);
            exit;
        }

        if (time() - $_SESSION['forgot_otp_time'] > 300) {
            session_unset();
            echo json_encode(['status'=>'error', 'message'=>'OTP expired.']);
            exit;
        }

        if ($_POST['forgot_otp'] == $_SESSION['forgot_otp']) {
            $_SESSION['forgot_otp_verified'] = true;
            echo json_encode(['status'=>'success', 'message'=>'OTP verified.']);
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Incorrect OTP.']);
        }
        exit;
    }

    // SET NEW PASSWORD
    if ($_POST['forgot_action'] === 'set_new_password' && isset($_POST['forgot_new_password'])) {
        if (!isset($_SESSION['forgot_otp_verified'], $_SESSION['forgot_otp_email']) || !$_SESSION['forgot_otp_verified']) {
            echo json_encode(['status'=>'error', 'message'=>'OTP verification required.']); exit;
        }
        $newPwd = $_POST['forgot_new_password'];
        if (strlen($newPwd) < 12) {
            echo json_encode(['status'=>'error', 'message'=>'Password must be at least 12 characters.']);
            exit;
        }
        $hashed = password_hash($newPwd, PASSWORD_ARGON2ID);
        $update = pg_query_params($connection, "UPDATE students SET password=$1 WHERE email=$2", [$hashed, $_SESSION['forgot_otp_email']]);
        if ($update) {
            session_unset();
            echo json_encode(['status'=>'success', 'message'=>'Password updated successfully.']);
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Update failed.']);
        }
        exit;
    }
}

// LOGIN FLOW
if (isset($_SESSION['student_username'])) {
    header("Location: student_homepage.php");
    exit;
}

if (isset($_POST['student_login'])) {
    $firstname = $_POST['firstname'];
    $lastname = $_POST['lastname'];
    $email = $_POST['email'];
    $password = $_POST['password'];

    $result = pg_query_params($connection, "SELECT * FROM students WHERE first_name=$1 AND last_name=$2 AND email=$3", [$firstname, $lastname, $email]);

    if ($row = pg_fetch_assoc($result)) {
        if (password_verify($password, $row['password'])) {
            $_SESSION['student_username'] = $firstname . ' ' . $lastname;
            $_SESSION['student_id'] = $row['student_id'];
            header("Location: student_homepage.php");
            exit;
        } else {
            echo "<script>alert('Invalid password.');window.location.href='student_login.html';</script>";
            exit;
        }
    } else {
        echo "<script>alert('User not found.');window.location.href='student_login.html';</script>";
        exit;
    }
}
?>
