# Security Audit Report - EducAid System
**Date:** $(Get-Date -Format "yyyy-MM-dd")  
**Audited By:** GitHub Copilot Security Scan  
**Scope:** CSRF Token Protection & SQL Injection Vulnerabilities

---

## Executive Summary

A comprehensive security audit was performed on the EducAid system, focusing on CSRF (Cross-Site Request Forgery) protection and SQL injection vulnerabilities across all PHP files. The system has a CSRF protection class (`CSRFProtection.php`) already implemented, but **critical services are not using it**.

### Risk Level: **HIGH** 🔴

---

## Critical Vulnerabilities Found

### 1. **CSRF Vulnerabilities** (HIGH PRIORITY)

#### ❌ **UNPROTECTED ENDPOINTS**

| File | Line | Issue | Impact |
|------|------|-------|--------|
| `services/save_login_content.php` | 26 | No CSRF token validation | Attackers can forge requests to edit login page content |
| `services/toggle_section_visibility.php` | 21 | No CSRF token validation | Attackers can hide/show sections without authorization |
| `website/contact.php` | 49 | No CSRF token validation | Contact form spam/abuse possible |
| `website/newsletter_subscribe.php` | 9 | No CSRF token validation | Mass subscription attacks possible |
| `services/upload_handler.php` | 18 | No CSRF token validation | Unauthorized file uploads possible |

#### ✅ **PROTECTED ENDPOINTS** (Good Implementation)

- `modules/admin/municipality_content.php` - Uses CSRF tokens correctly
- `modules/admin/sidebar_settings.php` - Properly validates tokens
- `modules/admin/topbar_settings.php` - Has CSRF protection
- `modules/admin/toggle_municipality_logo.php` - Token validation implemented

---

### 2. **SQL Injection Analysis**

#### ✅ **SECURE IMPLEMENTATIONS**

The system uses **parameterized queries** (`pg_query_params`) correctly in critical areas:

```php
// GOOD EXAMPLE from save_login_content.php (line 67-76)
$check_query = "SELECT block_key FROM login_content_blocks WHERE municipality_id = $1 AND block_key = $2";
$check_result = pg_query_params($connection, $check_query, [$municipality_id, $key]);
```

**Secure Files:**
- `services/save_login_content.php` - All queries use `pg_query_params()`
- `services/toggle_section_visibility.php` - Parameterized queries used
- `unified_login.php` - Login/password checking uses parameters
- `services/upload_handler.php` - File preview uses parameterized query

#### ⚠️ **MINOR CONCERNS** (Low Risk)

Several files use `pg_query()` with **hardcoded values** (not user input):

```php
// ACCEPTABLE (municipality_id=1 is hardcoded, not from user)
$resBlocksLogin = @pg_query($connection, "SELECT block_key, html FROM login_content_blocks WHERE municipality_id=1");
```

**Files with Static Queries:**
- `unified_login.php:37` - Hardcoded `municipality_id=1`
- `website/landingpage.php:85` - Static query
- `website/announcements.php:44` - No user input in query

These are **safe** because no user input is concatenated, but could be improved for consistency.

---

### 3. **XSS (Cross-Site Scripting) Analysis**

#### ✅ **SECURE OUTPUT ESCAPING**

The system properly escapes output in most places:

```php
// contact.php - Good escaping function
function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Usage:
value="<?= esc($_POST['name'] ?? '') ?>"
```

```php
// save_login_content.php - HTML sanitization
function sanitize_html($html) {
    $allowed_tags = '<p><br><b><strong><i><em><u><a><span><div><h1><h2><h3><h4><h5><h6><ul><ol><li>';
    $clean_html = strip_tags($html, $allowed_tags);
    return $clean_html;
}
```

**No critical XSS vulnerabilities found.**

---

## Detailed Findings

### File: `services/save_login_content.php`

**Issue:** No CSRF token validation  
**Risk:** HIGH  
**Attack Scenario:**
1. Attacker creates malicious webpage with hidden form
2. Victim (logged-in super admin) visits attacker's page
3. Form auto-submits to `save_login_content.php`
4. Login page content is replaced with attacker's content

**Current Security:**
- ✅ Session role checking: `$_SESSION['admin_role'] !== 'super_admin'`
- ✅ Output buffering to prevent output before JSON
- ✅ Parameterized queries: `pg_query_params()`
- ✅ HTML sanitization with `strip_tags()`
- ❌ **NO CSRF token validation**

---

### File: `services/toggle_section_visibility.php`

**Issue:** No CSRF token validation  
**Risk:** HIGH  
**Attack Scenario:**
1. Attacker sends AJAX request to hide important login sections
2. Users cannot see critical information
3. Phishing attacks become easier

**Current Security:**
- ✅ Session role checking: `$_SESSION['admin_role'] !== 'super_admin'`
- ✅ Parameterized queries
- ✅ Output buffering
- ❌ **NO CSRF token validation**

---

### File: `website/contact.php`

**Issue:** No CSRF token validation  
**Risk:** MEDIUM  
**Attack Scenario:**
1. Automated bots submit thousands of spam messages
2. Contact log file (`contact_messages.log`) grows excessively
3. Legitimate inquiries get buried

**Current Security:**
- ✅ Input validation (email, length checks)
- ✅ Output escaping with `esc()` function
- ✅ No database storage (logs to file)
- ❌ **NO CSRF token validation**

---

### File: `website/newsletter_subscribe.php`

**Issue:** No CSRF token validation  
**Risk:** MEDIUM  
**Attack Scenario:**
1. Email harvesting bots subscribe fake emails
2. Mailing list gets polluted
3. Email service reputation damaged

**Current Security:**
- ✅ Email validation with `FILTER_VALIDATE_EMAIL`
- ✅ File locking: `FILE_APPEND | LOCK_EX`
- ❌ **NO CSRF token validation**

---

### File: `unified_login.php`

**Issue:** No CSRF tokens on login/forgot password forms  
**Risk:** LOW (reCAPTCHA v3 provides some protection)  
**Current Security:**
- ✅ reCAPTCHA v3 validation
- ✅ OTP verification (2-factor protection)
- ✅ Parameterized queries for all DB operations
- ✅ Password hashing with `password_verify()`
- ✅ Blacklist checking
- ⚠️ No CSRF tokens (reCAPTCHA reduces risk)

---

## Recommended Fixes

### Priority 1: Add CSRF Protection to Critical Services

#### Fix for `services/save_login_content.php`

```php
<?php
// Add at top after session_start()
require_once __DIR__ . '/../includes/CSRFProtection.php';

// Add after line 24 (before processing POST data)
// Validate CSRF token
$token = $_POST['csrf_token'] ?? '';
if (!CSRFProtection::validateToken('edit_login_content', $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}
```

**Frontend fix for `unified_login.php` modal:**

```javascript
// Add inside saveContent() function before FormData
const csrfToken = '<?= CSRFProtection::generateToken("edit_login_content") ?>';
formData.append('csrf_token', csrfToken);
```

#### Fix for `services/toggle_section_visibility.php`

```php
<?php
// Add at top
require_once __DIR__ . '/../includes/CSRFProtection.php';

// Add after line 17 (session check)
$token = $_POST['csrf_token'] ?? '';
if (!CSRFProtection::validateToken('toggle_section', $token)) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}
```

**Frontend fix:**

```javascript
// Add inside AJAX call
data: {
    section_key: sectionKey,
    is_visible: isVisible,
    csrf_token: '<?= CSRFProtection::generateToken("toggle_section") ?>'
}
```

#### Fix for `website/contact.php`

```php
<?php
// Add at top
require_once __DIR__ . '/../includes/CSRFProtection.php';

// Generate token before HTML
$contactCsrfToken = CSRFProtection::generateToken('contact_form');

// Add validation after line 49
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_inquiry'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('contact_form', $token)) {
        $errors[] = 'Security validation failed. Please refresh the page.';
    } else {
        // existing validation code...
    }
}
```

**HTML form update:**

```html
<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($contactCsrfToken) ?>">
    <!-- rest of form -->
</form>
```

#### Fix for `website/newsletter_subscribe.php`

```php
<?php
require_once __DIR__ . '/../includes/CSRFProtection.php';

// Add after line 14
$token = $_POST['csrf_token'] ?? '';
if (!CSRFProtection::validateToken('newsletter_subscribe', $token)) {
    echo json_encode(['success' => false, 'message' => 'Security validation failed']);
    exit;
}
```

**Frontend fix (in landingpage.php or wherever form is):**

```javascript
// Add to AJAX request
data: {
    email: email,
    csrf_token: '<?= CSRFProtection::generateToken("newsletter_subscribe") ?>'
}
```

---

### Priority 2: Improve SQL Query Consistency

While no SQL injection vulnerabilities exist, improve code consistency:

```php
// BEFORE (unified_login.php:37)
$resBlocksLogin = @pg_query($connection, "SELECT block_key, html FROM login_content_blocks WHERE municipality_id=1");

// AFTER (best practice)
$resBlocksLogin = pg_query_params($connection, 
    "SELECT block_key, html FROM login_content_blocks WHERE municipality_id=$1", 
    [1]
);
```

---

### Priority 3: Add Rate Limiting

Implement rate limiting for:
- `contact.php` - Limit to 3 submissions per hour per IP
- `newsletter_subscribe.php` - Limit to 1 subscription per hour per IP
- `unified_login.php` - Already has reCAPTCHA (good)

---

## Security Checklist

### ✅ Implemented Correctly
- [x] Parameterized queries for user input
- [x] Password hashing (`password_verify()`)
- [x] Session management
- [x] Role-based access control
- [x] Output escaping (XSS prevention)
- [x] HTML sanitization in CMS
- [x] reCAPTCHA v3 on login
- [x] OTP verification
- [x] Email validation
- [x] Output buffering in services

### ❌ Needs Implementation
- [ ] CSRF tokens on CMS services
- [ ] CSRF tokens on public forms
- [ ] Rate limiting on public endpoints
- [ ] Content Security Policy headers
- [ ] Logging of failed CSRF attempts

---

## Testing Recommendations

### Manual Testing Steps

1. **Test CSRF Protection:**
   ```bash
   # Try to submit form without token
   curl -X POST http://localhost/EducAid/services/save_login_content.php \
        -d "municipality_id=1&login_title=Hacked"
   # Should return: "Invalid security token"
   ```

2. **Test SQL Injection:**
   ```bash
   # Try injection in login form
   email: admin' OR '1'='1
   # Should fail safely due to parameterized queries
   ```

3. **Test XSS:**
   ```html
   <!-- Try in contact form message field -->
   <script>alert('XSS')</script>
   <!-- Should be escaped on display -->
   ```

---

## Conclusion

The EducAid system has **good foundational security** with parameterized queries and output escaping. However, **CSRF protection is critically missing** in the new CMS services for the login page.

### Immediate Actions Required:
1. **TODAY:** Add CSRF tokens to `save_login_content.php` and `toggle_section_visibility.php`
2. **THIS WEEK:** Add CSRF tokens to `contact.php` and `newsletter_subscribe.php`
3. **THIS MONTH:** Implement rate limiting and CSP headers

### Overall Security Grade: **B-** (Would be A- with CSRF fixes)

---

## References

- OWASP CSRF Prevention Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
- OWASP SQL Injection Prevention: https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html
- PHP Security Best Practices: https://www.php.net/manual/en/security.php

---

**Report Generated:** $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")
