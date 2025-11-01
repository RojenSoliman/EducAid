feat: Implement Active Sessions Management for Student Accounts

Added comprehensive session tracking and management system allowing students 
to view and control all devices logged into their account. This enhances 
security for students using shared computers (internet cafes, computer labs).

## Features Added

### Backend Session Tracking
- Created `student_login_history` table to track all login attempts
- Created `student_active_sessions` table to track currently active sessions
- Implemented `SessionManager` class with full CRUD operations for sessions
- Implemented `UserAgentParser` class for device/browser/OS detection
- Auto-cleanup of expired sessions (24-hour inactivity timeout)
- 1% random cleanup trigger on page loads to prevent stale sessions

### Login Integration
- Integrated session logging into `unified_login.php`
- Tracks successful logins with device info, IP, browser, OS
- Tracks failed login attempts with failure reasons
- Records login method (OTP, password, manual)

### Active Sessions UI
- Added "Active Sessions" section to student settings page
- Minimalist, mobile-first responsive design
- Displays all active sessions with device icons (laptop/phone/tablet)
- Shows browser, OS, IP address, and last activity timestamp
- Green highlight for current device with "Current Device" badge
- Individual "Sign Out" button for each non-current session
- "Sign Out All Other Devices" emergency button with confirmation

### Session Activity Tracking
- Auto-updates session activity on every student page load
- Marks sessions as current/non-current based on active session
- Tracks session creation time and expiration (24 hours)

## Database Changes

### New Tables
- `student_login_history`: Comprehensive login attempt tracking
- `student_active_sessions`: Currently active session management

### Type Fix
- Fixed student_id type mismatch (students table uses VARCHAR/TEXT)
- Migrated session tables from INT to VARCHAR(50) for student_id
- Recreated foreign key constraints with correct types

## Files Created
- `sql/create_login_history_tables.sql` - Database schema
- `includes/UserAgentParser.php` - Device detection utility
- `includes/SessionManager.php` - Session management class
- `includes/student_session_tracker.php` - Activity tracking include
- `fix_student_id_type_mismatch.sql` - Type migration script
- `modules/student/create_session.php` - Manual session creation tool

## Files Modified
- `unified_login.php` - Added session logging on login/failure
- `modules/student/student_settings.php` - Added Active Sessions UI and handlers
- `modules/student/student_homepage.php` - Added session tracker include

## Security Enhancements
- Students can detect unauthorized access by viewing active sessions
- Remote session revocation for forgotten logouts on shared computers
- Bulk session revocation for emergency account security
- Session expiration after 24 hours of inactivity
- IP address and device tracking for audit trail

## UI/UX Features
- Bootstrap icons for device types and browsers
- Responsive design for mobile/tablet/desktop
- Flash messages for user feedback on actions
- Inline confirmation for bulk sign-out action
- Minimalist design to avoid overwhelming users

## Testing
- Verified session creation on successful login
- Tested individual session revocation
- Tested bulk session revocation
- Confirmed session activity updates on page load
- Validated responsive design on mobile devices

## Target Audience
Filipino students often using shared computers in internet cafes and 
computer labs. This feature provides actionable security controls without 
technical complexity.

## Future Enhancements (Optional)
- Email notifications on new device login
- Login History tab showing past login attempts
- Geographic location display (city/country)
- Suspicious activity detection and alerts
- Account activity log for password/email changes

---

Co-authored-by: GitHub Copilot
