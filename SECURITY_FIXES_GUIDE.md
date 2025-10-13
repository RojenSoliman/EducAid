# URGENT SECURITY FIXES - IMPLEMENTATION GUIDE

## ⚠️ STOP - READ THIS FIRST

**DO NOT commit any changes until you've revoked the exposed Gmail credentials!**

Execute these fixes in the order listed.

---

## FIX #1: REVOKE EXPOSED CREDENTIALS (DO THIS NOW!)

1. Go to https://myaccount.google.com/apppasswords
2. Find and REVOKE the app password: `jlld eygl hksj flvg`
3. Generate a NEW app password
4. Save it securely (you'll need it for Fix #3)

---

## FIX #2: Fix SQL Injection in upload_document.php

**File:** `modules/student/upload_document.php`  
**Lines:** 84-97

**REPLACE THIS CODE:**
```php
        // Move the uploaded file to the student's folder
        $filePath = $uploadDir . basename($fileName);
        if (move_uploaded_file($fileTmpName, $filePath)) {
            // Insert record into the documents table using escaped values
            // Escape values and insert record into the documents table
            /** @phpstan-ignore-next-line */
            @$esc_student_id = pg_escape_string($connection, $student_id);
            /** @phpstan-ignore-next-line */
            @$esc_type = pg_escape_string($connection, $fileType);
            /** @phpstan-ignore-next-line */
            @$esc_file_path = pg_escape_string($connection, $filePath);
            $sql = "INSERT INTO documents (student_id, type, file_path) VALUES ('{$esc_student_id}', '{$esc_type}', '{$esc_file_path}')";
            /** @phpstan-ignore-next-line */
            @pg_query($connection, $sql);
            $upload_success = true;
        } else {
            $upload_fail = true;
        }
```

**WITH THIS CODE:**
```php
        // Sanitize filename to prevent path traversal
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($fileName));
        $uniqueName = uniqid() . '_' . $safeName;
        
        // Move the uploaded file to the student's folder
        $filePath = $uploadDir . $uniqueName;
        if (move_uploaded_file($fileTmpName, $filePath)) {
            // Set restrictive file permissions
            chmod($filePath, 0644);
            
            // Insert record using parameterized query (SQL injection protection)
            $sql = "INSERT INTO documents (student_id, type, file_path) VALUES ($1, $2, $3)";
            $result = pg_query_params($connection, $sql, [$student_id, $fileType, $filePath]);
            
            if ($result) {
                $upload_success = true;
            } else {
                error_log("Document insert failed: " . pg_last_error($connection));
                $upload_fail = true;
                // Clean up file if database insert failed
                @unlink($filePath);
            }
        } else {
            error_log("File upload failed: " . $fileName);
            $upload_fail = true;
        }
```

---

## FIX #3: Move Credentials to Environment Variables

### Step 1: Create environment file

**Create file:** `config/env.php` (if it doesn't exist)

```php
<?php
// Email Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: 'dilucayaka02@gmail.com');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: 'YOUR_NEW_APP_PASSWORD_HERE');
define('SMTP_FROM_EMAIL', 'dilucayaka02@gmail.com');
define('SMTP_FROM_NAME', 'EducAid');

// For production, use environment variables:
// Set in Apache/Nginx config or use .env file with vlucas/phpdotenv
```

### Step 2: Update student_profile.php

**File:** `modules/student/student_profile.php`  
**Lines:** Around 40-50

**FIND:**
```php
            $mail->Username   = 'dilucayaka02@gmail.com'; // CHANGE
            $mail->Password   = 'jlld eygl hksj flvg';    // CHANGE
```

**REPLACE WITH:**
```php
            require_once __DIR__ . '/../../config/env.php';
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
```

### Step 3: Update student_settings.php

**File:** `modules/student/student_settings.php`  
**Lines:** Around 40-50

**Same replacement as student_profile.php**

---

## FIX #4: Fix Insecure mkdir Permissions

**File:** `modules/student/upload_document.php`  
**Line:** 68

**FIND:**
```php
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
```

**REPLACE WITH:**
```php
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
```

---

## FIX #5: Implement CSRF Protection

The CSRF protection system has been created at: `includes/csrf.php`

### Step 1: Add CSRF to upload_document.php

**Add at top of file (after session_start()):**
```php
session_start();
require_once __DIR__ . '/../../includes/csrf.php';

// For POST requests, verify CSRF token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['documents'])) {
    verify_csrf_or_die();
}
```

**In the HTML form (around line 340):**
```php
<form method="POST" enctype="multipart/form-data" id="uploadForm">
    <?php echo csrf_field(); ?>
    <!-- rest of form -->
</form>
```

### Step 2: Add CSRF to student_profile.php

**Add after session_start():**
```php
require_once __DIR__ . '/../../includes/csrf.php';
```

**Add to all forms (email change, password change, etc.):**
```php
<form method="POST">
    <?php echo csrf_field(); ?>
    <!-- form fields -->
</form>
```

**For AJAX requests, add this JavaScript:**
```javascript
// Get CSRF token from meta tag
const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// Add to AJAX requests
fetch(url, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
    },
    body: JSON.stringify(data)
});
```

### Step 3: Add CSRF meta tag in <head>

**In all pages with AJAX:**
```php
<head>
    <?php echo csrf_meta(); ?>
    <!-- other head content -->
</head>
```

---

## FIX #6: Improve File Upload Security

**File:** `modules/student/upload_document.php`  
**Add after line 60 (before the foreach loop):**

```php
    // File upload security configuration
    $maxFileSize = 5 * 1024 * 1024; // 5MB
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
    
    // Process the uploaded files with PRG pattern
    $upload_success = false;
    $upload_fail = false;
    $error_message = '';
    
    foreach ($_FILES['documents']['name'] as $index => $fileName) {
        $fileTmpName = $_FILES['documents']['tmp_name'][$index];
        $fileSize = $_FILES['documents']['size'][$index];
        $fileError = $_FILES['documents']['error'][$index];
        $fileType = $_POST['document_type'][$index];

        // Validate the document type
        if (!in_array($fileType, ['id_picture'])) {
            continue;
        }
        
        // Check for upload errors
        if ($fileError !== UPLOAD_ERR_OK) {
            $error_message = 'File upload error occurred';
            $upload_fail = true;
            continue;
        }
        
        // Check file size
        if ($fileSize > $maxFileSize) {
            $error_message = 'File too large (max 5MB)';
            $upload_fail = true;
            continue;
        }
        
        // Verify MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fileTmpName);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedMimeTypes)) {
            $error_message = 'Invalid file type';
            $upload_fail = true;
            continue;
        }
        
        // Verify extension
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions)) {
            $error_message = 'Invalid file extension';
            $upload_fail = true;
            continue;
        }

        // ... rest of upload code (the fixed version from Fix #2)
    }
```

---

## FIX #7: Add Input Validation

**File:** `modules/student/student_homepage.php`  
**Line:** 11

**FIND:**
```php
$studentId = $_SESSION['student_id'];
```

**REPLACE WITH:**
```php
$studentId = filter_var($_SESSION['student_id'] ?? 0, FILTER_VALIDATE_INT);
if ($studentId === false || $studentId <= 0) {
    session_destroy();
    header("Location: ../../unified_login.php");
    exit;
}
```

**Apply this same pattern to ALL files that use `$_SESSION['student_id']`**

---

## FIX #8: Improve OTP Security

**Files:** `student_profile.php` and `student_settings.php`

**FIND (around line 36):**
```php
        $otp = rand(100000, 999999);
```

**REPLACE WITH:**
```php
        $otp = random_int(100000, 999999);
```

**FIND (around line 67):**
```php
        if ((time() - $_SESSION['profile_otp_time']) > 40) {
```

**REPLACE WITH:**
```php
        // Implement rate limiting
        if (!isset($_SESSION['otp_request_count'])) {
            $_SESSION['otp_request_count'] = 0;
            $_SESSION['otp_request_reset'] = time();
        }
        
        if (time() - $_SESSION['otp_request_reset'] > 3600) {
            $_SESSION['otp_request_count'] = 0;
            $_SESSION['otp_request_reset'] = time();
        }
        
        if ($_SESSION['otp_request_count'] >= 3) {
            echo json_encode(['status' => 'error', 'message' => 'Too many OTP requests. Try again in an hour.']);
            exit;
        }
        
        if ((time() - $_SESSION['profile_otp_time']) > 300) { // Changed to 5 minutes
```

---

## FIX #9: Add Security Headers

**Create file:** `includes/security_headers.php`

```php
<?php
/**
 * Security Headers
 * Include this at the top of every page
 */

// Prevent clickjacking
header("X-Frame-Options: DENY");

// Prevent MIME sniffing
header("X-Content-Type-Options: nosniff");

// Enable XSS protection
header("X-XSS-Protection: 1; mode=block");

// Referrer policy
header("Referrer-Policy: strict-origin-when-cross-origin");

// Content Security Policy (adjust as needed for your site)
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://www.google.com https://www.gstatic.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: https:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self';");

// Only use HTTPS (if you have SSL)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}
```

**Add to all student pages (after PHP opening tag):**
```php
<?php
require_once __DIR__ . '/../../includes/security_headers.php';
```

---

## FIX #10: Configure Session Security

**File:** `config/database.php` or create `config/session_config.php`

**Add before any session_start() calls:**
```php
<?php
// Session security configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

// If using HTTPS (recommended)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}
```

---

## TESTING CHECKLIST

After implementing fixes:

- [ ] Test file uploads still work
- [ ] Test email OTP functionality  
- [ ] Test password changes
- [ ] Test all forms submit correctly
- [ ] Check error logs for any issues
- [ ] Verify no exposed credentials in code
- [ ] Test CSRF protection blocks invalid requests
- [ ] Verify file permissions are 0644/0755
- [ ] Test session regeneration on login
- [ ] Check security headers in browser dev tools

---

## PRIORITY ORDER

1. ✅ Revoke Gmail credentials (DO NOW)
2. ✅ Fix #2 - SQL Injection
3. ✅ Fix #3 - Move credentials to env
4. ✅ Fix #4 - File permissions
5. ✅ Fix #5 - CSRF protection
6. ✅ Fix #6 - File upload security
7. ✅ Fix #7 - Input validation
8. ✅ Fix #8 - OTP security
9. ✅ Fix #9 - Security headers
10. ✅ Fix #10 - Session security

---

## NEED HELP?

If you encounter issues implementing these fixes, refer to:
- `STUDENT_SECURITY_AUDIT.md` for detailed explanations
- PHP Manual: https://www.php.net/manual/en/security.php
- OWASP: https://owasp.org/www-project-top-ten/

**Questions? Review the audit report first, then test thoroughly in a development environment before deploying to production.**
