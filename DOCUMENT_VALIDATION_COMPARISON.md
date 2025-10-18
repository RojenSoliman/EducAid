# Document Validation Comparison

## Current State Analysis

This document compares the validation structures between `student_register.php` (registration flow) and `upload_document.php` (post-registration upload flow) for all document types.

---

## 1. EAF (Enrollment Assessment Form)

### ✅ student_register.php (Lines 200-480)
- **Structure:** 6 checks with `.verify.json` creation
- **Checks:**
  1. `first_name_match` (≥80% similarity)
  2. `middle_name_match` (≥70% similarity, auto-pass if empty)
  3. `last_name_match` (≥80% similarity)
  4. `year_level_match` (exact variations like "1st year", "freshman")
  5. `university_match` (≥60% word match)
  6. `document_keywords_found` (≥3 keywords: enrollment, assessment, form, official, academic, student, tuition, fees, semester, etc.)
- **Files Created:**
  - `enrollment_confidence.json` ✅
  - `.verify.json` ✅
  - `.ocr.txt` ✅
- **File Copying:** ✅ Lines 2629-2645 (copies .verify.json and .ocr.txt during registration)

### ✅ upload_document.php (Not implemented - EAF only uploaded during registration)
- **Status:** N/A - EAF is not uploaded via upload_document.php

---

## 2. ID Picture

### ❌ student_register.php
- **Status:** NOT IMPLEMENTED - ID Picture OCR not processed during registration
- **Note:** ID Picture is uploaded but no OCR validation occurs in student_register.php

### ✅ upload_document.php (Lines 550-736)
- **Structure:** 6 checks with `.verify.json` creation
- **Checks:**
  1. `first_name_match` (≥80% similarity)
  2. `middle_name_match` (≥70% similarity, auto-pass if empty)
  3. `last_name_match` (≥80% similarity)
  4. `year_level_match` (exact variations like "1st year", "freshman")
  5. `university_match` (≥60% word match)
  6. `document_keywords_found` (≥2 keywords: student, id, identification, university, college, school, name, number, valid, card, holder, expires)
- **Files Created:**
  - `.verify.json` ✅
  - `.ocr.txt` ✅

---

## 3. Letter to Mayor

### ❌ student_register.php (Lines 1063-1362)
- **Structure:** 4 checks, NO `.verify.json` creation ❌
- **Checks:**
  1. `first_name` (≥80% similarity)
  2. `last_name` (≥80% similarity)
  3. `barangay` (≥70% similarity)
  4. `mayor_header` (≥70% similarity to: office of the mayor, city mayor, municipal mayor, mayor, etc.)
- **Missing Checks:**
  - ❌ `middle_name_match`
  - ❌ `document_keywords_found`
- **Files Created:**
  - `letter_confidence.json` ✅
  - `.verify.json` ❌ MISSING
  - `.ocr.txt` ❌ MISSING
- **File Copying:** ❌ Lines 2700-2710 do NOT copy .verify.json or .ocr.txt
- **Success Criteria:** ≥3/4 checks OR ≥2 checks with 75% avg confidence

### ✅ upload_document.php (Lines 737-893)
- **Structure:** 6 checks with `.verify.json` creation
- **Checks:**
  1. `first_name_match` (≥80% similarity)
  2. `middle_name_match` (≥70% similarity, auto-pass if empty)
  3. `last_name_match` (≥80% similarity)
  4. `barangay_match` (≥70% similarity)
  5. `office_header_found` (≥1 header: office of the mayor, city mayor, municipal mayor, mayor, barangay captain, punong barangay, office of the barangay, office)
  6. `document_keywords_found` (≥2 keywords: letter, request, assistance, scholarship, mayor, respectfully, sincerely)
- **Files Created:**
  - `.verify.json` ✅
  - `.ocr.txt` ✅
- **Success Criteria:** ≥4/6 checks OR ≥3 checks with 80% avg confidence

---

## 4. Certificate of Indigency

### ❌ student_register.php (Lines 1367-1697)
- **Structure:** 5 checks, NO `.verify.json` creation ❌
- **Checks:**
  1. `certificate_title` (≥70% similarity to: certificate of indigency, indigency certificate, katunayan ng kahirapan, etc.)
  2. `first_name` (≥80% similarity)
  3. `last_name` (≥80% similarity)
  4. `barangay` (≥70% similarity)
  5. `general_trias` (≥70% similarity to: general trias, gen trias, general trias city, etc.)
- **Missing Checks:**
  - ❌ `middle_name_match`
  - ❌ `office_header_found` (replaced with general_trias)
  - ❌ `document_keywords_found` (replaced with certificate_title)
- **Files Created:**
  - `certificate_confidence.json` ✅
  - `.verify.json` ❌ MISSING
  - `.ocr.txt` ❌ MISSING
- **File Copying:** ❌ Lines 2760-2770 do NOT copy .verify.json or .ocr.txt
- **Success Criteria:** ≥4/5 checks OR ≥3 checks with 75% avg confidence

### ✅ upload_document.php (Lines 737-893)
- **Structure:** 6 checks with `.verify.json` creation (same as Letter)
- **Checks:**
  1. `first_name_match` (≥80% similarity)
  2. `middle_name_match` (≥70% similarity, auto-pass if empty)
  3. `last_name_match` (≥80% similarity)
  4. `barangay_match` (≥70% similarity)
  5. `office_header_found` (≥1 header: office of the mayor, city mayor, barangay captain, etc.)
  6. `document_keywords_found` (≥2 keywords: certificate, indigency, certify, resident, barangay, belongs, low income)
- **Files Created:**
  - `.verify.json` ✅
  - `.ocr.txt` ✅
- **Success Criteria:** ≥4/6 checks OR ≥3 checks with 80% avg confidence

---

## 5. Grades Document

### ❌ student_register.php (Lines 1701+)
- **Structure:** OCR processing exists but NO identity verification
- **Note:** Grades OCR extracts grade data only, no identity verification performed
- **Files Created:**
  - Grade data stored in `extracted_grades` table
  - `.verify.json` ❌ NOT CREATED
  - `.ocr.txt` ❌ NOT CREATED

### ❌ upload_document.php
- **Structure:** Basic OCR only, NO identity verification
- **Note:** Grades uploaded via separate flow (`grades_file`), no identity verification
- **Files Created:**
  - Grade data stored in database
  - `.verify.json` ❌ NOT CREATED
  - `.ocr.txt` ❌ NOT CREATED

---

## Issues Summary

### Critical Issues (Causing "No verification available" error)

1. **Letter to Mayor in student_register.php:**
   - ❌ Only 4 checks instead of 6
   - ❌ Missing `middle_name_match` check
   - ❌ Missing `document_keywords_found` check
   - ❌ Does NOT create `.verify.json` file
   - ❌ Does NOT create `.ocr.txt` file
   - ❌ File copying does NOT copy verification files

2. **Certificate of Indigency in student_register.php:**
   - ❌ Only 5 checks instead of 6
   - ❌ Missing `middle_name_match` check
   - ❌ Uses `certificate_title` instead of `document_keywords_found`
   - ❌ Uses `general_trias` instead of `office_header_found`
   - ❌ Does NOT create `.verify.json` file
   - ❌ Does NOT create `.ocr.txt` file
   - ❌ File copying does NOT copy verification files

### Inconsistencies

1. **Check Names Mismatch:**
   - student_register.php uses: `first_name`, `last_name`, `barangay`, `mayor_header`
   - upload_document.php uses: `first_name_match`, `last_name_match`, `barangay_match`, `office_header_found`
   - **Result:** API and frontend expect `_match` suffix, but registration files don't have it

2. **Different Number of Checks:**
   - EAF/ID in upload_document.php: 6 checks ✅
   - Letter in student_register.php: 4 checks ❌
   - Certificate in student_register.php: 5 checks ❌
   - Letter/Certificate in upload_document.php: 6 checks ✅

3. **Success Criteria Mismatch:**
   - student_register.php: ≥3/4 or ≥2 with 75% confidence
   - upload_document.php: ≥4/6 or ≥3 with 80% confidence

---

## Required Changes

### 1. Update Letter OCR in student_register.php (Line ~1063-1362)

**Add these checks:**
- Add `middle_name_match` check (with auto-pass if empty)
- Rename `mayor_header` → `office_header_found`
- Add `document_keywords_found` check (letter, request, assistance, scholarship, mayor, respectfully, sincerely)
- Rename all checks to use `_match` or `_found` suffixes
- Change from 4-check to 6-check structure
- Update success criteria to ≥4/6 or ≥3 with 80% confidence

**Add file creation:**
```php
// After verification structure is complete:
$verifyFile = $targetPath . '.verify.json';
@file_put_contents($verifyFile, json_encode($verification, JSON_PRETTY_PRINT));

$ocrFile = $targetPath . '.ocr.txt';
@file_put_contents($ocrFile, $ocrText);
```

**Update file copying (Line ~2700):**
```php
// Copy .verify.json
if (file_exists($letterPath . '.verify.json')) {
    @copy($letterPath . '.verify.json', $newLetterPath . '.verify.json');
}

// Copy .ocr.txt
if (file_exists($letterPath . '.ocr.txt')) {
    @copy($letterPath . '.ocr.txt', $newLetterPath . '.ocr.txt');
}
```

### 2. Update Certificate OCR in student_register.php (Line ~1367-1697)

**Replace checks:**
- Remove `certificate_title` check
- Remove `general_trias` check
- Add `middle_name_match` check (with auto-pass if empty)
- Add `office_header_found` check (same headers as Letter)
- Add `document_keywords_found` check (certificate, indigency, certify, resident, barangay, belongs, low income)
- Rename all remaining checks to use `_match` suffix
- Change from 5-check to 6-check structure
- Update success criteria to ≥4/6 or ≥3 with 80% confidence

**Add file creation:**
```php
// After verification structure is complete:
$verifyFile = $targetPath . '.verify.json';
@file_put_contents($verifyFile, json_encode($verification, JSON_PRETTY_PRINT));

$ocrFile = $targetPath . '.ocr.txt';
@file_put_contents($ocrFile, $ocrText);
```

**Update file copying (Line ~2760):**
```php
// Copy .verify.json
if (file_exists($certPath . '.verify.json')) {
    @copy($certPath . '.verify.json', $newCertPath . '.verify.json');
}

// Copy .ocr.txt
if (file_exists($certPath . '.ocr.txt')) {
    @copy($certPath . '.ocr.txt', $newCertPath . '.ocr.txt');
}
```

### 3. Standardized 6-Check Structure for All Documents

All documents should use this structure:

**For ID Picture / EAF:**
```php
$verification = [
    'first_name_match' => false,
    'middle_name_match' => false,
    'last_name_match' => false,
    'year_level_match' => false,
    'university_match' => false,
    'document_keywords_found' => false,
    'confidence_scores' => [
        'first_name' => 0,
        'middle_name' => 0,
        'last_name' => 0,
        'year_level' => 0,
        'university' => 0,
        'document_keywords' => 0
    ],
    'found_text_snippets' => [],
    'overall_success' => false,
    'summary' => [
        'passed_checks' => 0,
        'total_checks' => 6,
        'average_confidence' => 0,
        'recommendation' => ''
    ]
];
```

**For Letter / Certificate:**
```php
$verification = [
    'first_name_match' => false,
    'middle_name_match' => false,
    'last_name_match' => false,
    'barangay_match' => false,
    'office_header_found' => false,
    'document_keywords_found' => false,
    'confidence_scores' => [
        'first_name' => 0,
        'middle_name' => 0,
        'last_name' => 0,
        'barangay' => 0,
        'office_header' => 0,
        'document_keywords' => 0
    ],
    'found_text_snippets' => [],
    'overall_success' => false,
    'summary' => [
        'passed_checks' => 0,
        'total_checks' => 6,
        'average_confidence' => 0,
        'recommendation' => ''
    ]
];
```

---

## Testing Checklist

After implementing changes:

- [ ] Register new student with Letter to Mayor → Check `.verify.json` exists
- [ ] Register new student with Certificate → Check `.verify.json` exists
- [ ] Admin view Letter validation → Should show 6 checks with confidence scores
- [ ] Admin view Certificate validation → Should show 6 checks with confidence scores
- [ ] Upload Letter post-registration → Should show same 6 checks
- [ ] Upload Certificate post-registration → Should show same 6 checks
- [ ] Verify check names match between registration and upload flows
- [ ] Verify file copying preserves `.verify.json` and `.ocr.txt`
- [ ] Verify admin interface displays document-specific checks correctly

---

## Files to Modify

1. **c:\xampp\htdocs\EducAid\modules\student\student_register.php**
   - Lines 1063-1362: Letter OCR validation
   - Lines 1367-1697: Certificate OCR validation
   - Lines 2700-2710: Letter file copying
   - Lines 2760-2770: Certificate file copying

2. **Testing Files:**
   - Test complete registration flow
   - Verify validation modal in manage_applicants.php shows 6 checks for all documents

---

## Success Criteria

✅ All documents uploaded during registration have `.verify.json` files
✅ All documents use 6-check validation structure
✅ Check names are consistent between registration and upload flows
✅ Admin interface shows verification details for ALL documents
✅ No more "Detailed verification checks are not available" errors
✅ Confidence scores displayed for all 6 checks per document
✅ Document-specific checks displayed correctly (Year Level/University for ID/EAF, Barangay/Office Header for Letter/Certificate)
