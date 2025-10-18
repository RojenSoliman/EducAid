# Footer CMS Implementation Guide

## Overview
This guide explains how to implement and use the footer CMS system for the EducAid landing page.

## Database Setup

### 1. Run the SQL Schema
Execute the SQL file to create the `footer_settings` table:
```bash
psql -U postgres -d educaid_db -f sql/create_footer_settings.sql
```

Or through pgAdmin/PHP:
```php
require_once 'config/database.php';
$sql = file_get_contents('sql/create_footer_settings.sql');
pg_query($connection, $sql);
```

### 2. Table Structure
```sql
footer_settings (
    footer_id,
    municipality_id,
    footer_bg_color,           -- Footer background color
    footer_text_color,         -- Normal text color
    footer_heading_color,      -- Heading text color
    footer_link_color,         -- Link color
    footer_link_hover_color,   -- Link hover color
    footer_divider_color,      -- Divider line color
    footer_title,              -- "EducAid"
    footer_description,        -- Description text
    contact_address,           -- Physical address
    contact_phone,             -- Phone number
    contact_email,             -- Email address
    is_active,                 -- Active status
    created_at,
    updated_at
)
```

## Integration Steps

### Step 1: Use the Dynamic Footer in Landing Page

Replace the static footer in `modules/student/index.php` with:

```php
<?php
// At the top of index.php, make sure you have database connection
require_once '../../config/database.php';
?>

<!-- Your page content here -->

<?php
// Replace the static footer with:
include '../../includes/landing_footer.php';
?>
```

### Step 2: Create Footer Settings Admin Page

Create `modules/admin/footer_settings.php`:

```php
<?php
session_start();
require_once '../../config/database.php';
require_once '../../services/FooterThemeService.php';
require_once '../../includes/CSRFProtection.php';

// Admin authentication check
if (!isset($_SESSION['admin_id'])) {
    header('Location: ../../unified_login.php');
    exit;
}

$footerService = new FooterThemeService($connection);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $footerService->save($_POST, $_SESSION['admin_id']);
    $message = $result['message'];
    $success = $result['success'];
}

// Get current settings
$currentSettings = $footerService->getCurrentSettings();

// Include admin head and render form
$page_title = 'Footer Settings';
include '../../includes/admin/admin_head.php';
?>

<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>
    
    <section class="home-section">
        <div class="container-fluid py-4">
            <h2>Footer Settings</h2>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-<?= $success ? 'success' : 'danger' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <?= CSRFProtection::getTokenField('footer_settings') ?>
                
                <div class="card mb-4">
                    <div class="card-header"><h5>Colors</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <label>Background Color</label>
                                <input type="color" name="footer_bg_color" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($currentSettings['footer_bg_color']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label>Text Color</label>
                                <input type="color" name="footer_text_color" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($currentSettings['footer_text_color']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label>Heading Color</label>
                                <input type="color" name="footer_heading_color" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($currentSettings['footer_heading_color']) ?>">
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-4">
                                <label>Link Color</label>
                                <input type="color" name="footer_link_color" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($currentSettings['footer_link_color']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label>Link Hover Color</label>
                                <input type="color" name="footer_link_hover_color" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($currentSettings['footer_link_hover_color']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label>Divider Color</label>
                                <input type="color" name="footer_divider_color" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($currentSettings['footer_divider_color']) ?>">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header"><h5>Content</h5></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label>Footer Title</label>
                            <input type="text" name="footer_title" class="form-control" 
                                   value="<?= htmlspecialchars($currentSettings['footer_title']) ?>">
                        </div>
                        <div class="mb-3">
                            <label>Description</label>
                            <textarea name="footer_description" class="form-control" rows="3"><?= htmlspecialchars($currentSettings['footer_description']) ?></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header"><h5>Contact Information</h5></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label>Address</label>
                            <input type="text" name="contact_address" class="form-control" 
                                   value="<?= htmlspecialchars($currentSettings['contact_address']) ?>">
                        </div>
                        <div class="mb-3">
                            <label>Phone</label>
                            <input type="text" name="contact_phone" class="form-control" 
                                   value="<?= htmlspecialchars($currentSettings['contact_phone']) ?>">
                        </div>
                        <div class="mb-3">
                            <label>Email</label>
                            <input type="email" name="contact_email" class="form-control" 
                                   value="<?= htmlspecialchars($currentSettings['contact_email']) ?>">
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">Save Footer Settings</button>
            </form>
        </div>
    </section>
</div>
</body>
</html>
```

### Step 3: Integrate with Theme Generator

Update `services/ThemeGeneratorService.php` to also generate footer colors.

Add after sidebar/header theme generation:

```php
// Generate and save footer theme
require_once __DIR__ . '/FooterThemeService.php';
$footerService = new FooterThemeService($this->connection);
$footerColors = $footerService->generateFromTheme($primary, $secondary);

// Save footer colors
$footerSaveData = array_merge($footerColors, [
    'footer_title' => 'EducAid', // Keep existing title
    'footer_description' => 'Making education accessible.', // Keep existing description
]);
$footerService->save($footerSaveData, $adminId, $municipalityId);
```

## Usage

### For Admins:
1. Navigate to `modules/admin/footer_settings.php`
2. Customize colors, text, and contact information
3. Click "Save Footer Settings"
4. Changes apply immediately to the landing page

### For Theme Generator:
1. Go to Municipality Content → Generate Theme
2. Choose primary and secondary colors
3. Footer colors are automatically generated and saved
4. Footer background uses darker primary color
5. Footer text uses light colors for contrast

## Files Created/Modified

**New Files:**
- `sql/create_footer_settings.sql` - Database schema
- `services/FooterThemeService.php` - Footer management service
- `includes/landing_footer.php` - Dynamic footer component
- `FOOTER_CMS_GUIDE.md` - This documentation

**To Modify:**
- `modules/student/index.php` - Replace static footer with include
- `services/ThemeGeneratorService.php` - Add footer color generation
- `modules/admin/footer_settings.php` - Create admin page (if needed)

## Color Generation Logic

When generating from theme:
- **Background**: Primary color darkened by 20%
- **Text**: Light gray (#cbd5e1) for readability
- **Headings**: Always white (#ffffff)
- **Links**: Slightly lighter than text
- **Link Hover**: Secondary color (accent)
- **Divider**: Same as link hover (secondary color)

## Testing

1. **Database Test:**
   ```sql
   SELECT * FROM footer_settings WHERE is_active = TRUE;
   ```

2. **PHP Test:**
   ```php
   require_once 'services/FooterThemeService.php';
   $service = new FooterThemeService($connection);
   $settings = $service->getCurrentSettings();
   print_r($settings);
   ```

3. **Visual Test:**
   - Visit landing page
   - Check footer colors match database values
   - Test link hover effects
   - Verify contact information displays correctly

## Troubleshooting

**Footer not updating?**
- Clear browser cache
- Check database connection in `landing_footer.php`
- Verify `is_active = TRUE` in database

**Colors not applying?**
- Check CSS specificity
- Verify color hex codes are valid
- Inspect browser console for errors

**Theme generator not affecting footer?**
- Ensure `FooterThemeService` is included in theme generator
- Check that `generateFromTheme()` is called
- Verify `save()` method executes successfully

## Next Steps

1. Run the SQL schema
2. Update `index.php` to use dynamic footer
3. Create admin page for footer management
4. Integrate with existing theme generator
5. Test and deploy

## Support

For questions or issues, refer to:
- `services/FooterThemeService.php` - Service implementation
- `includes/landing_footer.php` - Footer rendering
- Database schema in `create_footer_settings.sql`
