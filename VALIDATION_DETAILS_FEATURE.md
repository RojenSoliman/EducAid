# Document Validation Details Feature

## Overview
Added a "Validation" button to each uploaded document that displays detailed OCR validation results in a modal dialog, showing confidence percentages per validation check (similar to the verification results interface shown in the requirements).

---

## Feature Implementation

### 1. **Validation Button Added to All Documents**

Each uploaded document card now has a "Validation" button that appears when:
- The document has OCR confidence data available
- For ID Picture: When identity verification data exists

**Documents with Validation Button:**
- ✅ **ID Picture** - Shows identity verification results
- ✅ **Academic Grades** - Shows extracted grades with per-subject confidence
- ✅ **EAF** - Shows OCR confidence and extracted text preview
- ✅ **Letter to the Mayor** - Shows OCR confidence and extracted text preview
- ✅ **Certificate of Indigency** - Shows OCR confidence and extracted text preview

---

## Modal Display Content

### **ID Picture Validation Modal**

Displays verification results similar to the example image provided:

```
┌─────────────────────────────────────────────────┐
│ Overall OCR Confidence:              94.3%  🟢  │
├─────────────────────────────────────────────────┤
│ VERIFICATION RESULTS:                           │
│                                                 │
│ ✓ First Name Match                      100% 🟢│
│ ✓ Middle Name Match                     100% 🟢│
│ ✓ Last Name Match                       100% 🟢│
│ ✗ Year Level Match                        0% 🔴│
│ ✓ University Match                       71% 🟡│
│ ✓ Official Document Keywords            100% 🟢│
├─────────────────────────────────────────────────┤
│ Overall Analysis:                               │
│ Average Confidence:                      94.3%  │
│ Passed Checks:                            6/6   │
│ Document validation successful                  │
└─────────────────────────────────────────────────┘
```

**Data Displayed:**
1. **Overall OCR Confidence** - Color-coded badge (🟢 Green ≥80%, 🟡 Yellow 60-79%, 🔴 Red <60%)
2. **Individual Verification Checks:**
   - First Name Match + Confidence %
   - Middle Name Match + Confidence %
   - Last Name Match + Confidence %
   - Year Level Match + Confidence %
   - University Match + Confidence %
   - Official Document Keywords + Confidence %
3. **Overall Analysis:**
   - Average Confidence across all checks
   - Passed Checks (X/6)
   - Validation Status (successful/needs review/failed)

---

### **Academic Grades Validation Modal**

```
┌─────────────────────────────────────────────────┐
│ Overall OCR Confidence:              87.5%  🟢  │
├─────────────────────────────────────────────────┤
│ EXTRACTED GRADES:                               │
│                                                 │
│ Subject       | Grade | Confidence | Status    │
│─────────────────────────────────────────────────│
│ Mathematics   │ 1.75  │  92.3% 🟢  │ ✓ Passing │
│ English       │ 2.00  │  88.7% 🟢  │ ✓ Passing │
│ Science       │ 1.50  │  95.1% 🟢  │ ✓ Passing │
│ History       │ 2.25  │  75.4% 🟡  │ ✓ Passing │
│ PE            │ 1.00  │  91.8% 🟢  │ ✓ Passing │
├─────────────────────────────────────────────────┤
│ Validation Status: PASSED                       │
└─────────────────────────────────────────────────┘
```

**Data Displayed:**
1. **Overall OCR Confidence** - Average across all subjects
2. **Extracted Grades Table:**
   - Subject Name
   - Grade Value (extracted from OCR)
   - Extraction Confidence per subject (color-coded badge)
   - Passing Status (✓/✗ based on university grading scale)
3. **Validation Status:** passed/failed/manual_review/pending

---

### **EAF / Letter / Certificate Validation Modal**

```
┌─────────────────────────────────────────────────┐
│ Overall OCR Confidence:              82.1%  🟢  │
├─────────────────────────────────────────────────┤
│ EXTRACTED TEXT PREVIEW:                         │
│                                                 │
│ ┌───────────────────────────────────────────┐  │
│ │ ENROLLMENT ASSESSMENT FORM                │  │
│ │                                           │  │
│ │ Student Name: John Doe                    │  │
│ │ Student ID: 12345678                      │  │
│ │ Course: BS Computer Science               │  │
│ │ Year Level: 3rd Year                      │  │
│ │ ...                                       │  │
│ └───────────────────────────────────────────┘  │
└─────────────────────────────────────────────────┘
```

**Data Displayed:**
1. **Overall OCR Confidence** - Color-coded badge
2. **Extracted Text Preview** - First 1000 characters of OCR-extracted text

---

## Files Modified

### 1. **`modules/student/upload_document.php`**

**Changes:**
- Added "Validation" button to all document cards (lines ~1567, 1665, 1780, 1875, 1970)
- Added validation modal HTML structure (before `</body>`)
- Added JavaScript function `showValidationDetails()` to fetch and display validation data
- Added JavaScript function `generateValidationHTML()` to format validation results
- Added CSS styles for validation modal display

**Button Implementation:**
```php
<?php if (!empty($uploaded_id_picture['ocr_confidence']) || file_exists($id_verify_path)): ?>
<button type="button" class="btn btn-outline-info btn-sm" 
        onclick="showValidationDetails('id_picture', '<?php echo htmlspecialchars($uploaded_id_picture['file_path']); ?>')">
  <i class="bi bi-clipboard-data"></i> Validation
</button>
<?php endif; ?>
```

**Modal Structure:**
```html
<div class="modal fade" id="validationModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title">
          <i class="bi bi-clipboard-data"></i>
          Document Validation Results
        </h5>
      </div>
      <div class="modal-body" id="validationModalBody">
        <!-- Dynamic content loaded via JavaScript -->
      </div>
    </div>
  </div>
</div>
```

---

### 2. **`modules/student/get_validation_details.php`** (NEW FILE)

**Purpose:** Backend API endpoint that fetches validation data for a specific document

**Functionality:**
1. **Authentication Check** - Verifies student is logged in
2. **Parameter Validation** - Checks for required doc_type and file_path
3. **Database Query** - Fetches document info from `documents` table
4. **Document-Specific Processing:**
   - **ID Picture:** 
     - Reads `.verify.json` file for identity verification data
     - Reads `.ocr.txt` file for extracted text
     - Queries student info for verification comparison
   - **Academic Grades:**
     - Queries `grade_uploads` table for validation status
     - Queries `extracted_grades` table for per-subject confidence
     - Calculates average confidence
   - **EAF/Letter/Certificate:**
     - Reads `.ocr.txt` file for extracted text
     - Returns OCR confidence from database
5. **JSON Response** - Returns formatted validation data

**Response Format:**
```json
{
  "success": true,
  "validation": {
    "ocr_confidence": 94.3,
    "upload_date": "2025-10-17 09:57:00",
    "identity_verification": {
      "first_name_match": true,
      "first_name_confidence": 100,
      "middle_name_match": true,
      "middle_name_confidence": 100,
      "last_name_match": true,
      "last_name_confidence": 100,
      "year_level_match": true,
      "year_level_confidence": 0,
      "school_match": true,
      "school_confidence": 71,
      "official_keywords": true,
      "keywords_confidence": 100,
      "verification_score": 94,
      "passed_checks": 6,
      "average_confidence": 94.3
    }
  },
  "document_type": "id_picture"
}
```

---

## User Experience Flow

### **Student Perspective:**

1. **Upload Document** → OCR processing happens in background
2. **View Document Card** → See "Validation" button if OCR data exists
3. **Click "Validation" Button** → Modal opens with loading spinner
4. **View Validation Results:**
   - See overall confidence score
   - View detailed per-check validation (for ID)
   - View per-subject grades and confidence (for Grades)
   - View extracted text preview (for other documents)
5. **Analyze Results:**
   - 🟢 Green badges (≥80%) - High confidence, reliable
   - 🟡 Yellow badges (60-79%) - Medium confidence
   - 🔴 Red badges (<60%) - Low confidence, may need reupload
6. **Take Action:**
   - If confidence is low, consider re-uploading with better quality
   - If verification checks fail, ensure document contains correct info

---

## Database Tables Used

### **`documents` Table:**
```sql
student_id       VARCHAR
type             VARCHAR  -- 'id_picture', 'academic_grades', 'eaf', etc.
file_path        TEXT
ocr_confidence   NUMERIC  -- Overall confidence (0-100)
upload_date      TIMESTAMP
```

### **`grade_uploads` Table:**
```sql
upload_id           SERIAL
student_id          VARCHAR
file_path           TEXT
ocr_confidence      NUMERIC
validation_status   VARCHAR  -- 'passed', 'failed', 'manual_review'
upload_date         TIMESTAMP
```

### **`extracted_grades` Table:**
```sql
grade_id               SERIAL
upload_id              INTEGER
subject_name           TEXT
grade_value            TEXT
extraction_confidence  NUMERIC  -- Per-subject confidence
is_passing             BOOLEAN
```

### **`students` Table:**
```sql
student_id      VARCHAR
first_name      VARCHAR
middle_name     VARCHAR
last_name       VARCHAR
year_level      VARCHAR
university_id   INTEGER
```

---

## File System Structure

### **Document Files:**
```
assets/uploads/students/{username}_{studentid}/
├── {studentid}_id_{timestamp}.jpg
│   ├── .ocr.txt              ← Extracted text
│   └── .verify.json          ← Identity verification results
├── {studentid}_grades_{timestamp}.pdf
│   └── .ocr.txt              ← Extracted text (if basic OCR)
├── {studentid}_eaf_{timestamp}.pdf
│   └── .ocr.txt              ← Extracted text
├── {studentid}_letter_{timestamp}.pdf
│   └── .ocr.txt              ← Extracted text
└── {studentid}_indigency_{timestamp}.pdf
    └── .ocr.txt              ← Extracted text
```

### **`.verify.json` Format (ID Picture):**
```json
{
  "name_match": true,
  "school_match": true,
  "verification_score": 94
}
```

---

## Color-Coding System

### **Confidence Badges:**
- 🟢 **Green (bg-success)**: 80-100% - High confidence, reliable extraction
- 🟡 **Yellow (bg-warning)**: 60-79% - Medium confidence, may need verification
- 🔴 **Red (bg-danger)**: 0-59% - Low confidence, requires manual review

### **Status Icons:**
- ✓ (check-circle-fill, text-success) - Check passed
- ✗ (x-circle-fill, text-danger) - Check failed

---

## Benefits

### **For Students:**
- ✅ **Transparency** - See exactly how their documents were processed
- ✅ **Quality Feedback** - Know if they need to reupload with better quality
- ✅ **Confidence** - Understand document verification status
- ✅ **Self-Service** - Identify and fix issues without contacting admin

### **For Admins:**
- ✅ **Reduced Support** - Students can self-diagnose document issues
- ✅ **Better Quality** - Students reupload low-confidence documents
- ✅ **Efficiency** - Less time spent explaining validation failures

---

## Testing Checklist

### **Functional Testing:**
- [ ] Click "Validation" button on ID Picture → Modal opens with identity verification results
- [ ] Click "Validation" button on Grades → Modal opens with extracted grades table
- [ ] Click "Validation" button on EAF → Modal opens with extracted text preview
- [ ] Click "Validation" button on Letter → Modal opens with confidence and text
- [ ] Click "Validation" button on Certificate → Modal opens with confidence and text
- [ ] Validation button only appears when OCR data exists
- [ ] Modal shows loading state while fetching data
- [ ] Modal displays error message if data fetch fails

### **Visual Testing:**
- [ ] Confidence badges are color-coded correctly (green/yellow/red)
- [ ] Status icons display correctly (✓/✗)
- [ ] Table layouts are responsive on mobile
- [ ] Modal is centered and properly sized
- [ ] Text is readable and properly formatted

### **Data Testing:**
```sql
-- Check documents with OCR confidence
SELECT student_id, type, ocr_confidence, file_path 
FROM documents 
WHERE ocr_confidence IS NOT NULL 
ORDER BY upload_date DESC LIMIT 10;

-- Check grades with extraction confidence
SELECT gu.student_id, eg.subject_name, eg.extraction_confidence, eg.is_passing
FROM grade_uploads gu
JOIN extracted_grades eg ON gu.upload_id = eg.upload_id
ORDER BY gu.upload_date DESC LIMIT 20;

-- Check for .verify.json files
-- Manually verify files exist: {file_path}.verify.json
```

---

## Future Enhancements (Optional)

1. **Export Validation Report** - Download PDF of validation results
2. **Comparison View** - Side-by-side of document image and extracted data
3. **Confidence Threshold Alerts** - Auto-notify if confidence below 60%
4. **Historical Validation** - Track validation results across resubmissions
5. **Batch Validation** - View all documents' validation in single screen

---

## Summary

✅ **"Validation" button added to all 5 document types**
✅ **Modal displays detailed validation results with confidence per check**
✅ **Color-coded badges for easy visual assessment**
✅ **Backend API endpoint created for data retrieval**
✅ **Responsive design for mobile/desktop**
✅ **Shows identity verification details for ID Picture (matching your example)**
✅ **Shows per-subject confidence for Grades**
✅ **Shows OCR text preview for other documents**

**Status**: ✅ COMPLETE - Ready for testing
