# Student ID Picture Addition Plan

## Overview
Add Student ID Picture verification to `student_register.php` before the EAF (Enrollment Assessment Form) step, using the same 6-check validation structure as in `upload_document.php`.

## Current Structure
- Step 1: Personal Information
- Step 2: Contact Information  
- Step 3: University and Year Level
- **Step 4: EAF Upload** ← Insert Student ID before this
- Step 5: Letter to Mayor Upload
- Step 6: Certificate of Indigency Upload
- Step 7: Academic Grades Upload
- Step 8: Academic Performance Declaration
- Step 9: Review and Submit

## Required Changes

### 1. Add Step Indicator (Line ~2952)
**Current:** 9 steps (1-9)
**New:** 10 steps (1-10)

Add:
```html
<span class="step" id="step-indicator-10">10</span>
```

### 2. Insert New Step 4 HTML (After line ~3045, before current Step 4)

```html
<!-- Step 4: Student ID Picture Upload and OCR Verification -->
<div class="step-panel d-none" id="step-4">
    <div class="mb-3">
        <label class="form-label">Upload Student ID Picture</label>
        <small class="form-text text-muted d-block">
            <i class="bi bi-info-circle me-1"></i>
            Upload a clear photo of your valid student ID card showing your name, university, and year level
        </small>
        <input type="file" class="form-control mt-2" id="id_picture_file" accept="image/*,.pdf" required>
        <div class="invalid-feedback">Please upload your student ID picture</div>
    </div>
    
    <!-- OCR Processing Status -->
    <div id="id_picture_processing" class="alert alert-info d-none">
        <div class="spinner-border spinner-border-sm me-2"></div>
        Processing your student ID...
    </div>
    
    <!-- OCR Results -->
    <div id="id_picture_result" class="d-none">
        <div class="alert" id="id_picture_alert"></div>
        <div id="id_picture_details"></div>
    </div>
    
    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
    <button type="button" class="btn btn-primary w-100" id="nextStep4Btn" disabled onclick="nextStep()">Next</button>
</div>
```

### 3. Renumber All Existing Steps (HTML)
- Current Step 4 (EAF) → Step 5
- Current Step 5 (Letter) → Step 6  
- Current Step 6 (Certificate) → Step 7
- Current Step 7 (Grades) → Step 8
- Current Step 8 (Performance) → Step 9
- Current Step 9 (Review) → Step 10

**Search and replace:**
- `id="step-4"` → `id="step-5"` (EAF)
- `id="step-5"` → `id="step-6"` (Letter)
- `id="step-6"` → `id="step-7"` (Certificate)
- `id="step-7"` → `id="step-8"` (Grades)
- `id="step-8"` → `id="step-9"` (Performance)
- `id="step-9"` → `id="step-10"` (Review)

- `nextStep4Btn` → `nextStep5Btn` (EAF button)
- `nextStep5Btn` → `nextStep6Btn` (Letter button)
- `nextStep6Btn` → `nextStep7Btn` (Certificate button)
- `nextStep7Btn` → `nextStep8Btn` (Grades button)

### 4. Add Backend Processing Endpoint

Create new file: `modules/student/process_id_picture.php`

```php
<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_FILES['id_picture']) || $_FILES['id_picture']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'No file uploaded or upload error']);
    exit;
}

include '../../config/database.php';

$uploadDir = '../../assets/uploads/temp/id_pictures/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Clear temp folder
$files = glob($uploadDir . '*');
foreach ($files as $file) {
    if (is_file($file)) unlink($file);
}

$uploadedFile = $_FILES['id_picture'];
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$year_level_id = intval($_POST['year_level_id'] ?? 0);
$university_id = intval($_POST['university_id'] ?? 0);

// Get year level and university names
$yearLevelName = '';
$universityName = '';

if ($year_level_id > 0) {
    $yl_res = pg_query_params($connection, "SELECT name FROM year_levels WHERE year_level_id = $1", [$year_level_id]);
    if ($yl_res) {
        $yl = pg_fetch_assoc($yl_res);
        $yearLevelName = $yl['name'] ?? '';
    }
}

if ($university_id > 0) {
    $uni_res = pg_query_params($connection, "SELECT name FROM universities WHERE university_id = $1", [$university_id]);
    if ($uni_res) {
        $uni = pg_fetch_assoc($uni_res);
        $universityName = $uni['name'] ?? '';
    }
}

$fileName = 'id_' . time() . '_' . basename($uploadedFile['name']);
$targetPath = $uploadDir . $fileName;

if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save file']);
    exit;
}

// Run OCR using same logic as upload_document.php
function ocr_extract_text_and_conf($filePath) {
    $result = ['text' => '', 'confidence' => null];
    
    // Use Tesseract
    $cmd = "tesseract " . escapeshellarg($filePath) . " stdout --oem 1 --psm 6 -l eng 2>&1";
    $tessOut = shell_exec($cmd);
    if (!empty($tessOut)) {
        $result['text'] = $tessOut;
    }
    
    // Dual-pass OCR for ID
    $passA = shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 11 2>&1");
    $passB = shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 7 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz,.- 2>&1");
    
    $result['text'] = trim($result['text'] . "\n" . $passA . "\n" . $passB);
    
    // Get confidence from TSV
    $tsv = shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 6 tsv 2>&1");
    if (!empty($tsv)) {
        $lines = explode("\n", $tsv);
        if (count($lines) > 1) {
            array_shift($lines);
            $sum = 0;
            $cnt = 0;
            foreach ($lines as $line) {
                $cols = explode("\t", $line);
                if (count($cols) >= 12) {
                    $conf = floatval($cols[10] ?? 0);
                    if ($conf > 0) {
                        $sum += $conf;
                        $cnt++;
                    }
                }
            }
            if ($cnt > 0) {
                $result['confidence'] = round($sum / $cnt, 2);
            }
        }
    }
    
    return $result;
}

function calculateIDSimilarity($needle, $haystack) {
    $needle = strtolower(trim($needle));
    $haystack = strtolower(trim($haystack));
    if (stripos($haystack, $needle) !== false) return 100;
    $words = explode(' ', $haystack);
    $maxSimilarity = 0;
    foreach ($words as $word) {
        if (strlen($word) >= 3 && strlen($needle) >= 3) {
            $similarity = 0;
            similar_text($needle, $word, $similarity);
            $maxSimilarity = max($maxSimilarity, $similarity);
        }
    }
    return $maxSimilarity;
}

try {
    $ocr = ocr_extract_text_and_conf($targetPath);
    $ocrTextLower = strtolower($ocr['text']);
    
    // Same 6-check validation as upload_document.php
    $verification = [
        'first_name_match' => false,
        'middle_name_match' => false,
        'last_name_match' => false,
        'year_level_match' => false,
        'university_match' => false,
        'document_keywords_found' => false,
        'confidence_scores' => []
    ];
    
    // Check first name
    if (!empty($first_name)) {
        $similarity = calculateIDSimilarity($first_name, $ocrTextLower);
        $verification['confidence_scores']['first_name'] = $similarity;
        if ($similarity >= 80) {
            $verification['first_name_match'] = true;
        }
    }
    
    // Check middle name
    if (empty($middle_name)) {
        $verification['middle_name_match'] = true;
        $verification['confidence_scores']['middle_name'] = 100;
    } else {
        $similarity = calculateIDSimilarity($middle_name, $ocrTextLower);
        $verification['confidence_scores']['middle_name'] = $similarity;
        if ($similarity >= 70) {
            $verification['middle_name_match'] = true;
        }
    }
    
    // Check last name
    if (!empty($last_name)) {
        $similarity = calculateIDSimilarity($last_name, $ocrTextLower);
        $verification['confidence_scores']['last_name'] = $similarity;
        if ($similarity >= 80) {
            $verification['last_name_match'] = true;
        }
    }
    
    // Check year level
    if (!empty($yearLevelName)) {
        $selectedYearVariations = [];
        if (stripos($yearLevelName, '1st') !== false || stripos($yearLevelName, 'first') !== false) {
            $selectedYearVariations = ['1st year', 'first year', '1st yr', 'year 1', 'yr 1', 'freshman'];
        } elseif (stripos($yearLevelName, '2nd') !== false) {
            $selectedYearVariations = ['2nd year', 'second year', '2nd yr', 'year 2', 'yr 2', 'sophomore'];
        } elseif (stripos($yearLevelName, '3rd') !== false) {
            $selectedYearVariations = ['3rd year', 'third year', '3rd yr', 'year 3', 'yr 3', 'junior'];
        } elseif (stripos($yearLevelName, '4th') !== false) {
            $selectedYearVariations = ['4th year', 'fourth year', '4th yr', 'year 4', 'yr 4', 'senior'];
        }
        foreach ($selectedYearVariations as $variation) {
            if (stripos($ocr['text'], $variation) !== false) {
                $verification['year_level_match'] = true;
                break;
            }
        }
    }
    
    // Check university name
    if (!empty($universityName)) {
        $universityWords = array_filter(explode(' ', strtolower($universityName)));
        $foundWords = 0;
        $totalWords = count($universityWords);
        foreach ($universityWords as $word) {
            if (strlen($word) > 2) {
                $similarity = calculateIDSimilarity($word, $ocrTextLower);
                if ($similarity >= 70) $foundWords++;
            }
        }
        $universityScore = ($foundWords / max($totalWords, 1)) * 100;
        $verification['confidence_scores']['university'] = round($universityScore, 1);
        if ($universityScore >= 60) {
            $verification['university_match'] = true;
        }
    }
    
    // Check document keywords
    $documentKeywords = ['student', 'id', 'identification', 'university', 'college', 'school', 'name', 'number', 'valid', 'card'];
    $keywordMatches = 0;
    $keywordScore = 0;
    foreach ($documentKeywords as $keyword) {
        $similarity = calculateIDSimilarity($keyword, $ocrTextLower);
        if ($similarity >= 80) {
            $keywordMatches++;
            $keywordScore += $similarity;
        }
    }
    $averageKeywordScore = $keywordMatches > 0 ? ($keywordScore / $keywordMatches) : 0;
    $verification['confidence_scores']['document_keywords'] = round($averageKeywordScore, 1);
    if ($keywordMatches >= 2) {
        $verification['document_keywords_found'] = true;
    }
    
    // Calculate overall success
    $passedChecks = 0;
    foreach (['first_name_match', 'middle_name_match', 'last_name_match', 'year_level_match', 'university_match', 'document_keywords_found'] as $check) {
        if ($verification[$check]) $passedChecks++;
    }
    
    $totalConfidence = array_sum($verification['confidence_scores']);
    $averageConfidence = count($verification['confidence_scores']) > 0 ? ($totalConfidence / count($verification['confidence_scores'])) : 0;
    
    $verification['overall_success'] = ($passedChecks >= 4) || ($passedChecks >= 3 && $averageConfidence >= 80);
    $verification['summary'] = [
        'passed_checks' => $passedChecks,
        'total_checks' => 6,
        'average_confidence' => round($averageConfidence, 1),
        'recommendation' => $verification['overall_success'] ? 
            'Student ID validation successful' : 
            'Please ensure the ID clearly shows your name, university, year level'
    ];
    
    // Save verification results
    file_put_contents($targetPath . '.verify.json', json_encode($verification));
    file_put_contents($targetPath . '.ocr.txt', $ocr['text']);
    
    // Save confidence to JSON file
    $confidenceFile = $uploadDir . 'id_picture_confidence.json';
    file_put_contents($confidenceFile, json_encode([
        'ocr_confidence' => $ocr['confidence'],
        'verification' => $verification
    ]));
    
    echo json_encode([
        'status' => 'success',
        'ocr_confidence' => $ocr['confidence'],
        'verification' => $verification,
        'file_path' => $fileName
    ]);
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'OCR processing failed: ' . $e->getMessage()]);
}
?>
```

### 5. Add JavaScript Handler (in student_register.php JavaScript section)

Add after line ~3600 (in the JavaScript section):

```javascript
// Handle Student ID Picture upload (Step 4)
document.getElementById('id_picture_file')?.addEventListener('change', async function(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    const processingDiv = document.getElementById('id_picture_processing');
    const resultDiv = document.getElementById('id_picture_result');
    const alertDiv = document.getElementById('id_picture_alert');
    const detailsDiv = document.getElementById('id_picture_details');
    const nextBtn = document.getElementById('nextStep4Btn');
    
    processingDiv.classList.remove('d-none');
    resultDiv.classList.add('d-none');
    nextBtn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('id_picture', file);
        formData.append('first_name', document.querySelector('[name="first_name"]').value);
        formData.append('middle_name', document.querySelector('[name="middle_name"]').value);
        formData.append('last_name', document.querySelector('[name="last_name"]').value);
        formData.append('year_level_id', document.querySelector('[name="year_level_id"]').value);
        formData.append('university_id', document.querySelector('[name="university_id"]').value);
        
        const response = await fetch('process_id_picture.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        processingDiv.classList.add('d-none');
        resultDiv.classList.remove('d-none');
        
        if (data.status === 'success') {
            const verification = data.verification;
            const passedChecks = verification.summary.passed_checks;
            const totalChecks = verification.summary.total_checks;
            const avgConfidence = verification.summary.average_confidence;
            
            if (verification.overall_success) {
                alertDiv.className = 'alert alert-success';
                alertDiv.innerHTML = `<i class="bi bi-check-circle me-2"></i><strong>Student ID Verified!</strong> ${passedChecks}/${totalChecks} checks passed (${avgConfidence}% confidence)`;
                nextBtn.disabled = false;
            } else {
                alertDiv.className = 'alert alert-warning';
                alertDiv.innerHTML = `<i class="bi bi-exclamation-triangle me-2"></i><strong>Verification Incomplete</strong><br>${verification.summary.recommendation}`;
                nextBtn.disabled = false; // Allow to proceed with warning
            }
            
            // Display detailed results
            detailsDiv.innerHTML = `
                <div class="card mt-3">
                    <div class="card-body">
                        <h6>Verification Details:</h6>
                        <ul class="list-unstyled">
                            <li>${verification.first_name_match ? '✓' : '✗'} First Name (${verification.confidence_scores.first_name}%)</li>
                            <li>${verification.middle_name_match ? '✓' : '✗'} Middle Name (${verification.confidence_scores.middle_name}%)</li>
                            <li>${verification.last_name_match ? '✓' : '✗'} Last Name (${verification.confidence_scores.last_name}%)</li>
                            <li>${verification.year_level_match ? '✓' : '✗'} Year Level</li>
                            <li>${verification.university_match ? '✓' : '✗'} University (${verification.confidence_scores.university}%)</li>
                            <li>${verification.document_keywords_found ? '✓' : '✗'} Document Keywords (${verification.confidence_scores.document_keywords}%)</li>
                        </ul>
                    </div>
                </div>
            `;
        } else {
            alertDiv.className = 'alert alert-danger';
            alertDiv.innerHTML = `<i class="bi bi-x-circle me-2"></i>${data.message}`;
            nextBtn.disabled = true;
        }
        
    } catch (error) {
        processingDiv.classList.add('d-none');
        resultDiv.classList.remove('d-none');
        alertDiv.className = 'alert alert-danger';
        alertDiv.innerHTML = `<i class="bi bi-x-circle me-2"></i>Error processing file: ${error.message}`;
        nextBtn.disabled = true;
    }
});

// Update step validation check
if (currentStep === 4) {
    const idPictureFile = document.getElementById('id_picture_file');
    const nextBtn = document.getElementById('nextStep4Btn');
    if (idPictureFile && idPictureFile.files.length > 0 && !nextBtn.disabled) {
        window.tempIDPicturePath = '../../assets/uploads/temp/id_pictures/' + document.getElementById('id_picture_result').dataset.filename;
    }
}
```

### 6. Update Final Form Submission

In the final form submission handler (around line ~2600-2700), add ID Picture file copying logic:

```php
// Copy Student ID Picture to permanent location
if (!empty($_SESSION['temp_id_picture_path'])) {
    $tempIDPath = $_SESSION['temp_id_picture_path'];
    if (file_exists($tempIDPath)) {
        $idFileName = $student_id_safe . '_id_' . time() . '.' . pathinfo($tempIDPath, PATHINFO_EXTENSION);
        $permanentIDPath = $studentDir . $idFileName;
        
        if (copy($tempIDPath, $permanentIDPath)) {
            // Copy .verify.json and .ocr.txt files
            if (file_exists($tempIDPath . '.verify.json')) {
                copy($tempIDPath . '.verify.json', $permanentIDPath . '.verify.json');
            }
            if (file_exists($tempIDPath . '.ocr.txt')) {
                copy($tempIDPath . '.ocr.txt', $permanentIDPath . '.ocr.txt');
            }
            
            // Insert into documents table
            $idConfidenceData = null;
            $confidenceFile = dirname($tempIDPath) . '/id_picture_confidence.json';
            if (file_exists($confidenceFile)) {
                $idConfidenceData = json_decode(file_get_contents($confidenceFile), true);
            }
            
            $insert_id_query = "INSERT INTO documents (student_id, type, file_path, upload_date, ocr_confidence) 
                               VALUES ($1, 'id_picture', $2, NOW(), $3)";
            pg_query_params($connection, $insert_id_query, [
                $student_id,
                $permanentIDPath,
                $idConfidenceData['ocr_confidence'] ?? null
            ]);
        }
    }
}
```

## Testing Checklist

1. ✅ Upload Student ID - verify OCR processes correctly
2. ✅ Check 6 validation checks appear
3. ✅ Verify confidence scores display
4. ✅ Test with valid ID (4+ checks pass)
5. ✅ Test with invalid/unclear ID (warnings shown)
6. ✅ Verify file is copied to permanent location after registration
7. ✅ Check .verify.json and .ocr.txt files are created
8. ✅ Verify documents table has id_picture entry
9. ✅ Check admin can view ID Picture validation results
10. ✅ Test complete registration flow end-to-end

## Notes

- This maintains the same 6-check validation structure as `upload_document.php`
- All validation logic is consistent between registration and post-registration upload
- The `.verify.json` file format matches other documents for consistent API access
- OCR confidence is stored in both the verification JSON and the documents table

