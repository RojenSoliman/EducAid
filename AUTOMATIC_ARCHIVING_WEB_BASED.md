# Automatic Student Archiving - Web-Based Solution

## 🎯 Overview

Instead of relying on cron jobs or scheduled tasks (which require server access), the automatic archiving system uses a **web-based, on-demand approach**:

- ✅ **No Server Access Required** - Works on shared hosting
- ✅ **Admin-Triggered** - Super admins run archiving when needed
- ✅ **Smart Notifications** - Dashboard alerts when archiving is due
- ✅ **User-Friendly Interface** - Web-based wizard guides the process
- ✅ **Full Audit Trail** - All operations logged automatically

---

## 🔄 How It Works

### 1. **Automatic Detection**

When a Super Admin logs into the dashboard, the system:
1. Checks when automatic archiving was last run
2. Determines if archiving is due (annually after graduation)
3. Counts eligible students
4. Shows notification banner if action is needed

### 2. **Smart Notification**

Dashboard displays a warning banner when:
- Never run before AND current month is July or later
- Last run was over a year ago
- Last run was in a previous academic year AND current month is July+

**Example Notification**:
```
⚠️ Automatic Archiving Recommended
Automatic archiving was last run on June 15, 2024. It's time to run it 
again for this academic year. There are currently 23 students eligible 
for archiving.

[Review & Run Archiving Button]
```

### 3. **Guided Execution**

Clicking the notification takes admin to a 3-step wizard:

**Step 1: Review Eligibility**
- Shows statistics (total, graduated, inactive)
- Lists all eligible students with reasons
- Displays graduation years and last login dates

**Step 2: Confirm Action**
- Reviews what will happen
- Requires explicit confirmation
- Shows warning about login prevention

**Step 3: Execute & Report**
- Runs PostgreSQL archiving function
- Updates all student records
- Creates audit trail entries
- Shows success summary with counts

---

## 📁 Files Created

### 1. `modules/admin/check_automatic_archiving.php`

**Purpose**: AJAX endpoint that checks if archiving is needed

**Called By**: Admin dashboard on page load

**Returns JSON**:
```json
{
  "should_archive": true,
  "message": "23 students eligible for archiving",
  "eligible_count": 23,
  "last_run": "June 15, 2024"
}
```

**Logic**:
- Queries audit trail for last bulk archiving event
- Checks if over a year has passed
- Counts eligible students
- Returns notification data

### 2. `modules/admin/run_automatic_archiving_admin.php`

**Purpose**: Web-based interface for running automatic archiving

**Features**:
- ✅ Multi-step wizard (Check → Execute → Success)
- ✅ Statistics dashboard with 4 cards
- ✅ Detailed student list (up to 100 shown)
- ✅ Reason badges (Graduated vs Inactive)
- ✅ Confirmation with warning
- ✅ Success page with links to archived students
- ✅ Error handling with troubleshooting steps

**Security**:
- Session-based authentication
- Super Admin role required
- CSRF protection via POST
- Database transaction support

### 3. `modules/admin/homepage.php` (Updated)

**Changes**:
- Added notification banner HTML (hidden by default)
- Added JavaScript to fetch archiving status
- Shows notification only to Super Admins
- Dismissible alert with action button

### 4. `includes/admin/admin_sidebar.php` (Updated)

**Changes**:
- Added "Run Auto-Archiving" menu item
- Placed in System Controls section
- Icon: clock-history
- Super Admin only

---

## 🎨 User Interface

### Dashboard Notification

```
┌─────────────────────────────────────────────────────────────────┐
│ ⚠️  Automatic Archiving Recommended                          [X]│
│                                                                   │
│ Automatic archiving was last run on June 15, 2024. It's time    │
│ to run it again for this academic year. There are currently     │
│ 23 students eligible for archiving.                             │
│                                                                   │
│ [📦 Review & Run Archiving]                                      │
└─────────────────────────────────────────────────────────────────┘
```

### Archiving Wizard - Step 1 (Review)

```
┌─────────────────────────────────────────────────────────────────┐
│ 📦 Automatic Student Archiving          [← Back to Dashboard]   │
│ Archive graduated and inactive students                          │
├─────────────────────────────────────────────────────────────────┤
│ ℹ️ Last Run: June 15, 2024 at 2:30 PM                           │
├─────────────────────────────────────────────────────────────────┤
│ ⚠️ Students Eligible for Archiving                              │
│ The following students meet the criteria for automatic          │
│ archiving. Please review the list and confirm.                  │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐           │
│  │   23    │  │   15    │  │    5    │  │    3    │           │
│  │ TOTAL   │  │GRADUATED│  │GRADUATED│  │INACTIVE │           │
│  │ELIGIBLE │  │ (PAST)  │  │(CURRENT)│  │         │           │
│  └─────────┘  └─────────┘  └─────────┘  └─────────┘           │
├─────────────────────────────────────────────────────────────────┤
│ Students to be Archived (showing 23 of 23)                      │
│                                                                  │
│ John Doe                        [Graduated (past expected year)]│
│ STU-2020-001 • john@email.com   4th Year • Grad: 2024          │
│                                                                  │
│ Jane Smith                      [Graduated (current year)]      │
│ STU-2021-002 • jane@email.com   4th Year • Grad: 2025          │
│                                                                  │
│ ... (21 more students)                                          │
├─────────────────────────────────────────────────────────────────┤
│ Confirm Archiving                                               │
│                                                                  │
│ This action will:                                               │
│ • Change the status of 23 students to "archived"               │
│ • Prevent these students from logging in                       │
│ • Remove them from active student lists                        │
│ • Create audit trail entries for all actions                   │
│                                                                  │
│ ⚠️ Note: You can unarchive students later if needed, but       │
│ it's recommended to review this list carefully.                │
│                                                                  │
│ [🗑️ Archive 23 Students]  [✖️ Cancel]                          │
└─────────────────────────────────────────────────────────────────┘
```

### Step 2: Success

```
┌─────────────────────────────────────────────────────────────────┐
│ ✅ Archiving Complete!                                           │
│ Successfully archived 23 students.                               │
├─────────────────────────────────────────────────────────────────┤
│                             ✓                                    │
│                                                                   │
│              Automatic Archiving Completed                       │
│       23 students have been successfully archived.               │
│                                                                   │
│  [📋 View Archived Students]  [🏠 Return to Dashboard]           │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🔐 Security Features

### Authentication & Authorization
- ✅ Session-based authentication required
- ✅ Super Admin role verification
- ✅ No public access allowed
- ✅ Role check on every request

### Data Protection
- ✅ Parameterized PostgreSQL queries
- ✅ CSRF protection via POST method
- ✅ Input validation on all parameters
- ✅ Transaction support for atomicity

### Audit Trail
- ✅ All operations logged to audit_trail table
- ✅ Admin username recorded
- ✅ Timestamp and IP address captured
- ✅ Student IDs and counts stored
- ✅ Searchable via Audit Trail page

---

## 📊 Archiving Criteria

Students are archived if they meet **ANY** of these conditions:

### 1. Graduated (Past Expected Year)
```sql
Current Year > Expected Graduation Year
```
**Example**: Student expected to graduate in 2024, current year is 2025

### 2. Graduated (Current Year, After June)
```sql
Current Year = Expected Graduation Year AND Current Month >= 6
```
**Example**: Student expected to graduate in 2025, current date is July 2025

### 3. Inactive (No Login for 2+ Years)
```sql
Last Login < (Today - 2 Years)
```
**Example**: Last logged in January 2023, current date is January 2025

### 4. Never Logged In (Registered 2+ Years Ago)
```sql
Last Login IS NULL AND Application Date < (Today - 2 Years)
```
**Example**: Registered January 2023 but never logged in, current date is January 2025

### Exclusions

Students are **NOT** archived if:
- Already archived (`is_archived = TRUE`)
- Blacklisted (`status = 'blacklisted'`)
- Expected graduation year is NULL

---

## 📅 Recommended Schedule

### When to Run

**Best Time**: July or August (after graduation season)

**Frequency**: Once per academic year

**Why These Months?**
- Graduation typically occurs in April-June
- July/August allows time for stragglers
- Gives buffer before new academic year
- Students have had chance to collect scholarships

### Workflow

1. **June**: Graduation ceremonies complete
2. **July**: Scholarship distribution wraps up
3. **Late July/Early August**: 
   - Super Admin logs into dashboard
   - Sees archiving notification
   - Reviews eligible students
   - Runs automatic archiving
4. **August**: New academic year begins with clean student list

---

## 🧪 Testing Guide

### Test Scenario 1: First Time Run

1. Login as Super Admin
2. Dashboard should show notification (if past June)
3. Click "Review & Run Archiving"
4. Verify eligible students list is correct
5. Click "Archive X Students"
6. Confirm action in popup
7. Verify success page
8. Check Archived Students page
9. Verify audit trail entry

### Test Scenario 2: No Students Eligible

1. Run archiving when no students are eligible
2. Should see "No Students Need Archiving" message
3. Should show green success icon
4. Should have "Return to Dashboard" button

### Test Scenario 3: Already Run Recently

1. Run archiving once
2. Refresh dashboard
3. Notification should NOT appear
4. Try running again manually
5. Should show 0 eligible students

### Test Scenario 4: Notification Display

1. Create test students with past graduation years
2. Login as Super Admin
3. Dashboard should show notification
4. Message should include student count
5. "Last Run" should display correct date or "Never"

### Test Scenario 5: Non-Super Admin

1. Login as regular admin
2. Dashboard should NOT show notification
3. Direct access to run_automatic_archiving_admin.php should redirect

---

## 🔧 Troubleshooting

### Issue 1: Notification Not Appearing

**Symptoms**: No notification on dashboard even though students are eligible

**Solutions**:
1. Check browser console for JavaScript errors
2. Verify `check_automatic_archiving.php` is accessible
3. Check that user is logged in as Super Admin
4. Test the endpoint directly:
```
http://localhost/EducAid/modules/admin/check_automatic_archiving.php
```

### Issue 2: "Database Connection Failed"

**Symptoms**: Error message on run_automatic_archiving_admin.php

**Solutions**:
1. Verify PostgreSQL is running
2. Check `config/database.php` credentials
3. Test connection manually:
```bash
psql -U postgres -d educaid_db
```

### Issue 3: "Archiving System Not Installed"

**Symptoms**: Error about missing is_archived column

**Solutions**:
Run the SQL migration:
```bash
psql -U postgres -d educaid_db -f sql/create_student_archiving_system.sql
```

### Issue 4: No Students Eligible (But Should Be)

**Symptoms**: Shows 0 eligible but you expect some

**Solutions**:
Check expected graduation years:
```sql
SELECT student_id, first_name, last_name, 
       year_level_id, expected_graduation_year,
       last_login, application_date
FROM students 
WHERE is_archived = FALSE 
  AND status = 'active'
ORDER BY expected_graduation_year;
```

If NULL, update them:
```sql
UPDATE students 
SET expected_graduation_year = EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER + 
    CASE 
        WHEN year_level_id = 1 THEN 4
        WHEN year_level_id = 2 THEN 3
        WHEN year_level_id = 3 THEN 2
        WHEN year_level_id = 4 THEN 1
        ELSE 4
    END
WHERE expected_graduation_year IS NULL;
```

### Issue 5: Archiving Takes Too Long

**Symptoms**: Page times out during execution

**Solutions**:
1. Check number of eligible students
2. If over 1000, consider batching:
   - Archive by graduation year
   - Archive by year level
   - Run multiple smaller operations
3. Increase PHP timeout temporarily in `run_automatic_archiving_admin.php`:
```php
set_time_limit(300); // 5 minutes
```

---

## 📚 Admin Instructions

### For Super Administrators

**When You See the Notification**:

1. **Don't Panic** - This is normal after graduation season
2. **Review the List** - Click "Review & Run Archiving"
3. **Check Each Category**:
   - **Graduated (Past)**: Expected, these students have definitely graduated
   - **Graduated (Current)**: Verify it's after June/July
   - **Inactive**: Check if these accounts are truly abandoned
4. **Look for Anomalies**:
   - Students who shouldn't be archived yet
   - Recently active students marked inactive
   - Incorrect graduation years
5. **If Everything Looks Good**: Click "Archive X Students"
6. **If Something Seems Wrong**: 
   - Cancel the operation
   - Contact technical support
   - Check student records individually

**After Running Archiving**:

1. **Verify Success**: Visit "Archived Students" page
2. **Spot Check**: Review a few archived students
3. **Check Audit Trail**: Confirm logging worked
4. **Communicate**: Let your team know archiving was run
5. **Monitor**: Watch for any complaints from archived students

**If Someone Was Incorrectly Archived**:

1. Go to: System Controls > Archived Students
2. Search for the student
3. Click "View Details"
4. Click "Unarchive Student"
5. Student can immediately login again
6. Consider adjusting their expected graduation year

---

## 🆚 Comparison: Web-Based vs Cron Job

| Feature | Web-Based (Current) | Cron Job (Traditional) |
|---------|---------------------|------------------------|
| **Server Access** | ❌ Not required | ✅ Required |
| **Setup Complexity** | ⭐ Simple | ⭐⭐⭐⭐ Complex |
| **Hosting Compatibility** | ✅ Shared hosting | ❌ VPS/Dedicated only |
| **Admin Control** | ✅ Full control | ❌ Limited visibility |
| **Review Before Execute** | ✅ Yes | ❌ Runs automatically |
| **Flexibility** | ✅ Run anytime | ❌ Fixed schedule |
| **Error Visibility** | ✅ Immediate feedback | ❌ Check logs |
| **Maintenance** | ✅ Self-contained | ❌ Can break silently |
| **Deployment** | ✅ Works immediately | ❌ Needs configuration |

**Winner**: 🏆 **Web-Based Solution** for your deployment scenario

---

## ✅ Advantages of This Approach

### 1. **No Infrastructure Dependencies**
- Works on any hosting (shared, VPS, cloud)
- No need for server access
- No cron/Task Scheduler setup
- Deploy and it just works

### 2. **Admin Control & Transparency**
- Admin reviews before execution
- Can see exactly what will happen
- Can cancel if something looks wrong
- Immediate feedback on results

### 3. **Smart & Adaptive**
- Detects when archiving is needed
- Notifies at the right time
- Counts eligible students
- Shows last run date

### 4. **User-Friendly**
- Visual interface (no command line)
- Step-by-step wizard
- Clear statistics and lists
- Success/error messages

### 5. **Flexible Timing**
- Run whenever needed
- Not locked to fixed schedule
- Can run multiple times if needed
- Can skip years if necessary

### 6. **Safe Operation**
- Confirmation required
- Detailed preview
- Full audit trail
- Easy undo (unarchive)

---

## 📖 Related Documentation

- **`ARCHIVING_SYSTEM_ADMIN_GUIDE.md`** - Complete admin reference
- **`ARCHIVING_SYSTEM_SUMMARY.md`** - Technical architecture
- **`ARCHIVED_STUDENTS_PAGE_GUIDE.md`** - Archived students interface
- **`ARCHIVING_IMPLEMENTATION_COMPLETE.md`** - Deployment guide

---

## 🎓 Summary

The web-based automatic archiving solution provides all the benefits of scheduled archiving **without the complexity of cron jobs**. It's:

- ✅ **Simpler** - No server configuration needed
- ✅ **Safer** - Admin reviews before execution  
- ✅ **Smarter** - Automatic detection and notification
- ✅ **Flexible** - Run on-demand when needed
- ✅ **Transparent** - Clear UI and audit trail
- ✅ **Compatible** - Works on any hosting

**Perfect for a system that's already deployed or about to be deployed!**

---

**Document Version**: 2.0 (Web-Based)  
**Last Updated**: October 2025  
**Status**: ✅ **PRODUCTION READY**
