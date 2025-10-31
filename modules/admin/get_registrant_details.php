<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username']) || !isset($_GET['id'])) {
    http_response_code(403);
    exit;
}

$student_id = trim($_GET['id']); // Remove intval for TEXT student_id

// This endpoint is ONLY for registrants (under_registration status)
// Used by review_registrations.php for pending registration reviews
$query = "SELECT s.*, b.name as barangay_name, u.name as university_name, yl.name as year_level_name,
                 COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) as confidence_score,
                 get_confidence_level(COALESCE(s.confidence_score, calculate_confidence_score(s.student_id))) as confidence_level
          FROM students s
          LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
          LEFT JOIN universities u ON s.university_id = u.university_id
          LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
          WHERE s.student_id = $1 AND s.status = 'under_registration'";

$result = pg_query_params($connection, $query, [$student_id]);
$student = pg_fetch_assoc($result);

if (!$student) {
    echo '<div class="alert alert-warning">Registrant not found or already processed.</div>';
    exit;
}

// Fetch documents (latest per type) using document_type_code
$docQuery = "SELECT document_type_code, file_path, ocr_confidence, verification_score,
                    verification_status, verification_details, status
             FROM documents 
             WHERE student_id = $1 
             ORDER BY upload_date DESC";
$docResult = pg_query_params($connection, $docQuery, [$student_id]);
$documents = [];
if ($docResult) {
    while ($row = pg_fetch_assoc($docResult)) {
        $code = $row['document_type_code'];
        if (!isset($documents[$code])) { // keep first (latest due to DESC)
            // Use ocr_confidence and verification_score directly from DB columns
            $ocr_conf = floatval($row['ocr_confidence'] ?? 0);
            $verif_score = floatval($row['verification_score'] ?? 0);
            
            // If DB columns are 0, try to extract from verification_details JSONB
            if (($ocr_conf == 0 || $verif_score == 0) && !empty($row['verification_details'])) {
                $verificationData = json_decode($row['verification_details'], true);
                
                // Extract OCR confidence based on document structure
                if ($ocr_conf == 0) {
                    if (isset($verificationData['tsv_quality']['avg_confidence'])) {
                        // EAF has tsv_quality
                        $ocr_conf = floatval($verificationData['tsv_quality']['avg_confidence']);
                    } elseif (isset($verificationData['confidence_scores']['grades'])) {
                        // Grades has confidence_scores.grades
                        $ocr_conf = floatval($verificationData['confidence_scores']['grades']);
                    }
                    // Note: Letter, Certificate, ID Picture don't have separate OCR confidence
                    // They only have verification score, so OCR will remain 0 for display
                }
                
                // Extract verification score if not in DB
                if ($verif_score == 0) {
                    if (isset($verificationData['verification']['summary']['average_confidence'])) {
                        $verif_score = floatval($verificationData['verification']['summary']['average_confidence']);
                    } elseif (isset($verificationData['summary']['average_confidence'])) {
                        $verif_score = floatval($verificationData['summary']['average_confidence']);
                    }
                }
            }
            
            // Calculate overall confidence
            if ($ocr_conf > 0 && $verif_score > 0) {
                $row['overall_confidence'] = ($ocr_conf + $verif_score) / 2;
            } elseif ($verif_score > 0) {
                $row['overall_confidence'] = $verif_score; // Use verification score only
            } elseif ($ocr_conf > 0) {
                $row['overall_confidence'] = $ocr_conf; // Use OCR score only
            } else {
                $row['overall_confidence'] = 0;
            }
            
            // Update the row with extracted values for display
            $row['ocr_confidence'] = $ocr_conf;
            $row['verification_score'] = $verif_score;
            
            $documents[$code] = $row;
        }
    }
}

// If no documents in database, check temp folder for under_registration students
if (empty($documents) && $student['status'] === 'under_registration') {
    // Check temp folders for registrants
    $tempFolders = [
        '04' => __DIR__ . '/../../assets/uploads/temp/id_pictures/',
        '00' => __DIR__ . '/../../assets/uploads/temp/enrollment_forms/',
        '02' => __DIR__ . '/../../assets/uploads/temp/letter_mayor/',
        '03' => __DIR__ . '/../../assets/uploads/temp/indigency/',
        '01' => __DIR__ . '/../../assets/uploads/temp/grades/'
    ];
    
    foreach ($tempFolders as $code => $folder) {
        if (is_dir($folder)) {
            $files = glob($folder . $student_id . '_*');
            foreach ($files as $file) {
                // Skip .verify.json and .ocr.txt files
                if (strpos($file, '.verify.json') !== false || strpos($file, '.ocr.txt') !== false) {
                    continue;
                }
                
                // Look for corresponding .verify.json file
                $verifyFile = $file . '.verify.json';
                $verificationDetails = null;
                $ocr_conf = 0;
                $verif_score = 0;
                
                if (file_exists($verifyFile)) {
                    $verifyContent = file_get_contents($verifyFile);
                    $verificationDetails = json_decode($verifyContent, true);
                    
                    // Extract scores from .verify.json - handle different structures
                    if (isset($verificationDetails['tsv_quality']['avg_confidence'])) {
                        // EAF and some documents have tsv_quality
                        $ocr_conf = floatval($verificationDetails['tsv_quality']['avg_confidence']);
                    } elseif (isset($verificationDetails['confidence_scores']['grades'])) {
                        // Grades documents have confidence_scores.grades
                        $ocr_conf = floatval($verificationDetails['confidence_scores']['grades']);
                    }
                    
                    // Get verification score from various locations
                    if (isset($verificationDetails['verification']['summary']['average_confidence'])) {
                        $verif_score = floatval($verificationDetails['verification']['summary']['average_confidence']);
                    } elseif (isset($verificationDetails['summary']['average_confidence'])) {
                        $verif_score = floatval($verificationDetails['summary']['average_confidence']);
                    }
                }
                
                // Calculate overall confidence
                if ($ocr_conf > 0 && $verif_score > 0) {
                    $overall_conf = ($ocr_conf + $verif_score) / 2;
                } elseif ($verif_score > 0) {
                    $overall_conf = $verif_score;
                } elseif ($ocr_conf > 0) {
                    $overall_conf = $ocr_conf;
                } else {
                    $overall_conf = 0;
                }
                
                // Create document entry from temp file
                $documents[$code] = [
                    'document_type_code' => $code,
                    'file_path' => str_replace('\\', '/', $file),
                    'ocr_confidence' => $ocr_conf,
                    'verification_score' => $verif_score,
                    'verification_status' => 'pending',
                    'verification_details' => json_encode($verificationDetails),
                    'status' => 'temp',
                    'overall_confidence' => $overall_conf
                ];
                
                break; // Only use first matching file for this document type
            }
        }
    }
}

// Document type codes to friendly names
$docNames = [
    '04' => 'ID Picture',
    '00' => 'Enrollment Assessment Form',
    '02' => 'Letter to Mayor',
    '03' => 'Certificate of Indigency',
    '01' => 'Academic Grades'
];
?>

<div class="row">
    <div class="col-md-6">
        <h6 class="fw-bold text-primary mb-3">Personal Information</h6>
        <table class="table table-sm">
            <tr>
                <td class="fw-semibold">Full Name:</td>
                <td><?php echo htmlspecialchars(trim($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name'] . ' ' . $student['extension_name'])); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Email:</td>
                <td><?php echo htmlspecialchars($student['email']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Mobile:</td>
                <td><?php echo htmlspecialchars($student['mobile']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Gender:</td>
                <td><?php echo htmlspecialchars($student['sex']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Birth Date:</td>
                <td><?php echo date('M d, Y', strtotime($student['bdate'])); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Age:</td>
                <td><?php echo date_diff(date_create($student['bdate']), date_create('today'))->y; ?> years old</td>
            </tr>
        </table>
    </div>
    <div class="col-md-6">
        <h6 class="fw-bold text-primary mb-3">Academic Information</h6>
        <table class="table table-sm">
            <tr>
                <td class="fw-semibold">Student ID:</td>
                <td><?php echo htmlspecialchars($student['student_id']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">University:</td>
                <td><?php echo htmlspecialchars($student['university_name']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Year Level:</td>
                <td><?php echo htmlspecialchars($student['year_level_name']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Barangay:</td>
                <td><?php echo htmlspecialchars($student['barangay_name']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Application Date:</td>
                <td><?php echo date('M d, Y g:i A', strtotime($student['application_date'])); ?></td>
            </tr>
        </table>
    </div>
</div>

<!-- Document Verification Results -->
<div class="mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold text-primary mb-0">Document Verification Results</h6>
        <div>
            <?php 
            $score = $student['confidence_score'];
            $level = $student['confidence_level'];
            $badgeClass = '';
            if ($score >= 85) $badgeClass = 'bg-success';
            elseif ($score >= 70) $badgeClass = 'bg-primary';
            elseif ($score >= 50) $badgeClass = 'bg-warning';
            else $badgeClass = 'bg-danger';
            ?>
            <span class="badge <?php echo $badgeClass; ?> text-white me-2"><?php echo number_format($score, 1); ?>%</span>
            <span class="text-muted"><?php echo $level; ?></span>
        </div>
    </div>
    
    <div id="verificationResults">
        <?php if (empty($documents)): ?>
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>
                No documents uploaded yet. Waiting for student to submit required documents.
            </div>
        <?php else: ?>
            <div class="accordion" id="verificationAccordion">
                <?php 
                $index = 0;
                foreach ($documents as $code => $doc): 
                    $index++;
                    $docLabel = $docNames[$code] ?? 'Document';
                    $verificationData = null;
                    
                    // Get verification data from verification_details JSONB column
                    $verificationData = null;
                    $fullVerificationData = null;
                    if (!empty($doc['verification_details'])) {
                        $fullVerificationData = json_decode($doc['verification_details'], true);
                        
                        // For EAF: validation fields are nested under "verification" key
                        // But we keep $fullVerificationData for accessing extracted_data, found_text_snippets, etc.
                        if (isset($fullVerificationData['verification'])) {
                            // Use the nested verification object for validation checks
                            $verificationData = $fullVerificationData['verification'];
                        } else {
                            // For other document types, data is at top level
                            $verificationData = $fullVerificationData;
                        }
                    }
                    
                    $overallConf = floatval($doc['overall_confidence']);
                    $confBadge = $overallConf >= 85 ? 'success' : ($overallConf >= 70 ? 'primary' : ($overallConf >= 50 ? 'warning' : 'danger'));
                ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="heading<?php echo $index; ?>">
                            <button class="accordion-button <?php echo $index > 1 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $index; ?>">
                                <i class="bi bi-file-earmark-check text-<?php echo $confBadge; ?> me-2"></i>
                                <strong><?php echo htmlspecialchars($docLabel); ?></strong>
                                <span class="badge bg-<?php echo $confBadge; ?> ms-2"><?php echo number_format($overallConf, 1); ?>%</span>
                                <?php if ($doc['verification_status'] === 'passed'): ?>
                                    <span class="badge bg-success ms-1"><i class="bi bi-check-circle"></i> Passed</span>
                                <?php elseif ($doc['verification_status'] === 'failed'): ?>
                                    <span class="badge bg-danger ms-1"><i class="bi bi-x-circle"></i> Failed</span>
                                <?php elseif ($doc['verification_status'] === 'manual_review'): ?>
                                    <span class="badge bg-warning ms-1"><i class="bi bi-exclamation-triangle"></i> Review</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary ms-1">Pending</span>
                                <?php endif; ?>
                            </button>
                        </h2>
                        <div id="collapse<?php echo $index; ?>" class="accordion-collapse collapse <?php echo $index === 1 ? 'show' : ''; ?>" data-bs-parent="#verificationAccordion">
                            <div class="accordion-body">
                                <?php 
                                // Only show OCR Confidence for documents that have OCR metrics (EAF and Grades)
                                $hasOcrMetrics = in_array($code, ['00', '01']); // EAF and Grades
                                $colWidth = $hasOcrMetrics ? 'col-md-4' : 'col-md-6';
                                ?>
                                <div class="row g-3 mb-3">
                                    <?php if ($hasOcrMetrics): ?>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block">OCR Confidence</small>
                                        <h5 class="mb-0"><?php echo number_format($doc['ocr_confidence'] ?? 0, 1); ?>%</h5>
                                        <small class="text-muted">Text extraction quality</small>
                                    </div>
                                    <?php endif; ?>
                                    <div class="<?php echo $colWidth; ?>">
                                        <small class="text-muted d-block">Verification Score</small>
                                        <h5 class="mb-0"><?php echo number_format($doc['verification_score'] ?? 0, 1); ?>%</h5>
                                        <small class="text-muted">Validation checks passed</small>
                                    </div>
                                    <div class="<?php echo $colWidth; ?>">
                                        <small class="text-muted d-block">Overall Confidence</small>
                                        <h5 class="mb-0 text-<?php echo $confBadge; ?>"><?php echo number_format($overallConf, 1); ?>%</h5>
                                        <small class="text-muted">Combined score</small>
                                    </div>
                                </div>
                                
                                <?php if ($verificationData): ?>
                                    <hr>
                                    <h6 class="text-primary mb-3"><i class="bi bi-clipboard-check me-2"></i>Validation Details</h6>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="30%">Validation Check</th>
                                                    <th width="15%" class="text-center">Status</th>
                                                    <th width="45%">Details</th>
                                                    <th width="10%" class="text-center">Confidence</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                // Handle different .verify.json structures for different document types
                                                
                                                // ID Picture format: uses "checks" object with nested data
                                                if (isset($verificationData['checks'])):
                                                    foreach ($verificationData['checks'] as $checkKey => $checkData):
                                                        $checkName = ucwords(str_replace('_', ' ', $checkKey));
                                                        $passed = $checkData['passed'] ?? false;
                                                        $confidence = floatval($checkData['similarity'] ?? $checkData['confidence'] ?? 0);
                                                        
                                                        // Build found text from check data
                                                        $foundText = '';
                                                        if (isset($checkData['expected'])) {
                                                            $foundText = "Expected: " . htmlspecialchars($checkData['expected']);
                                                            if (isset($checkData['found_in_ocr'])) {
                                                                $foundText .= " | Found in OCR: " . ($checkData['found_in_ocr'] ? 'Yes' : 'No');
                                                            }
                                                            if (isset($checkData['note'])) {
                                                                $foundText .= " | " . htmlspecialchars($checkData['note']);
                                                            }
                                                        } elseif (isset($checkData['found_keywords'])) {
                                                            $foundText = "Found keywords: " . implode(', ', $checkData['found_keywords']) . 
                                                                        " (" . $checkData['found_count'] . "/" . $checkData['total_keywords'] . ")";
                                                        } elseif (isset($checkData['matched_words'])) {
                                                            $foundText = "Matched words: " . $checkData['matched_words'];
                                                        }
                                                        
                                                        $confClass = $confidence >= 85 ? 'success' : ($confidence >= 70 ? 'primary' : ($confidence >= 50 ? 'warning' : 'danger'));
                                                ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars($checkName); ?></strong></td>
                                                        <td class="text-center">
                                                            <span class="badge bg-<?php echo $passed ? 'success' : 'danger'; ?>">
                                                                <i class="bi bi-<?php echo $passed ? 'check-circle-fill' : 'x-circle-fill'; ?>"></i> 
                                                                <?php echo $passed ? 'Pass' : 'Fail'; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($foundText)): ?>
                                                                <code class="text-muted small"><?php echo $foundText; ?></code>
                                                            <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <?php if ($confidence > 0): ?>
                                                                <span class="badge bg-<?php echo $confClass; ?>"><?php echo number_format($confidence, 0); ?>%</span>
                                                            <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php 
                                                    endforeach;
                                                    
                                                // Grades/Indigency/Letter format: flat boolean fields with separate confidence_scores and found_text_snippets
                                                else:
                                                    foreach ($verificationData as $key => $value):
                                                        // Skip non-check fields
                                                        if (in_array($key, ['confidence_scores', 'found_text_snippets', 'overall_success', 'summary', 
                                                                            'ocr_text_preview', 'grades', 'failing_grades', 'enhanced_grade_validation',
                                                                            'course_validation', 'university_code', 'validation_method', 'admin_requirements',
                                                                            'extracted_data', 'tsv_quality', 'course_data'])) continue;
                                                        
                                                        // Check if this is a boolean field (e.g., first_name_match, year_level_match)
                                                        if (is_bool($value)):
                                                            $passed = $value;
                                                            $checkName = ucwords(str_replace('_', ' ', $key));
                                                            
                                                            // Get confidence score if available from nested or flat structure
                                                            $confidenceKey = str_replace('_match', '', $key);
                                                            $confidenceKey = str_replace('_found', '', $confidenceKey);
                                                            
                                                            // Try to find confidence in different locations
                                                            $dataSource = $fullVerificationData ?? $verificationData;
                                                            $confidence = 0;
                                                            
                                                            if (isset($verificationData['confidence_scores'][$confidenceKey])) {
                                                                $confidence = floatval($verificationData['confidence_scores'][$confidenceKey]);
                                                            } elseif (isset($dataSource['verification']['confidence_scores'][$confidenceKey])) {
                                                                $confidence = floatval($dataSource['verification']['confidence_scores'][$confidenceKey]);
                                                            } elseif (isset($dataSource['confidence_scores'][$confidenceKey])) {
                                                                $confidence = floatval($dataSource['confidence_scores'][$confidenceKey]);
                                                            }
                                                            
                                                            // Get found text - check both found_text_snippets and extracted_data
                                                            // Use $fullVerificationData for EAF since these are at top level
                                                            $foundText = '';
                                                            
                                                            if (isset($dataSource['found_text_snippets'][$confidenceKey])) {
                                                                $foundText = "Found: " . $dataSource['found_text_snippets'][$confidenceKey];
                                                            } elseif (isset($dataSource['extracted_data'])) {
                                                                $extractedData = $dataSource['extracted_data'];
                                                                
                                                                // Map key to extracted_data path
                                                                if ($key === 'first_name_match' && isset($extractedData['student_name']['first_name_similarity'])) {
                                                                    $foundText = "Expected: " . htmlspecialchars($student['first_name']) . 
                                                                               " | Similarity: " . $extractedData['student_name']['first_name_similarity'] . "%";
                                                                } elseif ($key === 'middle_name_match' && isset($extractedData['student_name']['middle_name_similarity'])) {
                                                                    $foundText = "Expected: " . htmlspecialchars($student['middle_name']) . 
                                                                               " | Similarity: " . $extractedData['student_name']['middle_name_similarity'] . "%";
                                                                } elseif ($key === 'last_name_match' && isset($extractedData['student_name']['last_name_similarity'])) {
                                                                    $foundText = "Expected: " . htmlspecialchars($student['last_name']) . 
                                                                               " | Similarity: " . $extractedData['student_name']['last_name_similarity'] . "%";
                                                                } elseif ($key === 'year_level_match' && isset($extractedData['year_level']['raw'])) {
                                                                    $foundText = "Found: " . htmlspecialchars($extractedData['year_level']['raw']);
                                                                } elseif ($key === 'university_match' && isset($extractedData['university'])) {
                                                                    $foundText = "Match confidence: " . number_format($extractedData['university']['confidence'] ?? 0, 1) . "%";
                                                                } elseif ($key === 'course_match' && isset($extractedData['course'])) {
                                                                    $foundText = "Found: " . htmlspecialchars($extractedData['course']['raw'] ?? 'N/A') . 
                                                                                " → " . htmlspecialchars($extractedData['course']['normalized'] ?? '');
                                                                } elseif ($key === 'barangay_match' && isset($extractedData['barangay'])) {
                                                                    $foundText = "Found: " . htmlspecialchars($extractedData['barangay']);
                                                                } elseif ($key === 'city_match' && isset($extractedData['city'])) {
                                                                    $foundText = "Found: " . htmlspecialchars($extractedData['city']);
                                                                } elseif ($key === 'document_keywords_found') {
                                                                    $foundText = "Document keywords validated";
                                                                }
                                                            } elseif (isset($dataSource[$confidenceKey])) {
                                                                // For flat fields like barangay, first_name (in Letter/Certificate)
                                                                $foundText = is_bool($dataSource[$confidenceKey]) ? 
                                                                            ($dataSource[$confidenceKey] ? 'Verified' : 'Not found') : 
                                                                            "Found: " . htmlspecialchars($dataSource[$confidenceKey]);
                                                            }
                                                            
                                                            $confClass = $confidence >= 85 ? 'success' : ($confidence >= 70 ? 'primary' : ($confidence >= 50 ? 'warning' : 'danger'));
                                                ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars($checkName); ?></strong></td>
                                                        <td class="text-center">
                                                            <span class="badge bg-<?php echo $passed ? 'success' : 'danger'; ?>">
                                                                <i class="bi bi-<?php echo $passed ? 'check-circle-fill' : 'x-circle-fill'; ?>"></i> 
                                                                <?php echo $passed ? 'Pass' : 'Fail'; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($foundText)): ?>
                                                                <code class="text-muted small"><?php echo $foundText; ?></code>
                                                            <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <?php if ($confidence > 0): ?>
                                                                <span class="badge bg-<?php echo $confClass; ?>"><?php echo number_format($confidence, 0); ?>%</span>
                                                            <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php 
                                                        endif; // end is_bool check
                                                    endforeach; // end foreach verificationData
                                                endif; // end format check
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <?php if (isset($verificationData['summary'])): ?>
                                        <div class="alert alert-info mt-3 mb-0">
                                            <i class="bi bi-info-circle me-2"></i>
                                            <strong>Summary:</strong>
                                            <?php 
                                            $summary = $verificationData['summary'];
                                            if (is_array($summary)) {
                                                echo "Passed: {$summary['passed_checks']}/{$summary['total_checks']} checks | ";
                                                echo "Average Confidence: " . number_format($summary['average_confidence'], 1) . "% | ";
                                                echo $summary['recommendation'];
                                            } else {
                                                echo htmlspecialchars($summary);
                                            }
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="viewStudentDocument('<?php echo htmlspecialchars($student_id); ?>','<?php echo $code; ?>')">
                                            <i class="bi bi-eye me-1"></i> View Document
                                        </button>
                                    </div>
                                    
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        No verification data available. Document may not have been processed yet.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="mt-4 d-flex gap-2">
    <button type="button" class="btn btn-success" 
            onclick="bootstrap.Modal.getInstance(document.getElementById('studentDetailsModal')).hide(); showActionModal('<?php echo $student['student_id']; ?>', 'approve', '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>')">
        <i class="bi bi-check-circle"></i> Approve Registration
    </button>
    <button type="button" class="btn btn-danger" 
            onclick="bootstrap.Modal.getInstance(document.getElementById('studentDetailsModal')).hide(); showActionModal('<?php echo $student['student_id']; ?>', 'reject', '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>')">
        <i class="bi bi-x-circle"></i> Reject Registration
    </button>
</div>

<?php pg_close($connection); ?>
