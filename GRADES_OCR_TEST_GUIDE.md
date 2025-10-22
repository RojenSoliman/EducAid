# Grades OCR Test Script Guide

## Overview
The `test_grades_ocr.php` script allows you to test the Grades OCR year level validation feature without going through the full registration form. It features a **web-based upload interface** for easy testing.

## Purpose
This test script validates that the system:
1. **Extracts text** from grade documents using Tesseract OCR
2. **Identifies year levels** in the extracted text
3. **Extracts ONLY the declared year section** (not cumulative)
4. **Counts subjects** within the extracted section
5. **Calculates confidence scores** based on section clarity

## Setup Instructions

### 1. Install Prerequisites
- **XAMPP** with PHP 7.4+ and Apache running
- **Tesseract OCR** installed at `C:/Program Files/Tesseract-OCR/tesseract.exe`

### 2. Run the Test Script
1. Open your browser
2. Navigate to: `http://localhost/EducAid/test_grades_ocr.php`
3. You'll see an upload form

### 3. Upload and Test
1. Click **"Select Grades Document Image"** and choose your file (JPG, PNG, or TIFF)
2. Select the **Declared Year Level** (1st, 2nd, 3rd, or 4th Year)
3. Optionally check **"Delete uploaded file after test"** (recommended)
4. Click **"🚀 Run OCR Test"**
5. Review the results

## Test Output Explained

### Section 1: Upload Form
- **File Input:** Select your grades document image
- **Year Level Dropdown:** Select the declared year (1st-4th)
- **Delete Option:** Auto-delete uploaded file after test (recommended)

### Section 2: Test Configuration
Shows:
- Uploaded filename
- Declared year level
- Image file size and type

### Section 3: OCR Text Extraction
Shows:
- Tesseract command executed
- Extraction success/failure
- Character count
- Text preview (first 500 characters)

### Section 4: Year Level Validation
Shows:
- **Validation Result:** PASSED or FAILED
- **Confidence Score:** 0-100%
- **Message:** Description of what was found
- **Subjects Found:** Count of subjects in the extracted section
- **Extracted Year Section:** The specific text section validated

### Section 5: Debug Information
Shows:
- Full OCR text length
- PHP version
- Memory usage
- Full OCR text (collapsible)

## Test Scenarios

### Scenario 1: Valid 3rd Year Document
**Setup:**
```php
$declaredYearLevel = '3rd Year';
$declaredYearNum = 3;
```

**Expected Result:**
- ✓ VALIDATION PASSED
- Confidence: 90-100%
- Message: "Found declared year level section with X subject(s)"
- Extracted section shows ONLY 3rd year subjects

**Document Should Contain:**
```
First Year (ignored ❌)
- Subject 1
- Subject 2

Second Year (ignored ❌)
- Subject 3
- Subject 4

Third Year (extracted ✅)
- Subject 5
- Subject 6
- Subject 7

Fourth Year (ignored ❌)
- Subject 8
```

### Scenario 2: Mismatched Year Level
**Setup:**
```php
$declaredYearLevel = '4th Year';
$declaredYearNum = 4;
```

**Expected Result:**
- ✗ VALIDATION FAILED (if document only has 1st-3rd year)
- Confidence: 0%
- Message: "Declared year level '4th Year' not found in document"

### Scenario 3: Multiple Year Levels Present
**Setup:**
```php
$declaredYearLevel = '2nd Year';
$declaredYearNum = 2;
```

**Expected Result:**
- ✓ VALIDATION PASSED
- Confidence: 90-100%
- Extracted section shows ONLY 2nd year (1st and 3rd year ignored)
- Subject count reflects ONLY 2nd year subjects

## Confidence Score Breakdown

| Score | Meaning |
|-------|---------|
| **0%** | Year level not found in document |
| **50%** | Year level marker found, but no subjects detected |
| **80%** | Year level found with 1-4 subjects |
| **95%** | Year level found with 5+ subjects |
| **100%** | Year level found with 5+ subjects and clear boundaries |

## Common Issues and Solutions

### Issue 1: Upload Error
**Error:** `File is too large` or `File was only partially uploaded`

**Solution:**
- Check your PHP settings in `php.ini`:
  - `upload_max_filesize = 10M`
  - `post_max_size = 10M`
- Restart Apache after changing `php.ini`

### Issue 2: Tesseract Not Found
**Error:** `Tesseract not found at default path`

**Solution:**
- Install Tesseract OCR
- Or update the `$tesseractPath` in the script

### Issue 3: Invalid File Type
**Error:** `Invalid File Type: Only JPG, PNG, and TIFF images are allowed`

**Solution:**
- Use supported formats: JPG, PNG, or TIFF
- Convert PDF to image first using online tools or Adobe Acrobat

### Issue 4: Upload Failed
**Error:** `Failed to save uploaded file`

**Solution:**
- Check folder permissions on `test_documents/` folder
- Ensure Apache has write permissions
- On Windows: Right-click folder → Properties → Security → Give IUSR/IIS_IUSRS write access

### Issue 5: Low Confidence Score
**Issue:** Confidence is 50% or lower

**Possible Causes:**
- Poor image quality
- Unclear text in the scan
- Subject codes/labels not detected

**Solutions:**
- Use higher resolution scans (300 DPI+)
- Ensure text is clear and not blurred
- Check that subjects have codes (e.g., CS101, MATH201)

### Issue 6: Wrong Section Extracted
**Issue:** System extracts wrong year level

**Debug Steps:**
1. Check the "Full OCR Text" in the test output
2. Verify year level markers are clearly visible
3. Check for ambiguous markers (e.g., "Year 3" appearing in subject descriptions)
4. Ensure clear spacing between year level sections

## Advanced Testing

### Test with Multiple Documents
Simply upload different files one at a time using the web interface. The script processes each upload independently.

### Test Different File Types
The script automatically accepts:
- **JPG/JPEG** - Most common format
- **PNG** - Good for screenshots
- **TIFF** - High quality scans

### Check Uploaded Files
Uploaded files are saved in the `test_documents/` folder with timestamps:
- `grades_test_1729468800.jpg`
- `grades_test_1729468900.png`

Use the **"Delete uploaded file after test"** checkbox to auto-cleanup.

### Benchmark Performance
Check the test output for:
- **Execution time:** Shown at test completion
- **Memory usage:** Displayed in debug information
- **OCR text length:** Indicates complexity of document

Large documents (5000+ characters) may take 3-5 seconds to process.

## Quick Start Guide

1. **Open test page:** `http://localhost/EducAid/test_grades_ocr.php`
2. **Upload file:** Click file input, select grades document
3. **Select year:** Choose 1st, 2nd, 3rd, or 4th Year
4. **Run test:** Click "🚀 Run OCR Test" button
5. **Review results:** Check validation passed/failed and confidence score
6. **View extracted section:** See exactly what text was validated
7. **Debug if needed:** Expand full OCR text to see what Tesseract extracted

## Integration with student_register.php

The `validateDeclaredYear()` function in this test script is **identical** to the one in `student_register.php` (lines 2559-2625).

Any changes made to the validation logic should be:
1. First tested in `test_grades_ocr.php`
2. Then applied to `student_register.php`
3. Verified in the full registration flow

## Files Reference

| File | Purpose |
|------|---------|
| `test_grades_ocr.php` | Standalone test script |
| `test_documents/` | Folder for test grade images |
| `student_register.php` | Production registration form with OCR |
| `GRADES_OCR_YEAR_LEVEL_FIX.md` | Documentation of the fix |

## Next Steps

After successful testing:
1. ✅ Verify test script works with sample documents
2. ✅ Test with real grade documents from different universities
3. ✅ Test all year levels (1st, 2nd, 3rd, 4th)
4. ✅ Test edge cases (poor quality, multiple formats)
5. ✅ Proceed to full registration flow testing

## Support

If you encounter issues:
1. Check the "Debug Information" section in test output
2. Review the full OCR text for accuracy
3. Verify Tesseract installation and paths
4. Test with different image quality settings
5. Consult `GRADES_OCR_YEAR_LEVEL_FIX.md` for feature details
