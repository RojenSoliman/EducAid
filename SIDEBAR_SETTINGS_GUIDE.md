# Sidebar Settings Guide

## Overview
The **Sidebar Settings** page allows super admins to customize the appearance of the admin sidebar with a live preview of changes.

## Access
1. Log in as a **Super Admin**
2. Navigate to: **System Controls** → **Sidebar Settings**
3. Direct URL: `/modules/admin/sidebar_settings.php`

## Features

### Customizable Elements

#### 1. Sidebar Background
- **Background Start Color**: Top gradient color
- **Background End Color**: Bottom gradient color
- Creates a smooth vertical gradient effect

#### 2. Navigation Colors
- **Text Color**: Default text color for menu items
- **Icon Color**: Color for menu icons
- **Hover Background**: Background color when hovering over items
- **Hover Text**: Text color when hovering
- **Active Background**: Background color for the currently active page
- **Active Text**: Text color for the active page

#### 3. Profile Section
- **Avatar Start Color**: Top gradient color for avatar circle
- **Avatar End Color**: Bottom gradient color for avatar circle
- **Name Color**: Color for admin name display
- **Role Color**: Color for role label (e.g., "SUPER ADMIN")

#### 4. Submenu Colors
- **Background**: Background color for submenu containers
- **Text Color**: Default text color for submenu items
- **Hover Background**: Background when hovering over submenu items
- **Active Background**: Background for active submenu item
- **Active Text**: Text color for active submenu item

### Live Preview
- Real-time preview of all color changes
- Shows example menu structure with profile, navigation items, and submenus
- Updates instantly as you adjust colors

### Controls
- **Save Settings**: Apply changes to the sidebar (notifies all admins)
- **Reset to Defaults**: Restore original Bootstrap 5-themed colors

## Technical Details

### Database
- Table: `sidebar_theme_settings`
- Service: `SidebarThemeService`
- Controller: `SidebarSettingsController`
- Municipality-specific settings (currently ID: 1)

### Files
- Page: `/modules/admin/sidebar_settings.php`
- Service: `/services/SidebarThemeService.php`
- Controller: `/controllers/SidebarSettingsController.php`
- JavaScript: `/assets/js/admin/sidebar-theme-settings.js`
- Sidebar Include: `/includes/admin/admin_sidebar.php`

### Security
- CSRF token protection
- Super admin role required
- Input validation (hex color format)
- Activity logging

### Notifications
When settings are updated:
- Visual change notification sent to all admins via email
- Bell notification created for all admins
- Activity logged with before/after values

## Color Format
All colors must be in **hex format**: `#RRGGBB`
- Example: `#0d6efd` (Bootstrap primary blue)
- Example: `#6c757d` (Bootstrap secondary gray)

## Default Color Scheme
The default colors follow Bootstrap 5's design system:
- Light neutral backgrounds (#f8f9fa, #ffffff)
- Primary blue for active states (#0d6efd)
- Gray tones for text and borders (#212529, #6c757d, #dee2e6)

## Tips
1. **Test with Preview**: Always check the live preview before saving
2. **Color Contrast**: Ensure text colors have sufficient contrast with backgrounds
3. **Consistency**: Match colors with your topbar theme for unified branding
4. **Save Frequently**: Changes only apply after clicking "Save Settings"
5. **Document Custom Colors**: Keep a note of your custom hex codes for reference

## Troubleshooting

### "Table does not exist" error
Run the migration script:
```bash
php sql/migrate_sidebar_theme.php
```

### Changes not appearing
1. Clear browser cache (Ctrl + F5)
2. Check browser console for JavaScript errors
3. Verify you're logged in as super admin
4. Ensure CSRF token is valid (refresh page if needed)

### Preview doesn't update
1. Check browser console for errors
2. Verify `sidebar-theme-settings.js` is loaded
3. Ensure color inputs are properly formatted

## Related
- **Topbar Settings**: `/modules/admin/topbar_settings.php`
- **Theme Settings Service**: `/services/ThemeSettingsService.php`
- **Admin Sidebar**: `/includes/admin/admin_sidebar.php`
