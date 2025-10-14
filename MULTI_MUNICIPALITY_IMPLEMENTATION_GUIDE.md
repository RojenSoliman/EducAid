# Multi-Municipality Implementation Guide

## Overview
This guide provides step-by-step instructions to transform the EducAid system from a single-municipality setup (hardcoded to General Trias) into a multi-municipality platform where each municipality operates independently with complete data isolation.

**Current State:** All queries hardcoded to `municipality_id = 1` (General Trias)  
**Target State:** Dynamic municipality detection based on logged-in admin/student  
**Deployment Model:** Single codebase, single database, multi-tenant architecture

---

## Architecture Overview

### How It Will Work:
```
Admin from General Trias logs in:
├─ System fetches municipality_id from admins table (municipality_id = 1)
├─ Stores in session: $_SESSION['admin_municipality_id'] = 1
├─ All queries filter by municipality_id = 1
├─ Can only see/manage General Trias data
└─ Theme loads General Trias colors/logos

Admin from Dasmariñas logs in:
├─ System fetches municipality_id from admins table (municipality_id = 2)
├─ Stores in session: $_SESSION['admin_municipality_id'] = 2
├─ All queries filter by municipality_id = 2
├─ Can only see/manage Dasmariñas data
└─ Theme loads Dasmariñas colors/logos
```

### Database Structure (Already in Place):
- ✅ `admins.municipality_id` - Already exists
- ✅ `students.municipality_id` - Already exists
- ✅ `municipalities` table - Already populated
- ✅ All content/theme tables have `municipality_id` column

**The Issue:** Login system doesn't fetch or store municipality_id in session, so all queries default to hardcoded value `1`.

---

## Implementation Phases

### ⚡ Phase 1: Core Login System (CRITICAL - Do First)
Update login to capture and store municipality_id in session.

### 📊 Phase 2: Admin Dashboard & Core Modules
Update high-traffic admin pages to use dynamic municipality_id.

### 🎨 Phase 3: Themes & Content Management
Update all CMS and theme loading to respect municipality_id.

### 👨‍🎓 Phase 4: Student Portal
Update student-facing pages to use dynamic municipality_id.

### ✅ Phase 5: Testing & Validation
Create test municipality and verify complete data isolation.

---

## Phase 1: Core Login System

### File: `unified_login.php`

#### Step 1.1: Update Student Login Query
**Location:** Line ~181

**Current Code:**
```php
$studentRes = pg_query_params($connection,
    "SELECT student_id, password, first_name, last_name, status, 'student' as role FROM students
     WHERE email = $1 AND status != 'under_registration'",
    [$em]
);
```

**Updated Code:**
```php
$studentRes = pg_query_params($connection,
    "SELECT student_id, password, first_name, last_name, status, municipality_id, 'student' as role 
     FROM students
     WHERE email = $1 AND status != 'under_registration'",
    [$em]
);
```

**Change:** Added `municipality_id` to SELECT clause

---

#### Step 1.2: Update Admin Login Query
**Location:** Line ~188

**Current Code:**
```php
$adminRes = pg_query_params($connection,
    "SELECT admin_id, password, first_name, last_name, role FROM admins
     WHERE email = $1",
    [$em]
);
```

**Updated Code:**
```php
$adminRes = pg_query_params($connection,
    "SELECT admin_id, password, first_name, last_name, role, municipality_id 
     FROM admins
     WHERE email = $1",
    [$em]
);
```

**Change:** Added `municipality_id` to SELECT clause

---

#### Step 1.3: Store Municipality in Pending Session
**Location:** Line ~240-260 (where login OTP is sent and pending data is stored)

Look for where `$_SESSION['login_pending']` is set. Update it to include municipality_id:

**Find this pattern:**
```php
$_SESSION['login_pending'] = [
    'user_id' => $user['id'],
    'name' => trim($user['first_name'].' '.$user['last_name']),
    'role' => $user['role'],
    // ... other fields
];
```

**Add this line:**
```php
$_SESSION['login_pending'] = [
    'user_id' => $user['id'],
    'name' => trim($user['first_name'].' '.$user['last_name']),
    'role' => $user['role'],
    'municipality_id' => $user['municipality_id'], // ← ADD THIS
    // ... other fields
];
```

---

#### Step 1.4: Store Municipality in Session After OTP Verification
**Location:** Line ~310-315 (Student login success)

**Current Code:**
```php
$_SESSION['student_id'] = $pending['user_id'];
$_SESSION['student_name'] = $pending['name'];
```

**Updated Code:**
```php
$_SESSION['student_id'] = $pending['user_id'];
$_SESSION['student_name'] = $pending['name'];
$_SESSION['student_municipality_id'] = $pending['municipality_id']; // ← ADD THIS
```

**Location:** Line ~335 (Admin login success)

**Current Code:**
```php
$_SESSION['admin_id'] = $pending['user_id'];
$_SESSION['admin_username'] = $pending['name'];
$_SESSION['admin_role'] = $pending['role'];
```

**Updated Code:**
```php
$_SESSION['admin_id'] = $pending['user_id'];
$_SESSION['admin_username'] = $pending['name'];
$_SESSION['admin_role'] = $pending['role'];
$_SESSION['admin_municipality_id'] = $pending['municipality_id']; // ← ADD THIS
```

---

### Step 1.5: Create Municipality Helper Functions

**Create New File:** `includes/municipality_helper.php`

```php
<?php
/**
 * Municipality Helper Functions
 * Provides centralized municipality_id management for multi-municipality system
 */

/**
 * Get current municipality ID from session
 * Works for both admin and student logins
 * 
 * @return int Municipality ID
 */
function getCurrentMunicipalityId() {
    // Admin side
    if (isset($_SESSION['admin_municipality_id'])) {
        return (int)$_SESSION['admin_municipality_id'];
    }
    
    // Student side
    if (isset($_SESSION['student_municipality_id'])) {
        return (int)$_SESSION['student_municipality_id'];
    }
    
    // Default to General Trias (municipality 1) if not set
    // This provides backward compatibility during migration
    return 1;
}

/**
 * Get municipality data for current user
 * 
 * @param resource $connection PostgreSQL connection
 * @return array|null Municipality data or null if not found
 */
function getCurrentMunicipalityData($connection) {
    $municipality_id = getCurrentMunicipalityId();
    
    $result = pg_query_params($connection,
        "SELECT municipality_id, name, slug, type, preset_logo_image, max_capacity 
         FROM municipalities 
         WHERE municipality_id = $1 
         LIMIT 1",
        [$municipality_id]
    );
    
    if ($result && $row = pg_fetch_assoc($result)) {
        return $row;
    }
    
    return null;
}

/**
 * Check if current user belongs to specified municipality
 * Useful for authorization checks
 * 
 * @param int $municipality_id Municipality ID to check
 * @return bool True if user belongs to municipality
 */
function belongsToMunicipality($municipality_id) {
    return getCurrentMunicipalityId() === (int)$municipality_id;
}

/**
 * Get municipality name for current session
 * 
 * @param resource $connection PostgreSQL connection
 * @return string Municipality name
 */
function getCurrentMunicipalityName($connection) {
    $data = getCurrentMunicipalityData($connection);
    return $data ? $data['name'] : 'Unknown';
}
```

---

### Step 1.6: Test Phase 1

**Testing Checklist:**

1. **Check Session Storage:**
   - Add debug code to admin homepage temporarily:
   ```php
   // Temporary debug - Remove after testing
   echo "Admin Municipality ID: " . ($_SESSION['admin_municipality_id'] ?? 'NOT SET');
   ```

2. **Login as General Trias Admin:**
   - Verify `$_SESSION['admin_municipality_id']` = 1
   
3. **Check Helper Function:**
   - Add to admin homepage:
   ```php
   require_once __DIR__ . '/../../includes/municipality_helper.php';
   echo "Current Municipality: " . getCurrentMunicipalityId();
   ```

**Expected Result:** Should display "1" for General Trias admin

---

## Phase 2: Admin Dashboard & Core Modules

### Priority Files to Update:

#### File 1: `modules/admin/homepage.php`

**Location:** Line 38

**Current Code:**
```php
$municipality_id = 1; // Default municipality
```

**Updated Code:**
```php
require_once __DIR__ . '/../../includes/municipality_helper.php';
$municipality_id = getCurrentMunicipalityId();
```

**Impact:** Dashboard statistics will now show data for logged-in admin's municipality only.

---

#### File 2: `modules/admin/manage_slots.php`

**Location:** Line 10

**Current Code:**
```php
$municipality_id = 1;
```

**Updated Code:**
```php
require_once __DIR__ . '/../../includes/municipality_helper.php';
$municipality_id = getCurrentMunicipalityId();
```

**Impact:** Signup slot management will be municipality-specific.

---

#### File 3: `modules/admin/get_slot_stats.php`

**Location:** Line 13

**Current Code:**
```php
$municipality_id = 1;
```

**Updated Code:**
```php
require_once __DIR__ . '/../../includes/municipality_helper.php';
$municipality_id = getCurrentMunicipalityId();
```

**Impact:** Slot statistics API will return municipality-specific data.

---

#### File 4: `modules/admin/addbarangay.php`

**Location:** Line 52

**Current Code:**
```php
$municipality_id = 1; // General Trias
```

**Updated Code:**
```php
require_once __DIR__ . '/../../includes/municipality_helper.php';
$municipality_id = getCurrentMunicipalityId();
```

**Location:** Line 79

**Current Code:**
```php
$result = pg_query($connection, "SELECT * FROM barangays WHERE municipality_id = 1 ORDER BY name ASC");
```

**Updated Code:**
```php
$result = pg_query_params($connection, 
    "SELECT * FROM barangays WHERE municipality_id = $1 ORDER BY name ASC",
    [$municipality_id]
);
```

**Impact:** Barangay management will be municipality-specific.

---

#### File 5: `modules/admin/admin_management.php`

**Location:** Line 49

**Current Code:**
```php
$municipality_id = 1; // Default municipality
```

**Updated Code:**
```php
require_once __DIR__ . '/../../includes/municipality_helper.php';
$municipality_id = getCurrentMunicipalityId();
```

**Impact:** Admin management will only show admins from same municipality.

---

#### File 6: `modules/admin/manage_applicants.php`

**Location:** Line 334

**Current Code:**
```php
$barangays = $municipality_id ? (pg_fetch_all(pg_query_params($connection, "SELECT barangay_id, name FROM barangays WHERE municipality_id = $1", [$municipality_id])) ?: []) : [];
```

**Note:** This file may already use a dynamic `$municipality_id` variable. Verify at the top of the file:

**Add at top if not present (around line 10-20):**
```php
require_once __DIR__ . '/../../includes/municipality_helper.php';
$municipality_id = getCurrentMunicipalityId();
```

**Impact:** Applicant management will be municipality-specific.

---

## Phase 3: Themes & Content Management

### Admin Theme Settings

#### File 1: `includes/admin/admin_sidebar.php`

**Location:** Line 37

**Current Code:**
```php
$sidebarThemeQuery = pg_query_params($connection, "SELECT * FROM sidebar_theme_settings WHERE municipality_id = $1 LIMIT 1", [1]);
```

**Updated Code:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
$sidebarThemeQuery = pg_query_params($connection, "SELECT * FROM sidebar_theme_settings WHERE municipality_id = $1 LIMIT 1", [$current_municipality_id]);
```

---

#### File 2: `includes/admin/admin_header.php`

**Location:** Line 142

**Current Code:**
```php
$res = @pg_query($connection, "SELECT header_bg_color, header_border_color, header_text_color, header_icon_color, header_hover_bg, header_hover_icon_color FROM header_theme_settings WHERE municipality_id=1 LIMIT 1");
```

**Updated Code:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
$res = @pg_query_params($connection, "SELECT header_bg_color, header_border_color, header_text_color, header_icon_color, header_hover_bg, header_hover_icon_color FROM header_theme_settings WHERE municipality_id=$1 LIMIT 1", [$current_municipality_id]);
```

---

#### File 3: `includes/admin/admin_topbar.php`

**Location:** Line 15

**Current Code:**
```php
$result = pg_query($connection, "SELECT topbar_email, topbar_phone, topbar_office_hours, topbar_bg_color, topbar_bg_gradient, topbar_text_color, topbar_link_color FROM theme_settings WHERE municipality_id = 1 AND is_active = TRUE LIMIT 1");
```

**Updated Code:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
$result = pg_query_params($connection, "SELECT topbar_email, topbar_phone, topbar_office_hours, topbar_bg_color, topbar_bg_gradient, topbar_text_color, topbar_link_color FROM theme_settings WHERE municipality_id = $1 AND is_active = TRUE LIMIT 1", [$current_municipality_id]);
```

---

### Student Theme Settings

#### File 4: `includes/student/student_sidebar.php`

**Location:** Line 71

**Current Code:**
```php
$sidebarThemeQuery = pg_query_params($connection, "SELECT * FROM sidebar_theme_settings WHERE municipality_id = $1 LIMIT 1", [1]);
```

**Updated Code:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
$sidebarThemeQuery = pg_query_params($connection, "SELECT * FROM sidebar_theme_settings WHERE municipality_id = $1 LIMIT 1", [$current_municipality_id]);
```

---

#### File 5: `includes/student/student_header.php`

**Location:** Line 139

**Current Code:**
```php
$res = @pg_query($connection, "SELECT header_bg_color, header_border_color, header_text_color, header_icon_color, header_hover_bg, header_hover_icon_color FROM student_header_theme_settings WHERE municipality_id=1 LIMIT 1");
```

**Updated Code:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
$res = @pg_query_params($connection, "SELECT header_bg_color, header_border_color, header_text_color, header_icon_color, header_hover_bg, header_hover_icon_color FROM student_header_theme_settings WHERE municipality_id=$1 LIMIT 1", [$current_municipality_id]);
```

---

#### File 6: `includes/student/student_topbar.php`

**Location:** Line 24 (already uses parameterized query, but verify the parameter)

**Current Code:**
```php
WHERE municipality_id = $1 AND is_active = TRUE
```

**Verify the query is called with dynamic municipality_id:**

**Add at top of file (around line 10):**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
```

**Ensure the parameter uses this variable:** (around line 24-26)
```php
$result = pg_query_params($connection, 
    "SELECT ... FROM theme_settings WHERE municipality_id = $1 AND is_active = TRUE LIMIT 1",
    [$current_municipality_id]  // ← Ensure this uses the variable, not hardcoded [1]
);
```

---

### Website/Public Content Blocks

All content helper files use hardcoded `municipality_id=1`. These need updates:

#### File 7: `includes/website/landing_content_helper.php`

**Location:** Line 30

**Current Code:**
```php
$resBlocks = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM landing_content_blocks WHERE municipality_id=1");
```

**Updated Code:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
$resBlocks = @pg_query_params($connection, "SELECT block_key, html, text_color, bg_color FROM landing_content_blocks WHERE municipality_id=$1", [$current_municipality_id]);
```

---

#### File 8: `includes/website/about_content_helper.php`

**Location:** Line 23

**Current Code:**
```php
$res = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM about_content_blocks WHERE municipality_id=1");
```

**Updated Code:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
$res = @pg_query_params($connection, "SELECT block_key, html, text_color, bg_color FROM about_content_blocks WHERE municipality_id=$1", [$current_municipality_id]);
```

---

#### File 9: `includes/website/how_it_works_content_helper.php`

**Location:** Line 23

**Current Code:**
```php
$res = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM how_it_works_content_blocks WHERE municipality_id=1");
```

**Updated Code:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
$res = @pg_query_params($connection, "SELECT block_key, html, text_color, bg_color FROM how_it_works_content_blocks WHERE municipality_id=$1", [$current_municipality_id]);
```

---

#### File 10: `includes/website/requirements_content_helper.php`

**Location:** Line 23

**Current Code:**
```php
$res = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM requirements_content_blocks WHERE municipality_id=1");
```

**Updated Code:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
$res = @pg_query_params($connection, "SELECT block_key, html, text_color, bg_color FROM requirements_content_blocks WHERE municipality_id=$1", [$current_municipality_id]);
```

---

#### File 11: `includes/website/announcements_content_helper.php`

**Location:** Line 23

**Current Code:**
```php
$res = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM announcements_content_blocks WHERE municipality_id=1");
```

**Updated Code:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
$res = @pg_query_params($connection, "SELECT block_key, html, text_color, bg_color FROM announcements_content_blocks WHERE municipality_id=$1", [$current_municipality_id]);
```

---

#### File 12: `includes/website/contact_content_helper.php`

**Location:** Line 27

**Current Code:**
```php
$res = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM contact_content_blocks WHERE municipality_id=1");
```

**Updated Code:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
$res = @pg_query_params($connection, "SELECT block_key, html, text_color, bg_color FROM contact_content_blocks WHERE municipality_id=$1", [$current_municipality_id]);
```

---

#### File 13: `includes/website/navbar.php`

**Location:** Line 101

**Current Code:**
```php
WHERE municipality_id = $1
```

**Verify this is called with dynamic municipality_id. Add at top if needed:**
```php
require_once __DIR__ . '/../municipality_helper.php';
$current_municipality_id = getCurrentMunicipalityId();
```

---

#### File 14: `includes/website/topbar.php`

**Location:** Line 23

**Similar to navbar - verify dynamic municipality_id is used.**

---

## Phase 4: Student Portal

### Student Module Files

Most student files inherit municipality_id from session automatically, but verify any direct database queries.

#### Common Pattern to Check:

Look for queries filtering by `municipality_id` in these files:
- `modules/student/student_homepage.php`
- `modules/student/upload_document.php`
- `modules/student/qr_code.php`
- `modules/student/student_profile.php`

If any query filters by municipality (like fetching barangays), ensure it uses:
```php
require_once __DIR__ . '/../../includes/municipality_helper.php';
$municipality_id = getCurrentMunicipalityId();
```

---

## Phase 5: Testing & Validation

### Pre-Testing Checklist:

- [ ] Phase 1 completed (Login system stores municipality_id)
- [ ] `municipality_helper.php` created and accessible
- [ ] All admin dashboard files updated
- [ ] All theme files updated
- [ ] All content helper files updated
- [ ] Code backed up or committed to Git

---

### Test 1: General Trias (Existing Setup)

**Objective:** Ensure nothing broke for existing municipality

1. **Login as General Trias Admin**
2. **Verify Dashboard:**
   - Statistics show correct data
   - No errors in PHP error log
3. **Check Theming:**
   - Sidebar colors load correctly
   - Header displays properly
   - Topbar shows General Trias info
4. **Test Core Functions:**
   - Manage Slots works
   - Review Registrations works
   - Manage Applicants works
5. **Check CMS:**
   - Landing page editable
   - Content loads correctly

**Expected Result:** Everything works exactly as before

---

### Test 2: Create Second Municipality

**Step 1: Add New Municipality to Database**

```sql
-- Insert Dasmariñas as municipality_id = 2
INSERT INTO municipalities (municipality_id, name, slug, type, province_id, preset_logo_image, max_capacity)
VALUES (2, 'City of Dasmariñas', 'dasmarinas', 'city', 6, '/assets/City Logos/Dasmarinas_City_Logo.png', 5000);
```

**Step 2: Add Theme Settings for Dasmariñas**

```sql
-- Copy theme settings from General Trias and modify for Dasmariñas
INSERT INTO theme_settings (municipality_id, topbar_email, topbar_phone, topbar_office_hours, is_active)
SELECT 2, 'info@dasmarinas.gov.ph', '(046) 123-4567', 'Mon-Fri 8:00 AM - 5:00 PM', TRUE
FROM theme_settings WHERE municipality_id = 1 LIMIT 1;

-- Copy sidebar theme
INSERT INTO sidebar_theme_settings (municipality_id, sidebar_bg_start, sidebar_bg_end)
SELECT 2, sidebar_bg_start, sidebar_bg_end
FROM sidebar_theme_settings WHERE municipality_id = 1 LIMIT 1;

-- Copy header theme (admin)
INSERT INTO header_theme_settings (municipality_id, header_bg_color, header_text_color)
SELECT 2, header_bg_color, header_text_color
FROM header_theme_settings WHERE municipality_id = 1 LIMIT 1;

-- Copy header theme (student)
INSERT INTO student_header_theme_settings (municipality_id, header_bg_color, header_text_color)
SELECT 2, header_bg_color, header_text_color
FROM student_header_theme_settings WHERE municipality_id = 1 LIMIT 1;
```

**Step 3: Create Content Blocks for Dasmariñas**

```sql
-- Landing page blocks
INSERT INTO landing_content_blocks (municipality_id, block_key, html, updated_by)
SELECT 2, block_key, html, updated_by
FROM landing_content_blocks WHERE municipality_id = 1;

-- About blocks
INSERT INTO about_content_blocks (municipality_id, block_key, html, updated_by)
SELECT 2, block_key, html, updated_by
FROM about_content_blocks WHERE municipality_id = 1;

-- How it works blocks
INSERT INTO how_it_works_content_blocks (municipality_id, block_key, html, updated_by)
SELECT 2, block_key, html, updated_by
FROM how_it_works_content_blocks WHERE municipality_id = 1;

-- Requirements blocks
INSERT INTO requirements_content_blocks (municipality_id, block_key, html, updated_by)
SELECT 2, block_key, html, updated_by
FROM requirements_content_blocks WHERE municipality_id = 1;

-- Announcements blocks
INSERT INTO announcements_content_blocks (municipality_id, block_key, html, updated_by)
SELECT 2, block_key, html, updated_by
FROM announcements_content_blocks WHERE municipality_id = 1;

-- Contact blocks
INSERT INTO contact_content_blocks (municipality_id, block_key, html, updated_by)
SELECT 2, block_key, html, updated_by
FROM contact_content_blocks WHERE municipality_id = 1;

-- Login blocks
INSERT INTO login_content_blocks (municipality_id, block_key, html, updated_by)
SELECT 2, block_key, html, updated_by
FROM login_content_blocks WHERE municipality_id = 1;
```

**Step 4: Create Super Admin for Dasmariñas**

```sql
-- Create test admin account for Dasmariñas
INSERT INTO admins (municipality_id, first_name, last_name, email, username, password, role)
VALUES (
    2, 
    'Test', 
    'Admin', 
    'admin@dasmarinas.test', 
    'dasmarinas_admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: 'password'
    'super_admin'
);
```

**Note:** Use proper password hashing in production!

---

### Test 3: Verify Data Isolation

**Test Scenario A: Login as Dasmariñas Admin**

1. **Login:** Use `admin@dasmarinas.test`
2. **Check Session:**
   ```php
   // Add temporary debug
   var_dump($_SESSION['admin_municipality_id']); // Should be 2
   ```
3. **Dashboard Check:**
   - Student count should be 0 (no students registered yet)
   - Capacity should show 5000
   - No General Trias data visible
4. **Theme Check:**
   - Topbar shows Dasmariñas contact info
   - Logo shows Dasmariñas logo
5. **CMS Check:**
   - Content blocks editable
   - Changes don't affect General Trias

**Expected Result:** Complete isolation - no General Trias data visible

---

**Test Scenario B: Cross-Municipality Verification**

1. **Login as General Trias Admin**
2. **Create a Test Student** in General Trias
3. **Logout and Login as Dasmariñas Admin**
4. **Verify:** Dasmariñas admin CANNOT see the General Trias student
5. **Create a Test Student** in Dasmariñas
6. **Logout and Login as General Trias Admin**
7. **Verify:** General Trias admin CANNOT see the Dasmariñas student

**Expected Result:** Complete data isolation between municipalities

---

## Troubleshooting

### Issue 1: Session Not Storing Municipality ID

**Symptom:** `$_SESSION['admin_municipality_id']` is empty or undefined

**Causes:**
- Login query doesn't fetch municipality_id
- Municipality column is NULL in database
- Session not being stored in login_pending array

**Solution:**
1. Check database: `SELECT municipality_id FROM admins WHERE admin_id = YOUR_ID;`
2. If NULL, update: `UPDATE admins SET municipality_id = 1 WHERE admin_id = YOUR_ID;`
3. Verify login query includes `municipality_id` in SELECT
4. Check `$_SESSION['login_pending']` includes municipality_id
5. Add debug: `error_log("Municipality ID: " . ($_SESSION['admin_municipality_id'] ?? 'NOT SET'));`

---

### Issue 2: Still Seeing Hardcoded Data

**Symptom:** Dashboard shows General Trias data even for Dasmariñas admin

**Causes:**
- File not updated with `getCurrentMunicipalityId()`
- Using old session variable
- Query still has hardcoded `municipality_id = 1`

**Solution:**
1. Search file for: `municipality_id = 1` or `municipality_id=1`
2. Replace with dynamic municipality_id
3. Add `require_once` for municipality_helper.php
4. Clear PHP opcode cache if using
5. Restart Apache/PHP-FPM

---

### Issue 3: Theme Not Loading

**Symptom:** Theme colors/logo not showing for new municipality

**Causes:**
- Theme settings not created for new municipality
- Content blocks missing
- Helper file not included

**Solution:**
1. Check database: `SELECT * FROM theme_settings WHERE municipality_id = 2;`
2. If empty, run the SQL from Test 2 Step 2
3. Verify include path for municipality_helper.php
4. Check file permissions

---

### Issue 4: Fatal Error - Function Not Found

**Symptom:** `Fatal error: Call to undefined function getCurrentMunicipalityId()`

**Causes:**
- municipality_helper.php not included
- Wrong include path
- File doesn't exist

**Solution:**
1. Verify file exists: `includes/municipality_helper.php`
2. Check include path - use relative paths like: `__DIR__ . '/../municipality_helper.php'`
3. From admin modules: `__DIR__ . '/../../includes/municipality_helper.php'`
4. From includes: `__DIR__ . '/../municipality_helper.php'`

---

## Deployment Considerations

### URL Structure Options

#### Option A: Path-Based Routing (Recommended for Start)
```
educaid.ph/generaltrias
educaid.ph/dasmarinas
educaid.ph/imus
```

**Implementation:**
- Use `.htaccess` rewrite rules
- Detect municipality from URL path
- Simple to implement

#### Option B: Subdomain Routing
```
generaltrias.educaid.ph
dasmarinas.educaid.ph
imus.educaid.ph
```

**Implementation:**
- Requires DNS configuration
- Detect municipality from subdomain
- Professional appearance

#### Option C: Unified Login (Current Approach)
```
educaid.ph/unified_login.php
→ Detects municipality from admin/student account
→ Redirects to appropriate dashboard
```

**Current Implementation:** This is what you have now - municipality determined by login credentials

---

### Production Checklist

Before deploying to production:

- [ ] All hardcoded `municipality_id = 1` replaced
- [ ] Municipality helper functions tested
- [ ] Data isolation verified with 2+ test municipalities
- [ ] Theme settings created for all municipalities
- [ ] Content blocks populated for all municipalities
- [ ] Barangays added for each municipality
- [ ] Error logging enabled
- [ ] Session security configured
- [ ] Database backups automated
- [ ] SSL certificate installed
- [ ] reCAPTCHA keys updated for production domain

---

## Migration Script (Optional)

Create: `sql/run_multi_municipality_migration.php`

```php
<?php
/**
 * Multi-Municipality Migration Script
 * Sets up second municipality for testing
 */

session_start();

// Security check
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'super_admin') {
    die("ERROR: This script requires super admin access.");
}

require_once __DIR__ . '/../config/database.php';

if (!$connection) {
    die("ERROR: Database connection failed.");
}

echo "=== Multi-Municipality Migration ===\n\n";

pg_query($connection, "BEGIN");

try {
    // Check if Dasmariñas already exists
    $check = pg_query_params($connection, 
        "SELECT municipality_id FROM municipalities WHERE name ILIKE '%dasmarinas%'",
        []
    );
    
    if (pg_num_rows($check) > 0) {
        echo "⚠ Dasmariñas already exists. Skipping municipality creation.\n";
    } else {
        // Insert Dasmariñas
        pg_query_params($connection,
            "INSERT INTO municipalities (municipality_id, name, slug, type, province_id, max_capacity) 
             VALUES ($1, $2, $3, $4, $5, $6)",
            [2, 'City of Dasmariñas', 'dasmarinas', 'city', 6, 5000]
        );
        echo "✓ Created municipality: City of Dasmariñas\n";
    }
    
    // Copy theme settings
    $tables = [
        'theme_settings',
        'sidebar_theme_settings',
        'header_theme_settings',
        'student_header_theme_settings'
    ];
    
    foreach ($tables as $table) {
        $check = pg_query_params($connection,
            "SELECT 1 FROM $table WHERE municipality_id = 2",
            []
        );
        
        if (pg_num_rows($check) === 0) {
            pg_query($connection,
                "INSERT INTO $table 
                 SELECT 2, * FROM (SELECT * FROM $table WHERE municipality_id = 1 LIMIT 1) t"
            );
            echo "✓ Copied theme: $table\n";
        }
    }
    
    // Copy content blocks
    $contentTables = [
        'landing_content_blocks',
        'about_content_blocks',
        'how_it_works_content_blocks',
        'requirements_content_blocks',
        'announcements_content_blocks',
        'contact_content_blocks',
        'login_content_blocks'
    ];
    
    foreach ($contentTables as $table) {
        $check = pg_query_params($connection,
            "SELECT 1 FROM $table WHERE municipality_id = 2",
            []
        );
        
        if (pg_num_rows($check) === 0) {
            pg_query($connection,
                "INSERT INTO $table (municipality_id, block_key, html, updated_by)
                 SELECT 2, block_key, html, updated_by FROM $table WHERE municipality_id = 1"
            );
            echo "✓ Copied content: $table\n";
        }
    }
    
    pg_query($connection, "COMMIT");
    echo "\n✅ Migration completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Create super admin for Dasmariñas via Admin Management\n";
    echo "2. Test login with new admin\n";
    echo "3. Verify data isolation\n";
    
} catch (Exception $e) {
    pg_query($connection, "ROLLBACK");
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
}
```

---

## Summary

### What This Accomplishes:

✅ **Data Isolation:** Each municipality sees only their own data  
✅ **Theme Independence:** Each municipality has their own colors/logos  
✅ **Content Separation:** CMS content is municipality-specific  
✅ **User Management:** Admins/students belong to specific municipalities  
✅ **Scalability:** Easy to add more municipalities  
✅ **Backward Compatible:** General Trias continues working normally  

### Files Modified: ~30 files

**Core System:** 1 file (unified_login.php)  
**Helper Functions:** 1 new file (municipality_helper.php)  
**Admin Modules:** 6 files  
**Theme Files:** 6 files  
**Content Helpers:** 7 files  
**Student Files:** As needed based on queries  

### Estimated Implementation Time:

- **Phase 1:** 1-2 hours
- **Phase 2:** 2-3 hours
- **Phase 3:** 3-4 hours
- **Phase 4:** 1-2 hours
- **Phase 5:** 2-3 hours testing
- **Total:** 9-14 hours for complete implementation

---

## Support & Questions

If you encounter issues during implementation:

1. Check the Troubleshooting section
2. Verify each phase was completed correctly
3. Use `error_log()` to debug session values
4. Check PostgreSQL logs for query errors
5. Review PHP error logs for include/require issues

**Remember:** Test thoroughly with General Trias first before creating additional municipalities!

---

*Document Version: 1.0*  
*Last Updated: October 14, 2025*  
*Status: Ready for Implementation*
