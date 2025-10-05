# Municipality Logo Bulk Upload Tools

## Overview
Two tools for bulk uploading preset municipality logos from the `/assets/City Logos/` directory into the database.

## Tools

### 1. CLI Tool (Recommended) ⭐
**File**: `cli_upload_municipality_logos.php`

Best for:
- Automated deployment
- Initial setup
- CI/CD pipelines
- Server administrators

**Usage**:
```bash
# Preview changes (dry-run mode)
php cli_upload_municipality_logos.php --dry-run

# Apply changes to database
php cli_upload_municipality_logos.php

# Force update all logos (even if already set)
php cli_upload_municipality_logos.php --force

# Show help
php cli_upload_municipality_logos.php --help
```

**Output Example**:
```
╔════════════════════════════════════════════════════════════╗
║   Municipality Logo Bulk Upload Tool                      ║
╚════════════════════════════════════════════════════════════╝

📁 Scanning directory: /path/to/assets/City Logos
🖼️  Found 23 image files
🏛️  Found 23 municipalities
────────────────────────────────────────────────────────────

✅ [City of General Trias] Updated to: General_Trias_City_Logo.png (420.8KB)
⏭️  [Amadeo] Already set: Amadeo_Logo.png
⏭️  [Kawit] Already set: Kawit_Logo.png
...

════════════════════════════════════════════════════════════
📊 SUMMARY
════════════════════════════════════════════════════════════
✅ Updated:      1
⏭️  Skipped:      22
❌ Errors:       0
⚠️  No Match:     0
─────────────────────
📋 Total:        23 municipalities
════════════════════════════════════════════════════════════

🎉 Successfully updated 1 logo(s)!
```

---

### 2. Web Tool
**File**: `bulk_upload_municipality_logos.php`

Best for:
- Visual verification
- Manual review
- Non-technical users
- One-time operations

**Usage**:
1. Open in browser: `http://your-domain/bulk_upload_municipality_logos.php`
2. Review the auto-matching results in the table
3. Check statistics and any warnings
4. Results are applied immediately

**Features**:
- Color-coded status badges
- File size display
- Shows current vs. new logo paths
- Lists unmatched files
- Direct navigation to Municipality Hub

---

## How It Works

### Matching Algorithm

The tools use intelligent name matching to pair logo files with municipalities:

#### 1. Name Normalization
- Removes prefixes: "City of", "Municipality of"
- Converts special characters: ñ → n, á → a
- Replaces separators: spaces, dashes, commas → underscores
- Case-insensitive matching

#### 2. Pattern Matching
Tries multiple variations:
```php
// For "City of Dasmariñas"
- "dasmarinas"
- "city_of_dasmarinas"
- "dasma" (special mapping)
```

#### 3. Special Mappings
Pre-defined abbreviations:
- `City of Dasmariñas` → `Dasma_City_Logo.png`
- `General Emilio Aguinaldo` → `Gen_Emilio_Aguinaldo_Logo.png`
- `Mendez-Nuñez` → `Mendez_Logo.png`

### Update Logic

1. **Scan** `/assets/City Logos/` for image files (PNG, JPG, GIF, WebP, SVG)
2. **Fetch** all municipalities from database
3. **Match** each municipality to a logo file
4. **Compare** with existing `preset_logo_image` value
5. **Update** if different (or force mode enabled)
6. **Skip** if already set correctly
7. **Report** any unmatched municipalities or files

---

## File Naming Convention

### Standard Format
```
{Municipality_Name}_Logo.{ext}
{Municipality_Name}_City_Logo.{ext}
```

### Examples
✅ Good:
- `General_Trias_City_Logo.png`
- `Kawit_Logo.png`
- `Dasma_City_Logo.png`
- `Alfonso_Logo.png`

❌ Bad:
- `logo_gentri.png` (won't match)
- `city-logo.png` (too generic)
- `gt_logo.png` (abbreviation not in special mappings)

### Adding Custom Mappings

Edit both tools and add to the `$specialMappings` array:

```php
$specialMappings = [
    'dasmarinas' => 'dasma',
    'general_emilio_aguinaldo' => 'gen_emilio_aguinaldo',
    'mendez_nunez' => 'mendez',
    // Add your custom mapping here:
    'your_municipality_name' => 'abbreviated_form',
];
```

---

## Common Scenarios

### Scenario 1: Initial Deployment
You have all logo files but empty database:

```bash
# Step 1: Preview
php cli_upload_municipality_logos.php --dry-run

# Step 2: Apply
php cli_upload_municipality_logos.php

# Expected result: All 23 updated
```

### Scenario 2: Adding New Municipality
You added one new municipality and its logo:

```bash
# Only the new municipality will be updated
php cli_upload_municipality_logos.php

# Expected result: 1 updated, 22 skipped
```

### Scenario 3: Correcting Mistakes
You accidentally set wrong logos:

```bash
# Force re-upload all logos
php cli_upload_municipality_logos.php --force

# Expected result: All 23 updated (overwrites existing)
```

### Scenario 4: Logo File Renamed
You renamed `Old_Name_Logo.png` to `New_Name_Logo.png`:

```bash
# Remove old file, add new file, then run:
php cli_upload_municipality_logos.php

# The tool will detect the change and update
```

---

## Troubleshooting

### No Matches Found

**Problem**: Municipality shows "⚠️ No matching logo file found"

**Solutions**:
1. Check file naming: Should match municipality name
2. Check file extension: Must be image file (png, jpg, gif, webp, svg)
3. Add to special mappings if abbreviated name is used
4. Verify file exists in `/assets/City Logos/` directory

### Logo Already Set

**Problem**: Logo shows "⏭️ Already set" but you want to update it

**Solution**: Use `--force` flag
```bash
php cli_upload_municipality_logos.php --force
```

### Database Update Failed

**Problem**: Shows "❌ ERROR: ..."

**Solutions**:
1. Check database connection in `/config/database.php`
2. Verify `municipalities` table has `preset_logo_image` column
3. Run migration: `sql/add_municipalities_updated_at.sql`
4. Check PostgreSQL error log for details

### Special Characters Not Working

**Problem**: "Dasmariñas" or "Mendez-Nuñez" not matching

**Solution**: Already handled! The normalization converts ñ→n automatically.
If issues persist, add explicit mapping in `$specialMappings`.

---

## API Reference

### CLI Options

| Option | Description | Example |
|--------|-------------|---------|
| `--dry-run` | Preview without changes | `php cli_upload_municipality_logos.php --dry-run` |
| `--force` | Update all logos | `php cli_upload_municipality_logos.php --force` |
| `--help` | Show help message | `php cli_upload_municipality_logos.php --help` |

### Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success (no errors) |
| 1 | Errors occurred during update |

### Usage in Scripts

```bash
#!/bin/bash

# Run bulk upload and check exit code
php cli_upload_municipality_logos.php

if [ $? -eq 0 ]; then
    echo "✅ Logo upload successful"
else
    echo "❌ Logo upload failed"
    exit 1
fi
```

---

## Performance

- **Speed**: ~1 second for 23 municipalities
- **Memory**: <10MB
- **Database**: 1 query per municipality update
- **File I/O**: Minimal (only reads filenames)

---

## Security

✅ Read-only file system access (scans directory)  
✅ Parameterized SQL queries (prevents injection)  
✅ No user input validation needed (automated)  
✅ Safe for production use  

⚠️ **Note**: Web tool should be restricted to super_admin only or removed after initial setup.

---

## Maintenance

### After Adding New City Logos
1. Place logo file in `/assets/City Logos/`
2. Run: `php cli_upload_municipality_logos.php`
3. Verify via debug page: `/debug_municipality_logos.php`

### After Renaming Municipality
1. Update municipality name in database
2. Run with `--force`: `php cli_upload_municipality_logos.php --force`
3. Or add special mapping if old logo file name should still work

### Backup
Logo files are static assets - include in your regular file backup.
Database paths can be regenerated by re-running the bulk upload tool.

---

## Related Documentation

- [MUNICIPALITY_LOGO_SYSTEM.md](MUNICIPALITY_LOGO_SYSTEM.md) - Complete logo system overview
- [Municipality Hub Guide](modules/admin/municipality_content.php) - Manual logo upload via UI

---

## Support

For issues or questions:
1. Check `/debug_municipality_logos.php` for current database state
2. Run CLI tool with `--dry-run` to preview changes
3. Review matching logic in script source code
4. Check PostgreSQL logs for database errors

---

**Last Updated**: October 5, 2025  
**Version**: 1.0  
**Maintainer**: System Administrator
