<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username']) || !isset($_GET['id'])) {
    http_response_code(403);
    exit;
}

$student_id = trim($_GET['id']); // Remove intval for TEXT student_id

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
    echo '<div class="alert alert-warning">Student not found or already processed.</div>';
    exit;
}

// Fetch documents (latest per type) using document_type_code
$docQuery = "SELECT document_type_code, file_path, ocr_text_path, verification_data_path, 
                    ocr_confidence, verification_score, verification_status, status
             FROM documents 
             WHERE student_id = $1 
             ORDER BY upload_date DESC";
$docResult = pg_query_params($connection, $docQuery, [$student_id]);
$documents = [];
if ($docResult) {
    while ($row = pg_fetch_assoc($docResult)) {
        $code = $row['document_type_code'];
        if (!isset($documents[$code])) { // keep first (latest due to DESC)
            // Calculate overall document confidence as average of OCR and verification
            $ocr_conf = floatval($row['ocr_confidence'] ?? 0);
            $verif_score = floatval($row['verification_score'] ?? 0);
            $row['overall_confidence'] = ($ocr_conf + $verif_score) / 2;
            $documents[$code] = $row;
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
                    $verificationPath = $doc['verification_data_path'];
                    $verificationData = null;
                    
                    // Try to load verification JSON if it exists
                    if ($verificationPath && file_exists($verificationPath)) {
                        $verificationData = json_decode(file_get_contents($verificationPath), true);
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
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <small class="text-muted d-block">OCR Confidence</small>
                                        <h5 class="mb-0"><?php echo number_format($doc['ocr_confidence'] ?? 0, 1); ?>%</h5>
                                        <small class="text-muted">Text extraction quality</small>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block">Verification Score</small>
                                        <h5 class="mb-0"><?php echo number_format($doc['verification_score'] ?? 0, 1); ?>%</h5>
                                        <small class="text-muted">Validation checks passed</small>
                                    </div>
                                    <div class="col-md-4">
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
                                                // Parse the actual JSON structure
                                                foreach ($verificationData as $key => $value):
                                                    // Skip non-check fields
                                                    if (in_array($key, ['confidence_scores', 'found_text_snippets', 'overall_success', 'summary', 'ocr_text_preview'])) continue;
                                                    
                                                    // Check if this is a boolean field (e.g., first_name_match, year_level_match)
                                                    if (is_bool($value)):
                                                        $passed = $value;
                                                        $checkName = ucwords(str_replace('_', ' ', $key));
                                                        
                                                        // Get confidence score if available
                                                        $confidenceKey = str_replace('_match', '', $key);
                                                        $confidence = isset($verificationData['confidence_scores'][$confidenceKey]) 
                                                            ? floatval($verificationData['confidence_scores'][$confidenceKey]) 
                                                            : 0;
                                                        
                                                        // Get found text if available
                                                        $foundText = isset($verificationData['found_text_snippets'][$confidenceKey])
                                                            ? $verificationData['found_text_snippets'][$confidenceKey]
                                                            : '';
                                                        
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
                                                            <?php if ($foundText): ?>
                                                                <div class="mb-1">Found: <?php echo htmlspecialchars(substr($foundText, 0, 100)); ?><?php echo strlen($foundText) > 100 ? '...' : ''; ?></div>
                                                            <?php else: ?>
                                                                <div class="text-muted">-</div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <?php if ($confidence > 0): ?>
                                                                <span class="badge bg-<?php echo $confClass; ?>"><?php echo number_format($confidence, 1); ?>%</span>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php 
                                                    endif; // end is_bool check
                                                endforeach; // end foreach verificationData
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
