<?php
session_start();

// Only unset student-specific session variables
unset($_SESSION['student_id']);
unset($_SESSION['student_username']);
unset($_SESSION['schedule_modal_shown']);

// Remove any student-specific temporary variables
$studentKeys = array_filter(array_keys($_SESSION), function($key) {
    return strpos($key, 'student_') === 0 || 
           strpos($key, 'profile_') === 0 || 
           strpos($key, 'upload_') === 0 ||
           strpos($key, 'qr_codes') === 0;
});

foreach ($studentKeys as $key) {
    unset($_SESSION[$key]);
}

// Clear any shared login/OTP variables (since user is logging out)
unset($_SESSION['login_otp']);
unset($_SESSION['login_otp_time']);
unset($_SESSION['login_pending']);
unset($_SESSION['forgot_otp']);
unset($_SESSION['forgot_otp_time']);
unset($_SESSION['forgot_otp_email']);
unset($_SESSION['forgot_otp_role']);
unset($_SESSION['forgot_otp_verified']);

header("Location: ../../unified_login.php");
exit;
?>