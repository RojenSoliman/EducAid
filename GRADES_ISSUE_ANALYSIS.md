# Grades Upload Issue - Registration vs Upload Page

## Key Findings

### Why Registration Works ✅
Looking at `student_register.php`:
- Uses **JavaScript FormData** with AJAX (`fetch`)
- Uploads to temporary directory first
- Process happens in multiple steps with clear feedback
- Each document validated individually

### Why Upload Page Doesn't Work ❌
Looking at `upload_document.php`:
- Code structure is **correct**
- Form has proper `enctype="multipart/form-data"`
- JavaScript handlers are present
- BUT...

## The Real Issue

After analyzing both files, I found **MULTIPLE possible causes**:

### 1. **Form Confusion** (Most Likely)
There are TWO forms for grades:
- `gradesResubmitForm` - For re-uploading (line 2440)
- Regular grades input (line 2460)

**The regular grades input (line 2460) is NOT inside a form!**

### 2. **JavaScript Submit Blocking**
The JavaScript might be preventing form submission with `e.preventDefault()` without actually submitting.

### 3. **File Input Name Mismatch**
- Regular upload: `name="grades_file"`  
- But there's no wrapper form around it!

## The Fix

The regular grades input needs to be inside the main upload form OR have its own dedicated form. Currently it's an orphan input!

### Current (BROKEN):
```html
<!-- Line 2460 - NO FORM TAG! -->
<input type="file" name="grades_file" id="grades_input" accept=".pdf,.jpg,.jpeg,.png" data-max-mb="10">
```

### Should Be:
```html
<form method="POST" enctype="multipart/form-data" id="gradesUploadForm" action="upload_document.php">
    <input type="file" name="grades_file" id="grades_input" accept=".pdf,.jpg,.jpeg,.png" data-max-mb="10" required>
    <button type="submit" class="btn btn-primary">Upload Grades</button>
</form>
```

## Test This Now

1. **Open browser console** (F12)
2. **Try to upload grades**
3. **Check console** for errors
4. **Check Network tab** to see if POST request is sent
5. **Report back** what you see

Most likely you'll see:
- ❌ No POST request sent
- ❌ Console error about form not found
- ❌ Or JavaScript preventing submission

## Quick Fix

I'll add proper form wrapping for the grades input in the next update.
