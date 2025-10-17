# Fix: Missing Academic Grades in Manage Applicants

## Problem
Students who uploaded grades during registration were not seeing their grades in the Manage Applicants view, even though they had successfully uploaded them.

## Root Cause
During student registration:
1. Grades files were uploaded to `assets/uploads/temp/grades/` for OCR processing
2. OCR validation was performed and confidence scores were calculated
3. **BUT** grades files were never saved to the database (`documents` or `grade_uploads` tables)
4. After registration completion, temp files were cleaned up, leaving no record of the grades

The other documents (EAF, letter to mayor, certificate of indigency) were correctly:
- Saved to temp folders
- Recorded in the `documents` table
- Moved to permanent folders when approved

But grades were missing this flow entirely.

## Solution

### 1. Save Grades During Registration (`student_register.php`)
Added code to save grades files to the database after successful registration, following the same pattern as other documents:

```php
// Save grades to temporary folder (not permanent until approved)
$tempGradesDir = '../../assets/uploads/temp/grades/';
$allGradesFiles = glob($tempGradesDir . '*');
$gradesTempFiles = array_filter($allGradesFiles, function($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'pdf']) && is_file($file);
});

if (!empty($gradesTempFiles)) {
    foreach ($gradesTempFiles as $gradesTempFile) {
        $originalGradesFilename = basename($gradesTempFile);
        $gradesExtension = pathinfo($originalGradesFilename, PATHINFO_EXTENSION);
        
        // Rename with student ID prefix
        $newGradesFilename = $student_id . '_' . $namePrefix . '_grades.' . $gradesExtension;
        $gradesTempPath = $tempGradesDir . $newGradesFilename;

        // Get OCR confidence from temp file
        $gradesConfidenceFile = $tempGradesDir . 'grades_confidence.json';
        $gradesConfidence = 75.0; // default
        if (file_exists($gradesConfidenceFile)) {
            $confidenceData = json_decode(file_get_contents($gradesConfidenceFile), true);
            if ($confidenceData && isset($confidenceData['overall_confidence'])) {
                $gradesConfidence = $confidenceData['overall_confidence'];
            }
            unlink($gradesConfidenceFile);
        }

        if (copy($gradesTempFile, $gradesTempPath)) {
            // Save to documents table
            $gradesQuery = "INSERT INTO documents (student_id, type, file_path, is_valid, ocr_confidence) 
                           VALUES ($1, $2, $3, $4, $5)";
            pg_query_params($connection, $gradesQuery, 
                [$student_id, 'academic_grades', $gradesTempPath, 'false', $gradesConfidence]);
            
            unlink($gradesTempFile);
            break; // Only process first valid file
        }
    }
}
```

### 2. Move Grades to Permanent Location on Approval (`review_registrations.php`)
Updated the admin approval flow to also handle grades when moving documents to permanent storage:

```php
// Determine permanent directory based on document type
if ($docType === 'letter_to_mayor') {
    $permanentDocDir = __DIR__ . '/../../assets/uploads/student/letter_to_mayor/';
} elseif ($docType === 'certificate_of_indigency') {
    $permanentDocDir = __DIR__ . '/../../assets/uploads/student/indigency/';
} elseif ($docType === 'eaf') {
    $permanentDocDir = __DIR__ . '/../../assets/uploads/student/enrollment_forms/';
} elseif ($docType === 'academic_grades') {
    $permanentDocDir = __DIR__ . '/../../assets/uploads/student/grades/';
} else {
    continue;
}
```

Added grades temp directory to cleanup list:
```php
$tempDirs = [
    __DIR__ . '/../../assets/uploads/temp/enrollment_forms/',
    __DIR__ . '/../../assets/uploads/temp/letter_mayor/',
    __DIR__ . '/../../assets/uploads/temp/indigency/',
    __DIR__ . '/../../assets/uploads/temp/grades/'  // Added
];
```

### 3. Display Grades in Manage Applicants (`manage_applicants.php`)
Enhanced the grades display to check both `grade_uploads` table and `documents` table:

```php
// Check for grades - first check grade_uploads table, then fallback to documents table
$grades_query = pg_query_params($connection, 
    "SELECT * FROM grade_uploads WHERE student_id = $1 ORDER BY upload_date DESC LIMIT 1", 
    [$student_id]);
$has_grades = false;
$grade_upload = null;

if (pg_num_rows($grades_query) > 0) {
    $grade_upload = pg_fetch_assoc($grades_query);
    $has_grades = true;
} else {
    // Fallback: check documents table for academic_grades
    $docs_grades_query = pg_query_params($connection, 
        "SELECT * FROM documents WHERE student_id = $1 AND type = 'academic_grades' 
         ORDER BY upload_date DESC LIMIT 1", 
        [$student_id]);
    if (pg_num_rows($docs_grades_query) > 0) {
        $grade_upload = pg_fetch_assoc($docs_grades_query);
        $has_grades = true;
    }
}
```

Added grades to the document search in permanent folders:
```php
$document_types = [
    'eaf' => 'enrollment_forms',
    'letter_to_mayor' => 'letter_to_mayor',
    'certificate_of_indigency' => 'indigency',
    'academic_grades' => 'grades'  // Added
];
```

## Files Modified
1. `modules/student/student_register.php` - Added grades saving logic
2. `modules/admin/review_registrations.php` - Added grades to approval flow
3. `modules/admin/manage_applicants.php` - Enhanced grades display with fallback

## Testing Steps
1. **New Registration Test:**
   - Register a new student
   - Upload grades during step 7
   - Complete registration
   - Check Manage Applicants - grades should now appear

2. **Approval Test:**
   - Admin approves the registration
   - Grades should move from `temp/grades/` to `student/grades/`
   - Database entry should update with permanent path

3. **Display Test:**
   - Open student details in Manage Applicants
   - Grades section should show:
     - Image/PDF preview
     - OCR confidence score
     - Validation status (if available)
     - Link to Grades Validator

## Directory Structure
```
assets/uploads/
├── temp/
│   ├── enrollment_forms/
│   ├── letter_mayor/
│   ├── indigency/
│   └── grades/              [NEW - temp storage during registration]
└── student/
    ├── enrollment_forms/
    ├── letter_to_mayor/
    ├── indigency/
    └── grades/              [NEW - permanent storage after approval]
```

## Database Tables Affected
- **documents** table: Stores all document metadata including grades (type='academic_grades')
- **grade_uploads** table: More detailed grades info with OCR results and validation (when available)

## Backward Compatibility
- Existing students with grades already in the system are not affected
- The fallback mechanism ensures grades from either table will be displayed
- No database migrations required

## Notes
- The grades confidence file (`grades_confidence.json`) is cleaned up after being read
- Only the first valid grades file is processed if multiple exist
- OCR confidence score defaults to 75% if not available
- Files are renamed with student_id prefix for easy identification
