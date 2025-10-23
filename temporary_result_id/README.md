# Temporary ID Picture OCR Results

This folder stores temporary OCR verification results for ID Picture uploads (Step 4).

## Purpose

When a student clicks **"Verify Student ID"** in Step 4, the system:
1. Extracts text from the uploaded ID picture using Tesseract OCR
2. Validates the extracted information against form data
3. Saves the results here for debugging and audit purposes

## Files Generated

For each verification, **4 files** are created:

### 1. `{student_id}_{timestamp}_ocr_text.txt`
Raw text extracted from the ID picture by Tesseract OCR.

**Example:**
```
LYCEUM OF THE PHILIPPINES UNIVERSITY
CAVITE CAMPUS
STUDENT
SOLIMAN, Rojen P.
2022-2-00447
```

### 2. `{student_id}_{timestamp}_verification.json`
Complete verification results in JSON format.

**Contains:**
- Timestamp and student information
- OCR extraction details
- All validation checks (pass/fail)
- Confidence scores for each check
- Overall recommendation

**Example:**
```json
{
  "timestamp": "2025-10-23_14-30-45",
  "student_id": "STU-2024-001",
  "school_student_id": "2022-2-00447",
  "validation_checks": {
    "first_name_match": {
      "passed": true,
      "similarity": 85.5
    },
    "school_student_id_match": {
      "passed": true,
      "similarity": 100
    }
  },
  "overall_result": {
    "passed_checks": 5,
    "total_checks": 6,
    "recommendation": "APPROVED"
  }
}
```

### 3. `{student_id}_{timestamp}_report.txt`
Human-readable verification report.

**Example:**
```
=====================================
ID PICTURE OCR VERIFICATION REPORT
=====================================

Generated: October 23, 2025, 2:30 pm
Student ID: STU-2024-001
School Student ID: 2022-2-00447

-------------------------------------
VALIDATION CHECKS:
-------------------------------------

First Name Match               : ✓ PASS (85.5%)
Middle Name Match              : ✓ PASS (100%)
Last Name Match                : ✓ PASS (100%)
University Match               : ✓ PASS (80.0%)
Official Document Keywords     : ✓ PASS (100%)
School Student ID Number Match : ✓ PASS (100%)

-------------------------------------
OVERALL RESULT:
-------------------------------------

Passed Checks: 5 / 6
Status: ✓ APPROVED
Recommendation: Approve
```

### 4. `{student_id}_{timestamp}_image.{ext}`
Copy of the uploaded ID picture for reference.

## File Naming Convention

```
{student_id}_{timestamp}_{file_type}.{extension}
```

**Example:**
```
STU-2024-001_2025-10-23_14-30-45_ocr_text.txt
STU-2024-001_2025-10-23_14-30-45_verification.json
STU-2024-001_2025-10-23_14-30-45_report.txt
STU-2024-001_2025-10-23_14-30-45_image.jpg
```

## Retention Policy

⚠️ **TEMPORARY FILES**: These files are for debugging and short-term reference only.

- **Auto-cleanup**: Files older than 7 days should be automatically deleted
- **Manual cleanup**: Administrators can safely delete old files
- **Not for long-term storage**: Final results are stored in the database

## Privacy & Security

🔒 **IMPORTANT**: This folder contains sensitive student information:
- Personal names
- Student ID numbers
- ID pictures

**Security measures:**
- Folder is outside public web directory (not accessible via HTTP)
- `.gitignore` prevents committing to version control
- Restricted file permissions (755)
- Should not be backed up to public repositories

## Usage

### For Developers (Debugging)

Check OCR results after verification:

**PowerShell:**
```powershell
# View latest OCR text
Get-Content "temporary_result_id\*_ocr_text.txt" | Select-Object -Last 1

# View latest report
Get-Content "temporary_result_id\*_report.txt" | Select-Object -Last 1

# Parse JSON results
Get-Content "temporary_result_id\*_verification.json" | ConvertFrom-Json

# List all files for a student
Get-ChildItem "temporary_result_id\STU-2024-001_*"
```

### For Administrators (Audit Trail)

Review verification results:

**PowerShell:**
```powershell
# List all verification results (newest first)
Get-ChildItem "temporary_result_id\*_report.txt" | Sort-Object LastWriteTime -Descending

# View specific student's results
Get-ChildItem "temporary_result_id\STU-2024-001_*"

# Count total verifications today
(Get-ChildItem "temporary_result_id\*_verification.json" | 
    Where-Object { $_.LastWriteTime -ge (Get-Date).Date }).Count
```

## Cleanup Script

To manually clean old files (> 7 days):

**PowerShell:**
```powershell
# Delete files older than 7 days
$limit = (Get-Date).AddDays(-7)
Get-ChildItem "temporary_result_id\*" -Exclude "README.md",".gitignore" | 
    Where-Object { $_.LastWriteTime -lt $limit } | 
    Remove-Item -Force

Write-Host "Cleanup complete!"
```

## Troubleshooting

### No files being created?

1. **Check folder permissions:**
   ```powershell
   Test-Path "c:\xampp\htdocs\EducAid\temporary_result_id"
   ```

2. **Check Apache error log:**
   ```powershell
   Get-Content "C:\xampp\apache\logs\error.log" -Tail 50
   ```

3. **Look for "OCR Results Saved" message** in error log

### Files created but empty?

- Check that OCR extraction is successful
- Verify Tesseract is installed and working
- Review OCR text file to see what was extracted

### Permission denied errors?

Run PowerShell as Administrator and set permissions:
```powershell
$folder = "c:\xampp\htdocs\EducAid\temporary_result_id"
icacls $folder /grant "Everyone:(OI)(CI)F" /T
```

## Related Files

- **OCR Processing**: `modules/student/student_register.php` (lines 1300-1650)
- **Enhancement Docs**: 
  - `OCR_ENHANCEMENT_TIPS.md` - Tips for better OCR quality
  - `GRADES_OCR_SCHOOL_ID_ENHANCEMENT.md` - School ID validation docs
  - `SCHOOL_ID_OCR_VALIDATION.md` - ID Picture validation details

## What Gets Logged

Each verification saves:
- ✅ Raw OCR extracted text
- ✅ All 6 validation checks (pass/fail)
- ✅ Confidence scores (0-100%)
- ✅ Personal info from form
- ✅ Overall recommendation (APPROVED/NEEDS REVIEW)
- ✅ Copy of uploaded image

## Support

If you encounter issues with OCR results:

1. Check the `_ocr_text.txt` file to see what Tesseract extracted
2. Review the `_report.txt` for validation details
3. Examine the `_image.{ext}` to verify image quality
4. Check confidence scores in `_verification.json`

For OCR quality issues, see: **`OCR_ENHANCEMENT_TIPS.md`**

---

**Last Updated:** October 23, 2025  
**Version:** 1.0  
**Triggered By:** "Verify Student ID" button in Step 4 (ID Picture Upload)
