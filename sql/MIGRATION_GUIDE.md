# Student ID Migration Guide

## Overview
This migration changes the `student_id` from an auto-incrementing integer to a TEXT field using the existing `unique_student_id` values. This provides better student tracking without incremental IDs.

## What's Included

### 1. Migration Scripts
- **`migrate_to_unique_student_id.sql`**: Complete migration script
- **`rollback_student_id_migration.sql`**: Rollback script if needed
- **`run_migration.php`**: PHP script with safety checks and logging

### 2. Code Updates
Fixed the following files to handle TEXT student_id:
- `modules/admin/review_registrations.php` - Removed intval() from bulk actions and individual processing
- `modules/admin/blacklist_service.php` - Fixed student_id processing in both initiate and complete actions
- `modules/admin/manage_applicants.php` - Updated verification and rejection handling
- `modules/student/student_notifications.php` - Fixed parameterized queries
- `modules/admin/blacklist_details.php` - Updated GET parameter handling
- `modules/admin/get_student_document.php` - Fixed document retrieval

### 3. What the Migration Does

#### Database Changes
1. **Generates unique IDs**: Creates unique_student_id for any students missing them
2. **Updates foreign keys**: Changes all related tables to use TEXT student_id
3. **Restructures primary key**: Makes unique_student_id the new primary key (renamed to student_id)
4. **Updates constraints**: Recreates all foreign key relationships
5. **Updates functions**: Modifies calculate_confidence_score() to work with TEXT IDs

#### Tables Affected
- `students` (primary table)
- `applications`
- `documents` 
- `enrollment_forms`
- `distributions`
- `qr_logs`
- `schedules`
- `grade_uploads`
- `notifications`
- `qr_codes`

## Current Database State
- **Students**: 1 record with ID 47 and unique_student_id "2025-3-783486"
- **Applications**: 1 record
- **Documents**: 3 records
- **Enrollment forms**: 1 record

## How to Run the Migration

### Step 1: Pre-Migration Check
```bash
cd c:\xampp\htdocs\EducAid\sql
php check_structure.php
```

### Step 2: Run Migration
```bash
php run_migration.php
```

The script will:
1. Check preconditions
2. Create database backup
3. Run migration
4. Verify results
5. Generate log file

### Step 3: Verify Migration
After migration, student_id values will be TEXT:
- Old: `47` (integer)
- New: `"2025-3-783486"` (text)

## Rollback Procedure
If something goes wrong:
```bash
psql -h localhost -U postgres -d educaid -f rollback_student_id_migration.sql
```

## Impact on Application

### What Works Without Changes
- **Session handling**: `$_SESSION['student_id']` works with any value type
- **File operations**: Document naming uses string conversion anyway
- **Most queries**: Parameterized queries work with TEXT values

### What Was Fixed
- **Type casting**: Removed `intval()` calls that would break TEXT IDs
- **SQL queries**: Fixed non-parameterized queries to use placeholders
- **Form processing**: Updated to handle TEXT input properly

### Migration Benefits
1. **Better tracking**: Unique IDs like "2025-3-783486" vs incremental "47"
2. **Future-proof**: No ID collision issues when scaling
3. **Meaningful IDs**: IDs contain year and other context
4. **Consistent format**: All student IDs follow same pattern

## Testing Recommendations

### Before Migration
1. Test all critical user flows
2. Verify student login works
3. Check document uploads/viewing
4. Test admin functions

### After Migration
1. Verify same functionality works
2. Check that student IDs display correctly
3. Confirm new registrations get proper unique IDs
4. Test admin review and approval processes

## Files Changed Summary
- **6 PHP files** updated for TEXT compatibility
- **Migration scripts** created with full rollback support
- **Backup procedures** implemented
- **Logging system** for tracking changes

## Generated ID Format
New student IDs follow the pattern: `EDU-YYYY-XXXXXX`
- EDU: Prefix
- YYYY: Current year
- XXXXXX: Zero-padded sequence

Example: `EDU-2025-000047`

The migration preserves existing unique_student_id values like "2025-3-783486" and generates new ones only for students without them.

## Success Indicators
After migration, you should see:
- `student_id` column is TEXT type
- All foreign key relationships intact
- Student count unchanged
- Application functionality preserved
- New meaningful student ID format

## Emergency Contacts
If issues arise:
1. Check migration log file
2. Use rollback script if needed
3. Restore from backup if necessary
4. Verify database connection settings

## Next Steps
After successful migration:
1. Update any remaining custom scripts
2. Update documentation with new ID format
3. Consider updating UI to display meaningful IDs
4. Monitor system for any edge cases