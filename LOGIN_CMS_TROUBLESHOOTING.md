# Login Page CMS - Quick Start & Troubleshooting

## 🚀 Quick Start

### Step 1: Run the SQL Migration
First, ensure your database has the required table:

```sql
-- Run this in your PostgreSQL database
\i 'C:/xampp/htdocs/EducAid/sql/create_landing_content_blocks.sql'

-- If table already exists, add created_at column:
\i 'C:/xampp/htdocs/EducAid/sql/alter_add_created_at_to_content_blocks.sql'
```

OR run directly:
```sql
CREATE TABLE IF NOT EXISTS landing_content_blocks (
  id SERIAL PRIMARY KEY,
  municipality_id INT NOT NULL DEFAULT 1,
  block_key TEXT NOT NULL,
  html TEXT NOT NULL,
  text_color VARCHAR(20) DEFAULT NULL,
  bg_color VARCHAR(20) DEFAULT NULL,
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW(),
  UNIQUE (municipality_id, block_key)
);
```

### Step 2: Verify Super Admin Access

Make sure you're logged in as a super admin:

```php
// Check your session:
print_r($_SESSION);

// Should see:
// ['role'] => 'super_admin'
// ['admin_id'] => <some_number>
```

### Step 3: Access Edit Mode

Navigate to:
```
http://localhost/EducAid/unified_login.php?edit=1
```

You should see:
- ✅ **Orange banner** at the top saying "EDIT MODE ACTIVE"
- ✅ **Hover effects** on editable text (dashed white outline)
- ✅ **ContentTools toolbar** appearing when you click text

---

## 🔧 Troubleshooting

### ❌ Problem: "No toolbar appears when I click text"

**Possible causes:**

1. **Not in Edit Mode**
   - Solution: Add `?edit=1` to URL
   - Verify you see the orange banner

2. **Not a Super Admin**
   - Check session: `var_dump($_SESSION['role'])`
   - Should be: `'super_admin'`

3. **ContentTools Not Loading**
   - Open browser console (F12)
   - Check for JavaScript errors
   - Verify CDN is accessible:
     ```
     https://cdn.jsdelivr.net/npm/ContentTools@1.6.20/build/content-tools.min.js
     ```

4. **JavaScript Error**
   - Check console for: `ContentTools is not defined`
   - Clear browser cache (Ctrl+Shift+R)

**Test Script:**
Add this before `</body>` in edit mode:
```html
<script>
console.log('Edit mode active:', <?php echo $IS_LOGIN_EDIT_MODE ? 'true' : 'false'; ?>);
console.log('ContentTools loaded:', typeof ContentTools !== 'undefined');
</script>
```

---

### ❌ Problem: "Changes don't save"

**Possible causes:**

1. **Save Service Not Found**
   - Verify file exists: `services/save_login_content.php`
   - Check browser Network tab (F12) for 404 errors

2. **Database Connection Failed**
   - Check: `config/database.php`
   - Verify PostgreSQL is running

3. **Permission Error**
   - Response: `403 Unauthorized`
   - Solution: Confirm super admin session

4. **SQL Error**
   - Check `services/save_login_content.php` response
   - Look for: `created_at` column error
   - Run: `alter_add_created_at_to_content_blocks.sql`

**Test Save Service:**
```bash
curl -X POST http://localhost/EducAid/services/save_login_content.php \
  -H "Cookie: PHPSESSID=your_session_id" \
  -F "municipality_id=1" \
  -F "login_hero_title=Test Title"
```

---

### ❌ Problem: "Orange banner not showing"

**Check these conditions:**

```php
// In unified_login.php, verify:
$IS_LOGIN_EDIT_MODE = false;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin' && isset($_GET['edit']) && $_GET['edit'] == '1') {
    $IS_LOGIN_EDIT_MODE = true;
}

// Debug:
echo "Edit mode: " . ($IS_LOGIN_EDIT_MODE ? 'YES' : 'NO');
echo "Role: " . ($_SESSION['role'] ?? 'not set');
echo "GET param: " . ($_GET['edit'] ?? 'not set');
```

---

### ❌ Problem: "Statistics still showing"

**Verify removal:**
1. Clear browser cache
2. Do hard refresh (Ctrl+Shift+R)
3. Check around line 860 in `unified_login.php`
4. Should NOT see: `<div class="stats-grid">`

---

## 📊 Database Verification

### Check if table exists:
```sql
SELECT * FROM information_schema.tables 
WHERE table_name = 'landing_content_blocks';
```

### Check existing content:
```sql
SELECT block_key, html, created_at, updated_at 
FROM landing_content_blocks 
WHERE municipality_id = 1 
AND block_key LIKE 'login_%';
```

### Manually insert test content:
```sql
INSERT INTO landing_content_blocks (municipality_id, block_key, html)
VALUES (1, 'login_hero_title', '<h1>Test Title</h1>')
ON CONFLICT (municipality_id, block_key) 
DO UPDATE SET html = EXCLUDED.html;
```

---

## 🎯 Testing Checklist

### Before Testing:
- [ ] PostgreSQL running
- [ ] Apache/XAMPP running
- [ ] Logged in as super admin
- [ ] Table `landing_content_blocks` exists
- [ ] Column `created_at` exists in table

### During Testing:
- [ ] Visit: `http://localhost/EducAid/unified_login.php?edit=1`
- [ ] See orange "EDIT MODE ACTIVE" banner
- [ ] Hover over text shows dashed outline
- [ ] Click text shows ContentTools toolbar
- [ ] Make edit and click green checkmark
- [ ] See success flash (green check icon)
- [ ] Refresh page - changes persist

### After Testing:
- [ ] Visit without `?edit=1` parameter
- [ ] No orange banner shows
- [ ] No hover outlines
- [ ] Content displays normally
- [ ] Changes are visible

---

## 🐛 Debug Mode

Add this to the top of `unified_login.php` after session_start():

```php
// DEBUG MODE - Remove in production
if (isset($_GET['debug'])) {
    echo "<pre style='background:#000;color:#0f0;padding:1rem;position:fixed;top:0;left:0;right:0;z-index:99999;'>";
    echo "ROLE: " . ($_SESSION['role'] ?? 'not set') . "\n";
    echo "ADMIN ID: " . ($_SESSION['admin_id'] ?? 'not set') . "\n";
    echo "EDIT PARAM: " . ($_GET['edit'] ?? 'not set') . "\n";
    echo "EDIT MODE: " . ($IS_LOGIN_EDIT_MODE ? 'TRUE' : 'FALSE') . "\n";
    echo "BLOCKS LOADED: " . count($LOGIN_SAVED_BLOCKS) . "\n";
    echo "\nLOADED BLOCKS:\n";
    print_r(array_keys($LOGIN_SAVED_BLOCKS));
    echo "</pre>";
}
```

Access: `http://localhost/EducAid/unified_login.php?debug=1&edit=1`

---

## 📞 Still Having Issues?

### Check Browser Console (F12):
- Look for red JavaScript errors
- Check Network tab for failed requests
- Verify ContentTools loads (Sources tab)

### Check PHP Error Log:
- Location: `C:/xampp/php/logs/php_error_log`
- Or enable: `error_reporting(E_ALL); ini_set('display_errors', 1);`

### Check Database Logs:
```sql
SELECT * FROM admin_activity_log 
WHERE action = 'edit_login_page_content' 
ORDER BY created_at DESC 
LIMIT 10;
```

---

## ✅ Success Indicators

When everything works correctly:

1. **Edit Mode URL**: `?edit=1` appended
2. **Orange Banner**: Visible at top
3. **ContentTools**: Toolbar appears on click
4. **Flash Message**: Green checkmark on save
5. **Persistence**: Changes survive page refresh
6. **Database**: New rows in `landing_content_blocks`

---

*Last Updated: October 12, 2025*
