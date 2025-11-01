# Slot Threshold Notification System

## Overview

Automatically notifies students **both in-app (bell icon) AND via email** when distribution slots are running low, encouraging timely applications before slots fill up completely.

## 🔔 Notification Delivery Channels

### 1. In-App Notifications (Bell Dropdown) ✅
- **Location**: Student header bell icon
- **How it works**: 
  - `createStudentNotification()` inserts into `student_notifications` table
  - `includes/student/bell_notifications.php` reads from this table
  - Bell icon shows unread count badge
  - Dropdown displays recent notifications
  - Clicking notification marks it as read
- **Appears**: Instantly when script runs

### 2. Email Notifications ✅
- **How it works**: 
  - `createStudentNotification()` calls `student_handle_email_delivery()`
  - Respects student email preferences (immediate vs digest)
  - Uses existing `StudentEmailNotificationService.php`
- **Appears**: Based on student preference (immediate or daily digest)

## Notification Flow

```
check_slot_thresholds.php (Runs every 2 hours)
         ↓
Checks slot fill percentage (80%, 90%, 95%, 99%)
         ↓
Calls createStudentNotification() for each eligible student
         ↓
         ├─→ INSERT INTO student_notifications (in-app)
         │   └─→ Bell icon queries this table
         │       └─→ Student sees notification in dropdown ✅
         │
         └─→ Calls student_handle_email_delivery() 
             └─→ Checks student preferences
                 ├─→ immediate: Sends email now ✅
                 └─→ digest: Queues for daily batch ✅
```

## How It Works

### Student Experience

### What Students See:

1. **Bell Icon** (Top right of student portal)
   - Red badge appears with unread count
   - Example: `🔔 3` means 3 unread notifications

2. **Click Bell** → Dropdown opens showing:
   - "⏰ Slots Filling Fast" (example title)
   - "Only 50 slots left for 2024-2025 1st Semester"
   - Timestamp: "2 hours ago"
   - Click notification → Opens registration page

3. **Full Notifications Page** (`student_notifications.php`)
   - All notifications in chronological order
   - Filter by: All / Unread / Read
   - Filter by type: Warning / Info / Success
   - Mark as read / Delete options

4. **Email** (if enabled in preferences)
   - Subject: "⏰ Slots Filling Fast"
   - Body: Full message with action link
   - Sent: Immediately or in daily digest

### Example Notification:

**When 90% full:**
```
Title: ⏰ Slots Filling Fast
Message: Slots are filling up quickly for 2024-2025 1st Semester. 
         Only 50 slots left. Apply soon to secure your spot.
Type: Warning (⚠️ orange icon)
Priority: Medium
Action: Click to apply → student_register.php
```

**When 99% full:**
```
Title: 🚨 Last Chance to Apply!
Message: Only 3 slot(s) remaining for 2024-2025 1st Semester. 
         The distribution is almost full. Apply now before it's too late!
Type: Error (🔴 red icon)
Priority: High (shows as modal popup)
Action: Click to apply → student_register.php
```

## Threshold Levels

The system monitors four threshold levels based on slot fill percentage:

| Threshold | Fill % | Priority | Notification Type | Example Message |
|-----------|--------|----------|-------------------|-----------------|
| **Critical** | ≥99% | High (Modal) | Error | "🚨 Last Chance! Only 3 slots left" |
| **Urgent** | ≥95% | High (Modal) | Warning | "⚠️ Running Out! Only 15 slots left" |
| **Warning** | ≥90% | Medium | Warning | "⏰ Filling Fast! 30 slots remaining" |
| **Notice** | ≥80% | Medium | Info | "📢 Limited Slots! 60 slots available" |

### Smart Notification Logic

- **Progressive alerts**: Only sends higher-level notifications (won't spam with same threshold)
- **Time-based throttling**: Minimum 4 hours between same-threshold notifications
- **Targeted delivery**: Only notifies verified students who haven't applied yet
- **Email integration**: Uses existing notification preferences (immediate/digest)
- **Modal priority**: Critical/Urgent notifications show as popups for maximum visibility

## Setup Instructions

### 1. Create Database Table

Run the SQL migration:

```bash
psql -U your_user -d educaid -f sql/create_slot_threshold_notifications.sql
```

Or via pgAdmin:
- Open Query Tool
- Run `sql/create_slot_threshold_notifications.sql`

### 2. Schedule the Job

#### Option A: Windows Task Scheduler (Recommended for XAMPP)

1. Open **Task Scheduler** (search in Start Menu)
2. Click **Create Basic Task**
3. Name: "EducAid Slot Threshold Monitor"
4. Trigger: **Daily**, repeat every **2 hours** for duration of **1 day**
5. Action: **Start a program**
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\EducAid\check_slot_thresholds.php`
   - Start in: `C:\xampp\htdocs\EducAid`
6. Finish and **enable** the task

**Quick Setup via Batch File:**
```cmd
run_slot_threshold_check.bat
```

#### Option B: Linux Cron

Edit crontab:
```bash
crontab -e
```

Add this line (runs every 2 hours):
```cron
0 */2 * * * /usr/bin/php /path/to/EducAid/check_slot_thresholds.php >> /var/log/educaid_slots.log 2>&1
```

### 3. Test the System

#### Manual Test Run

**Windows:**
```cmd
cd C:\xampp\htdocs\EducAid
php check_slot_thresholds.php
```

**Linux:**
```bash
cd /path/to/EducAid
php check_slot_thresholds.php
```

#### Expected Output

```
[2025-11-01 14:30:00] Starting slot threshold check...

=== Distribution #5 (Municipality #1) ===
Period: 2024-2025 1st Semester
Slots: 450/500 used (90.00% full)
Slots left: 50
Threshold 'warning_90' triggered.
Found 1,234 eligible students to notify.
Notifications sent: 1,234 success, 0 failed.

[2025-11-01 14:32:15] Slot threshold check completed.
```

## Database Schema

### `slot_threshold_notifications` Table

```sql
slot_id              INTEGER       -- Which distribution
municipality_id      INTEGER       -- Which municipality
last_threshold       VARCHAR(20)   -- 'notice_80', 'warning_90', 'urgent_95', 'critical_99'
last_notified_at     TIMESTAMP     -- When last notification was sent
students_notified    INTEGER       -- How many students were notified
```

**Primary Key:** `(slot_id, municipality_id)`

## Configuration

### Adjust Thresholds

Edit `check_slot_thresholds.php` lines 65-90 to customize:

```php
if ($fill_percentage >= 99) {
    $threshold_key = 'critical_99';
    $notification_title = '🚨 Last Chance to Apply!';
    // ...
}
```

### Change Notification Frequency

- **Minimum time between same-threshold alerts**: Line 112
  ```php
  if ($hours_since < 4) { // Change from 4 hours
  ```

- **Rate limiting (mail server protection)**: Line 176
  ```php
  if ($success_count % 50 === 0) {
      usleep(100000); // Pause every 50 emails
  }
  ```

### Eligible Student Criteria

Line 140-148 defines who receives notifications:
```php
WHERE s.municipality_id = $1
  AND s.is_verified = TRUE
  AND s.student_id NOT IN (
      SELECT student_id FROM students WHERE slot_id = $2
  )
```

## Monitoring & Troubleshooting

### Check Last Run

Query the tracking table:
```sql
SELECT 
    ss.academic_year,
    ss.semester,
    stn.last_threshold,
    stn.last_notified_at,
    stn.students_notified
FROM slot_threshold_notifications stn
JOIN signup_slots ss ON ss.slot_id = stn.slot_id
ORDER BY stn.last_notified_at DESC;
```

### View Recent Notifications

```sql
SELECT 
    student_id,
    title,
    message,
    created_at
FROM student_notifications
WHERE type = 'warning' OR type = 'error'
ORDER BY created_at DESC
LIMIT 20;
```

### Common Issues

**No notifications sent:**
- Check if distribution is active: `SELECT * FROM signup_slots WHERE is_active = TRUE;`
- Verify fill percentage: `SELECT COUNT(*) FROM students WHERE slot_id = X;`
- Ensure students exist: `SELECT COUNT(*) FROM students WHERE is_verified = TRUE;`

**Duplicate notifications:**
- The system prevents this via `slot_threshold_notifications` table
- Check `last_notified_at` to confirm throttling is working

**Email delivery fails:**
- Check SMTP configuration in `services/StudentEmailNotificationService.php`
- Verify student email preferences: `SELECT * FROM student_notification_preferences;`

## Integration with Existing Systems

### Notification Types

Uses existing notification helper (`includes/student_notification_helper.php`):
- ✅ In-app notifications (bell icon)
- ✅ Email delivery (immediate or digest, based on preferences)
- ✅ Priority modals (critical/urgent thresholds)

### Student Portal Display

Students see notifications:
1. **Bell icon** badge count increases
2. **Dropdown** shows notification
3. **Modal popup** (for high-priority alerts)
4. **Email** (if enabled in preferences)

### Admin Visibility

Admins can view notification stats:
- Navigate to: **Admin → Reports → Notifications**
- Filter by type: `warning`, `error`
- See delivery rates and open rates

## Best Practices

### Timing
- Run every **2 hours** during distribution periods
- Disable during off-seasons (no active distributions)
- Increase frequency (hourly) during final 48 hours

### Messaging
- Keep messages **short and actionable**
- Include **specific numbers** (X slots left)
- Add **clear CTAs** ("Apply Now")
- Use **emojis sparingly** for visual distinction

### Throttling
- Don't send same threshold twice within 4 hours
- Only escalate (80% → 90% → 95% → 99%), never downgrade
- Batch emails (50 at a time) to avoid SMTP limits

## Future Enhancements

- [ ] SMS notifications for critical thresholds
- [ ] Customizable thresholds per municipality
- [ ] A/B test notification messages
- [ ] Predict slot fill rate and send proactive alerts
- [ ] Admin dashboard for threshold monitoring
- [ ] Historical analytics (conversion rate per threshold)

## Support

For issues or questions:
- Check logs: `check_slot_thresholds.php` output
- Review database: `slot_threshold_notifications` table
- Contact system administrator

---

**Last Updated:** November 1, 2025  
**Version:** 1.0  
**Maintained by:** EducAid Development Team
