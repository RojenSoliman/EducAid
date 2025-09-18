# Modular Navbar System

This system allows you to create reusable navigation components across your EducAid website.

## Files Created

1. **`includes/website/topbar.php`** - Top information bar with contact info and search
2. **`includes/website/navbar.php`** - Main navigation bar (modular)
3. **`includes/website/nav_config.php`** - Configuration examples and helper functions
4. **`examples/about.php`** - Example of custom navigation links
5. **`examples/student_dashboard.php`** - Example of custom branding and navigation

## Basic Usage

### Default Navbar (Landing Page)
```php
<?php include 'includes/website/topbar.php'; ?>
<?php include 'includes/website/navbar.php'; ?>
```

This will use the default navigation links:
- Home
- About  
- How it works
- Announcements
- Requirements
- Contact

### Custom Navigation Links
```php
<?php
$custom_nav_links = [
  ['href' => 'page1.php', 'label' => 'Page 1', 'active' => true],
  ['href' => 'page2.php', 'label' => 'Page 2', 'active' => false],
  ['href' => 'page3.php', 'label' => 'Page 3', 'active' => false]
];

include 'includes/website/navbar.php';
?>
```

### Custom Branding
```php
<?php
$custom_brand_config = [
  'badge' => 'EA',
  'name' => 'EducAid Admin',
  'subtitle' => '• Management Portal',
  'href' => 'dashboard.php'
];

include 'includes/website/navbar.php';
?>
```

### Both Custom Navigation AND Branding
```php
<?php
// Custom brand
$custom_brand_config = [
  'badge' => 'SA',
  'name' => 'Student Portal',
  'subtitle' => '• My Account',
  'href' => 'dashboard.php'
];

// Custom navigation
$custom_nav_links = [
  ['href' => 'dashboard.php', 'label' => 'Dashboard', 'active' => true],
  ['href' => 'profile.php', 'label' => 'Profile', 'active' => false],
  ['href' => 'logout.php', 'label' => 'Logout', 'active' => false]
];

include 'includes/website/navbar.php';
?>
```

## Advanced Usage

### Setting Active Navigation Dynamically
```php
<?php
include 'includes/website/nav_config.php';

// Get current page
$current_page = basename($_SERVER['PHP_SELF']);

// Use helper function to set active item
$custom_nav_links = setActiveNavItem($student_nav_links, $current_page);

include 'includes/website/navbar.php';
?>
```

### Skip Topbar for Internal Pages
```php
<?php
// Only include navbar, skip topbar
include 'includes/website/navbar.php';
?>
```

## Configuration Options

### Navigation Link Array Structure
```php
[
  'href' => 'page.php',    // Link URL
  'label' => 'Page Name',  // Display text
  'active' => true|false   // Whether this is the current page
]
```

### Brand Configuration Structure  
```php
[
  'badge' => 'EA',                        // Badge text (2-3 chars)
  'name' => 'EducAid',                   // Main brand name
  'subtitle' => '• City of General Trias', // Subtitle text
  'href' => '#'                          // Brand link URL
]
```

## File Structure
```
EducAid/
├── includes/
│   └── website/
│       ├── topbar.php      # Top information bar
│       ├── navbar.php      # Main navigation
│       └── nav_config.php  # Configuration examples
├── examples/
│   ├── about.php           # Custom nav example
│   └── student_dashboard.php # Custom brand + nav example
└── landingpage.php         # Updated to use modular navbar
```

## Migration Guide

### From Static HTML to Modular PHP

**Before (landingpage.html):**
```html
<!-- Navbar -->
<nav class="navbar navbar-expand-lg bg-white sticky-top">
  <div class="container">
    <a class="navbar-brand" href="#">
      <span class="brand-badge">EA</span>
      <span>EducAid <span class="text-body-secondary d-none d-sm-inline">• City of General Trias</span></span>
    </a>
    <!-- ... rest of navbar -->
  </div>
</nav>
```

**After (any-page.php):**
```php
<?php include 'includes/website/navbar.php'; ?>
```

### Benefits

1. **DRY Principle** - Don't repeat navbar code across pages
2. **Easy Updates** - Change navbar once, updates everywhere
3. **Flexible** - Different navigation for different sections
4. **Maintainable** - Centralized navigation management
5. **Consistent** - Same styling and behavior across site

### Page Types

1. **Landing/Marketing Pages** - Use topbar + navbar
2. **Admin Pages** - Use navbar only with admin nav links
3. **Student Portal** - Use navbar only with student nav links
4. **About/Info Pages** - Use topbar + navbar with custom links

This modular system makes your navbar reusable across all pages while allowing customization for different sections of your website.