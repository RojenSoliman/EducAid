# Modular Edit Toolbar - Implementation Guide 📝

## Overview

The **modular edit toolbar** is a reusable component that can be included in any editable page. It provides a consistent editing interface across all pages.

## Features

✅ **Responsive Design** - Works on desktop, tablet, and mobile  
✅ **Customizable** - Configure which buttons to show  
✅ **Auto-Detection** - Automatically counts editable blocks  
✅ **Dirty State Tracking** - Warns before leaving with unsaved changes  
✅ **Color Picker** - Built-in color customization modal  
✅ **History Viewer** - View and rollback edit history  
✅ **Exit Link** - Auto-detects current page for exit  

## File Location

```
includes/website/edit_toolbar.php
```

## Basic Usage

### Simple Include (Minimal Configuration)

```php
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'Contact Page'
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>
```

### Full Configuration

```php
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'Landing Page',
        'show_save_all' => true,
        'show_reset' => true,
        'show_history' => true,
        'show_exit' => true,
        'show_color_picker' => true,
        'exit_url' => 'landingpage.php' // Optional, auto-detects if not provided
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `page_title` | string | `'Page'` | Display name in toolbar badge |
| `show_save_all` | boolean | `true` | Show "Save All" button |
| `show_reset` | boolean | `true` | Show "Reset" button |
| `show_history` | boolean | `true` | Show "History" button |
| `show_exit` | boolean | `true` | Show "Exit" button |
| `show_color_picker` | boolean | `true` | Show "Colors" button |
| `exit_url` | string | `null` | Custom exit URL (auto-detects if null) |

## Implementation Examples

### Landing Page

```php
<!-- In landingpage.php, before ContentEditor.init() -->
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'Landing Page',
        'exit_url' => 'landingpage.php'
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>
```

### About Page

```php
<!-- In about.php -->
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'About',
        'show_color_picker' => false // Disable color picker if not needed
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>
```

### Contact Page

```php
<!-- In contact.php -->
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'Contact',
        'exit_url' => 'contact.php'
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>
```

### Custom Page (Minimal Buttons)

```php
<!-- Minimal toolbar with only save and exit -->
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'Custom Page',
        'show_reset' => false,
        'show_history' => false,
        'show_color_picker' => false
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>
```

## Visual Layout

### Desktop View
```
┌─────────────────────────────────────────────────────────────┐
│ 📝 Editing: Contact Page  │  18 blocks  │  ⚠ Unsaved changes │
│                                                               │
│        [Colors] [History] [Reset] [Save All] [Exit]         │
└─────────────────────────────────────────────────────────────┘
```

### Mobile View
```
┌───────────────────────────┐
│ 📝 Editing: Contact      │
│                           │
│ [🎨] [🕒] [↺] [💾] [✕]  │
└───────────────────────────┘
```

## Included Modals

The toolbar automatically includes these modals (if enabled):

### 1. Color Picker Modal
- Text color selection
- Background color selection
- Color preview
- Apply to selected block

### 2. History Modal
- Block selection dropdown
- Version history timeline
- Rollback functionality
- "Changed by" information

### 3. Reset Confirmation Modal
- Warning message
- Confirm/Cancel buttons
- Prevents accidental resets

## JavaScript Features

### Auto-Functions:

1. **Block Counter**
   - Counts all `[contenteditable="true"]` elements
   - Displays in toolbar stats

2. **Dirty State Tracking**
   - Detects when content is edited
   - Shows "Unsaved changes" indicator
   - Warns before leaving page

3. **Color Picker Sync**
   - Syncs color input with color picker
   - Validates hex color codes
   - Applies to selected block

4. **Body Padding**
   - Automatically adds `padding-top: 70px` to body
   - Prevents toolbar from covering content

## Integration with ContentEditor.js

The toolbar works seamlessly with ContentEditor.js. Here's the complete setup:

```php
<!-- 1. Include toolbar before body tag -->
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = ['page_title' => 'My Page'];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>

<!-- 2. Your page content here -->
<div class="container">
    <?php my_block('hero_title', 'Default Title', 'h1', 'display-4'); ?>
</div>

<!-- 3. Initialize ContentEditor (before </body>) -->
<?php if ($IS_EDIT_MODE): ?>
<script src="../assets/js/content_editor.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    ContentEditor.init({
        saveEndpoint: 'ajax_save_my_content.php',
        getEndpoint: 'ajax_get_my_blocks.php',
        resetEndpoint: 'ajax_reset_my_content.php',
        historyEndpoint: 'ajax_get_my_history.php',
        rollbackEndpoint: 'ajax_rollback_my_block.php',
        pageTitle: 'My Page'
    });
});
</script>
<?php endif; ?>
```

## Styling Customization

The toolbar includes inline styles, but you can override them:

```css
/* Custom toolbar colors */
.edit-toolbar {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
}

/* Custom badge style */
.edit-mode-badge {
    background: rgba(255,255,255,0.25) !important;
    border: 1px solid rgba(255,255,255,0.3);
}

/* Custom button colors */
.edit-toolbar .btn-success {
    background: #10b981 !important;
    border-color: #059669 !important;
}
```

## Button Events

The toolbar buttons trigger these IDs (connect to ContentEditor.js):

| Button | ID | Event Listener |
|--------|-----|----------------|
| Save All | `saveAllBtn` | `click` → ContentEditor.saveAll() |
| Reset | `resetBtn` | `click` → Show reset modal |
| History | `historyBtn` | `click` → Show history modal |
| Colors | `colorPickerBtn` | `click` → Show color picker |
| Exit | (link) | `href` → Exit to specified URL |

## Migration Guide

### Before (Hardcoded Toolbar):

```php
<!-- Old way: Toolbar code in every page -->
<div class="edit-toolbar">
    <div>Editing: Contact</div>
    <button id="saveAllBtn">Save</button>
    <button id="resetBtn">Reset</button>
    <!-- ... lots of duplicated code ... -->
</div>
```

### After (Modular Toolbar):

```php
<!-- New way: One line include -->
<?php
$toolbar_config = ['page_title' => 'Contact'];
include '../includes/website/edit_toolbar.php';
?>
```

**Result:** 
- ✅ 90% less code per page
- ✅ Consistent design across all pages
- ✅ Easy to update globally
- ✅ Better maintainability

## Benefits

### For Developers:
- 📦 **DRY Principle** - Don't Repeat Yourself
- 🔧 **Easy Updates** - Change once, apply everywhere
- 🎨 **Consistent UI** - Same look and feel
- ⚡ **Fast Implementation** - One-line include

### For Users:
- 🎯 **Familiar Interface** - Same toolbar on all pages
- 📱 **Responsive** - Works on all devices
- ⚠️ **Safety Features** - Warns before losing changes
- 🎨 **Color Tools** - Built-in customization

## Troubleshooting

### ❌ Toolbar not showing

**Check:**
```php
// 1. Is $IS_EDIT_MODE set to true?
var_dump($IS_EDIT_MODE); // Should be true

// 2. Is the include path correct?
include '../includes/website/edit_toolbar.php'; // Relative path

// 3. Is edit mode active?
// URL should have ?edit=1
```

### ❌ Buttons not working

**Ensure ContentEditor.js is loaded:**
```php
<?php if ($IS_EDIT_MODE): ?>
<script src="../assets/js/content_editor.js"></script>
<script>
ContentEditor.init({ /* config */ });
</script>
<?php endif; ?>
```

### ❌ Toolbar covering content

**Check body padding:**
```css
body.edit-mode {
    padding-top: 70px !important;
}
```

## Complete Example

Here's a full page implementation:

```php
<?php
session_start();

// Edit mode detection
$IS_EDIT_MODE = false;
if (isset($_GET['edit']) && $_GET['edit'] == '1') {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
        $IS_EDIT_MODE = true;
    } else {
        header('Location: mypage.php');
        exit;
    }
}

// Load helpers
require_once '../config/database.php';
require_once '../includes/website/mypage_content_helper.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php if ($IS_EDIT_MODE): ?>
    <link href="../assets/css/content_editor.css" rel="stylesheet">
    <?php endif; ?>
</head>
<body>

<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'My Page',
        'exit_url' => 'mypage.php'
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>

<div class="container">
    <?php my_block('title', 'Default Title', 'h1', 'display-4'); ?>
    <?php my_block('content', 'Default content...', 'p', 'lead'); ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($IS_EDIT_MODE): ?>
<script src="../assets/js/content_editor.js"></script>
<script>
ContentEditor.init({
    saveEndpoint: 'ajax_save_mypage_content.php',
    getEndpoint: 'ajax_get_mypage_blocks.php',
    resetEndpoint: 'ajax_reset_mypage_content.php',
    historyEndpoint: 'ajax_get_mypage_history.php',
    rollbackEndpoint: 'ajax_rollback_mypage_block.php',
    pageTitle: 'My Page'
});
</script>
<?php endif; ?>

</body>
</html>
```

## Summary

✅ **One file** to include for full toolbar functionality  
✅ **Configurable** via simple array  
✅ **Responsive** design out of the box  
✅ **Feature-rich** with modals and tracking  
✅ **Easy to maintain** and update  

The modular toolbar makes your CMS more professional and easier to maintain! 🚀

---

**File:** `includes/website/edit_toolbar.php`  
**Purpose:** Reusable edit mode toolbar  
**Dependencies:** Bootstrap 5, content_editor.js  
**Status:** ✅ Production Ready
