# Footer CMS - Quick Setup Guide

## ✅ What's Been Created

### 1. Database Schema
- **File:** `sql/create_footer_settings.sql`
- **Table:** `footer_settings`
- **Columns:** Colors, text content, contact information

### 2. Backend Service
- **File:** `services/FooterThemeService.php`
- **Functions:** Get/save footer settings, generate colors from theme

### 3. Admin Interface
- **File:** `modules/admin/footer_settings.php`
- **Features:** Live preview, color pickers, form validation, AJAX saving

### 4. JavaScript
- **File:** `assets/js/admin/footer-settings.js`
- **Features:** Real-time preview, form submission, error handling

### 5. Dynamic Footer
- **File:** `includes/landing_footer.php`
- **Features:** Reads from database, dynamic colors, fallback defaults

### 6. Sidebar Integration
- **File:** `includes/admin/admin_sidebar.php`
- **Added:** Footer Settings link under System Controls

## 🚀 Setup Steps

### Step 1: Run the Database Migration

Execute the SQL file to create the table:

```bash
# Using psql
psql -U postgres -d educaid_db -f sql/create_footer_settings.sql

# Or using pgAdmin
# - Open pgAdmin
# - Select your database
# - Open Query Tool
# - Load and execute create_footer_settings.sql
```

### Step 2: Update Landing Page

Open `modules/student/index.php` and find the footer section (around line 258).

**Replace the existing footer HTML with:**

```php
<?php
// At the top of the file, ensure you have:
require_once '../../config/database.php';
?>

<!-- Your page content here -->

<?php
// Replace the static footer with:
include '../../includes/landing_footer.php';
?>
```

### Step 3: Test the Admin Interface

1. Login as super admin
2. Navigate to **System Controls → Footer Settings**
3. You should see the footer settings page with:
   - Live preview at the top
   - Color pickers for all footer colors
   - Text fields for content
   - Contact information fields

### Step 4: Make Your First Change

1. Click any color picker (e.g., Background Color)
2. Choose a new color
3. Watch the preview update in real-time
4. Click **"Save Changes"**
5. Visit the landing page (`modules/student/index.php`)
6. The footer should display with your new colors!

## 📋 Admin Page Features

### Live Preview
- Shows exactly how the footer will look
- Updates in real-time as you change colors
- Displays all text content

### Color Controls
- **Background Color** - Main footer background
- **Text Color** - Regular text and icons
- **Heading Color** - Footer title and section headings
- **Link Color** - Default link color
- **Link Hover Color** - Color when hovering over links
- **Divider Color** - Horizontal line color

### Content Fields
- **Footer Title** - Main branding text (required)
- **Description** - Brief about text
- **Address** - Physical location
- **Phone** - Contact number
- **Email** - Contact email (validated)

### Smart Features
- ✅ AJAX form submission (no page reload)
- ✅ CSRF protection
- ✅ Real-time validation
- ✅ Success/error notifications
- ✅ Responsive design

## 🎨 Integration with Theme Generator

To automatically update footer colors when generating themes, update your theme generator:

**In `services/ThemeGeneratorService.php`** or your theme generation endpoint:

```php
// After generating sidebar/header themes, add:
require_once __DIR__ . '/FooterThemeService.php';
$footerService = new FooterThemeService($connection);

// Generate footer colors from your primary/secondary
$footerColors = $footerService->generateFromTheme($primaryColor, $secondaryColor);

// Save the generated colors
$footerService->save($footerColors, $adminId, $municipalityId);
```

## 🔍 Troubleshooting

### Footer Not Updating?
1. Check database connection in `landing_footer.php`
2. Verify data was saved: `SELECT * FROM footer_settings WHERE is_active = TRUE;`
3. Clear browser cache
4. Check console for JavaScript errors

### Colors Not Applying?
1. Inspect element to see if styles are loaded
2. Check that hex color codes are valid (e.g., `#1e3a8a`)
3. Verify inline styles in `landing_footer.php`

### Can't Access Admin Page?
1. Ensure you're logged in as super admin
2. Check `admin_role === 'super_admin'` in permissions
3. Verify sidebar link was added correctly

### CSRF Token Errors?
1. Ensure session is started
2. Check `CSRFProtection.php` is included
3. Verify form has `<?= CSRFProtection::getTokenField('footer_settings') ?>`

## 📱 Mobile Responsive

The footer settings page and the dynamic footer are both fully responsive:
- Mobile-friendly color pickers
- Stacked layout on small screens
- Touch-friendly controls
- Preview adapts to screen size

## 🔐 Security Features

- ✅ CSRF token validation
- ✅ Super admin only access
- ✅ SQL injection protection (parameterized queries)
- ✅ XSS protection (htmlspecialchars on all output)
- ✅ Email validation
- ✅ Color format validation

## 📊 Database Structure

```sql
footer_settings (
    footer_id                 SERIAL PRIMARY KEY
    municipality_id           INTEGER (for multi-tenancy)
    footer_bg_color          VARCHAR(7)  -- #1e3a8a
    footer_text_color        VARCHAR(7)  -- #cbd5e1
    footer_heading_color     VARCHAR(7)  -- #ffffff
    footer_link_color        VARCHAR(7)  -- #e2e8f0
    footer_link_hover_color  VARCHAR(7)  -- #fbbf24
    footer_divider_color     VARCHAR(7)  -- #fbbf24
    footer_title             VARCHAR(100) -- "EducAid"
    footer_description       TEXT
    contact_address          TEXT
    contact_phone            VARCHAR(50)
    contact_email            VARCHAR(100)
    is_active                BOOLEAN
    created_at               TIMESTAMP
    updated_at               TIMESTAMP
)
```

## 🎯 Next Steps

1. **Run the SQL migration**
2. **Update landing page footer**
3. **Test admin interface**
4. **Customize your footer**
5. **Optional: Integrate with theme generator**

## 📚 Related Files

- **Documentation:** `FOOTER_CMS_GUIDE.md` (detailed guide)
- **Schema:** `sql/create_footer_settings.sql`
- **Service:** `services/FooterThemeService.php`
- **Admin Page:** `modules/admin/footer_settings.php`
- **JavaScript:** `assets/js/admin/footer-settings.js`
- **Dynamic Footer:** `includes/landing_footer.php`
- **Sidebar:** `includes/admin/admin_sidebar.php`

## ✨ Features Summary

✅ **CMS-Controlled Colors** - All footer colors editable
✅ **Live Preview** - See changes before saving
✅ **Dynamic Content** - Edit text and contact info
✅ **AJAX Saving** - No page reloads
✅ **Security** - CSRF protection & validation
✅ **Responsive** - Works on all devices
✅ **Theme Integration** - Auto-update with theme generator
✅ **Easy Setup** - Just run SQL and update one line

---

**You're all set!** Just run the SQL file and update the landing page, then you'll have a fully functional footer CMS! 🎉
