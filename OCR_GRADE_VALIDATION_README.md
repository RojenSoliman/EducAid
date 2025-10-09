# OCR-Driven Per-Subject Grade Validation System

## Overview

This system implements comprehensive per-subject grade validation using Tesseract OCR and university-specific grading policies. Instead of using a simple 3.00 threshold, the system validates each subject individually based on the specific grading scale and policies of the student's university.

## Architecture

### Components

1. **Database Schema** (`sql/grading_policy_schema.sql`)
   - `grading.university_passing_policy` table with university-specific grading rules
   - PostgreSQL function `grading_is_passing()` for grade validation
   - Support for multiple grading scales: 1-5, 0-4, percentage, and letter grades

2. **Services**
   - `GradeValidationService.php`: Core validation logic and policy enforcement
   - `OCRProcessingService.php`: Tesseract integration with image preprocessing

3. **API Endpoint** (`api/eligibility/subject-check.php`)
   - REST API for grade document processing and validation
   - Supports both file upload and direct subject data

4. **Integration** (Enhanced `student_register.php`)
   - Seamless integration with existing 9-step registration process
   - Enhanced Step 7 with per-subject validation
   - Backward compatibility with legacy validation

## Grading Systems Supported

### Scale Types

1. **NUMERIC_1_TO_5** (State Universities)
   - Range: 1.00 - 5.00
   - Direction: Lower is better (1.00 = highest, 5.00 = lowest)
   - Passing: ≤ 3.00
   - Universities: BSU, CVSU, LSPU, URS, SLSU, etc.

2. **NUMERIC_0_TO_4** (Private Universities)
   - Range: 0.00 - 4.00
   - Direction: Higher is better (4.00 = highest, 0.00 = lowest)
   - Passing: ≥ 1.00
   - Universities: DLSU, ADMU, NU, etc.

3. **PERCENT** (Percentage-based)
   - Range: 0 - 100
   - Direction: Higher is better
   - Passing: ≥ 75 (configurable)

4. **LETTER** (Letter grades)
   - Scale: A+, A, A-, B+, B, B-, C+, C, C-, D, F
   - Direction: Earlier in alphabet is better
   - Passing: C or better (configurable)

### University-Specific Policies

The system includes pre-configured policies for 80+ universities in Region 4-A (CALABARZON):

- **State Universities**: BSU, CVSU, LSPU, URS, SLSU (1-5 scale, 3.00 passing)
- **Private Universities**: DLSU, ADMU, NU (0-4 scale, 1.00 passing)
- **Specialized Institutions**: TUP, PHILSCA (1-5 scale, 3.00 passing)

## OCR Processing Pipeline

### 1. Document Preprocessing
```bash
# Image enhancement for better OCR
convert input.jpg \
    -density 350 \
    -colorspace Gray \
    -normalize \
    -contrast \
    -sharpen 0x1 \
    -threshold 50% \
    output.png
```

### 2. Tesseract OCR Execution
```bash
# Generate TSV output for structured parsing
tesseract input.png output -l eng --oem 1 --psm 6 tsv
```

### 3. TSV Data Parsing
- Group text by page/block/paragraph/line coordinates
- Detect columns using x-coordinate clustering
- Extract subject-grade pairs using pattern matching
- Apply confidence filtering (>85% threshold)

### 4. Grade Normalization
Common OCR artifacts are automatically corrected:
- `3,00` → `3.00` (comma to decimal)
- `2O5` → `2.05` (O to 0)
- `S.00` → `5.00` (S to 5)
- `1.75°` → `1.75` (remove degree symbol)

## Installation & Setup

### Prerequisites
1. **PHP 7.4+** with extensions:
   - pdo, pdo_pgsql (required)
   - imagick, gd (optional, for image preprocessing)

2. **PostgreSQL 12+**

3. **Tesseract OCR 4.0+**
   ```bash
   # Ubuntu/Debian
   sudo apt install tesseract-ocr
   
   # Windows
   # Download from: https://github.com/UB-Mannheim/tesseract/wiki
   ```

### Installation Steps

1. **Run Setup Script**
   ```bash
   php scripts/setup_grade_validation.php
   ```

2. **Execute Database Schema**
   ```sql
   \i sql/university_schema_update.sql
   \i sql/grading_policy_schema.sql
   ```

3. **Create Directories**
   ```bash
   mkdir temp
   chmod 755 temp
   ```

4. **Test Installation**
   ```bash
   php scripts/test_grade_validation.php
   ```

## Usage

### 1. API Endpoint

**Upload Document for Processing:**
```javascript
const formData = new FormData();
formData.append('gradeDocument', file);
formData.append('universityKey', 'BSU_MAIN');

fetch('/api/eligibility/subject-check.php', {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => {
    console.log('Eligibility:', data.eligible);
    console.log('Failed Subjects:', data.failedSubjects);
});
```

**Direct Subject Validation:**
```javascript
const subjects = [
    {name: 'Mathematics 1', rawGrade: '2.50', confidence: 95},
    {name: 'English 1', rawGrade: '3.25', confidence: 90}
];

fetch('/api/eligibility/subject-check.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
        universityKey: 'BSU_MAIN',
        subjects: subjects
    })
});
```

### 2. PHP Service Usage

```php
require_once 'services/GradeValidationService.php';

$validator = new GradeValidationService($dbConnection);

// Check individual subject
$isPassing = $validator->isSubjectPassing('BSU_MAIN', '2.75');

// Validate all subjects for applicant
$subjects = [
    ['name' => 'Math', 'rawGrade' => '2.50', 'confidence' => 95],
    ['name' => 'English', 'rawGrade' => '3.25', 'confidence' => 90]
];

$result = $validator->validateApplicant('BSU_MAIN', $subjects);
echo $result['eligible'] ? 'ELIGIBLE' : 'INELIGIBLE';
```

### 3. OCR Processing

```php
require_once 'services/OCRProcessingService.php';

$ocr = new OCRProcessingService([
    'tesseract_path' => 'tesseract',
    'temp_dir' => './temp',
    'max_file_size' => 10 * 1024 * 1024
]);

$result = $ocr->processGradeDocument('/path/to/grades.pdf');
if ($result['success']) {
    foreach ($result['subjects'] as $subject) {
        echo "{$subject['name']}: {$subject['rawGrade']}\n";
    }
}
```

## Testing

### 1. Unit Tests
```bash
# Run complete test suite
php scripts/test_grade_validation.php

# Test with sample image
php scripts/test_grade_validation.php sample_grades.jpg
```

### 2. OCR Testing
```bash
# Windows
scripts\test_ocr.bat sample_grades.jpg

# Linux/Mac
bash scripts/test_ocr.sh sample_grades.jpg
```

### 3. Manual Testing

**Test Cases:**
1. **All Passing (BSU)**: Math 2.00, English 2.50, Science 3.00 → ELIGIBLE
2. **One Failing (BSU)**: Math 2.00, English 3.25, Science 2.75 → INELIGIBLE
3. **Low Confidence**: Any grade with <85% OCR confidence → INELIGIBLE
4. **Empty Grade**: Missing or unrecognized grade → INELIGIBLE

## Error Handling

### OCR Processing Errors
- **File size exceeded**: Max 10MB limit
- **Unsupported format**: Only PDF, JPG, PNG, TIFF allowed
- **Tesseract failure**: Check installation and PATH
- **Low confidence**: Grades <85% confidence treated as failing

### Validation Errors
- **Unknown university**: Returns false (strict default)
- **Invalid grade format**: Non-numeric/unrecognized grades fail
- **Missing policy**: University not in database fails validation

### Recovery Mechanisms
- **Fallback to legacy**: If enhanced validation fails, system falls back to legacy 3.00 threshold
- **Audit logging**: All validation results logged for manual review
- **Confidence reporting**: Low-confidence extractions flagged for admin review

## Performance Optimization

### Caching
- University policies cached per request
- OCR results cached for duplicate processing
- Temporary files cleaned up automatically

### Scalability
- Async OCR processing for large documents
- Batch validation for multiple applicants
- Connection pooling for database operations

### Security
- File upload sandboxing
- Input validation and sanitization
- SQL injection prevention with prepared statements
- Execution timeouts for OCR processes

## Integration with Registration System

The enhanced validation is seamlessly integrated into the existing 9-step student registration:

1. **Step 7 Enhancement**: Grade document upload with real-time validation
2. **Backward Compatibility**: Legacy validation as fallback
3. **UI Feedback**: Clear pass/fail indicators per subject
4. **Admin Override**: Manual review capability for edge cases

### Registration Flow
```
Student uploads grade document
    ↓
OCR extracts subjects and grades
    ↓
Enhanced per-subject validation
    ↓ (if fails)
Fallback to legacy validation
    ↓
Eligibility determination
    ↓
Continue to Step 8 or show failure
```

## Configuration

### Environment Variables
```env
TESSERACT_PATH=/usr/bin/tesseract
OCR_TEMP_DIR=/tmp/ocr
OCR_MAX_FILE_SIZE=10485760
OCR_CONFIDENCE_THRESHOLD=85
```

### Database Configuration
```php
// config/ocr_config.php
return [
    'tesseract_path' => 'tesseract',
    'temp_dir' => __DIR__ . '/../temp',
    'max_file_size' => 10 * 1024 * 1024,
    'confidence_threshold' => 85,
    'supported_formats' => ['pdf', 'png', 'jpg', 'jpeg', 'tiff']
];
```

## Troubleshooting

### Common Issues

**Tesseract not found:**
```bash
# Add to PATH or specify full path
export PATH=$PATH:/usr/local/bin
# or
TESSERACT_PATH=/usr/local/bin/tesseract
```

**ImageMagick preprocessing fails:**
```bash
# Install ImageMagick
sudo apt install imagemagick
# or use basic preprocessing
```

**Database connection errors:**
```sql
-- Verify schema exists
\dn grading

-- Check function
SELECT grading.grading_is_passing('BSU_MAIN', '2.50');
```

**OCR accuracy issues:**
- Ensure 300+ DPI image resolution
- Use clean, well-lit document scans
- Avoid skewed or rotated images
- Check for supported languages (eng, fil)

## Future Enhancements

1. **Machine Learning**: Training custom models for grade document recognition
2. **Multi-language**: Support for Filipino and regional languages
3. **Batch Processing**: Handle multiple documents simultaneously
4. **Mobile Integration**: Camera-based document capture
5. **Analytics**: Grade distribution and validation accuracy reporting

---

## Support

For questions or issues:
- **Documentation**: This README and inline code comments
- **Testing**: Use provided test scripts and sample data
- **Debugging**: Enable error logging and check OCR output files
- **Performance**: Monitor database queries and OCR processing times