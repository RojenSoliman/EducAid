<?php
/**
 * Newsletter Subscription Handler (No CAPTCHA)
 */

// Set JSON response header
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get form data
$email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
    exit;
}

// Here you would typically:
// 1. Save the email to your database
// 2. Send a confirmation email
// 3. Add to mailing list service

// For demo purposes, we'll just simulate success
// You can integrate with your actual newsletter system here

try {
    // Example: Save to a simple file or database
    $logFile = '../data/newsletter_subscribers.log';
    $logEntry = date('Y-m-d H:i:s') . " - Email: $email - IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
    
    // Create directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    // Append to log file
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Thank you for subscribing! You\'ll receive updates about EducAid programs and announcements.'
    ]);
    
} catch (Exception $e) {
    error_log("Newsletter subscription error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to process subscription. Please try again later.']);
}
?>