# Student Notification Integration Guide

## ✅ Announcement Notifications - DONE!

I've already integrated student notifications into the announcement system. When an admin posts a new announcement, **all students** will automatically receive a notification!

**File Updated**: `modules/admin/manage_announcements.php`

---

## 📚 Helper Functions Available

I've created a helper file with pre-made functions for common notification scenarios:

**File**: `includes/student_notification_helper.php`

### Available Functions:

1. **`createStudentNotification()`** - Send to one student
2. **`createBulkStudentNotification()`** - Send to multiple students  
3. **`notifyStudentDocumentStatus()`** - Document approval/rejection
4. **`notifyStudentApplicationStatus()`** - Application status updates
5. **`sendDeadlineReminder()`** - Deadline reminders
6. **`notifyStudentSchedule()`** - Schedule updates
7. **`sendSystemAnnouncement()`** - System-wide announcements

---

## 🔧 Integration Examples

### 1. Document Review (Approve/Reject)

Find your document review/validation code and add:

```php
// Include the helper
require_once __DIR__ . '/../../includes/student_notification_helper.php';

// When approving a document
if ($action == 'approve') {
    // Your existing approval code...
    
    // Add notification
    notifyStudentDocumentStatus(
        $connection,
        $student_id,
        'Certificate of Indigency',  // document type
        'approved'
    );
}

// When rejecting a document
if ($action == 'reject') {
    // Your existing rejection code...
    
    // Add notification
    notifyStudentDocumentStatus(
        $connection,
        $student_id,
        'Certificate of Indigency',  // document type
        'rejected',
        $rejection_reason  // why it was rejected
    );
}
```

### 2. Application Status Updates

```php
require_once __DIR__ . '/../../includes/student_notification_helper.php';

// When application is approved
notifyStudentApplicationStatus(
    $connection,
    $student_id,
    'approved',
    'Please proceed to the next step.'
);

// When application is submitted
notifyStudentApplicationStatus(
    $connection,
    $student_id,
    'submitted'
);
```

### 3. Schedule Assignment

```php
require_once __DIR__ . '/../../includes/student_notification_helper.php';

// When assigning a schedule
notifyStudentSchedule(
    $connection,
    $student_id,
    "Your distribution schedule: November 15, 2025 at 10:00 AM. Location: Main Office",
    'student_schedule.php'
);
```

### 4. Deadline Reminders (Can be in a cron job)

```php
require_once __DIR__ . '/../../includes/student_notification_helper.php';

// Send reminder to students who haven't submitted documents
sendDeadlineReminder(
    $connection,
    '2025-11-15',  // deadline date
    'submit all required documents',
    'WHERE documents_submitted = FALSE'  // filter
);
```

### 5. Custom Notifications

```php
require_once __DIR__ . '/../../includes/student_notification_helper.php';

// Send to one student
createStudentNotification(
    $connection,
    $student_id,
    'Welcome!',
    'Welcome to EducAid. Please complete your profile.',
    'info',  // type
    'low',   // priority
    'student_profile.php'  // action url
);

// Send to all students
createBulkStudentNotification(
    $connection,
    'System Maintenance',
    'The system will be down Nov 1 from 2AM-6AM.',
    'system',
    'medium',
    null,
    "WHERE status = 'active'"  // optional filter
);
```

---

## 🎯 Where to Integrate

Here are the key places in your app where you should add notifications:

### Priority 1 - High Impact
- [ ] **Document Review System** - Approve/reject notifications
- [ ] **Application Status** - Approval/rejection notifications
- [ ] **Announcement System** - ✅ ALREADY DONE!

### Priority 2 - Medium Impact
- [ ] **Schedule Assignment** - When student gets assigned a slot
- [ ] **Profile Updates** - When admin approves/rejects profile changes
- [ ] **Distribution Dates** - When distribution dates are set

### Priority 3 - Nice to Have
- [ ] **Deadline Reminders** - Automated reminders (cron job)
- [ ] **System Maintenance** - Notify before downtime
- [ ] **Policy Updates** - When requirements/policies change

---

## 📋 Quick Integration Checklist

For each feature that should notify students:

1. **Include the helper file**:
   ```php
   require_once __DIR__ . '/../../includes/student_notification_helper.php';
   ```

2. **Choose the right function** (or use `createStudentNotification` for custom)

3. **Add the notification call** after your existing logic succeeds

4. **Test it**: 
   - Do the action as admin
   - Login as student
   - Check bell icon for notification

---

## 🔍 Finding Where to Add Notifications

Use these searches to find key integration points:

### Find Document Approval Code:
```bash
# Search for document approval/rejection
grep -r "document.*approv" modules/admin/
grep -r "document.*reject" modules/admin/
```

### Find Application Status Code:
```bash
# Search for application updates
grep -r "application.*status" modules/admin/
grep -r "UPDATE.*applications" modules/admin/
```

### Find Schedule Assignment Code:
```bash
# Search for schedule creation
grep -r "INSERT.*schedule" modules/admin/
grep -r "schedule.*assign" modules/admin/
```

---

## 💡 Tips

1. **Always check if the action succeeded** before sending notification
2. **Use appropriate notification types** (see guide below)
3. **Set `is_priority = true`** for urgent items like rejections
4. **Add action URLs** so students can click to see details
5. **Test notifications** after adding them

### Notification Type Guide:
- `announcement` - General announcements
- `document` - Document-related updates
- `schedule` - Schedule/appointment changes
- `warning` - Deadlines, warnings
- `error` - Rejections, errors, problems
- `success` - Approvals, completions
- `system` - System updates
- `info` - General information

### Priority Guide:
- `low` - Normal information
- `medium` - Important updates (default for most)
- `high` - Urgent, requires immediate attention

---

## 📞 Need Help?

1. Check `includes/student_notification_helper.php` for function documentation
2. Look at `modules/admin/manage_announcements.php` for a working example
3. Test notifications by logging in as a student after triggering the action

---

## ✅ Summary

- ✅ Announcements now send notifications automatically
- ✅ Helper functions created for easy integration
- ⏳ You can now add notifications to other features as needed
- 📖 Use this guide to integrate step by step

Start with document reviews and application status - those are the most important for students!
