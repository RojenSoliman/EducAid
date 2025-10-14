# Audit Trail System - Quick Setup Guide

## Prerequisites
- PostgreSQL database (already configured)
- EducAid system installed and running
- Super admin account access

## Installation Steps

### 1. Run Database Migration

Open your PostgreSQL command line or pgAdmin:

```bash
# Using psql command line
psql -U postgres -d educaid_db

# Then run:
\i C:/xampp/htdocs/EducAid/sql/create_audit_trail.sql
```

**Or using pgAdmin:**
1. Open pgAdmin
2. Connect to your `educaid_db` database
3. Open Query Tool
4. Open file: `C:/xampp/htdocs/EducAid/sql/create_audit_trail.sql`
5. Execute (F5)

**Expected Result:**
```
CREATE TABLE
CREATE INDEX
... (multiple index creation messages)
INSERT 0 1
status
-----------------------------------
Audit trail system created successfully!
```

### 2. Verify Installation

Check if the audit_logs table was created:

```sql
-- View table structure
\d audit_logs

-- Check for initial system event
SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 5;
```

You should see one initial event: "Audit trail system initialized and ready"

### 3. Test Audit Logging

Log in to the admin panel and perform some actions. Then check if events are being logged:

```sql
-- View recent events
SELECT 
    event_type,
    username,
    action_description,
    created_at
FROM audit_logs
ORDER BY created_at DESC
LIMIT 10;
```

### 4. Access the Audit Trail Viewer

1. Log in as **super admin** (sub_admins cannot access this)
2. Look for **"Audit Trail"** in the sidebar (shield icon)
3. You should see:
   - Statistics dashboard (24-hour summary)
   - Filter options
   - Table of recent audit events
   - Export to CSV button

## Troubleshooting

### Issue: "relation audit_logs does not exist"

**Solution:** Run the SQL migration script again. Make sure you're connected to the correct database.

```sql
-- Verify current database
SELECT current_database();

-- If wrong database, connect to correct one
\c educaid_db
```

### Issue: No audit logs appearing

**Check 1 - Verify table exists:**
```sql
SELECT COUNT(*) FROM audit_logs;
```

**Check 2 - Test manual insert:**
```sql
INSERT INTO audit_logs (
    user_type, username, event_type, event_category, 
    action_description, status, ip_address
) VALUES (
    'system', 'test', 'test_event', 'system',
    'Test audit log entry', 'success', '127.0.0.1'
);
```

**Check 3 - Verify PHP can connect:**
- Check `config/database.php` connection
- Check PHP error logs: `C:/xampp/php/logs/php_error_log`

### Issue: Cannot access Audit Trail page

**Solution:** Ensure you're logged in as **super_admin**, not sub_admin.

Check your role:
```sql
SELECT admin_id, username, role 
FROM admins 
WHERE username = 'your_username';
```

If you need to change a user to super_admin:
```sql
UPDATE admins 
SET role = 'super_admin' 
WHERE username = 'your_username';
```

### Issue: Slow performance

**Solution:** Audit logs table has indexes, but if you have millions of records:

1. **Archive old logs:**
```sql
-- Archive logs older than 1 year
INSERT INTO audit_logs_archive
SELECT * FROM audit_logs
WHERE created_at < NOW() - INTERVAL '1 year';

DELETE FROM audit_logs
WHERE created_at < NOW() - INTERVAL '1 year';
```

2. **Vacuum the table:**
```sql
VACUUM ANALYZE audit_logs;
```

3. **Check index usage:**
```sql
SELECT schemaname, tablename, indexname, idx_scan, idx_tup_read, idx_tup_fetch
FROM pg_stat_user_indexes
WHERE tablename = 'audit_logs'
ORDER BY idx_scan DESC;
```

## Testing Checklist

After installation, verify these events are being logged:

- [ ] Admin login
- [ ] Student login
- [ ] Admin logout
- [ ] Student logout
- [ ] Slot opened
- [ ] Slot closed
- [ ] Applicant approved
- [ ] Applicant rejected
- [ ] Schedule created
- [ ] Schedule published
- [ ] Schedule cleared

### Quick Test Script

```sql
-- Count events by category
SELECT 
    event_category,
    COUNT(*) as total
FROM audit_logs
GROUP BY event_category
ORDER BY total DESC;

-- Recent failed events
SELECT 
    event_type,
    username,
    action_description,
    ip_address,
    created_at
FROM audit_logs
WHERE status = 'failure'
ORDER BY created_at DESC
LIMIT 10;

-- Activity by user
SELECT 
    user_type,
    username,
    COUNT(*) as actions
FROM audit_logs
WHERE created_at >= NOW() - INTERVAL '24 hours'
GROUP BY user_type, username
ORDER BY actions DESC;
```

## Next Steps

1. **Set up retention policy** - Decide how long to keep audit logs (1 year recommended)
2. **Schedule archiving** - Create a scheduled task to archive old logs monthly
3. **Monitor failed logins** - Regularly check for suspicious login attempts
4. **Export reports** - Use CSV export for compliance reporting
5. **Train admins** - Show super admins how to use the audit trail viewer

## Support

For issues or questions:
1. Check PHP error logs: `C:/xampp/php/logs/php_error_log`
2. Check PostgreSQL logs
3. Review the main `AUDIT_TRAIL_README.md` for detailed documentation
4. Verify all files are in place:
   - `sql/create_audit_trail.sql`
   - `services/AuditLogger.php`
   - `modules/admin/audit_logs.php`

---

**Last Updated:** October 15, 2025
