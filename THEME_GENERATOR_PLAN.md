# 🎨 Automatic Color Theme Generator - Implementation Plan

**Date:** October 15, 2025  
**Status:** 📋 Planning Phase  
**Goal:** One-click button to auto-generate all theme colors from primary/secondary colors

---

## 🎯 Current State Analysis

### What We Have Now:

1. **Municipality Colors (Stored)**
   - `municipalities` table has:
     - `primary_color` (e.g., `#2e7d32`)
     - `secondary_color` (e.g., `#1b5e20`)
   - Editable via color picker modal ✅
   - Saves in real-time via AJAX ✅

2. **Sidebar Theme Settings**
   - `sidebar_theme_settings` table has **19 color fields**:
     - `sidebar_bg_start`
     - `sidebar_bg_end`
     - `sidebar_border_color`
     - `nav_text_color`
     - `nav_icon_color`
     - `nav_hover_bg`
     - `nav_hover_text`
     - `nav_active_bg`
     - `nav_active_text`
     - `profile_avatar_bg_start`
     - `profile_avatar_bg_end`
     - `profile_name_color`
     - `profile_role_color`
     - `profile_border_color`
     - `submenu_bg`
     - `submenu_text_color`
     - `submenu_hover_bg`
     - `submenu_active_bg`
     - `submenu_active_text`

3. **Topbar Theme Settings**
   - Need to check what color fields exist

4. **Header Theme Settings**
   - Need to check if this exists

### Current Problem:
❌ Municipality colors and theme colors are **NOT connected**
❌ Changing primary/secondary colors doesn't affect sidebar/topbar
❌ Admin has to manually set 19+ colors for each theme

---

## 🎨 Proposed Solution: Auto-Generate Button

### User Experience Flow:

```
┌────────────────────────────────────────────┐
│  Municipality Content Page                 │
│  ┌──────────────────────────────────────┐  │
│  │  [■ #2e7d32] Primary                 │  │
│  │  [■ #1b5e20] Secondary               │  │
│  │  [Edit Colors] [🪄 Generate Theme]   │  │
│  └──────────────────────────────────────┘  │
└────────────────────────────────────────────┘
                    ↓
        User clicks "Generate Theme"
                    ↓
┌────────────────────────────────────────────┐
│  🪄 Generating Theme...                    │
│  ⏳ Please wait...                         │
└────────────────────────────────────────────┘
                    ↓
        System does magic:
        1. Takes primary: #2e7d32
        2. Takes secondary: #1b5e20
        3. Generates 19+ derivative colors
        4. Updates sidebar_theme_settings
        5. Updates topbar_theme_settings
        6. Updates header_theme_settings (if exists)
                    ↓
┌────────────────────────────────────────────┐
│  ✅ Theme Generated Successfully!          │
│  - Sidebar colors updated                  │
│  - Topbar colors updated                   │
│  - All pages will use new theme            │
└────────────────────────────────────────────┘
```

---

## 🧮 Color Generation Algorithm

### Input:
- `$primaryColor = '#2e7d32'` (Green)
- `$secondaryColor = '#1b5e20'` (Dark Green)

### Step 1: Convert to HSL (Hue, Saturation, Lightness)
```
Primary #2e7d32:
  RGB: (46, 125, 50)
  HSL: (123°, 46%, 34%)

Secondary #1b5e20:
  RGB: (27, 94, 32)
  HSL: (124°, 55%, 24%)
```

### Step 2: Generate Derivatives

#### From Primary Color:
```php
// Lighter variations (for backgrounds, hover states)
$primary_light_90 = lighten($primary, 90%)  → #e8f5e9  (very light bg)
$primary_light_80 = lighten($primary, 80%)  → #c8e6c9  (light bg)
$primary_light_20 = lighten($primary, 20%)  → #3a9d42  (hover state)

// Darker variations (for borders, active states)
$primary_dark_10 = darken($primary, 10%)    → #256028  (active state)
$primary_dark_05 = darken($primary, 5%)     → #29712e  (border)

// Desaturated (for subtle elements)
$primary_gray = desaturate($primary, 70%)   → #4a5d4b  (muted text)
```

#### From Secondary Color:
```php
$secondary_light_20 = lighten($secondary, 20%)  → #267b2e
$secondary_dark_10 = darken($secondary, 10%)    → #15481c
$secondary_saturate = saturate($secondary, 10%) → #1b6620
```

#### Auto-Generate Text Colors (Contrast):
```php
// White text on dark backgrounds
getContrastText('#2e7d32') → '#ffffff'

// Dark text on light backgrounds  
getContrastText('#e8f5e9') → '#000000'
```

---

## 🎨 Color Mapping Plan

### Sidebar Theme Colors:

| Field | Generated From | Formula | Example |
|-------|---------------|---------|---------|
| `sidebar_bg_start` | Primary | lighten(90%) | `#e8f5e9` |
| `sidebar_bg_end` | Primary | lighten(95%) | `#f1f8f1` |
| `sidebar_border_color` | Primary | darken(5%) | `#29712e` |
| `nav_text_color` | Auto | contrast check | `#212529` |
| `nav_icon_color` | Primary | desaturate(50%) | `#5a6d5b` |
| `nav_hover_bg` | Primary | lighten(80%) | `#c8e6c9` |
| `nav_hover_text` | Primary | base color | `#2e7d32` |
| `nav_active_bg` | Primary | base color | `#2e7d32` |
| `nav_active_text` | Auto | contrast text | `#ffffff` |
| `profile_avatar_bg_start` | Primary | base color | `#2e7d32` |
| `profile_avatar_bg_end` | Secondary | base color | `#1b5e20` |
| `profile_name_color` | Auto | dark gray | `#212529` |
| `profile_role_color` | Auto | gray | `#6c757d` |
| `profile_border_color` | Primary | lighten(70%) | `#a5d6a7` |
| `submenu_bg` | Primary | lighten(92%) | `#eff8ef` |
| `submenu_text_color` | Auto | dark gray | `#495057` |
| `submenu_hover_bg` | Primary | lighten(85%) | `#d4ead4` |
| `submenu_active_bg` | Primary | lighten(88%) | `#e1f2e1` |
| `submenu_active_text` | Primary | base color | `#2e7d32` |

### Topbar Theme Colors (Need to check what exists):
- Similar mapping strategy
- Use primary for main elements
- Use secondary for accents

---

## 🔧 Technical Architecture

### Phase 1: Color Generation Service

**File:** `services/ColorGeneratorService.php`

```php
class ColorGeneratorService {
    // Conversion functions
    public static function hexToRgb(string $hex): array
    public static function rgbToHsl(int $r, int $g, int $b): array
    public static function hslToRgb(float $h, float $s, float $l): array
    public static function rgbToHex(int $r, int $g, int $b): string
    
    // Color manipulation
    public static function lighten(string $hex, float $percent): string
    public static function darken(string $hex, float $percent): string
    public static function saturate(string $hex, float $percent): string
    public static function desaturate(string $hex, float $percent): string
    
    // Utility
    public static function getContrastText(string $bgHex): string
    public static function adjustBrightness(string $hex, float $amount): string
}
```

### Phase 2: Theme Generator Service

**File:** `services/ThemeGeneratorService.php`

```php
class ThemeGeneratorService {
    private $connection;
    
    public function __construct($connection) {
        $this->connection = $connection;
    }
    
    /**
     * Generate complete theme from primary/secondary colors
     */
    public function generateThemeFromColors(
        int $municipalityId,
        string $primaryColor,
        string $secondaryColor
    ): array {
        // Generate all derivative colors
        $palette = $this->generateColorPalette($primaryColor, $secondaryColor);
        
        // Apply to sidebar
        $sidebarResult = $this->applySidebarTheme($municipalityId, $palette);
        
        // Apply to topbar
        $topbarResult = $this->applyTopbarTheme($municipalityId, $palette);
        
        // Apply to header
        $headerResult = $this->applyHeaderTheme($municipalityId, $palette);
        
        return [
            'success' => true,
            'sidebar_updated' => $sidebarResult,
            'topbar_updated' => $topbarResult,
            'header_updated' => $headerResult,
            'palette' => $palette
        ];
    }
    
    private function generateColorPalette(string $primary, string $secondary): array {
        return [
            // Primary variations
            'primary_base' => $primary,
            'primary_light_90' => ColorGeneratorService::lighten($primary, 0.9),
            'primary_light_80' => ColorGeneratorService::lighten($primary, 0.8),
            'primary_light_20' => ColorGeneratorService::lighten($primary, 0.2),
            'primary_dark_05' => ColorGeneratorService::darken($primary, 0.05),
            'primary_dark_10' => ColorGeneratorService::darken($primary, 0.1),
            'primary_muted' => ColorGeneratorService::desaturate($primary, 0.7),
            'primary_contrast_text' => ColorGeneratorService::getContrastText($primary),
            
            // Secondary variations
            'secondary_base' => $secondary,
            'secondary_light_20' => ColorGeneratorService::lighten($secondary, 0.2),
            'secondary_dark_10' => ColorGeneratorService::darken($secondary, 0.1),
            
            // Neutral colors (based on primary)
            'neutral_dark' => '#212529',
            'neutral_medium' => '#6c757d',
            'neutral_light' => '#dee2e6'
        ];
    }
    
    private function applySidebarTheme(int $municipalityId, array $palette): bool {
        $sidebarSettings = [
            'sidebar_bg_start' => $palette['primary_light_90'],
            'sidebar_bg_end' => '#ffffff',
            'sidebar_border_color' => $palette['primary_dark_05'],
            'nav_text_color' => $palette['neutral_dark'],
            'nav_icon_color' => $palette['primary_muted'],
            'nav_hover_bg' => $palette['primary_light_80'],
            'nav_hover_text' => $palette['primary_base'],
            'nav_active_bg' => $palette['primary_base'],
            'nav_active_text' => $palette['primary_contrast_text'],
            'profile_avatar_bg_start' => $palette['primary_base'],
            'profile_avatar_bg_end' => $palette['secondary_base'],
            'profile_name_color' => $palette['neutral_dark'],
            'profile_role_color' => $palette['neutral_medium'],
            'profile_border_color' => $palette['neutral_light'],
            'submenu_bg' => $palette['primary_light_90'],
            'submenu_text_color' => $palette['neutral_dark'],
            'submenu_hover_bg' => $palette['primary_light_80'],
            'submenu_active_bg' => $palette['primary_light_90'],
            'submenu_active_text' => $palette['primary_base']
        ];
        
        $sidebarService = new SidebarThemeService($this->connection);
        return $sidebarService->updateSettings($sidebarSettings, $municipalityId);
    }
}
```

### Phase 3: AJAX Endpoint

**File:** `modules/admin/generate_theme.php`

```php
<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
require_once __DIR__ . '/../../services/ThemeGeneratorService.php';

header('Content-Type: application/json');

// Validate admin access
if (!isset($_SESSION['admin_id']) || getCurrentAdminRole($connection) !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Validate CSRF
if (!CSRFProtection::validateToken('generate-theme', $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

$municipalityId = (int) ($_POST['municipality_id'] ?? 0);

// Get municipality colors
$query = "SELECT primary_color, secondary_color FROM municipalities WHERE municipality_id = $1";
$result = pg_query_params($connection, $query, [$municipalityId]);
$municipality = pg_fetch_assoc($result);

if (!$municipality) {
    echo json_encode(['success' => false, 'message' => 'Municipality not found']);
    exit;
}

// Generate theme
$generator = new ThemeGeneratorService($connection);
$result = $generator->generateThemeFromColors(
    $municipalityId,
    $municipality['primary_color'],
    $municipality['secondary_color']
);

echo json_encode($result);
```

### Phase 4: UI Button

**Location:** `municipality_content.php`

Add button next to "Edit Colors":
```html
<button type="button" class="btn btn-success btn-sm" 
        id="generateThemeBtn"
        data-municipality-id="<?= $activeMunicipality['municipality_id'] ?>">
    <i class="bi bi-magic me-1"></i>Generate Theme
</button>
```

JavaScript:
```javascript
document.getElementById('generateThemeBtn')?.addEventListener('click', async function() {
    if (!confirm('Generate complete theme from current colors? This will update sidebar, topbar, and header colors.')) {
        return;
    }
    
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generating...';
    
    try {
        const formData = new FormData();
        formData.append('csrf_token', '<?= CSRFProtection::generateToken('generate-theme') ?>');
        formData.append('municipality_id', btn.dataset.municipalityId);
        
        const response = await fetch('generate_theme.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✅ Theme generated successfully!\n\n' +
                  '- Sidebar colors updated\n' +
                  '- Topbar colors updated\n' +
                  '- All changes applied');
        } else {
            alert('❌ ' + result.message);
        }
    } catch (error) {
        alert('❌ Failed to generate theme');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-magic me-1"></i>Generate Theme';
    }
});
```

---

## 🎨 Color Generation Examples

### Example 1: Green Theme
**Input:**
- Primary: `#2e7d32`
- Secondary: `#1b5e20`

**Generated Sidebar Colors:**
```
sidebar_bg_start:        #e8f5e9  (very light green)
sidebar_bg_end:          #ffffff  (white)
sidebar_border_color:    #29712e  (dark green border)
nav_hover_bg:            #c8e6c9  (light green hover)
nav_active_bg:           #2e7d32  (primary green)
nav_active_text:         #ffffff  (white text)
profile_avatar_bg_start: #2e7d32  (primary green)
profile_avatar_bg_end:   #1b5e20  (secondary dark green)
```

### Example 2: Blue Theme
**Input:**
- Primary: `#1976d2`
- Secondary: `#0d47a1`

**Generated Sidebar Colors:**
```
sidebar_bg_start:        #e3f2fd  (very light blue)
nav_hover_bg:            #bbdefb  (light blue hover)
nav_active_bg:           #1976d2  (primary blue)
profile_avatar_bg_start: #1976d2  (primary blue)
profile_avatar_bg_end:   #0d47a1  (secondary navy)
```

### Example 3: Red Theme
**Input:**
- Primary: `#d32f2f`
- Secondary: `#c62828`

**Generated Sidebar Colors:**
```
sidebar_bg_start:        #ffebee  (very light red)
nav_hover_bg:            #ffcdd2  (light red hover)
nav_active_bg:           #d32f2f  (primary red)
profile_avatar_bg_start: #d32f2f  (primary red)
profile_avatar_bg_end:   #c62828  (secondary dark red)
```

---

## 📊 Implementation Phases

### Phase 1: Color Math Library ⏱️ 2-3 hours
- Create `ColorGeneratorService.php`
- Implement hex ↔ RGB ↔ HSL conversions
- Implement lighten/darken/saturate functions
- Test with multiple colors

### Phase 2: Theme Generator ⏱️ 2-3 hours
- Create `ThemeGeneratorService.php`
- Implement color palette generation
- Implement sidebar theme application
- Check topbar/header tables structure

### Phase 3: AJAX Endpoint ⏱️ 1 hour
- Create `generate_theme.php`
- Add CSRF protection
- Add error handling
- Test API responses

### Phase 4: UI Integration ⏱️ 1 hour
- Add "Generate Theme" button
- Add JavaScript click handler
- Add loading states
- Add success/error messages

### Phase 5: Testing ⏱️ 2-3 hours
- Test with 5+ different color schemes
- Test contrast ratios (accessibility)
- Test on actual sidebar/topbar
- Fix edge cases

**Total Time Estimate:** 8-11 hours

---

## 🎯 Success Criteria

### Must Have:
- [ ] One-click button generates complete theme
- [ ] Updates sidebar_theme_settings table
- [ ] Updates topbar_theme_settings table (if exists)
- [ ] Colors look good (proper contrast, readability)
- [ ] No page refresh needed (AJAX)
- [ ] Works with any primary/secondary color combo

### Nice to Have:
- [ ] Preview before applying
- [ ] Undo/revert option
- [ ] Save multiple theme presets
- [ ] Export/import themes
- [ ] Color harmony validation

---

## 🚨 Edge Cases to Handle

### 1. Very Light Colors
**Problem:** White primary (#ffffff) would generate all-white theme
**Solution:** Check lightness, ensure minimum contrast ratios

### 2. Very Dark Colors
**Problem:** Black primary (#000000) would be hard to read
**Solution:** Auto-lighten backgrounds, darken text

### 3. Low Saturation (Gray)
**Problem:** Gray colors have no hue to work with
**Solution:** Use default neutral grays, maintain structure

### 4. Similar Primary/Secondary
**Problem:** #2e7d32 and #2f7e33 are too similar
**Solution:** Auto-adjust secondary to be more different

### 5. Accessibility Issues
**Problem:** Generated contrast ratio < 4.5:1 (WCAG fail)
**Solution:** Auto-adjust until passing contrast ratio

---

## 🔍 Questions to Resolve Before Coding

### 1. Topbar Structure
❓ What color fields exist in `topbar_theme_settings`?
❓ Do we need to check if the table exists first?

### 2. Header Structure
❓ Is there a `header_theme_settings` table?
❓ Or is header part of topbar?

### 3. Color Validation
❓ Should we validate that primary ≠ secondary?
❓ Should we enforce minimum contrast ratios?

### 4. Preview Feature
❓ Should we show a preview before applying?
❓ Or just apply immediately with undo option?

### 5. Scope
❓ Are there other pages/components that need theming?
❓ Should we generate colors for buttons, forms, etc.?

---

## 🎨 Alternative Approaches

### Option A: Full Auto-Generate (Recommended)
- Click button → instantly generates all 19+ colors
- Pros: Fast, easy, one-click
- Cons: Less control, might need tweaks

### Option B: Generate with Preview
- Click button → shows preview modal
- User can tweak before applying
- Pros: More control, safer
- Cons: Extra step, more complex

### Option C: Hybrid (Best of Both)
- Default: Auto-generate and apply
- Advanced: Show "Preview & Customize" option
- Pros: Fast for beginners, flexible for advanced
- Cons: Most complex to build

---

## 📝 Next Steps

1. **Discuss and Approve Plan**
   - Review color mapping strategy
   - Decide on edge case handling
   - Choose implementation approach

2. **Check Existing Tables**
   - Query `topbar_theme_settings` structure
   - Check if `header_theme_settings` exists
   - Document all color fields

3. **Start Coding**
   - Begin with `ColorGeneratorService.php`
   - Test color math functions
   - Build `ThemeGeneratorService.php`
   - Create AJAX endpoint
   - Add UI button

---

## 💡 Discussion Points

**Your Input Needed:**

1. **Color Mapping:** Do the generated colors look good in the examples above?
2. **User Experience:** Should we add a preview modal or just apply directly?
3. **Scope:** Should we also generate colors for other pages (student pages, forms, etc.)?
4. **Validation:** Should we enforce color rules (contrast, brightness, etc.)?
5. **Presets:** Should we add pre-made themes (Professional, Vibrant, Calm, etc.)?

**What do you think? Should we proceed with Option A (full auto-generate) or do you want previews and more control?**
