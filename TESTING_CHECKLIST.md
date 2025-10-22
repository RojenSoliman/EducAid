# Multi-Account Prevention System - Testing Checklist

## ✅ Code Integration Status

### 1. Database Schema Files
- ✅ `create_school_student_id_schema.sql` - Complete with university_name, first_name, last_name columns
- ✅ `add_student_info_columns.sql` - Migration script for existing installations
- ✅ `verify_multi_account_prevention.sql` - Verification tests (10 tests)

### 2. PHP Backend (student_register.php)
- ✅ **Line 575-576**: AJAX request detection for `check_school_student_id`
- ✅ **Lines 749-839**: AJAX handler with duplicate checking and identity matching
- ✅ **Lines 2958-2989**: Final validation before INSERT with duplicate check
- ✅ **Line 2982**: INSERT query includes `school_student_id` column
- ✅ **Line 3001**: `school_student_id` variable passed to INSERT

### 3. HTML Form (student_register.php)
- ✅ **Line 3498**: Input field `<input type="text" id="schoolStudentId" name="school_student_id" required>`
- ✅ **Line 3505**: Duplicate warning div `#schoolStudentIdDuplicateWarning`
- ✅ **Line 3509**: Available message div `#schoolStudentIdAvailable`

### 4. JavaScript (student_register.php)
- ✅ **Line 6028**: Function call `setupSchoolStudentIdCheck()`
- ✅ **Lines 6050-6089**: Setup function with event listeners
- ✅ **Lines 6095-6170**: AJAX check function with identity matching

## 🧪 Pre-Testing Requirements

### Install Database Schema
Run ONE of these commands:

**Option A: Fresh Installation**
```powershell
cd c:\xampp\htdocs\EducAid
psql -U postgres -d educaid -f create_school_student_id_schema.sql
```

**Option B: Update Existing Schema**
```powershell
cd c:\xampp\htdocs\EducAid
psql -U postgres -d educaid -f add_student_info_columns.sql
```

### Verify Installation
```powershell
psql -U postgres -d educaid -f verify_multi_account_prevention.sql
```

**Expected Output:**
```
✓ Test 1: PASS - All tables exist
✓ Test 2: PASS - View exists
✓ Test 3: PASS - Functions exist (found 3)
✓ Test 4: PASS - Trigger exists
✓ Test 5: PASS - Column exists
✓ Test 6: PASS - Unique index exists
✓ Test 7: PASS - Function works correctly
✓ Test 8: PASS - Foreign keys exist
✓ Test 9: PASS - Audit columns exist
✓ Test 10: Testing trigger functionality
```

## 📋 Test Scenarios

### Test 1: Normal Registration (First Time)
**Steps:**
1. Open: `http://localhost/EducAid/modules/student/student_register.php`
2. **Step 1 - Personal Information:**
   - First Name: `Juan`
   - Middle Name: `Dela`
   - Last Name: `Cruz`
   - Birthdate: `2000-01-01`
   - Sex: `Male`
   - Click Next

3. **Step 2 - Contact Information:**
   - Email: `juan.delacruz@example.com`
   - Mobile: `09123456789`
   - Password: `Test@123456`
   - Click Next

4. **Step 3 - School Information:**
   - Select Municipality: `General Santos City`
   - Select Barangay: `Any barangay`
   - Select University: `Notre Dame of Dadiangas University`
   - **School Student ID: `2024-TEST001`**
   - Wait 800ms (debounce)
   - ✅ **Expected:** Green message "✓ Available: This school student ID is not registered yet"
   - ✅ **Expected:** Next button ENABLED
   - Select Year Level: `1st Year`
   - Click Next

5. Continue through remaining steps
6. Submit registration

**Verification:**
```sql
-- Check students table
SELECT student_id, first_name, last_name, school_student_id, university_id, status
FROM students 
WHERE school_student_id = '2024-TEST001';

-- Check tracking table
SELECT id, school_student_id, university_name, first_name, last_name, status, registered_at
FROM school_student_ids 
WHERE school_student_id = '2024-TEST001';

-- Check audit log
SELECT school_student_id, action, new_value, performed_at
FROM school_student_id_audit 
WHERE school_student_id = '2024-TEST001';
```

### Test 2: Duplicate School ID - Same University
**Steps:**
1. Start NEW registration
2. Complete Steps 1-2 with DIFFERENT personal info:
   - First Name: `Maria`
   - Last Name: `Santos`
   - Email: `maria.santos@example.com`
   - Mobile: `09987654321`

3. **Step 3:**
   - Select SAME University: `Notre Dame of Dadiangas University`
   - **School Student ID: `2024-TEST001`** (DUPLICATE)
   - Wait 800ms

**Expected Results:**
- ❌ Red warning appears: "School Student ID Already Registered!"
- Shows details:
  - Registered to: Juan Dela Cruz
  - System ID: [generated ID]
  - Status: under_registration
  - Registered on: [date]
- Message: "⚠️ This school student ID belongs to another person"
- Message: "🛑 MULTIPLE ACCOUNTS PROHIBITED"
- **Next button DISABLED** (gray)
- System notifier shows: "🛑 MULTIPLE ACCOUNT DETECTED"

### Test 3: Same Person Trying Multiple Accounts (Identity Match)
**Steps:**
1. Start NEW registration
2. **Step 1:** Use SAME name and birthdate as Test 1:
   - First Name: `Juan`
   - Last Name: `Cruz`
   - Birthdate: `2000-01-01`

3. **Step 2:** Different contact:
   - Email: `juan.cruz.alt@example.com`
   - Mobile: `09111222333`

4. **Step 3:**
   - Same University: `Notre Dame of Dadiangas University`
   - **School Student ID: `2024-TEST001`** (DUPLICATE)
   - Wait 800ms

**Expected Results:**
- ❌ Red warning with IDENTITY MATCH detected
- Shows: "⚠️ This appears to be YOUR existing account"
- Shows hints:
  - Email: juan.delac...@example.com
  - Mobile: 0912345...
- Message: "🛑 MULTIPLE ACCOUNTS PROHIBITED"
- Message: "You cannot create multiple accounts. Please login with your existing credentials."
- **Next button DISABLED**

### Test 4: Different University - Same School ID (Should Work)
**Steps:**
1. Start NEW registration
2. Complete Steps 1-2 with NEW person info
3. **Step 3:**
   - Select DIFFERENT University: `Mindanao State University - GenSan`
   - **School Student ID: `2024-TEST001`** (Same ID, different university)
   - Wait 800ms

**Expected Results:**
- ✅ Green message: "Available: This school student ID is not registered yet"
- **Next button ENABLED**
- Can proceed with registration
- Reason: School IDs are unique PER UNIVERSITY, not globally

### Test 5: Real-time Validation
**Steps:**
1. Go to Step 3
2. Select university
3. **Start typing slowly:** `2024-T`
4. **Continue typing:** `2024-TE`
5. **Complete:** `2024-TEST001`

**Expected Behavior:**
- Each keystroke resets 800ms timer
- Only after 800ms of no typing, AJAX request fires
- Prevents excessive server requests
- Loading indicator shows during check

## 🔍 What to Check

### Browser Console (F12)
```javascript
// Should see these logs:
"Checking school student ID: 2024-TEST001 for university: 1"
"Duplicate check result: {status: 'duplicate', ...}"
// or
"Duplicate check result: {status: 'available'}"
```

### Network Tab (F12)
- POST request to `student_register.php`
- Form data includes:
  - `check_school_student_id: 1`
  - `school_student_id: 2024-TEST001`
  - `university_id: 1`
  - `first_name: Juan`
  - `last_name: Cruz`
  - `bdate: 2000-01-01`
- Response JSON:
  ```json
  {
    "status": "duplicate",
    "details": {
      "name": "Juan Dela Cruz",
      "system_student_id": "GENSAN-2024-1-ABC123",
      "status": "under_registration",
      "registered_at": "2025-10-20 04:00:00",
      "identity_match": true,
      "email_hint": "juan.delac...@example.com",
      "mobile_hint": "0912345..."
    }
  }
  ```

### Database Verification Queries

**Check trigger fired:**
```sql
SELECT COUNT(*) FROM school_student_ids;
-- Should increase after each successful registration
```

**Check data integrity:**
```sql
SELECT 
    ssi.school_student_id,
    ssi.university_name,
    ssi.first_name || ' ' || ssi.last_name as stored_name,
    s.first_name || ' ' || s.last_name as student_name,
    CASE 
        WHEN ssi.first_name = s.first_name AND ssi.last_name = s.last_name 
        THEN '✓ Match' 
        ELSE '✗ Mismatch' 
    END as data_integrity
FROM school_student_ids ssi
JOIN students s ON ssi.student_id = s.student_id;
```

**Find duplicates:**
```sql
SELECT * FROM v_school_student_id_duplicates;
-- Should be empty if no duplicates exist
```

**Audit trail:**
```sql
SELECT 
    school_student_id,
    action,
    new_value,
    to_char(performed_at, 'YYYY-MM-DD HH24:MI:SS') as timestamp,
    ip_address
FROM school_student_id_audit
ORDER BY performed_at DESC
LIMIT 20;
```

## ❌ Common Issues & Solutions

### Issue 1: "Function check_duplicate_school_student_id does not exist"
**Solution:** Run the schema installation script
```powershell
psql -U postgres -d educaid -f create_school_student_id_schema.sql
```

### Issue 2: No warning message appears
**Check:**
1. Browser console for JavaScript errors
2. Network tab - is AJAX request being sent?
3. PHP error logs: `c:\xampp\apache\logs\error.log`

### Issue 3: Warning shows but Next button not disabled
**Fix:** Check line 6129-6133 in student_register.php:
```javascript
if (nextBtn) {
    nextBtn.disabled = true;
    nextBtn.classList.remove('btn-primary');
    nextBtn.classList.add('btn-secondary');
}
```

### Issue 4: Duplicate allowed to register
**Check:**
1. Lines 2968-2978: Final validation before INSERT
2. Verify trigger is active:
```sql
SELECT * FROM information_schema.triggers 
WHERE trigger_name = 'trigger_track_school_student_id';
```

## ✅ Success Criteria

All these should work:
- ✅ Fresh registration saves school_student_id
- ✅ Real-time duplicate detection (800ms debounce)
- ✅ Visual feedback (green/red messages)
- ✅ Next button disabled on duplicate
- ✅ Identity matching detects same person
- ✅ System notifier shows warnings
- ✅ Database trigger populates tracking table
- ✅ Audit log records all checks
- ✅ Different universities can use same ID
- ✅ Same university cannot reuse ID
- ✅ Final validation prevents database insert

## 📊 Performance Expectations

- **Debounce delay:** 800ms (configurable)
- **AJAX response time:** < 500ms
- **Database query time:** < 100ms
- **No excessive requests:** Max 1 request per 800ms typing

## 🎯 Ready to Test!

Your code is **READY FOR TESTING**! All components are in place:
1. ✅ Database schema complete
2. ✅ PHP backend handlers integrated
3. ✅ HTML form fields present
4. ✅ JavaScript validation active
5. ✅ Multi-layer prevention implemented

Just run the schema installation and start testing! 🚀
