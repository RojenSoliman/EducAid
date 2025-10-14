# EducAid Audit Trail System

## Overview

The audit trail system provides comprehensive tracking of all major events, user activities, and administrative actions within the EducAid platform. It records login/logout events, slot management, applicant lifecycle, payroll operations, schedule management, profile changes, and distribution lifecycle events.

## Features

✅ **Authentication Tracking**
- Admin and student login/logout events
- Failed login attempts with IP tracking
- Session correlation

✅ **Slot Management**
- Slot opening/closing operations
- Slot configuration changes
- Applicant count tracking

✅ **Applicant Management**
- New registrations
- Approval/rejection events
- Document verification
- Status changes

✅ **Payroll & Schedule**
- Payroll number generation
- Payroll number changes
- Schedule creation and deletion
- Schedule publication status

✅ **Profile Changes**
- Email changes
- Password changes  
- Profile updates

✅ **Distribution Lifecycle**
- Distribution start/activate/complete events
- Deadline management
- Snapshot creation

✅ **Context Capture**
- IP address and user agent logging
- Request method and URI
- Session ID correlation
- Old and new values (JSON format)
- Custom metadata for each event

## Installation

### Step 1: Create Audit Tables

Run the SQL migration script to create the necessary database tables:

```bash
# Connect to PostgreSQL
psql -U your_username -d educaid_db

# Run the migration script
\i C:/xampp/htdocs/EducAid/sql/create_audit_trail.sql
```

**What this creates:**
- `audit_logs` table with comprehensive fields
- Multiple indexes for fast queries
- Helper views for common queries:
  - `v_recent_admin_activity`
  - `v_recent_student_activity`
  - `v_failed_logins`

### Step 2: Verify Installation

Check if the table was created successfully:

```sql
SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 10;
```

You should see one initial system event confirming the audit system initialization.

### Step 3: Grant Permissions (if needed)

If you're using a specific application database user:

```sql
GRANT SELECT, INSERT ON audit_logs TO your_app_user;
GRANT SELECT ON v_recent_admin_activity TO your_app_user;
GRANT SELECT ON v_recent_student_activity TO your_app_user;
GRANT SELECT ON v_failed_logins TO your_app_user;
```

## Usage

### Viewing Audit Logs (Admin Panel)

**Super Admin Dashboard Access:**

1. Log in as a super admin
2. Navigate to **Audit Trail** in the sidebar (shield icon)
3. View comprehensive audit logs with powerful filtering

**Features:**
- **Real-time Statistics Dashboard** - View 24-hour activity summary
  - Total events
  - Admin actions
  - Student actions
  - Failed events

- **Advanced Filtering**
  - Search by description, event type, or username
  - Filter by user type (Admin/Student/System)
  - Filter by event category (authentication, slot_management, etc.)
  - Filter by status (success/failure/warning)
  - Filter by username (partial match)
  - Filter by date range (from/to)
  - Filter by IP address

- **Detailed Event View**
  - Click "View" button on any log entry
  - See complete event details including:
    - User and session information
    - Request method and URI
    - User agent string
    - Old and new values (JSON format)
    - Custom metadata
    - Affected table and record ID

- **Export Capability**
  - Export filtered results to CSV
  - Download includes all matching records
  - Filename format: `audit_logs_YYYY-MM-DD_HHMMSS.csv`

- **Pagination**
  - 50 records per page
  - Easy navigation between pages
  - Shows total count and current page

### Database Query Examples

For direct database access or custom reporting:

#### Recent Admin Activity
```sql
SELECT * FROM v_recent_admin_activity LIMIT 50;
```

#### Recent Student Activity
```sql
SELECT * FROM v_recent_student_activity LIMIT 50;
```

#### Failed Login Attempts
```sql
SELECT * FROM v_failed_logins WHERE created_at >= NOW() - INTERVAL '24 hours';
```

#### Specific User Activity
```sql
SELECT 
    event_type,
    event_category,
    action_description,
    status,
    ip_address,
    created_at
FROM audit_logs
WHERE user_id = 'target_user_id'
ORDER BY created_at DESC;
```

#### Filter by Event Category
```sql
SELECT *
FROM audit_logs
WHERE event_category = 'slot_management'
ORDER BY created_at DESC
LIMIT 100;
```

#### Search for Specific Actions
```sql
SELECT *
FROM audit_logs
WHERE action_description ILIKE '%payroll%'
ORDER BY created_at DESC;
```

### Programmatic Usage

The `AuditLogger` service class provides methods for logging various events:

```php
// Initialize (already done in integrated files)
require_once __DIR__ . '/services/AuditLogger.php';
$auditLogger = new AuditLogger($connection);

// Log custom event
$auditLogger->logEvent(
    'custom_event_type',
    'custom_category',
    'Description of what happened',
    [
        'user_id' => $userId,
        'user_type' => 'admin', // or 'student'
        'username' => $username,
        'status' => 'success', // or 'failure', 'warning'
        'affected_table' => 'table_name',
        'affected_record_id' => $recordId,
        'old_values' => ['field' => 'old_value'],
        'new_values' => ['field' => 'new_value'],
        'metadata' => ['additional' => 'context']
    ]
);
```

## Event Types Reference

### Authentication Events (`authentication` category)
- `admin_login` - Admin successful login
- `student_login` - Student successful login
- `admin_logout` - Admin logout
- `student_logout` - Student logout
- `login_failed` - Failed login attempt

### Slot Management (`slot_management` category)
- `slot_opened` - New signup slot created and opened
- `slot_closed` - Signup slot closed/finished
- `slot_updated` - Slot configuration updated
- `slot_deleted` - Slot deleted

### Applicant Management (`applicant_management` category)
- `applicant_registered` - New student registration
- `applicant_approved` - Applicant approved to active status
- `applicant_rejected` - Applicant documents rejected
- `applicant_verified` - Applicant documents verified
- `applicant_migrated` - Applicant migrated via CSV

### Payroll (`payroll` category)
- `payroll_generated` - Bulk payroll number generation
- `payroll_number_changed` - Individual payroll number changed
- `qr_code_generated` - QR codes generated

### Schedule (`schedule` category)
- `schedule_created` - Distribution schedule created
- `schedule_published` - Schedule made visible to students
- `schedule_unpublished` - Schedule hidden from students
- `schedule_cleared` - All schedules permanently deleted

### Profile (`profile` category)
- `email_changed` - Email address updated
- `password_changed` - Password updated
- `profile_updated` - Profile information changed

### Distribution (`distribution` category)
- `distribution_started` - New distribution cycle started
- `distribution_activated` - Distribution moved to active state
- `distribution_completed` - Distribution cycle completed
- `documents_deadline_set` - Documents submission deadline configured

### System (`system` category)
- `config_changed` - System configuration updated
- `bulk_operation` - Bulk data operation
- `data_export` - Data exported
- `system_maintenance` - Maintenance operation

## Data Retention & Maintenance

### Archiving Old Logs

To maintain performance, consider archiving old audit logs periodically:

```sql
-- Create archive table (one-time)
CREATE TABLE audit_logs_archive (LIKE audit_logs INCLUDING ALL);

-- Archive logs older than 1 year
INSERT INTO audit_logs_archive
SELECT * FROM audit_logs
WHERE created_at < NOW() - INTERVAL '1 year';

-- Delete archived logs from main table
DELETE FROM audit_logs
WHERE created_at < NOW() - INTERVAL '1 year';
```

### Cleanup Failed Logins

Remove old failed login attempts:

```sql
DELETE FROM audit_logs
WHERE event_type = 'login_failed'
AND created_at < NOW() - INTERVAL '90 days';
```

## Security Considerations

1. **Access Control**: Only admins should have access to audit logs
2. **Data Sensitivity**: Audit logs contain IP addresses and user activities
3. **Retention Policy**: Define how long to keep audit data
4. **Backup**: Include audit_logs in regular database backups
5. **Monitoring**: Set up alerts for suspicious patterns (e.g., multiple failed logins)

## Troubleshooting

### Audit logs not appearing

1. Check if the table exists:
```sql
\d audit_logs
```

2. Verify the AuditLogger service is loaded:
```php
// Check in your PHP files
if (file_exists(__DIR__ . '/services/AuditLogger.php')) {
    echo "AuditLogger file exists";
}
```

3. Check PHP error logs:
```bash
tail -f C:/xampp/php/logs/php_error_log
```

### Performance issues

If queries are slow, check indexes:
```sql
-- View existing indexes
SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'audit_logs';

-- Add additional indexes if needed
CREATE INDEX idx_audit_custom ON audit_logs(column_name);
```

## Future Enhancements

- [x] Web-based audit log viewer in admin panel ✅ (Completed)
- [x] Export audit logs to CSV ✅ (Completed)
- [x] Advanced filtering and search capabilities ✅ (Completed)
- [ ] Export to PDF format
- [ ] Real-time audit log monitoring dashboard with auto-refresh
- [ ] Email alerts for critical events (multiple failed logins, etc.)
- [ ] Automated archiving scheduled task
- [ ] Audit log retention policy configuration
- [ ] Audit log integrity verification (checksums)

## Support

For questions or issues:
1. Check the database error logs
2. Review PHP error logs
3. Verify database permissions
4. Check the conversation summary for implementation details

## Version History

- **v1.0** (2025-10-15): Initial audit trail system
  - Login/logout tracking
  - Slot management events
  - Applicant lifecycle events
  - Payroll and schedule operations
  - Profile changes
  - Distribution lifecycle

---

**Last Updated:** October 15, 2025
**Author:** EducAid Development Team
