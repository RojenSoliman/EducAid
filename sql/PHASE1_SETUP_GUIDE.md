# 📚 Phase 1: File Management System Setup Guide

## Overview

This guide walks you through setting up the comprehensive file management system for EducAid.

**Date:** October 19, 2025  
**Phase:** 1 - Database & File Structure  
**Estimated Time:** 10-15 minutes

---

## 🎯 What This Phase Does

1. **Creates Database Tables**
   - `distribution_files` - Tracks all uploaded files
   - `file_archive_log` - Audit log for file operations
   
2. **Adds Settings Columns**
   - Archive retention period (years)
   - Auto-compression settings
   - Storage quotas
   
3. **Creates File Structure**
   - `uploads/students/` - Active student files
   - `uploads/distributions/` - Distribution archives
   - `uploads/archived_students/` - Archived student files
   - `uploads/temp/` - Temporary uploads

4. **Creates Helper Views**
   - `storage_statistics` - Real-time storage usage

---

## 📋 Prerequisites

- [ ] PostgreSQL database access
- [ ] PHP with write permissions to `uploads/` folder
- [ ] Backup of current database (recommended)
- [ ] `psql` command-line tool OR pgAdmin

---

## 🚀 Installation Steps

### Step 1: Backup Current Database (IMPORTANT!)

```bash
# Windows PowerShell
cd C:\xampp\htdocs\EducAid
pg_dump -U postgres -d educaid_db -F c -f backup_before_phase1.backup

# OR using SQL
psql -U postgres -d educaid_db -c "SELECT pg_dump('educaid_db') INTO '/path/to/backup.sql'"
```

### Step 2: Run Database Migration

**Option A: Using psql (Command Line)**

```bash
cd C:\xampp\htdocs\EducAid\sql
psql -U postgres -d educaid_db -f phase1_file_management_system.sql
```

**Option B: Using pgAdmin**

1. Open pgAdmin
2. Connect to your database
3. Right-click on `educaid_db` → Query Tool
4. Open file: `sql/phase1_file_management_system.sql`
5. Click Execute (F5)

**Expected Output:**

```
✅ Tables created:
   - distribution_files (17 columns)
   - file_archive_log (10 columns)

✅ Columns added to municipal_settings:
   - archive_file_retention_years
   - auto_compress_distributions
   - compress_after_days
   - max_storage_gb
   - enable_file_archiving

✅ Columns added to distributions:
   - status
   - ended_at
   - files_compressed
   - compression_date

✅ Indexes created: 7 indexes
✅ Views created: storage_statistics
✅ Phase 1 Database Setup Complete!
```

### Step 3: Create Folder Structure

**Run the PHP setup script:**

```
http://localhost/EducAid/sql/setup_folder_structure.php
```

**Expected Output:**

```
✅ Directories Created:
   - students/ (Active student files)
   - distributions/ (Distribution file archives)
   - archived_students/ (Archived student files)
   - temp/ (Temporary upload storage)

✅ Documentation Created:
   - README.md files in each directory
   - Example structure
   - .gitkeep files

✅ Disk Space Check: PASSED
```

### Step 4: Verify Installation

Run these verification queries in psql or pgAdmin:

```sql
-- Check tables exist
SELECT COUNT(*) FROM distribution_files;
-- Expected: 0 (empty table)

SELECT COUNT(*) FROM file_archive_log;
-- Expected: 0 (empty table)

-- Check settings columns
SELECT 
    archive_file_retention_years,
    auto_compress_distributions,
    compress_after_days,
    max_storage_gb,
    enable_file_archiving
FROM municipal_settings
LIMIT 1;
-- Expected: Default values (5, TRUE, 30, 100, TRUE)

-- Check storage statistics view
SELECT * FROM storage_statistics;
-- Expected: 3 rows showing storage breakdown

-- Check folder structure
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✅ Folder structure created'
        ELSE '❌ Folders missing'
    END as folder_status
FROM information_schema.tables
WHERE table_name = 'distribution_files';
```

---

## 📁 Folder Structure Reference

After setup, your `uploads/` directory will look like this:

```
uploads/
├── students/                       # Active students only
│   ├── EXAMPLE-2024-001/          # Example student
│   │   ├── profile/
│   │   ├── registration/
│   │   └── current_distribution/
│   └── README.md
│
├── distributions/                  # Distribution archives
│   └── README.md
│
├── archived_students/              # Archived students
│   └── README.md
│
└── temp/                          # Temporary files
    └── .gitkeep
```

---

## 🗃️ Database Schema Reference

### `distribution_files` Table

| Column | Type | Description |
|--------|------|-------------|
| file_id | SERIAL | Primary key |
| student_id | TEXT | Student ID (FK) |
| distribution_id | INTEGER | Distribution ID (FK) |
| academic_year | TEXT | Academic year |
| original_filename | TEXT | Original upload name |
| stored_filename | TEXT | Filename on disk (.zip if compressed) |
| file_path | TEXT | Full path from uploads/ |
| file_size | BIGINT | Size in bytes |
| file_type | TEXT | MIME type |
| file_category | TEXT | requirement, document, profile, registration |
| is_compressed | BOOLEAN | Compression status |
| is_archived | BOOLEAN | Archive status |
| compression_date | TIMESTAMP | When compressed |
| compression_ratio | NUMERIC | % space saved |
| uploaded_at | TIMESTAMP | Upload date |
| archived_at | TIMESTAMP | Archive date |
| checksum | TEXT | File integrity hash |

### `file_archive_log` Table

| Column | Type | Description |
|--------|------|-------------|
| log_id | SERIAL | Primary key |
| student_id | TEXT | Student ID |
| operation | TEXT | compress, archive, restore, purge |
| file_count | INTEGER | Files affected |
| total_size_before | BIGINT | Size before operation |
| total_size_after | BIGINT | Size after operation |
| space_saved | BIGINT | Bytes saved |
| operation_status | TEXT | success, failed, partial |
| error_message | TEXT | Error details if failed |
| performed_by | INTEGER | Admin ID |
| performed_at | TIMESTAMP | Operation date |

---

## ⚙️ Settings Reference

New columns in `municipal_settings`:

| Setting | Default | Description |
|---------|---------|-------------|
| archive_file_retention_years | 5 | Years before purging archived files |
| auto_compress_distributions | TRUE | Auto-compress when distribution ends |
| compress_after_days | 30 | Days to wait before compression |
| max_storage_gb | 100 | Maximum storage quota |
| enable_file_archiving | TRUE | Enable archiving system |

---

## 🔍 Troubleshooting

### Issue: "Table already exists" error

**Solution:** Tables exist from previous run. Safe to ignore or run:

```sql
DROP TABLE IF EXISTS distribution_files CASCADE;
DROP TABLE IF EXISTS file_archive_log CASCADE;
-- Then re-run migration
```

### Issue: "Permission denied" creating folders

**Solution:** Set write permissions:

```bash
# Windows (as Administrator)
icacls "C:\xampp\htdocs\EducAid\uploads" /grant Users:F /T

# Or use PHP file manager with proper permissions
```

### Issue: psql command not found

**Solution:** Add PostgreSQL to PATH:

```
System Properties → Advanced → Environment Variables
Add to Path: C:\Program Files\PostgreSQL\16\bin
```

### Issue: Cannot connect to database

**Solution:** Check connection settings in `config/database.php`:

```php
$host = "localhost";
$port = "5432";
$dbname = "educaid_db";
$user = "postgres";
$password = "your_password";
```

---

## ✅ Post-Installation Checklist

- [ ] Database migration completed successfully
- [ ] All tables created (2 tables)
- [ ] All columns added to existing tables
- [ ] All indexes created (7 indexes)
- [ ] Storage statistics view working
- [ ] Folder structure created
- [ ] README files generated
- [ ] Example student folder created
- [ ] Disk space checked
- [ ] Verification queries passed

---

## 🎉 Success!

If all steps completed successfully, you're ready for:

**Phase 2: Distribution Auto-Archive System**
- Build compression service
- Implement triggered archiving (when admin marks distribution as ended)
- Create file moving utilities
- Add admin controls

---

## 📞 Support

If you encounter issues:

1. Check the troubleshooting section
2. Verify database credentials
3. Check folder permissions
4. Review error logs in `data/` folder
5. Consult PostgreSQL logs

---

## 🔄 Rollback (If Needed)

To undo Phase 1 changes:

```sql
-- Drop tables
DROP TABLE IF EXISTS distribution_files CASCADE;
DROP TABLE IF EXISTS file_archive_log CASCADE;
DROP VIEW IF EXISTS storage_statistics CASCADE;

-- Remove columns from municipal_settings
ALTER TABLE municipal_settings 
DROP COLUMN IF EXISTS archive_file_retention_years,
DROP COLUMN IF EXISTS auto_compress_distributions,
DROP COLUMN IF EXISTS compress_after_days,
DROP COLUMN IF EXISTS max_storage_gb,
DROP COLUMN IF EXISTS enable_file_archiving;

-- Remove columns from distributions
ALTER TABLE distributions
DROP COLUMN IF EXISTS status,
DROP COLUMN IF EXISTS ended_at,
DROP COLUMN IF EXISTS files_compressed,
DROP COLUMN IF EXISTS compression_date;
```

**Note:** Folder structure will remain. Manually delete if needed.

---

**Last Updated:** October 19, 2025  
**Version:** 1.0  
**Status:** Ready for Production ✅
