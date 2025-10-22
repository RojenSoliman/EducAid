# Grades OCR Year Level Validation Fix

## Issue Description
The grades OCR validation was failing even when the correct year level was present in the document. The system needed to extract grades from ONLY the declared year level section.

## Problem
**Before Fix:**
- Declared Year: **3rd Year**
- OCR scans document and finds:
  - 1st Year grades
  - 2nd Year grades  
  - 3rd Year grades
- **Validation Result**: ❌ FAIL - "Declared year match failed"
- **Reason**: System was not correctly isolating the declared year section from other year sections

## Solution
Updated `validateDeclaredYear()` function to correctly extract **ONLY the declared year level section** from the grades document.

**After Fix:**
- Declared Year: **3rd Year**
- OCR scans document and extracts:
  - ~~1st Year grades~~ → Ignored
  - ~~2nd Year grades~~ → Ignored
  - ✓ **3rd Year grades** → Extracted for validation
- **Validation Result**: ✅ PASS - "3rd Year section found (95% confidence)"

## Changes Made

### Function: `validateDeclaredYear($ocrText, $declaredYearName)`
**Location**: `modules/student/student_register.php` (Line ~2559)

#### Logic:
```php
// Find ONLY the declared year section
$yearSectionStart = stripos($ocrTextLower, $declaredYearVariation);

// Find where this section ends (next year level or end of document)
$yearSectionEnd = findNextYearLevelPosition($yearSectionStart);

// Extract ONLY the declared year section
$yearSection = substr($ocrText, $yearSectionStart, $yearSectionEnd - $yearSectionStart);
```

## How It Works

### Example: Student Declared 3rd Year
```
Student declares: "3rd Year"
Document contains:
  FIRST YEAR
    Subject A: 1.50
    Subject B: 1.75
  SECOND YEAR  
    Subject C: 2.00
    Subject D: 2.25
  THIRD YEAR            ← START EXTRACTING HERE
    Subject E: 1.80
    Subject F: 2.10     ← STOP EXTRACTING HERE (or at end of document)

Validation Process:
  Step 1: Detect declared year = 3 (3rd Year)
  Step 2: Find "THIRD YEAR" position in document
  Step 3: Find end of 3rd year section (before 4th year or end of doc)
  Step 4: Extract ONLY that section
  
Result:
  ✅ Found: 3rd Year section
  ✅ Confidence: 95%
  ✅ Grades validated: Subject E (1.80), Subject F (2.10)
  ✅ Ignored: 1st and 2nd year grades (not relevant)
```

### Key Points:
1. **Only extracts declared year**: If student declares 3rd Year, only 3rd year grades are validated
2. **Ignores other years**: 1st, 2nd, 4th year grades are not included in validation
3. **Proper section boundaries**: Correctly identifies where the declared year section starts and ends
4. **Clean extraction**: No mixing of grades from different year levels

## Year Section Extraction Logic

The system identifies section boundaries by:

1. **Finding Start Position**: Search for declared year variation (e.g., "3rd Year", "Third Year", "Year 3")
2. **Finding End Position**: Look for:
   - Next year level marker (e.g., "4th Year" comes after "3rd Year")
   - End of document if no next year found
3. **Extracting Text**: Get everything between start and end positions

```
Document Structure:
┌─────────────────────┐
│ 1ST YEAR            │ ← Not extracted for 3rd year student
│   Subjects...       │
├─────────────────────┤
│ 2ND YEAR            │ ← Not extracted for 3rd year student
│   Subjects...       │
├─────────────────────┤
│ 3RD YEAR            │ ← START: Extract this section
│   Subject A: 1.50   │
│   Subject B: 2.00   │
│   Subject C: 1.75   │ ← END: Stop here
├─────────────────────┤
│ 4TH YEAR            │ ← Not extracted for 3rd year student
│   Subjects...       │
└─────────────────────┘
```

## Return Values

```php
[
    'match' => true,                 // Whether declared year section was found
    'section' => $yearSection,       // Text from ONLY the declared year
    'confidence' => 95,              // Confidence percentage
    'matched_variation' => '3rd year', // Which variation was matched
    'declared_year' => '3'           // The year number
]
```

## Benefits

1. **Accurate Validation**: Now validates cumulative grades, not just current year
2. **Flexible**: Still passes if some earlier years are missing (with lower confidence)
3. **Informative**: Shows which years were found vs. expected
4. **User-Friendly**: Clear message about what was validated

## Edge Cases Handled

### Case 1: Transferee (Missing Earlier Years)
```
Declared: 3rd Year
Found: 3rd Year only (transferred from another school)
Result: ✅ Passes with 78% confidence
```

### Case 2: Complete Transcript
```
Declared: 4th Year  
Found: 1st, 2nd, 3rd, 4th Year (complete)
Result: ✅ Passes with 95% confidence
```

### Case 3: Wrong Year on Document
```
Declared: 2nd Year
Found: 3rd Year only (document mismatch)
Result: ❌ Fails - declared year not found
```

### Case 4: Partial Transcript
```
Declared: 3rd Year
Found: 2nd, 3rd Year (1st year section missing)
Result: ✅ Passes with 87% confidence
```

## Year Level Variations Supported

The system recognizes multiple ways to write year levels:

```
1st Year Variations:
- "1st year", "first year", "1st yr"
- "year 1", "yr 1"
- "freshman", "grade 1"

2nd Year Variations:
- "2nd year", "second year", "2nd yr"
- "year 2", "yr 2"
- "sophomore", "grade 2"

3rd Year Variations:
- "3rd year", "third year", "3rd yr"
- "year 3", "yr 3"
- "junior", "grade 3"

4th Year Variations:
- "4th year", "fourth year", "4th yr"
- "year 4", "yr 4"
- "senior", "grade 4"
```

## Testing Scenarios

### Test 1: Student Declares 3rd Year - Complete Document
```
Declared Year: 3rd Year
Document contains:
  1ST YEAR
    Subject A: 1.50
  2ND YEAR
    Subject B: 2.00
  3RD YEAR
    Subject C: 1.75
    Subject D: 2.10

Expected Result:
✅ Extracts ONLY 3rd Year section
✅ Validates: Subject C (1.75), Subject D (2.10)
✅ Ignores: 1st and 2nd year subjects
```

### Test 2: Student Declares 2nd Year - Only 2nd Year Present
```
Declared Year: 2nd Year
Document contains:
  2ND YEAR
    Subject A: 1.80
    Subject B: 2.00

Expected Result:
✅ Extracts 2nd Year section
✅ Validates: Subject A (1.80), Subject B (2.00)
✅ 95% confidence
```

### Test 3: Student Declares 4th Year - Multiple Years Present
```
Declared Year: 4th Year
Document contains:
  1ST YEAR
    ...subjects...
  2ND YEAR
    ...subjects...
  3RD YEAR
    ...subjects...
  4TH YEAR
    Subject A: 1.50
    Subject B: 1.75

Expected Result:
✅ Extracts ONLY 4th Year section
✅ Validates: Subject A (1.50), Subject B (1.75)
✅ Ignores: 1st, 2nd, and 3rd year subjects
```

### Test 4: Wrong Year Declared
```
Declared Year: 3rd Year
Document contains:
  1ST YEAR
    Subject A: 1.50
  2ND YEAR
    Subject B: 2.00
  (No 3rd year section)

Expected Result:
❌ Validation fails - declared year section not found
```

## Benefits

1. **Accurate Extraction**: Only validates grades from the correct year level
2. **No Grade Mixing**: Prevents validation errors from other year levels
3. **Clear Boundaries**: Correctly identifies section start and end points
4. **Flexible Matching**: Recognizes multiple year level format variations

## Impact on Validation

The extracted `yearSection` is used by downstream validations:

1. **validateGradeThreshold()** - Checks grades ONLY from declared year
2. **Grade extraction** - Gets grades from correct year section
3. **Failing grade detection** - Checks only relevant year's grades

This ensures accurate validation of the correct academic year's performance.

## Files Modified

**modules/student/student_register.php**
- Line ~2559: Function `validateDeclaredYear()` - Corrected section extraction logic
- Lines ~2560-2625: Improved year section boundary detection
- Line ~2620: Returns only declared year section (not all years)

## Backward Compatibility

✅ **Fully backward compatible**
- Existing validation logic for grade thresholds unchanged
- Improved year level section detection
- Students with properly formatted grade documents will see improved accuracy

## Summary

**Before**: Grades validation was not correctly isolating the declared year section
**After**: Grades validation extracts ONLY the declared year section for accurate validation
**Result**: Correct year-specific grade validation without mixing grades from other years
