<?php
/**
 * EducAid Website Entry Point
 * Redirects to security verification page
 */

// Start session
session_start();

// Check if user is already verified
if (isset($_SESSION['captcha_verified']) && $_SESSION['captcha_verified'] === true) {
    // Check if verification is still valid (24 hours)
    $verificationTime = $_SESSION['captcha_verified_time'] ?? 0;
    $expirationTime = 24 * 60 * 60; // 24 hours
    
    if (time() - $verificationTime <= $expirationTime) {
        // Still valid, go to landing page
        header('Location: landingpage.php');
        exit;
    } else {
        // Expired, clear session
        unset($_SESSION['captcha_verified']);
        unset($_SESSION['captcha_verified_time']);
    }
}

// Redirect to security verification
header('Location: security_verification.php');
exit;
?>