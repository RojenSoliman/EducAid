<?php
// config/error_logging.php

function logEAFError($message, $context = []) {
    $logFile = __DIR__ . '/../logs/security_verifications.log';
    $logDir = dirname($logFile);
    
    // Create logs directory if it doesn't exist
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown IP';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Agent';
    $sessionId = session_id() ?? 'No Session';
    
    $logMessage = "[{$timestamp}] [IP: {$ip}] [Session: {$sessionId}] {$message}";
    
    // Add context if provided
    if (!empty($context)) {
        $logMessage .= " | Context: " . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    
    // Add user agent for additional debugging
    $logMessage .= " | User-Agent: {$userAgent}";
    
    $logMessage .= PHP_EOL;
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    
    // Also log to PHP error log for immediate visibility
    error_log("EAF_ERROR: " . trim(str_replace(PHP_EOL, ' ', $message)));
}

// Function to log file upload specific errors
function logFileUploadError($fieldName, $fileData, $additionalInfo = '') {
    $context = [
        'field' => $fieldName,
        'file_name' => $fileData['name'] ?? 'No file',
        'file_size' => $fileData['size'] ?? 0,
        'file_error' => $fileData['error'] ?? 'Unknown',
        'file_type' => $fileData['type'] ?? 'Unknown',
        'additional_info' => $additionalInfo
    ];
    
    $uploadErrors = [
        0 => 'No error',
        1 => 'File exceeds upload_max_filesize',
        2 => 'File exceeds MAX_FILE_SIZE directive',
        3 => 'File partially uploaded',
        4 => 'No file uploaded',
        6 => 'Missing temporary folder',
        7 => 'Failed to write file to disk',
        8 => 'PHP extension stopped upload'
    ];
    
    $errorMsg = $uploadErrors[$fileData['error']] ?? "Unknown error ({$fileData['error']})";
    
    logEAFError("FILE_UPLOAD_FAILED - Field: {$fieldName} - {$errorMsg}", $context);
}

// Function to log database errors
function logDatabaseError($operation, $error, $query = '') {
    $context = [
        'operation' => $operation,
        'error' => $error,
        'query' => $query
    ];
    
    logEAFError("DATABASE_ERROR - {$operation}", $context);
}

// Function to log form submission errors
function logFormError($formType, $error, $postData = []) {
    // Sanitize sensitive data before logging
    $sanitizedData = $postData;
    if (isset($sanitizedData['password'])) $sanitizedData['password'] = '***HIDDEN***';
    if (isset($sanitizedData['g-recaptcha-response'])) $sanitizedData['g-recaptcha-response'] = '***HIDDEN***';
    
    $context = [
        'form_type' => $formType,
        'error' => $error,
        'post_data' => $sanitizedData
    ];
    
    logEAFError("FORM_SUBMISSION_ERROR - {$formType}", $context);
}

// Function to log security violations
function logSecurityViolation($violationType, $details = '') {
    $context = [
        'violation_type' => $violationType,
        'details' => $details
    ];
    
    logEAFError("SECURITY_VIOLATION - {$violationType}", $context);
}