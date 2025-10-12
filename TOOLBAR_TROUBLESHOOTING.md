# 🔧 ContentTools Toolbar Not Appearing - Troubleshooting Guide

## Quick Diagnosis Steps

### Step 1: Enable Debug Mode
Visit the page with debug parameter:
```
http://localhost/EducAid/unified_login.php?edit=1&debug=1
```

**Check the debug output (black box at top):**
- ✅ **SESSION ROLE:** Should say `super_admin`
- ✅ **ADMIN ID:** Should be a number (not "NOT SET")
- ✅ **GET[edit]:** Should say `1`
- ✅ **$IS_LOGIN_EDIT_MODE:** Should say `TRUE`
- ✅ **BLOCKS LOADED:** Any number (0 is okay)

---

## Common Issues & Solutions

### ❌ Issue 1: "SESSION ROLE: NOT SET"
**Problem:** You're not logged in or session expired

**Solution:**
1. Go to admin login: `http://localhost/EducAid/unified_login.php`
2. Login with super admin credentials
3. After login, go to: `http://localhost/EducAid/modules/admin/municipality_content.php`
4. Click **"Edit Content"** on **"Login Page Info"** card
5. Confirm in the modal
6. Should redirect to edit mode

---

### ❌ Issue 2: "SESSION ROLE: sub_admin" or "admin"
**Problem:** Your account is not a super admin

**Solution:**
Check your role in database:
```sql
SELECT admin_id, username, role FROM admins WHERE admin_id = YOUR_ADMIN_ID;
```

To upgrade to super admin:
```sql
UPDATE admins SET role = 'super_admin' WHERE admin_id = YOUR_ADMIN_ID;
```

---

### ❌ Issue 3: "$IS_LOGIN_EDIT_MODE: FALSE" (but role is super_admin)
**Problem:** Missing `?edit=1` parameter in URL

**Solution:**
Make sure URL has `?edit=1`:
```
✅ CORRECT: http://localhost/EducAid/unified_login.php?edit=1
❌ WRONG:   http://localhost/EducAid/unified_login.php
```

---

### ❌ Issue 4: Orange Banner Shows, But No Toolbar
**Problem:** ContentTools JavaScript not loading or executing

**Solutions:**

#### A. Check Browser Console (F12)
Look for errors:
- `ContentTools is not defined`
- `Failed to load resource: net::ERR_BLOCKED_BY_CLIENT`
- Any red errors

#### B. Check Network Tab (F12)
Verify these files load:
```
✅ content-tools.min.css (from CDN)
✅ content-tools.min.js (from CDN)
```

**If blocked by ad blocker:**
- Disable ad blocker for localhost
- Or use browser incognito/private mode

#### C. Manual JavaScript Test
Open browser console (F12) and type:
```javascript
console.log(typeof ContentTools);
```

**Should see:** `object`
**If you see:** `undefined` → JavaScript didn't load

---

### ❌ Issue 5: Toolbar Appears But Won't Edit
**Problem:** Clicking text doesn't activate editor

**Solutions:**

#### Check Element Attributes
Open browser DevTools (F12), inspect brand section text, verify elements have:
```html
data-login-key="login_hero_title"
```

#### Console Test
```javascript
document.querySelectorAll('[data-login-key]').length
```

**Should see:** Number greater than 0 (e.g., 9)
**If you see:** 0 → Elements not properly tagged

---

### ❌ Issue 6: Can Edit, But Won't Save
**Problem:** Save endpoint not working

**Check Network Tab (F12) when saving:**
1. Click text
2. Make change
3. Click green checkmark (✓)
4. Look for POST to: `services/save_login_content.php`

**Possible responses:**
- **200 OK** → Success
- **403 Forbidden** → Not super admin
- **404 Not Found** → File missing
- **500 Error** → Server/PHP error

**Solution for 404:**
Verify file exists: `c:\xampp\htdocs\EducAid\services\save_login_content.php`

---

## Step-by-Step Test Procedure

### Test 1: Verify Login Status
```php
// Add to top of unified_login.php temporarily
<?php 
echo "<pre>";
var_dump($_SESSION);
echo "</pre>";
die();
?>
```

Look for:
```
['role'] => 'super_admin'
['admin_id'] => (some number)
```

### Test 2: Force Edit Mode
```php
// Temporarily change line ~72 to:
$IS_LOGIN_EDIT_MODE = true; // Force to true for testing
```

**Result:**
- Banner should appear
- ContentTools should load
- Click text should work

**If this works:** Your session check is the issue

### Test 3: Check ContentTools Loading
Add after `window.addEventListener('load'...`:
```javascript
console.log('=== CONTENTTOOLS DEBUG ===');
console.log('ContentTools loaded:', typeof ContentTools !== 'undefined');
console.log('Edit mode active:', <?php echo $IS_LOGIN_EDIT_MODE ? 'true' : 'false'; ?>);
console.log('Editable elements:', document.querySelectorAll('[data-login-key]').length);
```

Check console output.

---

## Quick Fix Checklist

Run through these in order:

1. [ ] Using correct URL: `unified_login.php?edit=1`
2. [ ] Debug mode shows: `SESSION ROLE: super_admin`
3. [ ] Debug mode shows: `$IS_LOGIN_EDIT_MODE: TRUE`
4. [ ] Orange banner appears at top
5. [ ] Browser console (F12) shows no errors
6. [ ] Network tab shows ContentTools files loaded
7. [ ] Console: `typeof ContentTools` returns `"object"`
8. [ ] Hovering over text shows dashed outline
9. [ ] Clicking text shows ContentTools toolbar
10. [ ] Making change and clicking ✓ saves successfully

**If all ✅:** Everything works!
**If any ❌:** See solutions above for that step

---

## Alternative Access Method

Instead of manually typing URL:

1. Login as super admin
2. Go to: **Municipality Content** page
3. Find: **"Login Page Info"** card
4. Click: **"Edit Content"** button
5. Confirm: In the modal
6. Auto-redirects: To edit mode with correct parameters

---

## Database Issues

### Missing Table
```sql
-- Check if table exists
SELECT * FROM information_schema.tables 
WHERE table_name = 'landing_content_blocks';
```

**If empty:** Run migration:
```sql
\i 'C:/xampp/htdocs/EducAid/sql/create_landing_content_blocks.sql'
```

### Missing Column
```sql
-- Check for created_at column
SELECT column_name 
FROM information_schema.columns 
WHERE table_name = 'landing_content_blocks';
```

**If missing `created_at`:** Run:
```sql
\i 'C:/xampp/htdocs/EducAid/sql/alter_add_created_at_to_content_blocks.sql'
```

---

## Clear Cache

Sometimes browser cache causes issues:

1. **Hard Refresh:**
   - Windows: `Ctrl + Shift + R`
   - Mac: `Cmd + Shift + R`

2. **Clear Browser Cache:**
   - Chrome: Settings → Privacy → Clear browsing data
   - Firefox: Options → Privacy → Clear Data

3. **Try Incognito/Private Mode:**
   - Test if it works there (no cache/extensions)

---

## Most Likely Causes (In Order)

1. **Not accessing with `?edit=1` parameter** (60%)
2. **Not logged in as super_admin** (25%)
3. **ContentTools CDN blocked** (10%)
4. **Browser cache issue** (5%)

---

## Success Indicators

When working correctly, you should see:

### Visual Indicators:
1. ✅ **Orange banner** at top: "EDIT MODE ACTIVE"
2. ✅ **Dashed outline** when hovering over text
3. ✅ **ContentTools toolbar** when clicking text
4. ✅ **Green flash** after saving

### Console Indicators:
```javascript
ContentTools: Object
Edit mode active: true
Editable elements: 9
```

---

## Contact Support

If still not working after all steps:

1. Take screenshot of debug output
2. Take screenshot of browser console (F12)
3. Take screenshot of Network tab showing ContentTools loads
4. Note your PHP version: `<?php echo phpversion(); ?>`
5. Note your PostgreSQL version

---

## Pro Tips

### Tip 1: Use Browser DevTools
- **F12** opens developer tools
- **Console tab** shows JavaScript errors
- **Network tab** shows file loading issues
- **Elements tab** shows HTML attributes

### Tip 2: Test in Different Browser
If Chrome doesn't work, try:
- Firefox
- Edge
- Safari

### Tip 3: Check PHP Error Log
Location: `C:/xampp/php/logs/php_error_log`

Look for errors related to:
- Session
- Database
- File includes

---

*Last Updated: October 12, 2025*
