# Municipality Logo Management System

## Overview
Complete system for managing municipality logos with support for both preset (default) and custom uploaded logos.

## Database Schema

### Municipalities Table Columns
```sql
- preset_logo_image    TEXT      -- Path to default/preset logo (e.g., /assets/City Logos/General_Trias_City_Logo.png)
- custom_logo_image    TEXT      -- Path to custom uploaded logo (e.g., /assets/uploads/municipality_logos/general-trias_1234567890.png)
- use_custom_logo      BOOLEAN   -- TRUE = use custom logo, FALSE = use preset logo
- updated_at           TIMESTAMP -- Auto-updated on any change
```

### Important Notes
- Logo paths are stored as **TEXT** (file paths), not binary data
- Actual image files are stored in the filesystem
- Database only stores the path/URL to the image file

## File Structure

### Preset Logos Location
```
/assets/City Logos/
├── General_Trias_City_Logo.png
├── Dasma_City_Logo.png
├── Imus_City_Logo.png
└── ... (other municipality logos)
```

### Custom Uploaded Logos Location
```
/assets/uploads/municipality_logos/
├── general-trias_1234567890.png
├── dasmarinas_1234567891.jpg
└── ... (uploaded files)
```

## Bulk Upload Tools

### CLI Tool (Recommended)
Command-line script for bulk uploading preset logos from `/assets/City Logos/` directory.

**Location**: `cli_upload_municipality_logos.php`

**Usage**:
```bash
# Dry-run mode (preview changes without applying them)
php cli_upload_municipality_logos.php --dry-run

# Apply changes
php cli_upload_municipality_logos.php

# Force update all logos (even if already set)
php cli_upload_municipality_logos.php --force

# Show help
php cli_upload_municipality_logos.php --help
```

**Features**:
- Automatically matches logo files to municipalities by name
- Handles special characters (ñ, á, etc.)
- Supports abbreviated names (e.g., "Dasma" for "Dasmariñas")
- Shows progress and summary statistics
- Dry-run mode to preview changes
- Force mode to update all logos

**Matching Logic**:
The script uses intelligent matching with:
- Case-insensitive name matching
- Special character normalization (ñ → n)
- Prefix removal ("City of", "Municipality of")
- Special mappings for abbreviated names:
  - Dasmariñas → Dasma
  - General Emilio Aguinaldo → Gen_Emilio_Aguinaldo
  - Mendez-Nuñez → Mendez

### Web Tool
Browser-based interface for bulk uploading preset logos.

**Location**: `bulk_upload_municipality_logos.php`

**Usage**:
1. Navigate to `http://your-domain/bulk_upload_municipality_logos.php`
2. View the auto-matching results in a table
3. See statistics and summary
4. Check for any unmatched files

**Features**:
- Visual HTML interface with color-coded status
- Shows file sizes and current vs. new paths
- Lists unused logo files
- Direct links to Municipality Hub and debug page

## Features

### 1. Logo Display Logic
The system automatically determines which logo to display:
1. If `use_custom_logo = TRUE` AND `custom_logo_image` exists → Use custom logo
2. Otherwise, if `preset_logo_image` exists → Use preset logo
3. Otherwise → Display "No Logo" placeholder

### 2. Upload Custom Logo
- **Location**: Municipality Content Hub → "Upload Custom Logo" button
- **File Types**: PNG, JPG, GIF, WebP, SVG
- **Max Size**: 5MB
- **Process**:
  1. Select image file
  2. Preview before upload
  3. Upload (automatic validation)
  4. Auto-saves to `/assets/uploads/municipality_logos/`
  5. Auto-sets `use_custom_logo = TRUE`

### 3. Toggle Between Custom/Preset
- If both custom and preset logos exist, admin can switch between them
- Click "Switch to [Preset/Custom]" button in upload modal
- No files are deleted, just changes which one is active

### 4. URL Encoding
The `build_logo_src()` function handles:
- Spaces in folder names (e.g., "City Logos" → "City%20Logos")
- Special characters in filenames
- Relative path resolution from `/modules/admin/` directory
- Base64 data URIs (for inline images)
- External URLs (https://)

## API Endpoints

### Upload Logo
**File**: `/modules/admin/upload_municipality_logo.php`
**Method**: POST (multipart/form-data)
**Parameters**:
- `municipality_id` (int, required)
- `logo_file` (file, required)
- `csrf_token` (string, required)

**Response**:
```json
{
  "success": true,
  "message": "Logo uploaded successfully",
  "data": {
    "municipality_id": 1,
    "municipality_name": "City of General Trias",
    "logo_path": "/assets/uploads/municipality_logos/general-trias_1696512345.png",
    "filename": "general-trias_1696512345.png",
    "file_size": 245678,
    "mime_type": "image/png"
  }
}
```

### Toggle Logo Type
**File**: `/modules/admin/toggle_municipality_logo.php`
**Method**: POST
**Parameters**:
- `municipality_id` (int, required)
- `use_custom` (boolean, required)
- `csrf_token` (string, required)

**Response**:
```json
{
  "success": true,
  "message": "Logo preference updated",
  "data": {
    "municipality_id": 1,
    "use_custom_logo": true
  }
}
```

## Security Features

1. **CSRF Protection**: All upload/update actions require valid CSRF tokens
2. **Role-Based Access**: Only super_admin can upload/manage logos
3. **File Type Validation**: 
   - MIME type checking (server-side)
   - File extension validation
   - Image validation via `getimagesize()`
4. **File Size Limits**: Maximum 5MB per file
5. **Safe Filenames**: Auto-generated using municipality slug + timestamp
6. **Path Traversal Prevention**: No user input in file paths

## Usage Guide

### For Super Admins

1. **Navigate to Municipality Hub**:
   - Admin Sidebar → System Controls → Municipalities

2. **Select Municipality**:
   - Use dropdown to select which municipality to manage

3. **Upload Custom Logo**:
   - Click "Upload Custom Logo" button
   - Select image file (PNG recommended)
   - Preview will show automatically
   - Click "Upload Logo"
   - Page refreshes with new logo

4. **Switch Between Logos** (if both exist):
   - Open upload modal
   - Click "Switch to [Preset/Custom]" button
   - Page refreshes with selected logo

5. **View Current Logo**:
   - Current logo shows badge: "Custom" or "Preset"
   - Displayed in hero card at top of hub

## Troubleshooting

### Logo Not Displaying
1. Check browser console for 404 errors
2. Verify file exists at path stored in database
3. Check file permissions (should be readable by web server)
4. Verify path encoding (spaces should be %20)

### Upload Fails
1. Check file size (max 5MB)
2. Verify file type (PNG, JPG, GIF, WebP, SVG only)
3. Check `/assets/uploads/municipality_logos/` directory exists and is writable
4. Check PHP upload limits in `php.ini`:
   - `upload_max_filesize`
   - `post_max_size`

### Database Query
To check current logo configuration:
```sql
SELECT 
    municipality_id,
    name,
    preset_logo_image,
    custom_logo_image,
    use_custom_logo,
    CASE 
        WHEN use_custom_logo AND custom_logo_image IS NOT NULL 
        THEN custom_logo_image
        ELSE preset_logo_image
    END as active_logo
FROM municipalities
WHERE municipality_id = 1;
```

## Files Modified/Created

### Created Files
- `/modules/admin/upload_municipality_logo.php` - Upload handler
- `/modules/admin/toggle_municipality_logo.php` - Toggle handler
- `/sql/add_municipalities_updated_at.sql` - Schema update
- `/assets/uploads/municipality_logos/` - Upload directory
- `/debug_municipality_logos.php` - Debug tool
- `/test_logo_function.php` - Path encoding test
- `/cli_upload_municipality_logos.php` - CLI bulk upload tool ⭐
- `/bulk_upload_municipality_logos.php` - Web bulk upload tool ⭐

### Modified Files
- `/modules/admin/municipality_content.php` - Added upload UI and modal
- `/assets/css/admin/municipality_hub.css` - Styling (if needed)

## Quick Start Guide

### Initial Setup (First Time)

1. **Run the database migration**:
   ```bash
   psql -U postgres -d educaid -f sql/add_municipalities_updated_at.sql
   ```

2. **Bulk upload all preset logos**:
   ```bash
   # Preview what will be uploaded
   php cli_upload_municipality_logos.php --dry-run
   
   # Apply the changes
   php cli_upload_municipality_logos.php
   ```

3. **Verify logos are set**:
   - Visit: `http://your-domain/debug_municipality_logos.php`
   - Or check Municipality Hub: Admin → System Controls → Municipalities

### Adding New Municipalities

1. Place logo file in `/assets/City Logos/`
2. Name it: `{Municipality_Name}_Logo.{ext}` (e.g., `New_City_Logo.png`)
3. Run: `php cli_upload_municipality_logos.php`

### Uploading Custom Logos

1. Go to Municipality Hub (Admin → System Controls → Municipalities)
2. Select the municipality from dropdown
3. Click "Upload Custom Logo"
4. Choose file and upload
5. Logo will automatically switch to custom version

## Migration Steps

If updating existing system:

1. Run SQL migration:
   ```bash
   psql -U postgres -d educaid -f sql/add_municipalities_updated_at.sql
   ```

2. Create upload directory:
   ```bash
   mkdir -p assets/uploads/municipality_logos
   chmod 755 assets/uploads/municipality_logos
   ```

3. Verify preset logos exist:
   ```bash
   ls -la "assets/City Logos/"
   ```

4. Test upload functionality via Municipality Hub

## Best Practices

1. **Logo Formats**:
   - Prefer PNG with transparent background
   - Recommended size: 200x200px to 400x400px
   - Keep file size under 500KB for performance

2. **Naming Convention**:
   - Uploaded files auto-named: `{slug}_{timestamp}.{ext}`
   - Preset files: `{Municipality_Name}_Logo.{ext}`

3. **Backup**:
   - Custom logos in `/assets/uploads/municipality_logos/`
   - Include in regular backup routine
   - Database paths can be regenerated from filenames

4. **Performance**:
   - Optimize images before upload
   - Consider using WebP format for smaller file sizes
   - Cache logo images on frontend

## Future Enhancements

- [ ] Image cropping/resizing on upload
- [ ] Multiple logo variants (horizontal/vertical/icon)
- [ ] Logo approval workflow for sub-admins
- [ ] Bulk logo upload via CSV/ZIP
- [ ] Logo usage analytics
- [ ] Dark mode logo variants
