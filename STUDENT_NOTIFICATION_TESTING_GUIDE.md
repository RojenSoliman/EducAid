# Student Notification System - Testing Guide

## Quick Test Scenarios

### 1. Test Registration Approval Notifications

#### Bulk Approval Test:
1. Navigate to `modules/admin/review_registrations.php`
2. Select multiple pending registrations (checkbox)
3. Click "Approve Selected"
4. **Expected**: Each approved student receives notification:
   - Title: "Registration Approved!"
   - Type: success badge
   - Bell icon shows new count

#### Individual Approval Test:
1. Navigate to `modules/admin/review_registrations.php`
2. Click "Approve" on single registration
3. Enter remarks (optional)
4. Submit
5. **Expected**: Student receives notification

#### Auto-Approval Test:
1. Navigate to `modules/admin/review_registrations.php`
2. Click "Auto-Approve High Confidence"
3. Set confidence threshold (e.g., 85%)
4. **Expected**: All auto-approved students receive notification

#### Verify Results:
- Log in as approved student
- Check bell icon in header (should show count)
- Click bell to see dropdown notification
- Click "View All" to see full notifications page

---

### 2. Test Application Verification Notifications

#### Verification Test:
1. Navigate to `modules/admin/manage_applicants.php`
2. Find applicant with complete documents
3. Click "Verify" or "Mark as Verified"
4. **Expected**: Student receives notification:
   - Title: "Application Approved!"
   - Message: "Congratulations! Your application has been verified and approved..."

#### Override Verification Test (Super Admin Only):
1. Log in as super_admin
2. Navigate to `modules/admin/manage_applicants.php`
3. Find incomplete applicant
4. Click "Override Verify"
5. **Expected**: Student receives same approval notification

---

### 3. Test Announcement Notifications

1. Navigate to `modules/admin/manage_announcements.php`
2. Click "Add New Announcement"
3. Fill in:
   - Title: "Test Announcement"
   - Content: "This is a test announcement message"
4. Submit
5. **Expected**: ALL students receive notification:
   - Title: "New Announcement: Test Announcement"
   - Message: Truncated content (200 chars max)
   - Type: announcement badge
   - Links to student_announcements.php

#### Verify:
- Log in as ANY student
- Check bell icon (should show +1)
- Click notification
- Should redirect to announcements page

---

### 4. Test Distribution Notifications

1. Navigate to `modules/admin/scan_qr.php`
2. Scan or manually enter student payroll number
3. Confirm distribution
4. **Expected**: Student receives notification:
   - Title: "Scholarship Aid Distributed!"
   - Message: "Your scholarship aid has been successfully distributed..."
   - Type: success badge

---

## Notification Verification Checklist

### Frontend Checks:
- [ ] Bell icon appears in student header
- [ ] Unread count badge displays correctly
- [ ] Dropdown shows recent notifications
- [ ] Click notification marks as read
- [ ] Timestamp shows "X minutes ago" format
- [ ] "Mark All as Read" button works
- [ ] "View All" button links to notifications page

### Notifications Page Checks:
- [ ] All notifications display correctly
- [ ] Filter by type works (All/Announcements/Success/Info/Warning)
- [ ] Filter by status works (All/Unread/Read)
- [ ] Pagination works (10 per page)
- [ ] Action buttons work (Mark as Read, Delete)
- [ ] Notification icons show correct color/type

### Database Checks:
```sql
-- Check notifications were created
SELECT * FROM student_notifications 
WHERE student_id = 'YOUR_TEST_STUDENT_ID' 
ORDER BY created_at DESC;

-- Check unread count
SELECT COUNT(*) FROM student_notifications 
WHERE student_id = 'YOUR_TEST_STUDENT_ID' 
AND is_read = false;

-- Check notification types
SELECT type, COUNT(*) as count 
FROM student_notifications 
GROUP BY type;
```

---

## Test Student Credentials

Create test student accounts with different statuses:
1. **Test Student 1**: under_registration → Test auto-approval
2. **Test Student 2**: applicant → Test verification
3. **Test Student 3**: active → Test announcements & distribution

---

## Common Issues & Solutions

### Issue: Bell icon not showing
**Solution**: Check that `bell_notifications.php` is included in `student_header.php`

### Issue: Notifications not appearing
**Solutions**:
1. Check database connection in helper function
2. Verify student_id is TEXT type (not integer)
3. Check student_notifications table exists
4. Verify foreign key constraint is valid

### Issue: Unread count not updating
**Solution**: Check JavaScript AJAX calls in bell_notifications.php

### Issue: "Mark All as Read" not working
**Solution**: Check API endpoint at `api/student/mark_all_notifications_read.php`

---

## API Endpoints to Test

### Mark Single Notification as Read:
```
POST api/student/mark_notification_read.php
Body: { notification_id: 123 }
```

### Mark All Notifications as Read:
```
POST api/student/mark_all_notifications_read.php
Body: { }
```

### Get Unread Count:
```
GET api/student/get_notification_count.php
```

### Delete Notification:
```
POST api/student/delete_notification.php
Body: { notification_id: 123 }
```

---

## Success Criteria

✅ All 5 integration points send notifications  
✅ Notifications appear in bell dropdown  
✅ Notifications appear in full page view  
✅ Unread count updates correctly  
✅ Mark as read functionality works  
✅ Delete functionality works  
✅ No PHP errors in any file  
✅ No JavaScript console errors  
✅ All notifications link to correct pages  

---

## Performance Testing

### Load Test:
1. Create announcement (sends to ALL students)
2. Check execution time
3. Verify all students received notification
4. **Expected**: Under 5 seconds for 1000 students

### Database Query Test:
```sql
-- Should use index on student_id
EXPLAIN ANALYZE 
SELECT * FROM student_notifications 
WHERE student_id = 'TEST_ID' AND is_read = false;
```

---

## Rollback Plan

If issues occur, remove these lines from each file:

### review_registrations.php (Line 3):
```php
require_once __DIR__ . '/../../includes/student_notification_helper.php';
```

### manage_applicants.php (After database include):
```php
require_once __DIR__ . '/../../includes/student_notification_helper.php';
```

### auto_approve_high_confidence.php (Line 3):
```php
require_once __DIR__ . '/../../includes/student_notification_helper.php';
```

### scan_qr.php (Line 4):
```php
require_once __DIR__ . '/../../includes/student_notification_helper.php';
```

And comment out all `createStudentNotification()` calls.

---

**Ready to Test!** 🚀
