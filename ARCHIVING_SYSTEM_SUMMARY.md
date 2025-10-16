# Student Archiving System - Implementation Summary

## Overview
The Student Archiving System provides both automatic and manual methods for archiving student accounts. This is useful for managing graduated students and inactive accounts, ensuring data integrity while preventing unauthorized access.

---

## System Architecture

### **Archiving Triggers**

1. **Automatic Archiving** (System-initiated)
   - Graduated students (past expected graduation year)
   - Inactive accounts (no login for 2+ years)
   - Never logged in + registered 2+ years ago

2. **Manual Archiving** (Admin-initiated)
   - Admin manually archives a student
   - Requires explicit reason
   - Can be used for special cases (didn't attend distribution, withdrew, etc.)

---

## Database Schema Changes

### **New Columns Added to `students` Table**

```sql
is_archived                 BOOLEAN DEFAULT FALSE
archived_at                 TIMESTAMP NULL
archived_by                 INTEGER REFERENCES admins(admin_id)
archive_reason              TEXT
expected_graduation_year    INTEGER
academic_year_registered    TEXT
```

### **Updated Status Constraint**
```sql
status CHECK (status IN (
    'under_registration',
    'applicant',
    'active',
    'disabled',
    'given',
    'blacklisted',
    'archived'  -- NEW
))
```

### **New Indexes for Performance**
```sql
idx_students_is_archived        ON students(is_archived)
idx_students_archived_at        ON students(archived_at)
idx_students_graduation_year    ON students(expected_graduation_year)
idx_students_year_level         ON students(year_level_id)
idx_students_active_status      ON students(is_archived, status) WHERE is_archived = FALSE
```

---

## Automatic Graduation Year Calculation

The system automatically calculates when a student is expected to graduate based on their year level at registration:

| Year Level | Years to Add | Example: Registered 2024 | Expected Graduation |
|------------|--------------|--------------------------|---------------------|
| 1st Year   | +4 years     | 2024-2025                | 2028                |
| 2nd Year   | +3 years     | 2024-2025                | 2027                |
| 3rd Year   | +2 years     | 2024-2025                | 2026                |
| 4th Year   | +1 year      | 2024-2025                | 2025                |
| 5th Year   | +1 year      | 2024-2025                | 2025                |

**Automatic Archiving Logic:**
- If current year > expected graduation year → Archive (graduated)
- If current year = graduation year AND month ≥ June → Archive (graduated)
- If last login < 2 years ago → Archive (inactive)
- If never logged in AND registered > 2 years ago → Archive (inactive)

---

## Database Views

### **v_students_eligible_for_archiving**
Shows all students who meet automatic archiving criteria:
```sql
SELECT * FROM v_students_eligible_for_archiving;
```

Returns:
- Student details (ID, name, email, status)
- Year level information
- Expected graduation year
- Years past graduation
- Last login date
- Eligibility reason

### **v_archived_students_summary**
Shows all archived students with full details:
```sql
SELECT * FROM v_archived_students_summary;
```

Returns:
- Student information
- Archive metadata (when, why, by whom)
- Archive type (automatic vs. manual)
- University and year level
- Academic year registered

---

## PostgreSQL Functions

### **1. archive_graduated_students()**
Automatically archives eligible students.

```sql
SELECT * FROM archive_graduated_students();
```

**Returns:**
- `archived_count`: Number of students archived
- `student_ids`: Array of archived student IDs

**Use Case:** Run annually or as a scheduled task

---

### **2. archive_student_manual()**
Manually archives a specific student.

```sql
SELECT archive_student_manual(
    'EDU-2024-000123',  -- student_id
    1,                  -- admin_id
    'Student graduated early'  -- reason
);
```

**Returns:** Boolean (success/failure)

---

### **3. unarchive_student()**
Unarchives a student and restores their account.

```sql
SELECT unarchive_student(
    'EDU-2024-000123',  -- student_id
    1                   -- admin_id
);
```

**Returns:** Boolean (success/failure)

**Behavior:** Restores previous status or defaults to 'active'

---

## Login Protection

### **Blocked Access for Archived Students**

Archived students cannot:
- Log in to the system
- Request password reset OTP
- Access any student features

### **Error Messages**

**During Login:**
```
"Your account has been archived due to graduation. 
If you believe this is an error, please contact the Office of the Mayor for assistance."
```

**During Password Reset:**
```
"Your account has been archived. 
Please contact the Office of the Mayor if you believe this is an error."
```

---

## Audit Trail Integration

### **New Event Category: `archive`**

Event Types:
- `student_archived_manual` - Admin manually archived student
- `student_archived_automatic` - System automatically archived student
- `student_unarchived` - Admin unarchived student
- `bulk_archiving_executed` - Bulk archiving completed

### **Logged Information**

Each archiving event logs:
- **Who**: Admin ID/username (or "system" for automatic)
- **What**: Student archived/unarchived
- **When**: Timestamp
- **Why**: Archive reason
- **Details**: Student data, graduation year, year level
- **Context**: IP address, session ID, user agent

### **AuditLogger Methods**

```php
// Manual archiving
$auditLogger->logStudentArchived(
    $adminId,
    $adminUsername,
    $studentId,
    $studentData,
    $reason,
    false  // isAutomatic
);

// Automatic archiving
$auditLogger->logStudentArchived(
    null,
    'system',
    $studentId,
    $studentData,
    $reason,
    true  // isAutomatic
);

// Unarchiving
$auditLogger->logStudentUnarchived(
    $adminId,
    $adminUsername,
    $studentId,
    $studentData
);

// Bulk archiving
$auditLogger->logBulkArchiving(
    $totalArchived,
    $studentIds,
    $executedBy  // optional
);
```

---

## Files Created/Modified

### **Created:**
1. `sql/create_student_archiving_system.sql` - Complete database migration
2. Archive event methods in `services/AuditLogger.php`

### **Modified:**
1. `unified_login.php` - Added archived student blocking
2. `services/AuditLogger.php` - Added 4 new archiving methods
3. `sql/create_audit_trail 10-15 3'24AM.sql` - Added 'archive' event category

---

## Implementation Status

### ✅ **Completed:**
1. Database schema design with all columns and constraints
2. Automatic graduation year calculation logic
3. Database views for eligible and archived students
4. PostgreSQL functions (archive, unarchive, bulk archive)
5. Login blocking for archived students
6. Password reset blocking for archived students
7. Audit logging integration (4 new methods)
8. Event category documentation

### 🔄 **In Progress / Pending:**
1. Automatic archiving cron job/scheduled task
2. Manual archive functionality in manage_applicants.php
3. Archived students viewer page (modules/admin/archived_students.php)
4. Admin sidebar navigation menu item
5. Comprehensive documentation (ARCHIVING_SYSTEM.md)
6. Testing and validation

---

## Next Steps

### **Priority 1: Core Functionality**
1. Run SQL migration: `sql/create_student_archiving_system.sql`
2. Add manual archive button in manage_applicants.php
3. Create archived students viewer page
4. Add sidebar menu item

### **Priority 2: Automation**
1. Create automatic archiving script
2. Set up cron job or Windows Task Scheduler
3. Configure email notifications for auto-archiving

### **Priority 3: Documentation & Testing**
1. Create comprehensive admin documentation
2. Test all archiving scenarios
3. Verify audit trail logging
4. Test login blocking

---

## Usage Examples

### **For Admins:**

**Check Eligible Students:**
```sql
SELECT * FROM v_students_eligible_for_archiving;
```

**Manually Archive:**
```sql
SELECT archive_student_manual('EDU-2024-000123', 1, 'Did not attend distribution');
```

**View Archived:**
```sql
SELECT * FROM v_archived_students_summary;
```

**Unarchive:**
```sql
SELECT unarchive_student('EDU-2024-000123', 1);
```

**Run Bulk Archiving:**
```sql
SELECT * FROM archive_graduated_students();
```

### **For Developers:**

**Log Manual Archive:**
```php
$auditLogger->logStudentArchived(
    $_SESSION['admin_id'],
    $_SESSION['admin_username'],
    $studentId,
    [
        'full_name' => $studentName,
        'year_level' => $yearLevel,
        'expected_graduation_year' => $gradYear
    ],
    'Student requested account closure',
    false
);
```

---

## Security Considerations

1. **Access Control**: Only super_admin and admin roles can archive/unarchive
2. **Audit Trail**: All archiving actions are logged with full context
3. **Login Blocking**: Archived accounts cannot authenticate
4. **Data Preservation**: Archived student data remains in database
5. **Reversible**: Unarchiving is possible (with proper authorization)
6. **Reason Required**: Manual archiving requires explicit reason

---

## Performance Optimization

**Indexes** ensure fast queries on:
- Archive status checks
- Graduation year lookups
- Archived date filtering
- Active student queries

**Composite Index** optimizes most common query:
```sql
WHERE is_archived = FALSE AND status = 'active'
```

---

## Best Practices

### **When to Archive:**
✅ Student confirmed graduated
✅ Student hasn't logged in for 2+ years
✅ Student explicitly requested account closure
✅ Student failed to attend distribution repeatedly

### **When NOT to Archive:**
❌ Student is currently registered
❌ Student is in active semester
❌ Temporary inactivity (less than 2 years)
❌ Pending verification or approval

### **Unarchiving Guidelines:**
- Verify student identity before unarchiving
- Document reason for unarchiving
- Check if student meets current eligibility criteria
- Update student information if needed

---

**Version:** 1.0  
**Last Updated:** October 16, 2025  
**Status:** Database Schema Complete, Core Functionality In Progress
