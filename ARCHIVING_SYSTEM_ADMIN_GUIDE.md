# Student Archiving System - Administrator Guide

**Version**: 1.0  
**Last Updated**: January 2025  
**For**: EducAid Super Administrators

---

## 📖 Table of Contents

1. [System Overview](#system-overview)
2. [Key Concepts](#key-concepts)
3. [Automatic Archiving](#automatic-archiving)
4. [Manual Archiving](#manual-archiving)
5. [Viewing Archived Students](#viewing-archived-students)
6. [Unarchiving Students](#unarchiving-students)
7. [Best Practices](#best-practices)
8. [Troubleshooting](#troubleshooting)
9. [FAQ](#faq)

---

## 🎯 System Overview

### What is Student Archiving?

The Student Archiving System automatically and manually moves students who have graduated or are no longer active into an archived state, keeping your active student database clean and organized.

### Why Archive Students?

- **Database Organization**: Separate active from inactive students
- **System Performance**: Improve query performance by reducing active records
- **Historical Records**: Maintain student data without cluttering active lists
- **Security**: Prevent graduated students from accessing the system
- **Compliance**: Track student lifecycle for reporting purposes

### Key Features

✅ **Automatic Archiving**: System identifies and archives graduated/inactive students  
✅ **Manual Archiving**: Admins can archive individual students with reason  
✅ **Login Protection**: Archived students cannot login  
✅ **Audit Trail**: All archiving actions are logged  
✅ **Unarchive Capability**: Restore incorrectly archived students  
✅ **Advanced Filtering**: Search and filter archived records  
✅ **CSV Export**: Download archived student data  
✅ **Statistics Dashboard**: View archiving metrics at a glance

---

## 🔑 Key Concepts

### Archive States

| State | Description | Can Login? | Visible in Active Lists? |
|-------|-------------|------------|--------------------------|
| **Active** | Current student | ✅ Yes | ✅ Yes |
| **Archived** | Graduated or inactive | ❌ No | ❌ No |
| **Blacklisted** | Restricted access | ❌ No | ⚠️ Separate list |

### Archive Types

#### Automatic
- **Trigger**: System-detected conditions
- **Frequency**: Annual (scheduled task)
- **Reasons**:
  - Graduated (past expected year)
  - Graduated (current year, after June)
  - Inactive (no login for 2+ years)
  - Never logged in (registered 2+ years ago)

#### Manual
- **Trigger**: Admin action
- **Frequency**: As needed
- **Reasons**:
  - Did not attend distribution
  - No longer eligible
  - Withdrew from program
  - Duplicate account
  - Custom reason

### Expected Graduation Year

Calculated automatically when student registers:

| Year Level | Years to Graduation | Example (2025 Registration) |
|------------|--------------------|-----------------------------|
| 1st Year | +4 years | 2029 |
| 2nd Year | +3 years | 2028 |
| 3rd Year | +2 years | 2027 |
| 4th Year | +1 year | 2026 |

---

## 🤖 Automatic Archiving

### How It Works

The system runs an annual script that:
1. Identifies students meeting archiving criteria
2. Archives qualified students
3. Updates database records
4. Creates audit trail entries
5. Generates summary report

### Archiving Criteria

Students are **automatically archived** if they meet ANY condition:

#### 1. Past Graduation Year
```
Current Year > Expected Graduation Year
```
**Example**: Student expected to graduate in 2024, current year is 2025

#### 2. Current Graduation Year (After June)
```
Current Year = Expected Graduation Year AND Month >= June
```
**Example**: Student expected to graduate in 2025, current date is July 2025

#### 3. Inactive Account (No Login)
```
Last Login < (Today - 2 years)
```
**Example**: Last logged in January 2023, current date is January 2025

#### 4. Never Logged In
```
Last Login = NULL AND Application Date < (Today - 2 years)
```
**Example**: Registered January 2023 but never logged in, current date is January 2025

### Excluded from Automatic Archiving

Students are **NOT automatically archived** if:
- Already archived
- Blacklisted status
- Expected graduation year not set

### Viewing Automatic Archives

1. Navigate to: **System Controls > Archived Students**
2. Set filter: **Archive Type = Automatic**
3. View list with dates and reasons

### Schedule

**Recommended**: Run annually in **July or August** after graduation season

See: `AUTOMATIC_ARCHIVING_SETUP.md` for scheduling instructions

---

## 👤 Manual Archiving

### When to Use Manual Archiving

Use manual archiving for students who:
- Did not attend scholarship distribution
- Are no longer eligible (changed university, course, etc.)
- Withdrew from the program
- Have duplicate accounts
- Need to be archived for other reasons

### How to Manually Archive

#### From Manage Applicants Page

1. **Navigate**: Admin Dashboard > **Manage Applicants**

2. **Find Student**: Use search or filter to locate student

3. **Open Details**: Click **View Details** button

4. **Archive Student**: Click **Archive Student** button (yellow/warning color)

5. **Select Reason**: Choose from dropdown:
   - Graduated
   - Did not attend distribution
   - No longer eligible
   - Withdrew from program
   - Duplicate account
   - Other (requires custom reason)

6. **Custom Reason** (if selected "Other"):
   - Enter detailed reason in text area
   - Be specific for audit purposes

7. **Confirm**: Review popup message showing:
   - Student name
   - Selected reason
   - Confirmation prompt

8. **Execute**: Click **Archive** to confirm

9. **Verification**: Page refreshes, student removed from active list

### Manual Archive Reasons

| Reason | When to Use | Example |
|--------|-------------|---------|
| **Graduated** | Student completed degree early or manually confirmed | Student graduated mid-year |
| **Did not attend distribution** | Student approved but never collected scholarship | No-show at distribution event |
| **No longer eligible** | Criteria changed mid-process | Transferred to non-covered university |
| **Withdrew from program** | Student voluntarily left program | Student dropped out of school |
| **Duplicate account** | Multiple applications from same student | Found duplicate registration |
| **Other** | Any other reason not listed | Custom circumstances |

### Audit Trail

Every manual archiving action logs:
- Admin username who archived
- Student details (name, email, year level)
- Archive reason
- Timestamp
- IP address

---

## 📋 Viewing Archived Students

### Accessing Archived Students Page

**Path**: Admin Dashboard > System Controls > **Archived Students**

**Permissions**: Super Admin only

### Page Sections

#### 1. Statistics Dashboard (Top)

Four cards displaying:

- **Total Archived**: All archived students
- **Automatic Archives**: System-archived count
- **Manual Archives**: Admin-archived count  
- **Archived Last 30 Days**: Recent archives

#### 2. Filter Panel

**Available Filters**:

| Filter | Options | Purpose |
|--------|---------|---------|
| **Search** | Text input | Search name, email, or student ID |
| **Archive Type** | All / Automatic / Manual | Filter by archiving method |
| **Year Level** | All / 1st-4th Year | Filter by original year level |
| **Date From** | Date picker | Start of date range |
| **Date To** | Date picker | End of date range |

**Filter Actions**:
- **Apply Filters**: Execute search with current filters
- **Clear Filters**: Reset all filters to defaults
- **Export CSV**: Download filtered results

#### 3. Archived Students Table

**Columns**:
- Student ID
- Full Name
- Email
- Year Level (at archiving)
- Archive Type (badge: green=auto, blue=manual)
- Archive Reason
- Archived Date
- Actions (View Details, Unarchive)

**Pagination**: 50 students per page

### Viewing Student Details

1. Click **View Details** button (eye icon)
2. Modal opens with three sections:

**Personal Information**:
- Full Name
- Email
- Phone
- Address
- Birth Date & Age

**Academic Information**:
- University
- Year Level
- Expected Graduation Year
- Academic Year Registered
- Last Login
- Graduation Status

**Archive Information**:
- Archive Type
- Archived Date
- Archived By (admin or "System")
- Archive Reason

3. Click **Close** to return to list

### Exporting Data

**CSV Export** includes:
- All filtered results (not just current page)
- Headers: Student ID, Name, Email, Phone, University, Year Level, etc.
- Filename: `archived_students_YYYYMMDD_HHMMSS.csv`

**Use Cases**:
- Annual reporting
- Data backup
- External analysis
- Record keeping

---

## ↩️ Unarchiving Students

### When to Unarchive

Unarchive students if:
- Archived by mistake
- Returning to program after leave
- Administrative error correction
- Student successfully appealed

### How to Unarchive

#### Method 1: From Archived Students Page

1. Navigate to: **System Controls > Archived Students**
2. Find the student (use filters if needed)
3. Click **View Details** button
4. In the modal, click **Unarchive Student** button
5. Confirm the action in the popup
6. Student restored to active status

#### Method 2: Via Database (Advanced)

```sql
SELECT unarchive_student(
    123,  -- student_id
    45    -- admin_id (from admins table)
);
```

### What Happens When Unarchiving

1. **Status Changed**: `archived` → `active`
2. **Flags Updated**:
   - `is_archived` = FALSE
   - `archived_at` = NULL
   - `archived_by` = NULL
   - `archive_reason` = NULL
3. **Login Restored**: Student can login again
4. **Audit Logged**: Unarchive action recorded
5. **Visible Again**: Appears in active student lists

### Audit Trail

Every unarchive action logs:
- Admin username who unarchived
- Student details
- Timestamp
- IP address

---

## ✅ Best Practices

### Archiving Guidelines

1. **Review Before Archiving**
   - Verify student has truly graduated or left program
   - Confirm with distribution records if applicable
   - Check for any pending transactions

2. **Use Appropriate Reasons**
   - Select most accurate reason from dropdown
   - Provide detailed custom reason if selecting "Other"
   - Reason is permanent in audit trail

3. **Regular Reviews**
   - Run automatic archiving annually
   - Review archives after distribution events
   - Quarterly audit of active students

4. **Communication**
   - Notify students before archiving (if appropriate)
   - Document archiving decisions
   - Keep records of any student communications

### Data Management

1. **Before Archiving**
   - Ensure expected graduation year is set correctly
   - Verify student information is complete
   - Check for duplicate accounts first

2. **After Archiving**
   - Review archived students list
   - Check audit trail entries
   - Export data for records if needed

3. **Backup Strategy**
   - Database backups before bulk archiving
   - Export CSV monthly for external backup
   - Document any mass archiving operations

### Security Considerations

1. **Access Control**
   - Only Super Admins can archive/unarchive
   - All actions logged with timestamp and IP
   - Regular audit trail reviews

2. **Data Retention**
   - Archived student data retained indefinitely
   - No automatic deletion
   - GDPR/data protection compliance as needed

3. **Login Protection**
   - Archived students cannot access system
   - Clear error messages on login attempt
   - Contact information provided for appeals

---

## 🔧 Troubleshooting

### Common Issues

#### Issue 1: Student Not Appearing in Archived List

**Symptoms**: Archived student not visible in Archived Students page

**Possible Causes**:
- Page not refreshed after archiving
- Filters hiding the student
- Database update failed

**Solutions**:
1. Refresh the page (F5)
2. Click "Clear Filters" button
3. Check database:
```sql
SELECT * FROM students WHERE student_id = 'STU-2024-001';
```
4. Verify `is_archived = TRUE` and `status = 'archived'`

#### Issue 2: Archived Student Can Still Login

**Symptoms**: Student receives error but can proceed

**Possible Causes**:
- Cache issue
- Session not cleared
- Database update incomplete

**Solutions**:
1. Verify in database:
```sql
SELECT is_archived, status FROM students WHERE student_id = 'STU-2024-001';
```
2. Clear student's session:
```sql
-- If sessions stored in database
DELETE FROM sessions WHERE student_id = 'STU-2024-001';
```
3. Check `unified_login.php` has archived check
4. Have student clear browser cookies

#### Issue 3: Cannot Unarchive Student

**Symptoms**: Unarchive button does nothing or shows error

**Possible Causes**:
- Not logged in as Super Admin
- JavaScript error
- Database connection issue

**Solutions**:
1. Verify you are logged in as Super Admin
2. Check browser console for JavaScript errors (F12)
3. Try refreshing the page
4. Use database method if persistent:
```sql
SELECT unarchive_student(123, your_admin_id);
```

#### Issue 4: Automatic Archiving Not Running

**Symptoms**: Eligible students not archived after scheduled time

**Possible Causes**:
- Scheduled task not set up
- Task failed to execute
- Script error

**Solutions**:
1. **Windows**: Check Task Scheduler
   - Verify task is enabled
   - Check "Last Run Result" (should be 0x0 for success)
   - View log file: `logs\archiving_YYYYMMDD.log`

2. **Linux**: Check cron
```bash
crontab -l  # Verify cron job exists
grep -i cron /var/log/syslog  # Check for execution
```

3. Run manually to test:
```bash
php run_automatic_archiving.php
```

4. Review error output and logs

#### Issue 5: Expected Graduation Year Incorrect

**Symptoms**: Student archived too early or too late

**Possible Causes**:
- Incorrect year level at registration
- Manual update needed
- Data migration issue

**Solutions**:
1. Check current value:
```sql
SELECT 
    student_id,
    expected_graduation_year,
    academic_year_registered,
    year_level_id
FROM students 
WHERE student_id = 'STU-2024-001';
```

2. Update if incorrect:
```sql
UPDATE students 
SET expected_graduation_year = 2028 
WHERE student_id = 'STU-2024-001';
```

3. Unarchive if archived incorrectly
4. Re-archive with correct reason if needed

### Database Verification Queries

```sql
-- Check archiving system installation
SELECT column_name 
FROM information_schema.columns 
WHERE table_name = 'students' 
  AND column_name IN ('is_archived', 'archived_at', 'archived_by', 
                      'archive_reason', 'expected_graduation_year');

-- View eligible students for archiving
SELECT * FROM v_students_eligible_for_archiving 
ORDER BY expected_graduation_year;

-- Count archived vs active
SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_archived THEN 1 ELSE 0 END) as archived,
    SUM(CASE WHEN NOT is_archived THEN 1 ELSE 0 END) as active
FROM students;

-- Recent archiving activity
SELECT * 
FROM audit_trail 
WHERE event_category = 'archive' 
ORDER BY created_at DESC 
LIMIT 20;

-- Students with missing graduation years
SELECT student_id, first_name, last_name, year_level_id
FROM students 
WHERE expected_graduation_year IS NULL 
  AND status = 'active';
```

---

## ❓ FAQ

### General Questions

**Q: What happens to an archived student's data?**  
**A**: All data is retained. Only the status changes to "archived" and login is disabled. Data can be viewed in Archived Students page.

**Q: Can an archived student login?**  
**A**: No. Archived students cannot login and receive a message explaining their status.

**Q: Can I unarchive multiple students at once?**  
**A**: Currently no. Unarchiving must be done individually through the admin panel.

**Q: Are blacklisted students automatically archived?**  
**A**: No. Blacklisted students remain blacklisted and are excluded from automatic archiving.

**Q: How long is archived data kept?**  
**A**: Indefinitely. Archived data is not automatically deleted.

### Automatic Archiving

**Q: When does automatic archiving run?**  
**A**: Based on your scheduled task setup. Recommended: annually in July/August.

**Q: Can I customize the automatic archiving criteria?**  
**A**: Yes, by modifying the `archive_graduated_students()` function in the database. Contact your technical administrator.

**Q: What if a student is incorrectly auto-archived?**  
**A**: Simply unarchive them from the Archived Students page. Consider adjusting their expected graduation year to prevent future automatic archiving.

**Q: Do I get notified when automatic archiving runs?**  
**A**: Currently no. Check the audit trail or archived students list. Consider implementing email notifications.

### Manual Archiving

**Q: Who can manually archive students?**  
**A**: Only Super Administrators.

**Q: Can I archive a student mid-year?**  
**A**: Yes. Manual archiving can be done anytime with an appropriate reason.

**Q: What if I select the wrong reason?**  
**A**: Unarchive the student and archive again with the correct reason. The audit trail will show both actions.

**Q: Can I edit the archive reason after archiving?**  
**A**: Not through the UI. Contact technical administrator for database update if critical.

### Technical Questions

**Q: Where are archived students stored in the database?**  
**A**: Same `students` table with `is_archived = TRUE` and `status = 'archived'`.

**Q: Are there any performance impacts?**  
**A**: No. Indexes are optimized for archived/active filtering.

**Q: Can I export all archived students at once?**  
**A**: Yes. Use "Export CSV" button on Archived Students page (apply filters first if needed).

**Q: How do I restore from a backup if archiving goes wrong?**  
**A**: Contact your database administrator to restore from backup. Always backup before bulk operations.

---

## 📚 Related Documentation

- **`ARCHIVING_SYSTEM_SUMMARY.md`**: Technical architecture and database schema
- **`ARCHIVED_STUDENTS_PAGE_GUIDE.md`**: Detailed page features and UI guide
- **`AUTOMATIC_ARCHIVING_SETUP.md`**: Scheduling automatic archiving script
- **`sql/create_student_archiving_system.sql`**: Database migration script

---

## 📞 Support

### Need Help?

1. **Check This Guide**: Review relevant sections above
2. **Check Troubleshooting**: Common issues and solutions
3. **Check Audit Trail**: Review what happened
4. **Check Database**: Run verification queries
5. **Contact Technical Support**: If issue persists

### Reporting Issues

When reporting archiving issues, include:
- Student ID
- Action attempted (archive/unarchive)
- Error message (if any)
- Screenshots
- Browser console errors (F12)
- Recent audit trail entries

---

**Document Version**: 1.0  
**System Version**: EducAid 2025  
**Maintained By**: Development Team  
**Last Review**: January 2025

---

## Quick Reference Card

### Common Tasks

| Task | Navigation | Action |
|------|-----------|--------|
| **View Archives** | System Controls > Archived Students | Browse list |
| **Manual Archive** | Manage Applicants > View Details | Click Archive button |
| **Unarchive** | Archived Students > View Details | Click Unarchive button |
| **Export Data** | Archived Students | Click Export CSV |
| **Check Audit Log** | Audit Trail | Filter: Category=Archive |

### Important Reminders

✅ **Always** verify before archiving  
✅ **Always** select accurate reason  
✅ **Always** check audit trail after bulk operations  
✅ **Never** delete archived records manually  
✅ **Backup** before running automatic archiving

---

*This guide is subject to updates as the system evolves.*
