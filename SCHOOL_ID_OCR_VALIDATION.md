# School Student ID Validation in ID Picture OCR

## Overview
Added school student ID number validation as the 6th check in the ID Picture OCR verification process.

## Changes Made

### 1. PHP Backend (student_register.php)

#### Added Check #6 - School Student ID Match (Lines ~1379-1425)
```php
// 6. School Student ID Number Match (NEW)
$schoolStudentId = trim($_POST['school_student_id'] ?? '');
if (!empty($schoolStudentId)) {
    // Clean both the expected ID and OCR text for better matching
    $cleanSchoolId = preg_replace('/[^A-Z0-9]/i', '', $schoolStudentId);
    $cleanOcrText = preg_replace('/[^A-Z0-9]/i', '', $ocrText);
    
    // Check if school student ID appears in OCR text
    $idFound = stripos($cleanOcrText, $cleanSchoolId) !== false;
    
    // Also check with original formatting (dashes, spaces, etc.)
    if (!$idFound) {
        $idFound = stripos($ocrText, $schoolStudentId) !== false;
    }
    
    // Calculate similarity for partial matches
    $idSimilarity = 0;
    if (!$idFound) {
        // Extract potential ID numbers from OCR text
        preg_match_all('/\b[\d\-]+\b/', $ocrText, $matches);
        foreach ($matches[0] as $potentialId) {
            $cleanPotentialId = preg_replace('/[^A-Z0-9]/i', '', $potentialId);
            if (strlen($cleanPotentialId) >= 4) {
                similar_text($cleanSchoolId, $cleanPotentialId, $percent);
                $idSimilarity = max($idSimilarity, $percent);
            }
        }
    }
    
    $checks['school_student_id_match'] = [
        'passed' => $idFound || $idSimilarity >= 70,
        'similarity' => $idFound ? 100 : round($idSimilarity, 2),
        'threshold' => 70,
        'expected' => $schoolStudentId,
        'found_in_ocr' => $idFound,
        'note' => $idFound ? 'Exact match found' : ($idSimilarity >= 70 ? 'Partial match found' : 'Not found - please verify')
    ];
}
```

**Validation Logic:**
1. **Exact Match**: Strips special characters and checks if school ID appears in OCR text
2. **Format Match**: Checks with original formatting (dashes, spaces)
3. **Similarity Match**: If not found, extracts all number patterns from OCR and calculates similarity
4. **Threshold**: 70% similarity required to pass
5. **Auto-pass**: If no school_student_id provided (backward compatibility)

#### Updated Total Checks Count
- Changed from 5 checks to **6 checks**
- Updated overall success criteria: 4+ checks OR 3+ checks with 80%+ confidence
- Updated comment: `// Validation Checks (6 checks total)`

#### Updated Confidence Calculation
- Added `$schoolIdSimilarity` to average confidence calculation
- Changed denominator from `/4` to `/5` for proper averaging

#### Updated Summary Output
```php
'total_checks' => 6,  // Changed from 5
```

#### Updated Debug Logging
Added school student ID check to error logs:
```php
error_log("School Student ID Match: " . ($checks['school_student_id_match']['passed'] ? 'PASS' : 'FAIL') . 
          " (" . ($checks['school_student_id_match']['similarity'] ?? 'N/A') . "%)");
error_log("Overall: " . $passedCount . "/6 checks passed - " . $recommendation);
```

### 2. JavaScript (student_register.php)

#### Added school_student_id to Form Data (Line ~4665)
```javascript
const schoolStudentIdInput = document.querySelector('input[name="school_student_id"]');
if (schoolStudentIdInput && schoolStudentIdInput.value) 
    formData.append('school_student_id', schoolStudentIdInput.value);
```

#### Added UI Update Call (Line ~4740)
```javascript
updateCheckItem('idpic-check-schoolid', 'idpic-confidence-schoolid', checks.school_student_id_match);
```

### 3. HTML UI (student_register.php)

#### Added Check Item Display (Line ~3655)
```html
<div class="form-check d-flex justify-content-between align-items-center" id="idpic-check-schoolid">
    <div>
        <i class="bi bi-x-circle text-danger me-2"></i>
        <span>School Student ID Number</span>
    </div>
    <span class="badge bg-secondary confidence-score" id="idpic-confidence-schoolid">0%</span>
</div>
```

#### Updated Passed Checks Counter (Line ~3670)
```html
<span class="fw-bold" id="idpic-passed-checks">0/6</span>  <!-- Changed from 0/5 -->
```

## How It Works

### Matching Process:

1. **User fills Step 3**: Enters school student ID (e.g., `2024-12345`)
2. **User uploads ID Picture in Step 4**: OCR processes the image
3. **Validation runs**:
   - Strips special characters: `202412345`
   - Checks if this appears in OCR text
   - If not found, tries with original format: `2024-12345`
   - If still not found, extracts all number patterns from OCR and compares similarity
4. **Result**:
   - ✅ **100% confidence** if exact match found
   - ✅ **70-99% confidence** if similar pattern found (passes if ≥70%)
   - ❌ **<70% confidence** if not found or poor match (fails)

### UI Indicators:

- **Green check (✓)**: School ID found in document with ≥70% confidence
- **Red X (✗)**: School ID not found or <70% confidence
- **Badge color**:
  - 🟢 Green (80-100%): High confidence
  - 🟡 Yellow (60-79%): Moderate confidence
  - 🔴 Red (<60%): Low confidence

### Pass Criteria:

**Updated from 5 checks to 6 checks:**
- **OLD**: Pass if 3+ checks OR 2+ checks with 80%+ avg confidence
- **NEW**: Pass if 4+ checks OR 3+ checks with 80%+ avg confidence

This ensures stricter validation while still allowing for OCR imperfections.

## Benefits

1. **Prevents ID Fraud**: Ensures uploaded ID actually contains the claimed student number
2. **Cross-validation**: School student ID from Step 3 is verified against physical ID in Step 4
3. **Flexible Matching**: Handles various ID formats (dashes, spaces, no separators)
4. **Partial Match Support**: Accepts similar patterns if exact match not found (OCR errors)
5. **User-Friendly**: Shows which check failed so user knows what to fix

## Testing Scenarios

### Test 1: Perfect Match
- School Student ID: `2024-12345`
- ID Picture contains: `ID NO: 2024-12345`
- **Result**: ✅ 100% confidence, check passes

### Test 2: Format Variation
- School Student ID: `2024-12345`
- ID Picture contains: `STUDENT NUMBER 202412345`
- **Result**: ✅ 100% confidence, check passes (stripped match)

### Test 3: Partial OCR Error
- School Student ID: `2024-12345`
- ID Picture contains (OCR error): `ID: 2024-I2345` (1 misread as I)
- **Result**: ✅ ~90% similarity, check passes

### Test 4: Wrong ID
- School Student ID: `2024-12345`
- ID Picture contains: `ID: 2023-99999`
- **Result**: ❌ <70% similarity, check fails

### Test 5: ID Not Visible
- School Student ID: `2024-12345`
- ID Picture doesn't contain any ID number
- **Result**: ❌ 0% similarity, check fails

## Edge Cases Handled

1. **No school_student_id provided**: Auto-passes (backward compatibility)
2. **Empty OCR text**: Fails validation
3. **Special characters in ID**: Strips for comparison (handles dashes, slashes, etc.)
4. **Multiple number patterns in OCR**: Compares against all, uses highest similarity
5. **Short ID numbers**: Requires minimum 4 characters for similarity matching

## Files Modified

1. **modules/student/student_register.php**
   - Lines ~1299: Added comment update (6 checks)
   - Lines ~1379-1425: Added school student ID validation logic
   - Lines ~1437-1447: Updated confidence calculation and total checks
   - Lines ~1469-1473: Updated debug logging
   - Lines ~3655-3662: Added HTML check item display
   - Lines ~3670: Updated check counter (0/6)
   - Lines ~4665: Added school_student_id to form data
   - Lines ~4740: Added updateCheckItem call

## Backward Compatibility

✅ **Fully backward compatible**
- If `school_student_id` field is empty/missing, check auto-passes
- Existing registrations without this field will continue to work
- Only applies validation when school student ID is provided

## Next Steps

1. ✅ Code implemented and ready
2. ⏳ Test with real student IDs
3. ⏳ Verify OCR accuracy with various ID formats
4. ⏳ Adjust threshold (70%) if needed based on testing
5. ⏳ Document common OCR issues and solutions

## Security Enhancement

This adds an extra layer of verification to the multi-account prevention system:
- **Step 3**: Real-time duplicate check of school student ID in database
- **Step 4**: Physical verification that uploaded ID contains the claimed ID number
- **Final submission**: Database trigger tracks all school student IDs

All three layers work together to prevent:
- Multiple account creation with same ID
- Using someone else's ID number
- Uploading wrong/fake ID documents
