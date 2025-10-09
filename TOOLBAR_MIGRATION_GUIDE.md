# Modular Toolbar Migration - Step by Step Guide 🚀

## Overview

This guide shows you how to update all your editable pages to use the new modular toolbar.

## Benefits of Migration

**Before:** Each page has its own toolbar code (duplicated)  
**After:** All pages use one shared toolbar file

✅ Easier to maintain  
✅ Consistent across all pages  
✅ Faster to implement new features  
✅ Less code duplication  

---

## Step 1: Contact Page ✅ (Already Done!)

The Contact page has been updated as an example.

**What was added:**
```php
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'Contact Page',
        'exit_url' => 'contact.php'
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>
```

---

## Step 2: Update Landing Page

### File: `website/landingpage.php`

**Add toolbar after `<body>` tag:**

```php
</head>
<body>
  <?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'Landing Page',
        'exit_url' => 'landingpage.php'
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
  <?php endif; ?>
  
  <?php
  // Existing navigation code...
```

**Remove old toolbar code** (if any hardcoded toolbar exists)

---

## Step 3: Update About Page

### File: `website/about.php`

**Add toolbar after `<body>` tag:**

```php
</head>
<body>
  <?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'About',
        'exit_url' => 'about.php'
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
  <?php endif; ?>
  
  <?php
  // Existing navigation code...
```

---

## Step 4: Update How It Works Page

### File: `website/how-it-works.php`

**Add toolbar after `<body>` tag:**

```php
</head>
<body>
  <?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'How It Works',
        'exit_url' => 'how-it-works.php'
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
  <?php endif; ?>
  
  <?php
  // Existing navigation code...
```

---

## Step 5: Update Requirements Page

### File: `website/requirements.php`

**Add toolbar after `<body>` tag:**

```php
</head>
<body>
  <?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'Requirements',
        'exit_url' => 'requirements.php'
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
  <?php endif; ?>
  
  <?php
  // Existing navigation code...
```

---

## Step 6: Update Announcements Page

### File: `website/announcements.php`

**Add toolbar after `<body>` tag:**

```php
</head>
<body>
  <?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'Announcements',
        'exit_url' => 'announcements.php'
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
  <?php endif; ?>
  
  <?php
  // Existing navigation code...
```

---

## Quick Copy-Paste Templates

### Template 1: Basic Toolbar
```php
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'PAGE_NAME_HERE',
        'exit_url' => 'FILENAME_HERE.php'
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>
```

### Template 2: Toolbar Without Color Picker
```php
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'PAGE_NAME_HERE',
        'exit_url' => 'FILENAME_HERE.php',
        'show_color_picker' => false
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>
```

### Template 3: Minimal Toolbar (Save & Exit Only)
```php
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'PAGE_NAME_HERE',
        'exit_url' => 'FILENAME_HERE.php',
        'show_reset' => false,
        'show_history' => false,
        'show_color_picker' => false
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>
```

---

## Testing Checklist

After updating each page, test:

### ✅ Visual Check
- [ ] Toolbar appears at top when `?edit=1` is in URL
- [ ] Toolbar shows correct page title
- [ ] All buttons are visible (Save All, Reset, History, Exit)
- [ ] Toolbar is responsive on mobile

### ✅ Functionality Check
- [ ] Save All button works
- [ ] Reset button shows confirmation modal
- [ ] History button opens history modal
- [ ] Exit button returns to public view
- [ ] Color picker works (if enabled)

### ✅ Integration Check
- [ ] ContentEditor.js still works
- [ ] Editable blocks still have red outlines
- [ ] Right-click context menu still works
- [ ] Changes save to database

---

## Before & After Comparison

### Before Migration (Per Page):

**Hardcoded toolbar in each file:**
```php
<!-- Lots of duplicated HTML/CSS/JS in every page -->
<div class="edit-toolbar" style="...">
    <div>Editing: Contact</div>
    <button onclick="saveAll()">Save</button>
    <button onclick="reset()">Reset</button>
    <!-- 50+ lines of toolbar code -->
</div>
<style>
    /* 30+ lines of CSS */
</style>
<script>
    /* 20+ lines of JavaScript */
</script>
```

**Lines of code per page:** ~100 lines  
**Total for 6 pages:** ~600 lines  

### After Migration:

**One-line include in each file:**
```php
<?php
$toolbar_config = ['page_title' => 'Contact'];
include '../includes/website/edit_toolbar.php';
?>
```

**Lines of code per page:** ~7 lines  
**Total for 6 pages:** ~42 lines  

**Savings:** ~558 lines of code! 🎉

---

## Troubleshooting

### ❌ Toolbar not showing after migration

**Check:**
1. Is `$IS_EDIT_MODE` defined before the include?
2. Is the path to `edit_toolbar.php` correct?
3. Is the toolbar include inside `<?php if ($IS_EDIT_MODE): ?>` block?

```php
// Should be:
<?php if ($IS_EDIT_MODE): ?>
    <?php include '../includes/website/edit_toolbar.php'; ?>
<?php endif; ?>

// Not:
<?php include '../includes/website/edit_toolbar.php'; ?> ❌
```

### ❌ Buttons not working

**Ensure:**
- ContentEditor.js is still loaded
- Button IDs match (`saveAllBtn`, `resetBtn`, etc.)
- Bootstrap modals are initialized

### ❌ Toolbar covering content

**Add to your CSS:**
```css
body.edit-mode {
    padding-top: 70px !important;
}
```

---

## Advanced Customization

### Custom Button Colors

Add to your page CSS:
```css
.edit-toolbar .btn-success {
    background: #10b981 !important;
}
```

### Custom Page-Specific Features

```php
<?php if ($IS_EDIT_MODE): ?>
    <?php
    $toolbar_config = [
        'page_title' => 'My Page',
        'show_history' => false, // Disable history for this page
        'show_color_picker' => true, // Enable color picker
        'exit_url' => 'custom-exit.php' // Custom exit URL
    ];
    include '../includes/website/edit_toolbar.php';
    ?>
<?php endif; ?>
```

---

## Summary

**Migration Steps:**
1. ✅ Contact Page - Already migrated (example)
2. ⏳ Landing Page - Copy template, update page title
3. ⏳ About Page - Copy template, update page title
4. ⏳ How It Works - Copy template, update page title
5. ⏳ Requirements - Copy template, update page title
6. ⏳ Announcements - Copy template, update page title

**Time to Migrate:** ~5 minutes per page (30 minutes total)

**Benefits:**
- ✅ Consistent toolbar across all pages
- ✅ Easier to maintain and update
- ✅ Less code duplication
- ✅ Professional appearance

Once migrated, updating the toolbar design/functionality globally only requires editing **one file**: `includes/website/edit_toolbar.php` 🚀

---

**Migration Guide Version:** 1.0  
**Last Updated:** October 5, 2025  
**Status:** Ready to implement
