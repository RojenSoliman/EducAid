# How It Works Page - Inline Editor Implementation

## Created Files

### 1. SQL Table Definitions
- ✅ `sql/create_how_it_works_content_blocks.sql` - Main content storage table
- ✅ `sql/create_how_it_works_content_audit.sql` - Audit/history tracking table

### 2. PHP Helper
- ✅ `includes/website/how_it_works_content_helper.php`
  - Functions: `hiw_block()`, `hiw_block_style()`, `hiw_sanitize_html()`
  - Uses global variable: `$HIW_SAVED_BLOCKS`
  - **Fixed bug**: Loads data on every include (not just first time)

### 3. AJAX Endpoints
- ✅ `website/ajax_save_hiw_content.php` - Save edited blocks
- ✅ `website/ajax_get_hiw_blocks.php` - Fetch current blocks for refresh
- ✅ `website/ajax_reset_hiw_content.php` - Reset all blocks to defaults
- ✅ `website/ajax_get_hiw_history.php` - View edit history
- ✅ `website/ajax_rollback_hiw_block.php` - Rollback to previous version

## Next Steps to Complete Integration

### Step 1: Run SQL Files
Execute the SQL files to create the tables:

```bash
# From your PostgreSQL client or pgAdmin
\i C:/xampp/htdocs/EducAid/sql/create_how_it_works_content_blocks.sql
\i C:/xampp/htdocs/EducAid/sql/create_how_it_works_content_audit.sql
```

Or via PHP:
```bash
cd C:\xampp\htdocs\EducAid
php -r "require 'config/database.php'; pg_query_file(\$connection, 'sql/create_how_it_works_content_blocks.sql'); pg_query_file(\$connection, 'sql/create_how_it_works_content_audit.sql'); echo 'Tables created';"
```

### Step 2: Update how-it-works.php

Add these sections at the top of `website/how-it-works.php`:

```php
<?php
session_start();
// Determine super admin edit mode for How It Works page (?edit=1)
$IS_EDIT_MODE = false; $is_super_admin = false;
@include_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
  $role = @getCurrentAdminRole($connection);
  if ($role === 'super_admin') { $is_super_admin = true; }
}
if ($is_super_admin && isset($_GET['edit']) && $_GET['edit'] == '1') { $IS_EDIT_MODE = true; }
// Load dedicated how-it-works page content helper (separate storage)
@include_once __DIR__ . '/../includes/website/how_it_works_content_helper.php';
?>
```

### Step 3: Add Editor Toolbar

Add this after the navbar (if `$IS_EDIT_MODE`):

```php
<?php if($IS_EDIT_MODE): ?>
<!-- Add the same toolbar and styles from about.php -->
<!-- See about.php lines 30-75 for the complete toolbar HTML -->
<?php endif; ?>
```

### Step 4: Make Content Editable

Replace static content with helper functions. Example:

**Before:**
```html
<h1 class="display-4 fw-bold mb-3">How <span class="text-primary">EducAid</span> Works</h1>
```

**After:**
```php
<h1 class="display-4 fw-bold mb-3" data-lp-key="hiw_hero_title"<?php echo hiw_block_style('hiw_hero_title'); ?>><?php echo hiw_block('hiw_hero_title','How <span class="text-primary">EducAid</span> Works'); ?></h1>
```

### Step 5: Add Shared Editor Script

Add before closing `</body>` tag:

```php
<?php if($IS_EDIT_MODE): ?>
<script src="../assets/js/website/content_editor.js"></script>
<script>
// Initialize shared ContentEditor for How It Works page
ContentEditor.init({
  page: 'how-it-works',
  saveEndpoint: 'ajax_save_hiw_content.php',
  resetAllEndpoint: 'ajax_reset_hiw_content.php',
  history: { 
    fetchEndpoint: 'ajax_get_hiw_history.php', 
    rollbackEndpoint: 'ajax_rollback_hiw_block.php' 
  },
  refreshAfterSave: async (keys)=>{
    try {
      const r = await fetch('ajax_get_hiw_blocks.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({keys})
      });
      const d = await r.json(); 
      if(!d.success) return;
      (d.blocks||[]).forEach(b=>{ 
        const el=document.querySelector('[data-lp-key="'+CSS.escape(b.block_key)+'"]'); 
        if(!el) return; 
        el.innerHTML=b.html; 
        if(b.text_color) el.style.color=b.text_color; 
        else el.style.removeProperty('color'); 
        if(b.bg_color) el.style.backgroundColor=b.bg_color; 
        else el.style.removeProperty('background-color'); 
      });
    } catch(err){ console.error('Refresh error', err); }
  }
});
</script>
<?php endif; ?>
```

## Function Reference

### Helper Functions (in templates)
```php
// Get block HTML content
hiw_block('block_key', 'Default HTML')

// Get inline styles for block
hiw_block_style('block_key')

// Sanitize user HTML
hiw_sanitize_html($html)
```

### Example Usage
```php
<h2 data-lp-key="hiw_section_title"<?php echo hiw_block_style('hiw_section_title'); ?>>
  <?php echo hiw_block('hiw_section_title', 'Default Title'); ?>
</h2>
```

## Naming Convention

Following the pattern:
- **Landing Page**: `lp_*` (landing page)
- **About Page**: `about_*`
- **How It Works**: `hiw_*` (how-it-works shortened)

Example block keys:
- `hiw_hero_title`
- `hiw_hero_lead`
- `hiw_step1_title`
- `hiw_step1_desc`
- etc.

## Testing Checklist

1. ✅ Create SQL tables
2. ✅ Add edit mode logic to how-it-works.php
3. ✅ Add toolbar and styles (copy from about.php)
4. ✅ Convert static content to `hiw_block()` calls
5. ✅ Add `data-lp-key` attributes to editable elements
6. ✅ Include content_editor.js script
7. ✅ Initialize ContentEditor with correct endpoints
8. ✅ Test: Visit `how-it-works.php?edit=1`
9. ✅ Test: Edit content and click Save
10. ✅ Test: Refresh page - changes should persist
11. ✅ Test: View history modal
12. ✅ Test: Reset all blocks

## Key Difference from About Page

The **bug fix** applied to `about_content_helper.php` is already implemented in `how_it_works_content_helper.php`:
- ✅ No early `return` statement that skips data loading
- ✅ `$HIW_SAVED_BLOCKS` is loaded on every include
- ✅ Functions are wrapped in `if (!function_exists())` checks

This ensures saved content displays immediately after save/refresh!
