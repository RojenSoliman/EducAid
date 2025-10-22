# 🔍 DEBUG: Grades Upload Test

## What I Added

I've added SUPER VISIBLE debugging at multiple points:

### 1. **JavaScript Load Check** ✅
- Logs immediately when the script loads
- Shows in console with `============` borders

### 2. **DOMContentLoaded Check** ✅
- Logs when page is ready
- Shows all form elements (form, grades input, submit button)
- Verifies grades input is INSIDE the form

### 3. **Submit Button Click Handler** ✅
- Logs when button is clicked BEFORE form submits
- Shows button state and file count at click time

### 4. **Form Submit Handler** ✅
- **Shows ALERT popup** saying "Form submit handler triggered!"
- Logs detailed file information
- Shows file name, size, type if grades file is selected

### 5. **PHP Backend Check** ✅
- Logs immediately when POST is received
- Shows if grades_file is in $_FILES
- Shows error code, file name, file size

---

## 📋 Testing Steps

### Step 1: Open Browser Console
1. Open `http://localhost/EducAid/modules/student/upload_document.php`
2. Press **F12** to open Developer Tools
3. Click **Console** tab
4. **Clear the console** (trash can icon)

### Step 2: Check Initial Logs
You should IMMEDIATELY see:
```
============================================
UPLOAD_DOCUMENT.PHP: JavaScript file loaded!
Timestamp: 2025-10-20T...
============================================
```

Then shortly after:
```
============================================
DOMContentLoaded event fired!
Checking form elements...
Form found: true
Grades input found: true
Submit button found: true
Form ID: uploadForm
Form action: http://localhost/EducAid/modules/student/upload_document.php
Form method: post
Form enctype: multipart/form-data
Grades input ID: grades_input
Grades input name: grades_file
Grades input type: file
Grades input is inside form: true
============================================
```

**❌ If you DON'T see these logs:**
- JavaScript file failed to load
- Check browser console for red errors
- Check network tab for failed script loads

---

### Step 3: Select Grades File
1. Click "Choose file" for Academic Grades
2. Select a JPG or PDF file
3. Watch the file name appear below the input

---

### Step 4: Click Submit Button
1. Click the "Submit Documents" button
2. **Watch for these in order:**

#### A) Submit Button Click Log
```
╔════════════════════════════════════════╗
║   SUBMIT BUTTON CLICKED!!!            ║
╚════════════════════════════════════════╝
Button element: <button>...
Grades files at click time: 1
Grades file name: my_grades.jpg
```

#### B) Alert Popup
You should see: **"🔍 DEBUG: Form submit handler triggered! Check console for details."**

#### C) Form Submit Log
```
╔════════════════════════════════════════╗
║   FORM SUBMIT EVENT TRIGGERED!!!      ║
╚════════════════════════════════════════╝
Event object: SubmitEvent {...}
★★★ GRADES FILE DETAILS ★★★
File name: my_grades.jpg
File size: 245678
File type: image/jpeg
```

#### D) Form Validation Logs
```
Grades input found: true
Grades files selected: 1
Document inputs found: 4
Documents files selected total: 0
EAF input found: true
EAF files selected: 0
Form validation - hasGrades: true, hasDocuments: false, hasEaf: false
Form validation passed, submitting...
```

---

### Step 5: Check PHP Logs
After the page refreshes, run:
```powershell
Get-Content "C:\xampp\apache\logs\error.log" -Tail 50
```

You should see:
```
========================================
UPLOAD_DOCUMENT.PHP: POST REQUEST RECEIVED!
Timestamp: 2025-10-20 14:30:45
documents isset: NO
grades_file isset: YES
eaf_file isset: NO
confirm_uploads isset: NO
grades_file ERROR CODE: 0
grades_file NAME: my_grades.jpg
grades_file SIZE: 245678
========================================
```

---

## 🎯 What Each Scenario Means

### Scenario A: NO JavaScript logs at all
**Problem:** JavaScript file not loading or browser blocking it
**Fix:** Check browser console for errors, check file path

### Scenario B: JavaScript logs but NO click/submit logs
**Problem:** Event handlers not attaching or button/form not found
**Fix:** Check if elements exist (logs will show "found: false")

### Scenario C: Click log but NO submit log
**Problem:** Button click not triggering form submit
**Fix:** Check if button type is "submit" (should be by default)

### Scenario D: Submit log but NO alert popup
**Problem:** Alert code not reached (form prevented before alert)
**Fix:** Check if validation failing earlier

### Scenario E: Alert shown but NO PHP logs
**Problem:** Form submitting but POST not reaching PHP
**Fix:** Check form action URL, check Apache error log for PHP errors

### Scenario F: PHP logs show `grades_file isset: NO`
**Problem:** File not being sent in POST request
**Fix:** Check form enctype, check if input is disabled/removed

### Scenario G: PHP logs show `ERROR CODE: 4`
**Problem:** No file selected (UPLOAD_ERR_NO_FILE)
**Fix:** File input cleared before submit

### Scenario H: PHP logs show `ERROR CODE: 1` or `2`
**Problem:** File too large
**Fix:** Increase upload_max_filesize in php.ini

### Scenario I: Everything logs correctly
**Problem:** Upload successful but UI not showing it
**Fix:** Check session messages, check database

---

## 🔧 Next Steps Based on Results

**Please test now and tell me:**
1. ✅ Do you see JavaScript load logs?
2. ✅ Do you see DOMContentLoaded logs?
3. ✅ Do you see "Form found: true"?
4. ✅ Do you see "Grades input found: true"?
5. ✅ Do you see "Grades input is inside form: true"?
6. ✅ After selecting file, do you see the click log?
7. ✅ Do you see the ALERT popup?
8. ✅ Do you see the submit log?
9. ✅ Do you see validation logs?
10. ✅ Do you see PHP POST logs?

Tell me which numbers you see ✅ and which you DON'T see ❌, and I'll know exactly what's wrong!
