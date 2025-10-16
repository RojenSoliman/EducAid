# Archived Students Page - Implementation Guide

## Overview
The Archived Students page provides a comprehensive interface for managing archived student accounts, including viewing, filtering, unarchiving, and exporting data.

---

## Files Created

### 1. **modules/admin/archived_students.php** (Main Page)
**Purpose:** Display and manage all archived students

**Features:**
- ✅ Statistics dashboard with 4 metric cards
- ✅ Advanced filtering system (7 filter options)
- ✅ Pagination (50 records per page)
- ✅ Unarchive functionality with confirmation
- ✅ CSV export with applied filters
- ✅ Responsive design with Bootstrap 5
- ✅ Audit trail integration
- ✅ Municipality-based filtering (automatic)

**Statistics Cards:**
1. Total Archived - Shows total archived student count
2. Automatically Archived - System-archived students
3. Manually Archived - Admin-archived students
4. Last 30 Days - Recent archiving activity

**Filter Options:**
1. **Search** - Name, Email, or Student ID
2. **Archive Type** - Manual / Automatic
3. **Year Level** - Filter by year level
4. **Date From** - Start date range
5. **Date To** - End date range
6. **Clear Filters** - Reset all filters
7. **Export CSV** - Download filtered results

**Actions:**
- **View Details** - Opens modal with full student information
- **Unarchive** - Restores student account (with confirmation)

---

### 2. **modules/admin/get_archived_student_details.php** (Details Modal)
**Purpose:** Display comprehensive student information in modal

**Information Sections:**
1. **Personal Information**
   - Full Name
   - Student ID
   - Email & Mobile
   - Sex & Birth Date (with age calculation)
   - Barangay & Municipality

2. **Academic Information**
   - University
   - Year Level
   - Academic Year Registered
   - Expected Graduation Year (with years past indicator)
   - Payroll Number
   - Application Date
   - Last Login (with time ago indicator)
   - Current Status

3. **Archive Information**
   - Archive Type (Manual/Automatic badge)
   - Archived At (timestamp)
   - Archived By (admin name or "System")
   - Archive Reason (highlighted in warning box)

---

## Navigation Integration

### Admin Sidebar Menu
**Location:** System Controls > Archived Students

**Menu Structure:**
```
System Controls (super_admin only)
├── Blacklist Archive
├── Archived Students  ← NEW
├── Document Archives
├── Admin Management
└── ...
```

**Menu Item:**
- Icon: `bi bi-archive-fill`
- Text: "Archived Students"
- Active state detection included
- Super admin only access

---

## Database Queries

### Main Query (with filters)
```sql
SELECT 
    s.student_id,
    s.first_name, s.middle_name, s.last_name, s.extension_name,
    s.email, s.mobile, s.bdate,
    yl.name as year_level_name,
    u.name as university_name,
    s.academic_year_registered,
    s.expected_graduation_year,
    s.archived_at, s.archived_by, s.archive_reason,
    s.last_login,
    CONCAT(a.first_name, ' ', a.last_name) as archived_by_name,
    CASE WHEN s.archived_by IS NULL THEN 'Automatic' ELSE 'Manual' END as archive_type
FROM students s
LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
LEFT JOIN universities u ON s.university_id = u.university_id
LEFT JOIN admins a ON s.archived_by = a.admin_id
WHERE s.is_archived = TRUE
  [+ additional filters]
ORDER BY s.archived_at DESC
LIMIT 50 OFFSET [page]
```

### Statistics Query
```sql
SELECT 
    COUNT(*) as total_archived,
    COUNT(CASE WHEN archived_by IS NULL THEN 1 END) as auto_archived,
    COUNT(CASE WHEN archived_by IS NOT NULL THEN 1 END) as manual_archived,
    COUNT(CASE WHEN archived_at >= CURRENT_DATE - INTERVAL '30 days' THEN 1 END) as archived_last_30_days
FROM students
WHERE is_archived = TRUE
```

---

## Unarchive Functionality

### Backend (POST Handler)
```php
// Validates student_id
// Fetches student data for audit log
// Calls unarchive_student() PostgreSQL function
// Logs to audit trail using AuditLogger
// Returns JSON response
```

### Frontend (JavaScript)
```javascript
function unarchiveStudent(studentId, studentName) {
    // Shows confirmation dialog
    // Sends POST request with FormData
    // Handles success/error responses
    // Reloads page on success
}
```

### Audit Trail
Logs unarchive action with:
- Admin ID and username
- Student ID and full name
- Previous archive reason
- Archive timestamp
- IP address, user agent, session ID

---

## CSV Export

### Export Features:
- ✅ Respects all applied filters
- ✅ Includes all matching records (no pagination limit)
- ✅ Filename format: `archived_students_YYYY-MM-DD_HHMMSS.csv`
- ✅ 12 columns of data

### CSV Columns:
1. Student ID
2. First Name
3. Middle Name
4. Last Name
5. Email
6. Mobile
7. Year Level
8. University
9. Archived At
10. Archive Type (Automatic/Manual)
11. Archived By (Admin name or "System")
12. Reason

---

## UI/UX Features

### Design Elements:
- **Color Scheme:**
  - Primary: #2c3e50
  - Secondary: #3498db
  - Success: #27ae60
  - Warning: #f39c12
  - Danger: #e74c3c

- **Stat Card Icons:**
  - Total: `bi-archive-fill` (blue)
  - Automatic: `bi-robot` (green)
  - Manual: `bi-person-fill-check` (orange)
  - Recent: `bi-calendar-check` (blue)

- **Badges:**
  - Automatic: Light blue background
  - Manual: Light orange background

- **Table:**
  - Hover effect on rows
  - Responsive with horizontal scroll
  - Dark header with white text

### User Experience:
1. **Loading States** - Spinner shown while fetching details
2. **Confirmations** - Required for unarchive action
3. **Empty State** - Clear message when no results
4. **Pagination** - Show current page and total pages
5. **Filter Persistence** - Filters maintained across pagination

---

## Security Considerations

### Access Control:
- ✅ Session-based authentication required
- ✅ Admin role verification
- ✅ Municipality-based data isolation
- ✅ Super admin only (menu visibility)

### Data Protection:
- ✅ Parameterized queries (SQL injection prevention)
- ✅ Input sanitization (htmlspecialchars)
- ✅ POST method for state-changing operations
- ✅ Audit trail for all unarchive actions

---

## Integration Points

### 1. Database Functions
- `unarchive_student()` - PostgreSQL function
- Returns boolean success status
- Restores previous status or defaults to 'active'

### 2. Audit Logger
- `logStudentUnarchived()` - Logs unarchive events
- Includes full context (who, what, when, why)

### 3. Admin Sidebar
- Added to `$sysControlsFiles` array
- Active state detection
- Super admin only visibility

---

## Testing Checklist

### Functional Testing:
- [ ] Page loads without errors
- [ ] Statistics display correctly
- [ ] All filters work independently
- [ ] Multiple filters work together
- [ ] Search finds students by name/email/ID
- [ ] Pagination navigates correctly
- [ ] Filter persistence across pages
- [ ] Details modal shows correct data
- [ ] Unarchive button works
- [ ] Confirmation dialog appears
- [ ] Successful unarchive redirects/reloads
- [ ] CSV export downloads
- [ ] CSV contains correct filtered data
- [ ] Audit trail logs unarchive events

### UI/UX Testing:
- [ ] Responsive on mobile devices
- [ ] Statistics cards display properly
- [ ] Table scrolls horizontally on small screens
- [ ] Badges render correctly
- [ ] Empty state displays when no results
- [ ] Loading spinner shows in modal
- [ ] Error messages display appropriately

### Security Testing:
- [ ] Non-admin users cannot access
- [ ] Municipality filtering works
- [ ] SQL injection attempts blocked
- [ ] XSS attempts sanitized

---

## Usage Instructions

### For Admins:

**1. Access the Page:**
```
Navigate to: System Controls > Archived Students
```

**2. View Archived Students:**
- See statistics at the top
- Browse the table of archived students
- Use pagination to view more records

**3. Filter Results:**
- Enter search term (name, email, or ID)
- Select archive type (Manual/Automatic)
- Choose year level
- Set date range
- Click "Filter" button

**4. View Student Details:**
- Click "View Details" (eye icon)
- Modal shows complete information
- Close modal when done

**5. Unarchive a Student:**
- Click "Unarchive" button
- Confirm the action
- Student account is restored
- Page refreshes automatically

**6. Export Data:**
- Apply filters if needed
- Click "Export to CSV"
- File downloads automatically
- Open in Excel or similar

**7. Clear Filters:**
- Click "Clear Filters" button
- Returns to unfiltered view

---

## Performance Optimization

### Database Indexes Used:
- `idx_students_is_archived` - Fast filtering by archive status
- `idx_students_archived_at` - Quick date range queries
- `idx_students_year_level` - Year level filtering
- `idx_students_active_status` - Composite index for common queries

### Query Optimization:
- Parameterized queries prevent SQL parsing overhead
- LEFT JOINs only when needed
- Pagination limits result set
- Count query separate from data query

### Frontend Performance:
- CSS loaded from CDN
- Bootstrap Icons from CDN
- Minimal custom JavaScript
- AJAX used only for modal content

---

## Future Enhancements

### Potential Features:
1. **Bulk Actions**
   - Bulk unarchive selected students
   - Bulk export selected records

2. **Advanced Filters**
   - Filter by municipality (for multi-tenant)
   - Filter by last login date
   - Filter by graduation year range

3. **Sorting**
   - Sort by name, date, type
   - Ascending/descending toggle

4. **Email Notifications**
   - Notify student when unarchived
   - Send welcome back email

5. **Archive History**
   - Show previous archive/unarchive events
   - Track multiple archiving cycles

6. **Dashboard Integration**
   - Add archived count to main dashboard
   - Show recent archiving activity

---

## Troubleshooting

### Common Issues:

**Page doesn't load:**
- Check session is active
- Verify admin role
- Confirm database connection

**No students shown:**
- Check if any students are archived
- Verify municipality filter
- Check database query execution

**Unarchive doesn't work:**
- Verify `unarchive_student()` function exists
- Check admin permissions
- Review error logs

**CSV export fails:**
- Check write permissions
- Verify PHP output buffering
- Confirm query returns results

**Modal doesn't load:**
- Check AJAX endpoint exists
- Verify student ID is valid
- Review browser console for errors

---

## Related Files

- `sql/create_student_archiving_system.sql` - Database schema
- `services/AuditLogger.php` - Audit logging methods
- `unified_login.php` - Login blocking for archived students
- `includes/admin/admin_sidebar.php` - Navigation menu
- `ARCHIVING_SYSTEM_SUMMARY.md` - Complete system documentation

---

**Version:** 1.0  
**Created:** October 16, 2025  
**Status:** ✅ Complete and Ready for Testing
