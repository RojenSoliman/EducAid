# Student ID Type Mismatch Fix

## Problem
Fatal error when accessing Active Sessions in student settings:
```
Warning: pg_query_params(): Query failed: ERROR: invalid input syntax for type integer: "2025-3-481217"
Fatal error: pg_fetch_all(): Argument #1 ($result) must be of type PgSql\Result, bool given
```

## Root Cause
- The `students` table uses TEXT/VARCHAR for `student_id` column (values like `'2025-3-481217'`)
- The session tracking tables (`student_login_history` and `student_active_sessions`) were created with INT for `student_id`
- PostgreSQL couldn't match VARCHAR values against INT columns, causing query failures

## Investigation
1. Checked session storage - `$_SESSION['student_id']` contained `'2025-3-481217'`
2. Ran debug script and discovered `student_id` in students table is TEXT, not SERIAL/INT
3. Confirmed mismatch between students.student_id (TEXT) and session tables (INT)

## Solution
Created and executed migration script (`fix_student_id_type_mismatch.sql`) to:

1. Drop existing foreign key constraints
2. Alter `student_login_history.student_id` to VARCHAR(50)
3. Alter `student_active_sessions.student_id` to VARCHAR(50)
4. Recreate foreign key constraints with correct type

## Files Changed

### Created:
- `fix_student_id_type_mismatch.sql` - Migration script
- `run_student_id_fix.php` - PHP runner for migration
- `debug_student_id.php` - Debug script to inspect student_id values

### Modified:
- `includes/SessionManager.php` - Removed integer type casting (was incorrectly converting `'2025-3-481217'` to `2025`)
- `modules/student/student_settings.php` - Removed integer type casting from `$student_id`

## Verification
After migration:
```
Table: student_active_sessions - Column: student_id - Data Type: character varying(50)
Table: student_login_history - Column: student_id - Data Type: character varying(50)
Table: students - Column: student_id - Data Type: text
```

All tables now use VARCHAR/TEXT for student_id, ensuring compatibility.

## Testing
1. Login to student account
2. Navigate to Settings page
3. Scroll to "Active Sessions" section
4. Verify sessions are displayed without errors
5. Test "Sign Out" button functionality
6. Test "Sign Out All Other Devices" button

## Note
The students table uses custom student IDs (format: `YEAR-MONTH-SEQUENCE`) instead of auto-incrementing integers. This is intentional and matches the school's student numbering system.
