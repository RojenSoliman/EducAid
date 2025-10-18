<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_FILES['id_picture']) || $_FILES['id_picture']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'No file uploaded or upload error';
    if (isset($_FILES['id_picture']['error'])) {
        $errorMsg .= ' (Code: ' . $_FILES['id_picture']['error'] . ')';
    }
    echo json_encode(['status' => 'error', 'message' => $errorMsg]);
    exit;
}

include '../../config/database.php';

$uploadDir = '../../assets/uploads/temp/id_pictures/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Clear temp folder - delete previous ID files for this session
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
    $tessOut = @shell_exec($cmd);
    if (!empty($tessOut)) {
        $result['text'] = $tessOut;
    }
    
    // Dual-pass OCR for ID
    $passA = @shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 11 2>&1");
    $passB = @shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 7 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz,.- 2>&1");
    
    $result['text'] = trim($result['text'] . "\n" . $passA . "\n" . $passB);
    
    // Get confidence from TSV
    $tsv = @shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 6 tsv 2>&1");
    if (!empty($tsv)) {
        $lines = explode("\n", $tsv);
        if (count($lines) > 1) {
            array_shift($lines);
            $sum = 0;
            $cnt = 0;
            foreach ($lines as $line) {
                if (!trim($line)) continue;
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
        } elseif (stripos($yearLevelName, '2nd') !== false || stripos($yearLevelName, 'second') !== false) {
            $selectedYearVariations = ['2nd year', 'second year', '2nd yr', 'year 2', 'yr 2', 'sophomore'];
        } elseif (stripos($yearLevelName, '3rd') !== false || stripos($yearLevelName, 'third') !== false) {
            $selectedYearVariations = ['3rd year', 'third year', '3rd yr', 'year 3', 'yr 3', 'junior'];
        } elseif (stripos($yearLevelName, '4th') !== false || stripos($yearLevelName, 'fourth') !== false) {
            $selectedYearVariations = ['4th year', 'fourth year', '4th yr', 'year 4', 'yr 4', 'senior'];
        } elseif (stripos($yearLevelName, '5th') !== false || stripos($yearLevelName, 'fifth') !== false) {
            $selectedYearVariations = ['5th year', 'fifth year', '5th yr', 'year 5', 'yr 5'];
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
        if ($universityScore >= 60 || ($totalWords <= 2 && $foundWords >= 1)) {
            $verification['university_match'] = true;
        }
    }
    
    // Check document keywords
    $documentKeywords = ['student', 'id', 'identification', 'university', 'college', 'school', 'name', 'number', 'valid', 'card', 'holder', 'expires'];
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
        'verification' => $verification,
        'file_path' => $fileName
    ]));
    
    // Store in session for final submission
    $_SESSION['temp_id_picture_path'] = $targetPath;
    $_SESSION['temp_id_picture_filename'] = $fileName;
    
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
