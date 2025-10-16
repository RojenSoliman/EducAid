# Automatic Student Archiving - Setup Guide

## Overview

The automatic archiving script (`run_automatic_archiving.php`) archives students who have graduated or have been inactive for 2+ years. This guide explains how to set up scheduled execution.

---

## 📋 Prerequisites

1. **Database Migration**: Run `sql/create_student_archiving_system.sql` first
2. **Test Run**: Execute script manually before scheduling
3. **Backup**: Ensure database backups are in place

---

## 🔧 Script Details

### Location
```
c:\xampp\htdocs\EducAid\run_automatic_archiving.php
```

### Execution Methods

#### Method 1: Command Line (Recommended)
```bash
php c:\xampp\htdocs\EducAid\run_automatic_archiving.php
```

#### Method 2: Web Browser (Super Admin Only)
```
http://localhost/EducAid/run_automatic_archiving.php
```

---

## 🕐 Recommended Schedule

**Best Practice**: Run annually after graduation season

- **Frequency**: Once per year
- **Timing**: July or August (after June graduation)
- **Time**: Off-hours (e.g., 2:00 AM) to minimize impact

---

## 💻 Windows Setup (XAMPP)

### Using Windows Task Scheduler

#### Step 1: Create Batch File

Create `run_archiving.bat` in `c:\xampp\htdocs\EducAid\`:

```batch
@echo off
cd /d c:\xampp\htdocs\EducAid
c:\xampp\php\php.exe run_automatic_archiving.php >> logs\archiving_%date:~-4,4%%date:~-10,2%%date:~-7,2%.log 2>&1
```

#### Step 2: Open Task Scheduler

1. Press `Win + R`
2. Type: `taskschd.msc`
3. Click OK

#### Step 3: Create New Task

1. Click **Create Task** (not Basic Task)
2. **General Tab**:
   - Name: `EducAid - Annual Student Archiving`
   - Description: `Automatically archives graduated and inactive students`
   - Check: **Run whether user is logged on or not**
   - Check: **Run with highest privileges**
   - Configure for: **Windows 10/11**

3. **Triggers Tab**:
   - Click **New**
   - Begin the task: **On a schedule**
   - Settings: **One time**
   - Start date: Next July 1st
   - Advanced settings:
     - Check: **Repeat task every 1 year**
     - For a duration of: **Indefinitely**
     - Time: **2:00 AM**

4. **Actions Tab**:
   - Click **New**
   - Action: **Start a program**
   - Program/script: `c:\xampp\htdocs\EducAid\run_archiving.bat`
   - Start in: `c:\xampp\htdocs\EducAid`

5. **Conditions Tab**:
   - Uncheck: **Start the task only if the computer is on AC power**
   - Check: **Wake the computer to run this task** (optional)

6. **Settings Tab**:
   - Check: **Allow task to be run on demand**
   - Check: **If the task fails, restart every: 1 hour**
   - Check: **Stop the task if it runs longer than: 1 hour**

7. Click **OK** and enter administrator password

#### Step 4: Test Task

1. Right-click the task
2. Select **Run**
3. Check log file: `logs\archiving_YYYYMMDD.log`

---

## 🐧 Linux Setup (Production Server)

### Using Cron

#### Step 1: Make Script Executable
```bash
chmod +x /var/www/html/EducAid/run_automatic_archiving.php
```

#### Step 2: Edit Crontab
```bash
crontab -e
```

#### Step 3: Add Cron Job

**Run annually on July 1st at 2:00 AM**:
```cron
0 2 1 7 * /usr/bin/php /var/www/html/EducAid/run_automatic_archiving.php >> /var/www/html/EducAid/logs/archiving_$(date +\%Y\%m\%d).log 2>&1
```

**Explanation**:
- `0 2 1 7 *` = At 2:00 AM, on July 1st, every year
- `>> logs/...` = Append output to dated log file
- `2>&1` = Redirect errors to log file

#### Step 4: Verify Cron Job
```bash
crontab -l
```

#### Step 5: Test Execution
```bash
sudo -u www-data /usr/bin/php /var/www/html/EducAid/run_automatic_archiving.php
```

---

## 📊 Script Behavior

### Archiving Criteria

Students are archived if they meet ANY of these conditions:

1. **Graduated (Past Year)**
   - Current year > expected graduation year
   
2. **Graduated (Current Year)**
   - Current year = expected graduation year AND month >= June

3. **Inactive (No Login)**
   - Last login was 2+ years ago

4. **Inactive (Never Logged In)**
   - Never logged in AND registered 2+ years ago

### Excluded Students

Students are NOT archived if:
- Already archived (`is_archived = TRUE`)
- Blacklisted (`status = 'blacklisted'`)
- Expected graduation year not set

### What Gets Archived

- Student status changed to `'archived'`
- `is_archived` flag set to `TRUE`
- `archived_at` timestamp recorded
- `archived_by` set to `NULL` (automatic archiving)
- `archive_reason` set to:
  - `"Graduated - automatic archiving"`
  - `"Inactive account - automatic archiving"`

---

## 📝 Audit Trail

Every archiving operation is logged with:

- **Event Category**: `archive`
- **Event Type**: `bulk_archiving_executed`
- **Details**:
  - Number of students archived
  - Student IDs (array)
  - Executed by (NULL for automatic, username for manual)
- **Timestamp**: Execution time
- **IP Address**: Server IP (for scheduled tasks)

---

## 🔍 Monitoring

### Check Logs

**Windows**:
```
c:\xampp\htdocs\EducAid\logs\archiving_YYYYMMDD.log
```

**Linux**:
```
/var/www/html/EducAid/logs/archiving_YYYYMMDD.log
```

### Log Contents

- Eligible students list
- Archiving confirmation (CLI only)
- Archived count
- Summary statistics
- Error messages (if any)

### View in Admin Panel

1. Login as Super Admin
2. Navigate to: **System Controls > Archived Students**
3. Filter by: **Archive Type = Automatic**
4. Check recent archives

### Check Audit Trail

1. Navigate to: **System Controls > Audit Trail**
2. Filter by: **Event Category = Archive**
3. Look for: `bulk_archiving_executed`

---

## ⚠️ Error Handling

### Common Issues

#### 1. Database Connection Failed
**Error**: `ERROR: Database connection failed!`

**Solution**:
- Check PostgreSQL is running
- Verify credentials in `config/database.php`
- Check database server is accessible

#### 2. Archiving System Not Installed
**Error**: `ERROR: Archiving system not installed!`

**Solution**:
```bash
psql -U postgres -d educaid -f sql/create_student_archiving_system.sql
```

#### 3. Permission Denied (Windows)
**Error**: Task runs but no output

**Solution**:
- Run Task Scheduler as Administrator
- Ensure batch file has correct permissions
- Check "Run with highest privileges"

#### 4. Permission Denied (Linux)
**Error**: `Permission denied`

**Solution**:
```bash
chmod +x run_automatic_archiving.php
chown www-data:www-data run_automatic_archiving.php
```

#### 5. PHP Not Found
**Error**: `'php' is not recognized...`

**Solution (Windows)**:
- Use full path: `c:\xampp\php\php.exe`

**Solution (Linux)**:
```bash
which php  # Find PHP path
# Use full path in cron: /usr/bin/php
```

---

## 🧪 Testing

### Manual Test Run

#### Windows (PowerShell):
```powershell
cd c:\xampp\htdocs\EducAid
php run_automatic_archiving.php
```

#### Linux:
```bash
cd /var/www/html/EducAid
php run_automatic_archiving.php
```

### Expected Output

```
========================================
  AUTOMATIC STUDENT ARCHIVING SCRIPT
========================================

Started at: 2025-07-01 02:00:00

✓ Archiving system detected

Checking eligibility...

ELIGIBILITY SUMMARY:
  • Total eligible for archiving: 15
  • Graduated students: 12
  • Inactive (no login 2+ years): 2
  • Never logged in (registered 2+ years ago): 1

ELIGIBLE STUDENTS:

  1. John Doe (STU-2020-001) - Graduated (past expected year) | Grad: 2024 | Last Login: 2024-06-15
  2. Jane Smith (STU-2020-002) - Graduated (current year) | Grad: 2025 | Last Login: 2025-05-20
  ...

========================================
Do you want to proceed with archiving these students? (yes/no): yes

Proceeding with automatic archiving...

✓ Archiving completed successfully!

RESULTS:
  • Students archived: 15
  • Timestamp: 2025-07-01 02:00:05

✓ Audit trail updated

========================================
  SUMMARY REPORT
========================================
Database Status:
  • Total students: 500
  • Total archived: 120
  • Active students: 380
  • Newly archived: 15

RECOMMENDATIONS:
  1. Review archived students at: System Controls > Archived Students
  2. Check audit trail for detailed archiving logs
  3. Schedule this script to run annually after graduation
  4. Consider unarchiving any incorrectly archived students

========================================
Script completed successfully at: 2025-07-01 02:00:05
========================================
```

### Test Checklist

- [ ] Script runs without errors
- [ ] Eligible students are correctly identified
- [ ] Archiving confirmation works (CLI mode)
- [ ] Students are archived in database
- [ ] Audit trail entry created
- [ ] Summary report shows correct counts
- [ ] Archived students appear in admin panel
- [ ] Archived students cannot login
- [ ] Log file created with output

---

## 🔐 Security Considerations

### Web Access Control

- Only **Super Admins** can run via web browser
- Session authentication required
- No public access allowed

### Command Line Security

- Requires server access
- Should be run by web server user (www-data, apache, etc.)
- Logs stored securely

### Batch File Security (Windows)

- Store in protected directory
- Set appropriate NTFS permissions
- Restrict modification rights

---

## 🔄 Unarchiving

If students are incorrectly archived:

1. Navigate to: **System Controls > Archived Students**
2. Find the student
3. Click **View Details**
4. Click **Unarchive Student**
5. Confirm action

Or use PostgreSQL function:
```sql
SELECT unarchive_student(
    123,  -- student_id
    45    -- admin_id
);
```

---

## 📞 Support

### If Issues Occur

1. **Check Logs**: Review archiving log files
2. **Check Database**: Verify migration ran successfully
3. **Test Manually**: Run script from command line
4. **Check Audit Trail**: Look for error events
5. **Review Eligibility**: Query `v_students_eligible_for_archiving` view

### Database Verification

```sql
-- Check if archiving system is installed
SELECT column_name 
FROM information_schema.columns 
WHERE table_name = 'students' AND column_name = 'is_archived';

-- View eligible students
SELECT * FROM v_students_eligible_for_archiving;

-- Check archived students
SELECT COUNT(*) FROM students WHERE is_archived = TRUE;

-- View archive audit logs
SELECT * FROM audit_trail 
WHERE event_category = 'archive' 
ORDER BY created_at DESC 
LIMIT 10;
```

---

## 📚 Related Documentation

- `ARCHIVING_SYSTEM_SUMMARY.md` - System architecture overview
- `ARCHIVED_STUDENTS_PAGE_GUIDE.md` - Admin interface documentation
- `sql/create_student_archiving_system.sql` - Database migration

---

## ✅ Quick Setup Checklist

- [ ] Run SQL migration
- [ ] Test script manually
- [ ] Create batch file (Windows) or make executable (Linux)
- [ ] Set up scheduled task/cron job
- [ ] Test scheduled execution
- [ ] Verify log file creation
- [ ] Check audit trail integration
- [ ] Document for team
- [ ] Set calendar reminder to review archives

---

**Last Updated**: January 2025
**Script Version**: 1.0
**Recommended Schedule**: Annually (July/August after graduation)
