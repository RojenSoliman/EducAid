<?php
/**
 * CAPTCHA Verification Handler for Security Page
 */

// Include reCAPTCHA configuration
require_once '../config/recaptcha_v2_config.php';

// Start session
session_start();

// Set JSON response header
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get reCAPTCHA response
$recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

// Verify reCAPTCHA
if (empty($recaptchaResponse)) {
    echo json_encode(['success' => false, 'message' => 'Please complete the CAPTCHA verification']);
    exit;
}

// Verify reCAPTCHA with Google
$verifyURL = 'https://www.google.com/recaptcha/api/siteverify';
$verifyData = [
    'secret' => RECAPTCHA_V2_SECRET_KEY,
    'response' => $recaptchaResponse,
    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
];

$verifyResponse = file_get_contents($verifyURL . '?' . http_build_query($verifyData));
$verifyResult = json_decode($verifyResponse, true);

if (!$verifyResult['success']) {
    echo json_encode(['success' => false, 'message' => 'CAPTCHA verification failed. Please try again.']);
    exit;
}

// Set session variable to mark user as verified
$_SESSION['captcha_verified'] = true;
$_SESSION['captcha_verified_time'] = time();

// Log successful verification
$logFile = '../data/security_verifications.log';
$logEntry = date('Y-m-d H:i:s') . " - IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . " - User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . "\n";

// Create directory if it doesn't exist
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Append to log file
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

echo json_encode([
    'success' => true, 
    'message' => 'Verification successful. Redirecting to EducAid...'
]);
?>