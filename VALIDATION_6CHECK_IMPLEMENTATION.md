# 6-Check Validation System Implementation

## Overview
Implemented unified 6-check validation structure across all document types (ID Picture, EAF) to provide detailed confidence metrics for admin validation view.

## What Changed

### 1. student_register.php (EAF Validation)
**Purpose:** Save detailed verification data during registration

**Changes:**
- **Lines 471-475:** Added `.verify.json` and `.ocr.txt` file saving after EAF OCR processing
  ```php
  // Save full verification data to .verify.json for admin validation view
  $verifyFile = $targetPath . '.verify.json';
  @file_put_contents($verifyFile, json_encode($verification, JSON_PRETTY_PRINT));
  
  // Save OCR text to .ocr.txt for reference
  $ocrFile = $targetPath . '.ocr.txt';
  @file_put_contents($ocrFile, $ocrText);
  ```

- **Lines 2629-2645:** Copy `.verify.json` and `.ocr.txt` files when moving EAF to permanent storage
  ```php
  // Copy associated .verify.json and .ocr.txt files if they exist
  $verifySourceFile = $tempFile . '.verify.json';
  $ocrSourceFile = $tempFile . '.ocr.txt';
  $verifyDestFile = $tempEnrollmentPath . '.verify.json';
  $ocrDestFile = $tempEnrollmentPath . '.ocr.txt';
  
  if (file_exists($verifySourceFile)) {
      copy($verifySourceFile, $verifyDestFile);
      unlink($verifySourceFile);
  }
  if (file_exists($ocrSourceFile)) {
      copy($ocrSourceFile, $ocrDestFile);
      unlink($ocrSourceFile);
  }
  ```

**Verification Structure (Already existed, lines 248-456):**
```php
$verification = [
    'first_name_match' => false,           // Boolean: First name found in OCR text
    'middle_name_match' => false,          // Boolean: Middle name found in OCR text
    'last_name_match' => false,            // Boolean: Last name found in OCR text
    'year_level_match' => false,           // Boolean: Year level found in OCR text
    'university_match' => false,           // Boolean: University name found in OCR text
    'document_keywords_found' => false,    // Boolean: Official keywords found
    'confidence_scores' => [
        'first_name' => 0-100,             // Percentage: First name similarity
        'middle_name' => 0-100,            // Percentage: Middle name similarity
        'last_name' => 0-100,              // Percentage: Last name similarity
        'university' => 0-100,             // Percentage: University match score
        'document_keywords' => 0-100       // Percentage: Keywords match score
    ],
    'summary' => [
        'passed_checks' => 0-6,            // Number of checks passed
        'total_checks' => 6,               // Total checks performed
        'average_confidence' => 0-100,     // Average of all confidence scores
        'recommendation' => 'string'       // Human-readable recommendation
    ]
];
```

### 2. upload_document.php (ID Picture Validation)
**Purpose:** Use same 6-check structure for ID Picture uploads after registration

**Changes:**
- **Lines 552-730:** Replaced simple 2-check validation (name_match, school_match) with detailed 6-check validation matching student_register.php structure
  - Added helper function `calculateIDSimilarity()` for text matching
  - Implemented all 6 checks: first_name, middle_name, last_name, year_level, university, document_keywords
  - Added confidence scoring for each check
  - Generate summary with passed_checks, total_checks, average_confidence
  - Save complete verification structure to `.verify.json`

**Old Structure (REMOVED):**
```php
$verify = [
    'name_match' => true/false,
    'school_match' => true/false,
    'verification_score' => 0-100
];
```

**New Structure (IMPLEMENTED):**
Same 6-check structure as student_register.php (see above)

### 3. get_validation_details.php (API Endpoint)
**Purpose:** Read and return validation data to admin interface

**Changes:**
- **Lines 58-97:** Updated ID Picture verification reading to parse 6-check structure
  ```php
  $validation_data['identity_verification'] = [
      'first_name_match' => $verify_data['first_name_match'] ?? false,
      'first_name_confidence' => $verify_data['confidence_scores']['first_name'] ?? 0,
      'middle_name_match' => $verify_data['middle_name_match'] ?? false,
      'middle_name_confidence' => $verify_data['confidence_scores']['middle_name'] ?? 0,
      'last_name_match' => $verify_data['last_name_match'] ?? false,
      'last_name_confidence' => $verify_data['confidence_scores']['last_name'] ?? 0,
      'year_level_match' => $verify_data['year_level_match'] ?? false,
      'school_match' => $verify_data['university_match'] ?? false,
      'school_confidence' => $verify_data['confidence_scores']['university'] ?? 0,
      'official_keywords' => $verify_data['document_keywords_found'] ?? false,
      'keywords_confidence' => $verify_data['confidence_scores']['document_keywords'] ?? 0,
      'passed_checks' => $verify_data['summary']['passed_checks'] ?? 0,
      'total_checks' => 6,
      'average_confidence' => $verify_data['summary']['average_confidence'] ?? 0,
      'recommendation' => $verify_data['summary']['recommendation'] ?? ''
  ];
  ```

- **Lines 156-186:** Added EAF verification reading (same structure as ID Picture)

### 4. manage_applicants.php (Admin Interface)
**Purpose:** Display detailed validation checks in modal

**Changes:**
- **Lines 2323-2404:** Added identity_verification display in `generateValidationHTML()` function
  - Display all 6 checks in a table format
  - Show match status (✓ or ✗) for each check
  - Display confidence percentage with color-coded badges:
    - Green: ≥80%
    - Yellow: 60-79%
    - Red: <60%
  - Show overall summary: passed_checks / total_checks, average confidence
  - Display recommendation text

**Modal Display:**
```
IDENTITY VERIFICATION CHECKS:
┌─────────────────────────────────┬────────────┬────────────┐
│ Check                           │ Status     │ Confidence │
├─────────────────────────────────┼────────────┼────────────┤
│ First Name Match                │ ✓ Match    │ 95.0%      │
│ Middle Name Match               │ ✓ Match    │ 100.0%     │
│ Last Name Match                 │ ✓ Match    │ 92.0%      │
│ Year Level Match                │ ✗ Not Found│ N/A        │
│ University Match                │ ✓ Match    │ 85.0%      │
│ Official Document Keywords      │ ✓ Found    │ 88.0%      │
└─────────────────────────────────┴────────────┴────────────┘

Passed Checks: 5 / 6
Average Confidence: 92.0%
```

## File Locations

### Verification JSON Files
- **During Registration (temp):** `assets/uploads/temp/enrollment_forms/{filename}.verify.json`
- **After Registration (pending):** `assets/uploads/temp/enrollment_forms/{student_id}_{name}_eaf.{ext}.verify.json`
- **ID Picture Uploads:** `assets/uploads/temp/id_picture/{student_id}_{name}_id.{ext}.verify.json`

### OCR Text Files
- Same locations as `.verify.json` files, with `.ocr.txt` extension

## Validation Thresholds

### Name Matching (First & Last)
- **Pass:** ≥80% similarity
- **Method:** Fuzzy text matching using `similar_text()` + exact substring match

### Middle Name Matching
- **Pass:** ≥70% similarity (lower threshold)
- **Auto-pass:** If student has no middle name

### Year Level Matching
- **Pass:** Boolean - found any of the expected variations
- **Variations:** "1st year", "first year", "1st yr", "year 1", "yr 1", "freshman", etc.

### University Matching
- **Pass:** ≥60% of university name words found with ≥70% similarity each
- **OR:** Short names (≤2 words) with ≥1 word matched

### Document Keywords
- **Pass:** ≥3 keywords found with ≥80% similarity each
- **ID Keywords:** student, id, identification, university, college, school, name, number, valid, card, holder, expires
- **EAF Keywords:** enrollment, assessment, form, official, academic, student, tuition, fees, semester, registration, course, subject, grade, transcript, record, university, college, school, eaf, assessment form, billing, statement, certificate

## Overall Success Criteria
Document passes validation if:
- **≥4 out of 6 checks pass**, OR
- **≥3 checks pass AND average confidence ≥80%**

## Admin View Features

### Document Cards
- Display OCR confidence badge with color-coding
- "View Validation" button appears when OCR data exists
- Button triggers modal showing detailed validation results

### Validation Modal
- Overall OCR Confidence (large badge at top)
- Identity Verification table (6 checks with status and confidence)
- Overall summary (passed checks, average confidence, success rate percentage)
- Recommendation text
- For Grades: Extracted grades table with per-subject confidence
- For other docs: Extracted OCR text preview

## Testing Steps

### Test ID Picture Validation
1. Login as student
2. Upload ID Picture via "Upload Document" page
3. Login as admin
4. Go to "Manage Applicants" → Select student
5. Click "View" on ID Picture card
6. Click "View Validation" button
7. Verify modal shows 6 checks with confidence percentages

### Test EAF Validation (from Registration)
1. Complete student registration with EAF upload
2. OCR validation should occur during Step 4 (EAF upload)
3. Login as admin
4. Go to "Manage Applicants" → Find newly registered student
5. Click "View" on EAF document card
6. Click "View Validation" button
7. Verify modal shows 6 checks with confidence percentages

### Test Grades Validation
1. Login as student with grades uploaded
2. Admin: "Manage Applicants" → Select student
3. Click "View" on Grades card
4. Click "View Validation" button
5. Verify modal shows grades table with per-subject confidence

## Backward Compatibility

### Legacy ID Picture Files
- Old `.verify.json` files with 2-check structure (name_match, school_match) will NOT work
- Admin should request students to re-upload ID Pictures for proper validation
- API gracefully handles missing fields with fallback to 0% confidence

### EAF Files from Before Update
- EAF files uploaded during registration BEFORE this update will not have `.verify.json` files
- Confidence score will still display (from `ocr_confidence` column)
- "View Validation" button will show only OCR text, not detailed checks
- Students do not need to re-submit (manual review by admin)

## Data Structure Examples

### Complete .verify.json Example
```json
{
    "first_name_match": true,
    "middle_name_match": true,
    "last_name_match": true,
    "year_level_match": false,
    "university_match": true,
    "document_keywords_found": true,
    "confidence_scores": {
        "first_name": 95.5,
        "middle_name": 100.0,
        "last_name": 92.3,
        "university": 85.0,
        "document_keywords": 88.5
    },
    "found_text_snippets": {
        "first_name": "Juan",
        "middle_name": "Dela",
        "last_name": "Cruz",
        "university": "Lyceum Philippines University",
        "document_keywords": "student, identification, university"
    },
    "overall_success": true,
    "summary": {
        "passed_checks": 5,
        "total_checks": 6,
        "average_confidence": 92.3,
        "recommendation": "Document validation successful"
    },
    "ocr_text_preview": "LYCEUM PHILIPPINES UNIVERSITY\nCAVITE CAMPUS\nSTUDENT IDENTIFICATION CARD\n..."
}
```

## Security Notes
- Validation data is stored server-side only (not exposed to students)
- API endpoint `get_validation_details.php` requires authentication (admin or student session)
- Students can only view their own validation data (session student_id enforcement)
- Admins can view any student's validation data (requires admin session)

## Performance Considerations
- Verification files are small (~1-3 KB per document)
- Modal loads data asynchronously (no page refresh)
- OCR text preview limited to 1000 characters in modal
- Validation calculations happen during upload (not on every view)

## Troubleshooting

### "Missing required parameters" Error
- Ensure API receives both `doc_type` and `student_id`
- Check browser console for actual request payload
- Verify database has matching document record

### No Validation Button Appears
- Check if `ocr_confidence` column has a value in database
- Verify document type is supported (id_picture, eaf, grades, letter_to_mayor, certificate_of_indigency)
- Ensure document upload completed successfully

### Confidence Shows 0% for All Checks
- Verify `.verify.json` file exists at document file path
- Check file permissions (should be readable by web server)
- Inspect `.verify.json` contents for proper structure
- Re-upload document to regenerate verification data

### Year Level Always Shows N/A
- Year level is boolean match (found or not found)
- No percentage confidence calculated
- Check if student has year_level_id set in database

## Future Enhancements
- Add verification for Letter to Mayor and Certificate of Indigency
- Implement ML-based confidence scoring
- Add confidence threshold configuration in admin settings
- Generate verification reports (PDF export)
- Track validation accuracy over time (learning system)

## Related Files
- `VALIDATION_ADMIN_ONLY.md` - Documents admin-only visibility requirement
- `OCR_CONFIDENCE_IMPLEMENTATION.md` - OCR confidence tracking across all docs
- `GRADES_UPLOAD_FIX.md` - Grades upload bug fix documentation
