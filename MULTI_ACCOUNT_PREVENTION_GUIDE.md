# Multi-Account Prevention System

## Overview
This system prevents students from creating multiple accounts by tracking school-issued student IDs across universities. It includes real-time duplicate detection, database-level constraints, and comprehensive audit logging.

## Key Features

### 1. **Dual ID System**
- **`student_id`**: System-generated unique identifier (e.g., `GENSAN-2024-1-ABC123`)
  - Format: `<MUNICIPALITY>-<YEAR>-<YEARLEVEL>-<RANDOM6>`
  - Used internally for all system operations
  - Unique across the entire system

- **`school_student_id`**: University/School-issued ID number (e.g., `2024-12345`)
  - Entered by student during registration
  - Unique per university (same number can exist in different schools)
  - Verified against ID picture during document validation

### 2. **Multi-Layer Prevention**

#### Layer 1: Real-Time Duplicate Detection
- Checks database as user types their school student ID
- Shows immediate feedback (available/duplicate)
- Blocks navigation if duplicate detected
- Provides detailed information about existing account

#### Layer 2: Identity Matching
- Cross-references name and birthdate
- Detects if same person is trying to re-register
- Provides different messages for:
  - Same person (your existing account)
  - Different person (someone else's ID)

#### Layer 3: Database Constraints
```sql
-- Composite unique index prevents duplicates at DB level
CREATE UNIQUE INDEX idx_unique_school_student_id 
ON students(university_id, school_student_id);
```

#### Layer 4: Final Validation
- Double-checks before account creation
- Uses database function for consistency
- Prevents race conditions

#### Layer 5: Audit Trail
- Every registration is logged with:
  - University ID
  - School student ID
  - System student ID
  - Timestamp
  - IP address
  - Action type

## Database Schema

### Tables Created

#### 1. `school_student_ids`
Tracks all registered school student IDs per university.

| Column | Type | Description |
|--------|------|-------------|
| id | SERIAL | Primary key |
| university_id | INTEGER | Reference to universities table |
| student_id | VARCHAR(50) | System student ID (FK to students) |
| school_student_id | VARCHAR(50) | School-issued ID number |
| registered_at | TIMESTAMP | Registration timestamp |
| status | VARCHAR(50) | active/inactive/suspended |
| notes | TEXT | Optional notes |

**Unique Constraint**: `(university_id, school_student_id)`

#### 2. `school_student_id_audit`
Audit log for all changes and registrations.

| Column | Type | Description |
|--------|------|-------------|
| audit_id | SERIAL | Primary key |
| university_id | INTEGER | University ID |
| student_id | VARCHAR(50) | System student ID |
| school_student_id | VARCHAR(50) | School student ID |
| action | VARCHAR(50) | register/update/deactivate |
| old_value | TEXT | Previous value (if update) |
| new_value | TEXT | New value |
| performed_by | VARCHAR(100) | Who performed action |
| performed_at | TIMESTAMP | When action occurred |
| ip_address | VARCHAR(50) | IP address of request |
| notes | TEXT | Additional notes |

### Views

#### `v_school_student_id_duplicates`
Shows all duplicate registrations across the system.

```sql
SELECT * FROM v_school_student_id_duplicates;
```

Returns:
- university_name
- school_student_id
- registration_count (how many times registered)
- system_student_ids (array of all system IDs)
- student_names (array of all registered names)
- statuses (array of all account statuses)
- first_registered (earliest registration date)
- last_registered (most recent registration date)

### Functions

#### `check_duplicate_school_student_id(p_university_id, p_school_student_id)`
Checks if a school student ID is already registered.

**Parameters:**
- `p_university_id` (INTEGER): University to check
- `p_school_student_id` (VARCHAR): School ID to verify

**Returns:** Table with:
- is_duplicate (BOOLEAN)
- system_student_id (VARCHAR)
- student_name (TEXT)
- student_email (TEXT)
- student_mobile (TEXT)
- student_status (TEXT)
- registered_at (TIMESTAMP)

**Example:**
```sql
SELECT * FROM check_duplicate_school_student_id(1, '2024-12345');
```

#### `get_school_student_ids(p_university_id)`
Returns all registered school IDs for a university.

**Parameters:**
- `p_university_id` (INTEGER): University ID

**Returns:** Table with:
- school_student_id
- system_student_id
- student_name
- status
- registered_at

**Example:**
```sql
SELECT * FROM get_school_student_ids(1);
```

### Triggers

#### `trigger_track_school_student_id`
Automatically tracks school student IDs when a student registers.

**Fires:** AFTER INSERT on `students` table

**Actions:**
1. Inserts record into `school_student_ids` table
2. Logs registration in `school_student_id_audit` table
3. Records IP address and timestamp

## Installation

### Step 1: Run SQL Schema
```bash
# From XAMPP directory
cd c:\xampp\htdocs\EducAid
psql -U postgres -d educaid -f create_school_student_id_schema.sql
```

Or manually in pgAdmin:
1. Open pgAdmin
2. Connect to your database
3. Open Query Tool
4. Load `create_school_student_id_schema.sql`
5. Execute (F5)

### Step 2: Verify Tables
```sql
-- Check if tables exist
SELECT table_name 
FROM information_schema.tables 
WHERE table_schema = 'public' 
  AND table_name LIKE '%school_student%';

-- Should return:
-- school_student_ids
-- school_student_id_audit
```

### Step 3: Test Functions
```sql
-- Test duplicate check (should return no duplicates initially)
SELECT * FROM check_duplicate_school_student_id(1, 'TEST-001');

-- Should return: is_duplicate = false
```

## User Experience

### Registration Flow

#### Step 3: University & Student ID
1. User selects university
2. User enters school student ID
3. System checks for duplicates (800ms debounce)
4. **If available**: Green checkmark, can proceed
5. **If duplicate**: Red warning, cannot proceed

#### Warning Messages

**If same person detected:**
```
⚠️ School Student ID Already Registered!
Registered to: Juan Dela Cruz
System ID: GENSAN-2024-1-ABC123
Status: under_registration
Registered on: 10/20/2024

⚠️ This appears to be YOUR existing account.
Email: jua***@gmail.com
Mobile: 0912***89

🛑 MULTIPLE ACCOUNTS PROHIBITED
You cannot create multiple accounts. Please login with your existing credentials.
```

**If different person detected:**
```
⚠️ School Student ID Already Registered!
Registered to: Maria Santos
System ID: GENSAN-2024-2-XYZ789
Status: approved

⚠️ This school student ID belongs to another person.
🛑 MULTIPLE ACCOUNTS PROHIBITED
Creating multiple accounts is strictly prohibited and may result in permanent disqualification.
```

## Admin Management

### View All Duplicates
```sql
-- See all duplicate registrations
SELECT * FROM v_school_student_id_duplicates;
```

### Check Specific Student ID
```sql
-- Check if a specific school ID is registered
SELECT * FROM check_duplicate_school_student_id(1, '2024-12345');
```

### View University's Registered IDs
```sql
-- Get all school IDs for a university
SELECT * FROM get_school_student_ids(1);
```

### View Audit Log
```sql
-- See recent registrations
SELECT * 
FROM school_student_id_audit 
WHERE action = 'register'
ORDER BY performed_at DESC 
LIMIT 20;
```

### Deactivate Duplicate Account
```sql
-- If you find a duplicate and need to deactivate it
UPDATE school_student_ids 
SET status = 'inactive', 
    notes = 'Duplicate account - deactivated by admin'
WHERE school_student_id = '2024-12345' 
  AND university_id = 1;

-- Log the action
INSERT INTO school_student_id_audit (
    university_id, student_id, school_student_id, 
    action, notes, performed_by
) VALUES (
    1, 'GENSAN-2024-1-ABC123', '2024-12345',
    'deactivate', 'Duplicate account', 'admin_username'
);
```

## API Endpoints

### Check School Student ID
**Endpoint:** `POST /modules/student/student_register.php`

**Parameters:**
```json
{
  "check_school_student_id": "1",
  "school_student_id": "2024-12345",
  "university_id": "1",
  "first_name": "Juan",      // Optional - for identity matching
  "last_name": "Dela Cruz",  // Optional - for identity matching
  "bdate": "2000-01-01"      // Optional - for identity matching
}
```

**Response (Available):**
```json
{
  "status": "available",
  "message": "School student ID is available"
}
```

**Response (Duplicate):**
```json
{
  "status": "duplicate",
  "message": "This school student ID number is already registered in our system.",
  "details": {
    "system_student_id": "GENSAN-2024-1-ABC123",
    "name": "Juan Dela Cruz",
    "status": "approved",
    "email_hint": "jua***@gmail.com",
    "mobile_hint": "0912***89",
    "registered_at": "2024-10-20 14:30:00",
    "identity_match": true,
    "match_details": {
      "name_match": true,
      "bdate_match": true,
      "message": "This appears to be your existing account."
    },
    "can_reapply": false
  }
}
```

## Security Features

### 1. Database-Level Protection
- Unique constraints prevent duplicates even if frontend bypassed
- Composite index on (university_id, school_student_id)
- Foreign key constraints maintain data integrity

### 2. Audit Trail
- Every action logged with timestamp and IP
- Cannot be deleted by regular users
- Provides forensic evidence if needed

### 3. Real-Time Validation
- Prevents duplicate submission before form completion
- Reduces server load by catching duplicates early
- Better user experience with immediate feedback

### 4. Identity Cross-Reference
- Matches name and birthdate to detect re-registration
- Different messages for same person vs. different person
- Helps legitimate users who forgot they already registered

## Troubleshooting

### Issue: Duplicate check not working

**Check 1: Database function exists**
```sql
SELECT routine_name 
FROM information_schema.routines 
WHERE routine_name = 'check_duplicate_school_student_id';
```

**Check 2: AJAX handler registered**
```php
// In student_register.php around line 572
$isAjaxRequest = ... || isset($_POST['check_school_student_id']);
```

**Check 3: JavaScript function defined**
```javascript
// Check browser console
console.log(typeof checkSchoolStudentIdDuplicate);
// Should output: "function"
```

### Issue: Trigger not firing

**Check trigger exists:**
```sql
SELECT trigger_name, event_manipulation, event_object_table 
FROM information_schema.triggers 
WHERE trigger_name = 'trigger_track_school_student_id';
```

**Test trigger:**
```sql
-- Insert test record
INSERT INTO students (
    student_id, municipality_id, first_name, last_name, 
    university_id, school_student_id, email, mobile, 
    password, sex, bdate
) VALUES (
    'TEST-001', 1, 'Test', 'User', 1, 'TEST-SCHOOLID-001',
    'test@test.com', '09123456789', 'hashed_password', 
    'Male', '2000-01-01'
);

-- Check if tracked
SELECT * FROM school_student_ids WHERE school_student_id = 'TEST-SCHOOLID-001';

-- Cleanup
DELETE FROM students WHERE student_id = 'TEST-001';
```

### Issue: False positives

If legitimate users are being blocked:

1. **Check for data entry errors**
   ```sql
   -- Look for similar IDs
   SELECT school_student_id, COUNT(*) 
   FROM school_student_ids 
   GROUP BY school_student_id 
   HAVING COUNT(*) > 1;
   ```

2. **Verify university assignment**
   ```sql
   -- Ensure students are assigned correct university
   SELECT s.student_id, s.school_student_id, u.name as university
   FROM students s
   JOIN universities u ON s.university_id = u.university_id
   WHERE s.school_student_id = 'PROBLEMATIC-ID';
   ```

3. **Manual override (if needed)**
   ```sql
   -- Deactivate old registration
   UPDATE school_student_ids 
   SET status = 'inactive' 
   WHERE school_student_id = 'ID' AND student_id = 'OLD-SYSTEM-ID';
   ```

## Maintenance

### Weekly Tasks
```sql
-- Check for new duplicates
SELECT * FROM v_school_student_id_duplicates 
WHERE last_registered > NOW() - INTERVAL '7 days';

-- Review recent registrations
SELECT COUNT(*), university_id 
FROM school_student_id_audit 
WHERE action = 'register' 
  AND performed_at > NOW() - INTERVAL '7 days'
GROUP BY university_id;
```

### Monthly Tasks
```sql
-- Archive old audit logs (keep last 12 months)
DELETE FROM school_student_id_audit 
WHERE performed_at < NOW() - INTERVAL '12 months';

-- Analyze duplicate patterns
SELECT 
    u.name,
    COUNT(*) as total_duplicates
FROM v_school_student_id_duplicates d
JOIN universities u ON u.name = d.university_name
GROUP BY u.name
ORDER BY total_duplicates DESC;
```

## Best Practices

### For Users
1. Always enter your school ID exactly as shown on your ID card
2. Include dashes, hyphens, or spaces if present
3. If you see a duplicate warning and it's your account, login instead
4. Contact support if you believe the warning is incorrect

### For Admins
1. Regularly review duplicate reports
2. Investigate suspicious patterns (multiple attempts from same IP)
3. Keep audit logs for at least 12 months
4. Document any manual overrides with detailed notes

## Support

### Common Questions

**Q: Can students from different universities have the same school ID?**
A: Yes, the system allows this. Uniqueness is enforced per university, not globally.

**Q: What if a student transfers universities?**
A: They would need a new system account with their new university's student ID.

**Q: Can admin manually add school IDs?**
A: Yes, but it's not recommended. Use the registration form to maintain audit trail.

**Q: How to handle rejected/disqualified students who want to reapply?**
A: The system detects this and shows appropriate message. They can reapply through their existing account.

## Version History

- **v1.0** (2024-10-20): Initial implementation
  - School student ID tracking
  - Real-time duplicate detection
  - Database schema with triggers
  - Audit logging
  - Admin views and functions

## Files Modified

- `create_school_student_id_schema.sql` - Database schema
- `modules/student/student_register.php` - Registration form with duplicate checking
- `MULTI_ACCOUNT_PREVENTION_GUIDE.md` - This documentation

---

**Last Updated:** October 20, 2024
**Maintained By:** EducAid Development Team
