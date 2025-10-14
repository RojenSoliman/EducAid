# 🐛 Theme Generator Debugging Guide

**Created:** October 15, 2025  
**Purpose:** Debug theme generation errors

---

## 🎯 Quick Start

### Option 1: Run Debug Script (Comprehensive Tests)
```
http://localhost/EducAid/debug_theme_generator.php
```

**What it does:**
✅ Checks all class files exist  
✅ Tests ColorGeneratorService methods  
✅ Tests ThemeGeneratorService methods  
✅ Tests SidebarThemeService  
✅ Generates test palette  
✅ Checks database tables  
✅ Shows full diagnostic report  

---

### Option 2: View Error Log (Real-time Errors)
```
http://localhost/EducAid/view_error_log.php
```

**What it shows:**
✅ Last 100 lines of PHP error log  
✅ Theme generator logs highlighted  
✅ Color-coded by severity (error/warning/info)  
✅ Auto-refresh option  

---

## 🔍 Debugging Workflow

### Step 1: Run Debug Script
1. Open: `http://localhost/EducAid/debug_theme_generator.php`
2. Check all 8 test sections pass ✅
3. If any fail, note the error message

### Step 2: Try Theme Generation
1. Go to Municipality Content page
2. Click "Generate Theme" button
3. Watch for errors in:
   - Browser console (F12 → Console tab)
   - Network tab (F12 → Network tab)
   - Alert messages

### Step 3: Check Error Log
1. Open: `http://localhost/EducAid/view_error_log.php`
2. Look for lines with `THEME GEN:` (highlighted in blue)
3. Check the sequence of events

---

## 📊 Expected Error Log Flow

### Successful Generation:
```
=== THEME GENERATOR START ===
THEME GEN: Authenticated - admin_id: 1
THEME GEN: Role check passed - super_admin
THEME GEN: Municipality ID: 1
THEME GEN: Municipality found - Dasmarinas
THEME GEN: Primary color: #2e7d32
THEME GEN: Secondary color: #1b5e20
THEME GEN: Starting theme generation...
THEME GEN: Generation result - {"success":true,...}
THEME GEN: Success! Colors applied: 19
=== THEME GENERATOR END (SUCCESS) ===
```

### Failed Generation:
```
=== THEME GENERATOR START ===
THEME GEN: Authenticated - admin_id: 1
THEME GEN: Role check passed - super_admin
THEME GEN: Municipality ID: 1
THEME GEN: Municipality found - Dasmarinas
THEME GEN: Primary color: #2e7d32
THEME GEN: Secondary color: #1b5e20
THEME GEN: Starting theme generation...
THEME GEN: Exception caught - [ERROR MESSAGE HERE]
THEME GEN: Stack trace - [STACK TRACE HERE]
=== THEME GENERATOR END (ERROR) ===
```

---

## 🐛 Common Issues & Fixes

### Issue 1: "Unexpected token '<'" in JSON
**Symptom:** Browser console shows JSON parse error  
**Cause:** PHP errors/warnings output before JSON  
**Fix:** ✅ Added output buffering (`ob_start()`) - should be fixed  
**Check:** View error log for PHP warnings

---

### Issue 2: "403 Forbidden - Security token expired"
**Symptom:** AJAX request returns 403  
**Cause:** CSRF token consumed or invalid  
**Fix:** ✅ Token now reusable (not consumed) - should be fixed  
**Check:** Console log should show token value

---

### Issue 3: "Method generateThemePreview() not found"
**Symptom:** Fatal error about missing method  
**Cause:** Using wrong method name  
**Fix:** ✅ Now uses `generateAndApplyTheme()` - should be fixed  
**Check:** Debug script Test #2 shows available methods

---

### Issue 4: Colors apply but sidebar doesn't update
**Symptom:** Success message but sidebar looks the same  
**Cause:** Need to refresh page to see changes  
**Fix:** Page should auto-reload after success  
**Check:** Look for `location.reload()` in success handler

---

### Issue 5: Database connection error
**Symptom:** Cannot connect to database  
**Cause:** PostgreSQL not running or wrong credentials  
**Check:** 
- XAMPP PostgreSQL is running
- `config/database.php` has correct credentials
- Run debug script to test connection

---

## 📝 Debug Checklist

Before reporting issues, check:

- [ ] XAMPP Apache is running
- [ ] XAMPP PostgreSQL is running
- [ ] Logged in as super admin
- [ ] Municipality has primary and secondary colors set
- [ ] Browser console shows no JavaScript errors
- [ ] Network tab shows 200 or 403 response (not 500)
- [ ] Error log shows "THEME GENERATOR START"
- [ ] Debug script passes all 8 tests

---

## 🔧 Manual Testing Steps

### Test 1: Check Token
```javascript
// Run in browser console
document.getElementById('generateThemeCsrfToken')?.value
// Should output: long hex string (64 characters)
```

### Test 2: Check Municipality ID
```javascript
// Run in browser console
document.getElementById('confirmGenerateThemeBtn')?.dataset.municipalityId
// Should output: number (e.g., "1")
```

### Test 3: Manual AJAX Call
```javascript
// Run in browser console (ONLY FOR TESTING)
const formData = new FormData();
formData.append('csrf_token', document.getElementById('generateThemeCsrfToken').value);
formData.append('municipality_id', document.getElementById('confirmGenerateThemeBtn').dataset.municipalityId);

fetch('generate_and_apply_theme.php', {
    method: 'POST',
    body: formData
}).then(r => r.text()).then(console.log);

// Check console for response
```

---

## 📊 Files Created

### 1. debug_theme_generator.php
**Purpose:** Comprehensive system diagnostics  
**Location:** Root directory  
**When to use:** First time debugging or after major changes  
**Features:**
- 8 test sections
- Visual color swatches
- Database table checks
- Method availability checks
- Sample palette generation

### 2. view_error_log.php
**Purpose:** Real-time error log viewer  
**Location:** Root directory  
**When to use:** During active debugging  
**Features:**
- Last 100 log lines
- Color-coded by severity
- Theme generator logs highlighted
- Auto-refresh option
- Quick links to other debug tools

### 3. generate_and_apply_theme.php (Enhanced)
**Purpose:** AJAX endpoint with logging  
**Location:** modules/admin/  
**Changes:**
- Added comprehensive error logging
- Added output buffering
- Added try-catch blocks
- Logs every step of process

---

## 🎯 What to Look For

### In Browser Console:
```
✅ Good:
- "generateThemeCsrfToken" element found
- Token value: "abc123..."
- Municipality ID: 1
- Fetch request to generate_and_apply_theme.php
- Response: {"success":true,...}

❌ Bad:
- "generateThemeCsrfToken" is null
- "Unexpected token '<'"
- "Server error: 500"
- "Failed to fetch"
```

### In Error Log:
```
✅ Good:
- THEME GENERATOR START
- Authenticated - admin_id: 1
- Role check passed
- Municipality found
- Starting theme generation
- Success! Colors applied: 19
- THEME GENERATOR END (SUCCESS)

❌ Bad:
- Authentication failed
- Access denied
- Municipality not found
- Exception caught
- Fatal error
- THEME GENERATOR END (ERROR)
```

### In Network Tab:
```
✅ Good:
- Status: 200 OK
- Response type: application/json
- Response body: {"success":true,...}

❌ Bad:
- Status: 403 Forbidden
- Status: 500 Internal Server Error
- Response body: HTML error page
- Response body: "<br /><b>Warning..."
```

---

## 🚀 Next Steps After Debugging

If debug script passes all tests but generation still fails:

1. **Check ThemeGeneratorService.php:**
   - Does `generateAndApplyTheme()` method exist?
   - Is it public?
   - What does it return?

2. **Check SidebarThemeService.php:**
   - Does `updateSettings()` work?
   - Are all 19 color fields defined?
   - Is the SQL query correct?

3. **Check database:**
   - Does `sidebar_theme_settings` table exist?
   - Does it have all required columns?
   - Can you INSERT/UPDATE manually?

4. **Enable more logging:**
   - Add error_log() to ThemeGeneratorService
   - Add error_log() to SidebarThemeService
   - Check each step of palette generation

---

## 📞 Support Info

**Debug Tools:**
- Debug Script: http://localhost/EducAid/debug_theme_generator.php
- Error Log: http://localhost/EducAid/view_error_log.php
- Municipality Content: http://localhost/EducAid/modules/admin/municipality_content.php

**Log Files:**
- PHP Error Log: C:\xampp\php\logs\php_error_log
- Apache Error Log: C:\xampp\apache\logs\error.log

**Key Files:**
- AJAX Endpoint: modules/admin/generate_and_apply_theme.php
- Theme Service: services/ThemeGeneratorService.php
- Color Service: services/ColorGeneratorService.php
- Sidebar Service: services/SidebarThemeService.php

---

**🎉 Happy Debugging!**
