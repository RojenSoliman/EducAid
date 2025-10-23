# Document Completeness Badge Fix

## Issue
The "Incomplete" badge was showing for all applicants in `manage_applicants.php` even when all required documents were uploaded.

## Root Cause
The `check_documents()` function was comparing:
- **Required documents**: Document type names (`'eaf'`, `'letter_to_mayor'`, `'certificate_of_indigency'`)
- **Uploaded documents**: Document type codes (`'00'`, `'01'`, `'02'`, `'03'`, `'04'`)

This mismatch caused the function to always return `false`, showing "Incomplete" for everyone.

## Solution Applied
Updated the `check_documents()` function in `manage_applicants.php` (lines 601-681) to:

### 1. Use Document Type Codes for Required Documents
```php
// OLD (document names)
$required = ['eaf', 'letter_to_mayor', 'certificate_of_indigency'];

// NEW (document type codes)
$required_codes = ['00', '02', '03'];
```

### 2. Convert File System Results to Codes
When checking the file system with `find_student_documents_by_id()`, the function returns document names. We now convert them to codes:

```php
$name_to_code_map = [
    'eaf' => '00',
    'letter_to_mayor' => '02',
    'certificate_of_indigency' => '03',
    'id_picture' => '04',
    'grades' => '01'
];

foreach (array_keys($found_documents) as $doc_name) {
    if (isset($name_to_code_map[$doc_name])) {
        $uploaded_codes[] = $name_to_code_map[$doc_name];
    }
}
```

### 3. Check Grades Using Code '01'
```php
// OLD
$has_grades = $grades_row && $grades_row['count'] > 0;

// NEW
$has_grades = in_array('01', $uploaded_codes);
```

### 4. Compare Codes with Codes
```php
// All comparisons now use document_type_codes
return count(array_diff($required_codes, $uploaded_codes)) === 0 && $has_grades;
```

## Document Type Code Reference
| Code | Document Type |
|------|--------------|
| `'00'` | Enrollment Assistance Form (EAF) |
| `'01'` | Academic Grades |
| `'02'` | Letter to Mayor |
| `'03'` | Certificate of Indigency |
| `'04'` | ID Picture |

## Required Documents for Completeness
An applicant is considered "Complete" when they have:
- ✅ EAF (code '00')
- ✅ Letter to Mayor (code '02')
- ✅ Certificate of Indigency (code '03')
- ✅ Academic Grades (code '01')

**Note**: ID Picture (code '04') is uploaded but not required for the "Complete" badge.

## Testing
1. Refresh the Manage Applicants page
2. Students with all 4 required documents should show **"Complete"** badge (green)
3. Students missing any required document should show **"Incomplete"** badge (gray)

## Files Modified
- `modules/admin/manage_applicants.php` - Updated `check_documents()` function (lines 601-681)

## Date Fixed
October 24, 2025
