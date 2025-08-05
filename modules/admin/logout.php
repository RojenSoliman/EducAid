<?php
session_start();

// Only unset admin-specific session variables
unset($_SESSION['admin_id']);
unset($_SESSION['admin_username']);

// Remove any admin-specific temporary variables
$adminKeys = array_filter(array_keys($_SESSION), function($key) {
    return strpos($key, 'admin_') === 0;
});

foreach ($adminKeys as $key) {
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