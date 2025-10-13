# Student Portal Security Audit Report
**Date:** October 14, 2025  
**Auditor:** Security Review  
**Scope:** Student Module Pages

---

## Executive Summary

This audit identifies **CRITICAL** and **HIGH** priority security vulnerabilities across all student-facing pages. Immediate action required.

### Risk Rating Legend
- 🔴 **CRITICAL** - Immediate fix required (RCE, SQL Injection, Auth Bypass)
- 🟠 **HIGH** - Fix within 24-48 hours (XSS, CSRF, Data Exposure)
- 🟡 **MEDIUM** - Fix within 1 week (Information Disclosure, Weak Validation)
- 🟢 **LOW** - Fix when convenient (Missing Headers, Code Quality)

---

## 1. CRITICAL ISSUES 🔴

### 1.1 SQL Injection Vulnerabilities (CRITICAL)
**Location:** `upload_document.php` lines 86-92

**Issue:**
```php
@$esc_student_id = pg_escape_string($connection, $student_id);
@$esc_type = pg_escape_string($connection, $fileType);
@$esc_file_path = pg_escape_string($connection, $filePath);
$sql = "INSERT INTO documents (student_id, type, file_path) 
        VALUES ('{$esc_student_id}', '{$esc_type}', '{$esc_file_path}')";
@pg_query($connection, $sql);
```

**Problems:**
- Using string concatenation instead of parameterized queries
- `pg_escape_string()` is NOT safe against all injection vectors
- Error suppression with `@` hides SQL errors
- No validation of `$fileType` beyond array check

**Impact:** Attacker can inject malicious SQL, potentially:
- Extract entire database
- Modify/delete data
- Escalate privileges
- Execute arbitrary SQL

**Fix:**
```php
// Use parameterized query
$sql = "INSERT INTO documents (student_id, type, file_path) VALUES ($1, $2, $3)";
$result = pg_query_params($connection, $sql, [$student_id, $fileType, $filePath]);
if (!$result) {
    error_log("Document insert failed: " . pg_last_error($connection));
    $_SESSION['upload_fail'] = true;
}
```

---

### 1.2 Insecure Password Query (CRITICAL)
**Location:** `student_profile.php` line 97, `student_settings.php` line 97

**Issue:**
```php
$pwdRes = pg_query($connection, "SELECT password FROM students 
          WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
```

**Problems:**
- String concatenation in SQL query
- Should use parameterized query even with escaping

**Fix:**
```php
$pwdRes = pg_query_params($connection, 
    "SELECT password FROM students WHERE student_id = $1", 
    [$student_id]);
```

---

### 1.3 Hardcoded Credentials (CRITICAL)
**Location:** `student_profile.php` lines 44-45, `student_settings.php` lines 44-45

**Issue:**
```php
$mail->Username   = 'dilucayaka02@gmail.com';
$mail->Password   = 'jlld eygl hksj flvg';
```

**Problems:**
- Credentials exposed in source code
- Version control may contain this password
- Anyone with file access can steal credentials
- Gmail App Password exposed

**Impact:**
- Email account compromise
- Phishing attacks using your domain
- Data breach notifications sent to wrong parties

**Fix:**
```php
// In config/env.php or .env file
define('SMTP_USERNAME', getenv('SMTP_USERNAME'));
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD'));

// In code:
$mail->Username = SMTP_USERNAME;
$mail->Password = SMTP_PASSWORD;
```

**Immediate Actions:**
1. ✅ REVOKE the exposed Gmail app password immediately
2. ✅ Generate new app password
3. ✅ Move to environment variables
4. ✅ Add credentials to `.gitignore`

---

## 2. HIGH PRIORITY ISSUES 🟠

### 2.1 No CSRF Protection (HIGH)
**Location:** ALL forms in all pages

**Affected Pages:**
- `upload_document.php` - File upload form
- `student_profile.php` - Profile update, email change, password change
- `student_settings.php` - Settings update
- `student_notifications.php` - Mark as read actions

**Issue:** No CSRF tokens on any forms

**Impact:**
- Attacker can force users to:
  - Upload malicious files
  - Change email/password
  - Modify profile data
  - Mark notifications as read

**Fix:** Implement CSRF protection system

**Create:** `includes/csrf.php`
```php
<?php
// Generate CSRF token
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validate_csrf_token($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// HTML helper
function csrf_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}
```

**Usage in forms:**
```php
<form method="POST">
    <?php echo csrf_field(); ?>
    <!-- form fields -->
</form>

// Validation at top of file:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed');
    }
}
```

---

### 2.2 Path Traversal Vulnerability (HIGH)
**Location:** `upload_document.php` line 72

**Issue:**
```php
$filePath = $uploadDir . basename($fileName);
```

**Problems:**
- `basename()` alone may not prevent all attacks
- No validation of file path components
- Could potentially access parent directories

**Fix:**
```php
// Sanitize filename
$fileName = $_FILES['documents']['name'][$index];
$fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
$fileName = basename($fileName);

// Validate extension
$allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExts)) {
    $_SESSION['upload_fail'] = true;
    continue;
}

// Generate unique name to prevent overwrites
$uniqueName = uniqid() . '_' . $fileName;
$filePath = realpath($uploadDir) . '/' . $uniqueName;

// Verify path is within upload directory
if (strpos(realpath(dirname($filePath)), realpath($uploadDir)) !== 0) {
    die('Invalid file path');
}
```

---

### 2.3 Unrestricted File Upload (HIGH)
**Location:** `upload_document.php` lines 60-80

**Issues:**
- No file size limit validation
- No MIME type verification
- Only checks extension via `$fileType`
- No virus scanning
- Uploaded files executable if misconfigured

**Current Validation:**
```php
if (!in_array($fileType, ['id_picture'])) {
    continue;
}
```

**Fix:**
```php
// Configuration
$maxFileSize = 5 * 1024 * 1024; // 5MB
$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
$allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];

foreach ($_FILES['documents']['name'] as $index => $fileName) {
    $fileTmpName = $_FILES['documents']['tmp_name'][$index];
    $fileSize = $_FILES['documents']['size'][$index];
    $fileError = $_FILES['documents']['error'][$index];
    $fileType = $_POST['document_type'][$index];
    
    // Check for upload errors
    if ($fileError !== UPLOAD_ERR_OK) {
        continue;
    }
    
    // Check file size
    if ($fileSize > $maxFileSize) {
        $_SESSION['upload_fail'] = true;
        $_SESSION['error_message'] = 'File too large (max 5MB)';
        continue;
    }
    
    // Verify MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $fileTmpName);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedMimeTypes)) {
        $_SESSION['upload_fail'] = true;
        $_SESSION['error_message'] = 'Invalid file type';
        continue;
    }
    
    // Verify extension
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions)) {
        continue;
    }
    
    // Sanitize filename
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
    $uniqueName = uniqid() . '_' . $safeName;
    
    // Move file
    $filePath = $uploadDir . $uniqueName;
    if (move_uploaded_file($fileTmpName, $filePath)) {
        // Set restrictive permissions
        chmod($filePath, 0644);
        
        // Insert to database using parameterized query
        $sql = "INSERT INTO documents (student_id, type, file_path) VALUES ($1, $2, $3)";
        pg_query_params($connection, $sql, [$student_id, $fileType, $filePath]);
    }
}
```

---

### 2.4 Session Fixation Risk (HIGH)
**Location:** All pages

**Issue:** Session ID not regenerated after login

**Fix in login handler:**
```php
// After successful login
$_SESSION['student_id'] = $studentId;
$_SESSION['student_username'] = $username;

// Regenerate session ID to prevent fixation
session_regenerate_id(true);
```

---

### 2.5 Insecure Direct Object Reference (HIGH)
**Location:** `qr_code.php`, potentially others

**Issue:**
```php
$student_id = $_SESSION['student_id'];
// Query uses session student_id, but what if session is manipulated?
```

**Recommendation:**
- Always validate that session data matches database
- Don't trust session data alone for authorization decisions
- Implement additional checks for sensitive operations

---

## 3. MEDIUM PRIORITY ISSUES 🟡

### 3.1 Weak OTP Security (MEDIUM)
**Location:** `student_profile.php`, `student_settings.php`

**Issues:**
```php
$otp = rand(100000, 999999); // Weak randomness
$_SESSION['profile_otp_time'] = time();
// ...
if ((time() - $_SESSION['profile_otp_time']) > 40) // Too short window
```

**Problems:**
- `rand()` is predictable (not cryptographically secure)
- 40-second window is too short for email delivery
- No rate limiting on OTP requests
- No attempt limit on verification

**Fix:**
```php
// Generate secure OTP
$otp = random_int(100000, 999999);

// Extend validity
$_SESSION['profile_otp_time'] = time();
$validityPeriod = 300; // 5 minutes

// Add rate limiting
if (!isset($_SESSION['otp_request_count'])) {
    $_SESSION['otp_request_count'] = 0;
    $_SESSION['otp_request_reset'] = time();
}

// Reset counter every hour
if (time() - $_SESSION['otp_request_reset'] > 3600) {
    $_SESSION['otp_request_count'] = 0;
    $_SESSION['otp_request_reset'] = time();
}

// Limit to 3 OTP requests per hour
if ($_SESSION['otp_request_count'] >= 3) {
    echo json_encode(['status' => 'error', 'message' => 'Too many requests. Try again later.']);
    exit;
}

$_SESSION['otp_request_count']++;

// Add verification attempt limiting
if (!isset($_SESSION['otp_verify_attempts'])) {
    $_SESSION['otp_verify_attempts'] = 0;
}

if ($_SESSION['otp_verify_attempts'] >= 5) {
    unset($_SESSION['profile_otp'], $_SESSION['profile_otp_email']);
    echo json_encode(['status' => 'error', 'message' => 'Too many failed attempts.']);
    exit;
}

$_SESSION['otp_verify_attempts']++;
```

---

### 3.2 Information Disclosure (MEDIUM)
**Location:** Multiple files

**Issues:**
- `@` error suppression hides errors but doesn't log them
- Verbose error messages in AJAX responses
- Stack traces may be visible in production

**Fix:**
```php
// In production config
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/path/to/php-errors.log');

// Replace @ suppression with proper error handling
$result = pg_query_params($connection, $sql, $params);
if (!$result) {
    error_log("Query failed: " . pg_last_error($connection));
    // Show generic error to user
    echo json_encode(['status' => 'error', 'message' => 'An error occurred. Please try again.']);
    exit;
}
```

---

### 3.3 Missing Input Validation (MEDIUM)
**Location:** `student_homepage.php` lines 11-12

**Issue:**
```php
$studentId = $_SESSION['student_id'];
$student_info_query = "SELECT last_login, first_name, last_name FROM students WHERE student_id = $1";
```

**Problem:** While using parameterized query (good!), no validation that `$studentId` is an integer

**Fix:**
```php
$studentId = filter_var($_SESSION['student_id'], FILTER_VALIDATE_INT);
if ($studentId === false) {
    session_destroy();
    header("Location: ../../unified_login.php");
    exit;
}
```

---

### 3.4 Insecure File Permissions (MEDIUM)
**Location:** `upload_document.php` line 68

**Issue:**
```php
mkdir($uploadDir, 0777, true); // Too permissive
```

**Fix:**
```php
mkdir($uploadDir, 0755, true); // Owner can write, others can only read/execute

// After upload:
chmod($filePath, 0644); // Owner can write, others read-only
```

---

### 3.5 No Content Security Policy (MEDIUM)
**Location:** All pages

**Issue:** Missing CSP headers allow XSS attacks

**Fix - Add to all pages:**
```php
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://www.google.com https://www.gstatic.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: https:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self';");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
```

---

## 4. LOW PRIORITY ISSUES 🟢

### 4.1 Session Cookie Security (LOW)
**Location:** Session initialization

**Fix - Add to config:**
```php
// In config or before session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // If using HTTPS
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
```

---

### 4.2 Missing Logging (LOW)
**Location:** All security-sensitive actions

**Recommendation:** Log all:
- Failed login attempts
- Password changes
- Email changes
- File uploads
- Permission denials

---

### 4.3 Code Quality Issues (LOW)

**Issues:**
- Inconsistent error handling
- Mixed coding styles
- No input validation library
- Comments marked with `@phpstan-ignore`

---

## 5. RECOMMENDED ACTIONS (Priority Order)

### IMMEDIATE (Today)
1. ✅ **Revoke exposed Gmail credentials**
2. ✅ **Fix SQL injection in upload_document.php**
3. ✅ **Move credentials to environment variables**
4. ✅ **Implement CSRF protection system**

### URGENT (This Week)
5. ✅ **Fix file upload vulnerabilities**
6. ✅ **Add path traversal protection**
7. ✅ **Implement session regeneration**
8. ✅ **Add input validation filters**

### HIGH (Next 2 Weeks)
9. ✅ **Improve OTP security**
10. ✅ **Add rate limiting**
11. ✅ **Implement proper error handling**
12. ✅ **Add security headers**

### MEDIUM (Next Month)
13. ✅ **Add comprehensive logging**
14. ✅ **Implement audit trail**
15. ✅ **Add virus scanning for uploads**
16. ✅ **Create security testing suite**

---

## 6. SECURITY CHECKLIST FOR NEW CODE

- [ ] Use parameterized queries (pg_query_params) - NEVER string concatenation
- [ ] Validate and sanitize ALL user input
- [ ] Include CSRF tokens on ALL forms
- [ ] Escape output with htmlspecialchars() or similar
- [ ] Use FILTER_* constants for validation
- [ ] Check file uploads (size, type, MIME, path)
- [ ] Log security-relevant events
- [ ] Never expose credentials in code
- [ ] Set restrictive file permissions
- [ ] Implement rate limiting on sensitive actions
- [ ] Use secure random functions (random_int, random_bytes)
- [ ] Add security headers
- [ ] Regenerate session IDs after privilege changes
- [ ] Implement proper error handling (don't use @)
- [ ] Use HTTPS-only cookies

---

## 7. TOOLS FOR ONGOING SECURITY

### Recommended Tools:
- **Static Analysis:** PHPStan, Psalm, SonarQube
- **Dependency Scanning:** Composer audit
- **WAF:** ModSecurity, Cloudflare WAF
- **Penetration Testing:** OWASP ZAP, Burp Suite

### Regular Tasks:
- Weekly: Review access logs for suspicious activity
- Monthly: Update dependencies
- Quarterly: Full security audit
- Yearly: Penetration testing

---

## CONCLUSION

**Current Risk Level: HIGH** 🔴

The student portal has several **critical vulnerabilities** that could lead to:
- Database compromise via SQL injection
- Account takeover via CSRF
- Malicious file uploads
- Credential theft

**Immediate action required on items 1-4 in the Recommended Actions section.**

After implementing all HIGH priority fixes, reassess risk level.

---

**Report prepared by:** AI Security Assistant  
**Review Status:** Requires developer validation  
**Next Review Date:** After fixes implemented
