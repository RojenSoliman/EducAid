# Student Archiving System - Setup Instructions

## Quick Setup (One Command)

Run this **ONE** SQL file to set up the complete archiving system:

```powershell
# Option 1: If psql is in your PATH
psql -U postgres -d educaid_db -f sql/setup_archiving_system_COMPLETE.sql

# Option 2: If psql is not in PATH (use full path)
& "C:\Program Files\PostgreSQL\16\bin\psql.exe" -U postgres -d educaid_db -f sql/setup_archiving_system_COMPLETE.sql
```

**That's it!** The file will:
1. ✅ Add archiving columns to students table
2. ✅ Create performance indexes
3. ✅ Create archive/unarchive functions (with correct TEXT student_id type)
4. ✅ Create views for archived students
5. ✅ Update audit log categories
6. ✅ Verify installation and show results

## What the Archiving System Does

### Manual Archiving
- **Super admins** can archive individual students from `Manage Applicants` page
- Click "Archive" button → Select reason → Confirm
- Archived students **cannot log in**
- All actions are logged in audit trail

### Automatic Archiving
- Visit `Run Auto-Archiving` in admin sidebar
- Select graduation year
- Preview students to be archived
- Execute bulk archiving

### View Archived Students
- Visit `Archived Students` page in admin sidebar
- Filter by reason, date range, year level
- Unarchive students if needed
- Export to CSV

## Important Notes

⚠️ **Only run the SQL file ONCE** - it includes `IF NOT EXISTS` checks to prevent errors

✅ **The system uses TEXT for student_id** - This is correctly handled in the SQL file

📝 **All archiving actions are logged** - Check the audit trail for full history

🔒 **Archived students cannot log in** - The unified_login.php checks the `is_archived` flag

## Troubleshooting

### Error: "psql is not recognized"
Use the full path to psql.exe (Option 2 above)

### Error: "database does not exist"
Make sure you're using the correct database name (`educaid_db`)

### Error: "relation already exists"
The SQL file uses `IF NOT EXISTS` - these warnings are safe to ignore

### Need to verify installation?
Run the verification SQL file:
```powershell
psql -U postgres -d educaid_db -f sql/verify_archiving_setup.sql
```

## Files Included

- ✅ `setup_archiving_system_COMPLETE.sql` - **Main setup file (run this one!)**
- ✅ `verify_archiving_setup.sql` - Diagnostic/verification tool
- ✅ Various documentation files (.md) - Read for detailed guides

## Database Schema Changes

The SQL file adds these columns to the `students` table:
- `is_archived` (BOOLEAN) - Flag indicating if student is archived
- `archived_at` (TIMESTAMP) - When the student was archived
- `archived_by` (INTEGER) - Admin who archived the student
- `archive_reason` (TEXT) - Reason for archiving
- `expected_graduation_year` (INTEGER) - For automatic archiving

## For More Information

See the comprehensive documentation files:
- `ARCHIVING_SYSTEM_ADMIN_GUIDE.md` - Complete admin guide
- `AUTOMATIC_ARCHIVING_WEB_BASED.md` - Automatic archiving details
- `ARCHIVED_STUDENTS_PAGE_GUIDE.md` - Using the archived students page
- `ARCHIVING_IMPLEMENTATION_COMPLETE.md` - Technical implementation details
