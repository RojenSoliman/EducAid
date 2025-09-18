<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username']) || !isset($_GET['id'])) {
    http_response_code(403);
    exit;
}

$student_id = trim($_GET['id']); // Remove intval for TEXT student_id

$query = "SELECT s.*, b.name as barangay_name, u.name as university_name, yl.name as year_level_name,
                 ef.file_path as enrollment_form_path, ef.original_filename,
                 COALESCE(s.confidence_score, calculate_confidence_score(s.student_id)) as confidence_score,
                 get_confidence_level(COALESCE(s.confidence_score, calculate_confidence_score(s.student_id))) as confidence_level
          FROM students s
          LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
          LEFT JOIN universities u ON s.university_id = u.university_id
          LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
          LEFT JOIN enrollment_forms ef ON s.student_id = ef.student_id
          WHERE s.student_id = $1 AND s.status = 'under_registration'";

$result = pg_query_params($connection, $query, [$student_id]);
$student = pg_fetch_assoc($result);

if (!$student) {
    echo '<div class="alert alert-warning">Student not found or already processed.</div>';
    exit;
}
// Fetch documents (latest per type)
$docQuery = "SELECT type, file_path FROM documents WHERE student_id = $1 ORDER BY upload_date DESC";
$docResult = pg_query_params($connection, $docQuery, [$student_id]);
$documents = [];
if ($docResult) {
    while ($row = pg_fetch_assoc($docResult)) {
        $type = $row['type'];
        if (!isset($documents[$type])) { // keep first (latest due to DESC)
            $documents[$type] = $row['file_path'];
        }
    }
}
// Friendly names
$docNames = [
    'eaf' => 'Enrollment Assessment Form',
    'letter_to_mayor' => 'Letter to Mayor',
    'certificate_of_indigency' => 'Certificate of Indigency'
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

<!-- Confidence Score Breakdown -->
<div class="mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold text-primary mb-0">Confidence Score Analysis</h6>
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
    
    <div id="confidenceBreakdown">
        <div class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-primary" role="status">
                <span class="visually-hidden">Loading confidence breakdown...</span>
            </div>
            <small class="d-block mt-2 text-muted">Loading detailed analysis...</small>
        </div>
    </div>
</div>

<script>
// Load confidence breakdown
fetch(`get_confidence_breakdown.php?id=<?php echo $student_id; ?>`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayConfidenceBreakdown(data.breakdown);
        } else {
            document.getElementById('confidenceBreakdown').innerHTML = '<div class="alert alert-warning">Could not load confidence breakdown.</div>';
        }
    })
    .catch(error => {
        document.getElementById('confidenceBreakdown').innerHTML = '<div class="alert alert-danger">Error loading confidence breakdown.</div>';
    });

function displayConfidenceBreakdown(breakdown) {
    const container = document.getElementById('confidenceBreakdown');
    
    let html = '<div class="row">';
    
    // Personal Information
    html += `
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-header bg-light py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="fw-bold text-primary">Personal Information</small>
                        <span class="badge bg-secondary">${breakdown.personal.score}/${breakdown.personal.max_score} pts</span>
                    </div>
                </div>
                <div class="card-body py-2">
                    <small class="text-muted d-block mb-2">${breakdown.personal.summary}</small>
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless mb-0">`;
    
    breakdown.personal.details.forEach(detail => {
        const iconClass = detail.value === '✓' ? 'text-success' : 'text-danger';
        html += `
            <tr>
                <td class="py-1"><small>${detail.field}:</small></td>
                <td class="py-1 text-end"><small class="${iconClass}">${detail.value}</small></td>
            </tr>`;
    });
    
    html += `
                        </table>
                    </div>
                </div>
            </div>
        </div>`;
    
    // Document Upload
    html += `
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-header bg-light py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="fw-bold text-primary">Document Upload</small>
                        <span class="badge bg-secondary">${breakdown.documents.score}/${breakdown.documents.max_score} pts</span>
                    </div>
                </div>
                <div class="card-body py-2">
                    <small class="text-muted d-block mb-2">${breakdown.documents.summary}</small>
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless mb-0">`;
    
    breakdown.documents.details.forEach(detail => {
        const iconClass = detail.status === 'Uploaded' ? 'text-success' : 'text-danger';
        const icon = detail.status === 'Uploaded' ? '✓' : '✗';
        html += `
            <tr>
                <td class="py-1"><small>${detail.document}:</small></td>
                <td class="py-1 text-end"><small class="${iconClass}">${icon} (+${detail.points})</small></td>
            </tr>`;
    });
    
    html += `
                        </table>
                    </div>
                </div>
            </div>
        </div>`;
    
    // OCR & Verification
    html += `
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-header bg-light py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="fw-bold text-primary">Document Quality</small>
                        <span class="badge bg-secondary">${breakdown.ocr.score.toFixed(1)}/${breakdown.ocr.max_score} pts</span>
                    </div>
                </div>
                <div class="card-body py-2">
                    <small class="text-muted">${breakdown.ocr.summary}</small>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-header bg-light py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="fw-bold text-primary">Email Verification</small>
                        <span class="badge bg-secondary">${breakdown.verification.score}/${breakdown.verification.max_score} pts</span>
                    </div>
                </div>
                <div class="card-body py-2">
                    <small class="text-muted">${breakdown.verification.summary}</small>
                </div>
            </div>
        </div>`;
    
    html += '</div>';
    
    // Total Score Summary
    html += `
        <div class="alert alert-info mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>Total Confidence Score</strong>
                    <br><small class="text-muted">Based on data completeness, document uploads, and verification status</small>
                </div>
                <div class="text-end">
                    <h4 class="mb-0">${breakdown.total.score.toFixed(1)}%</h4>
                    <small class="text-muted">${breakdown.total.score}/${breakdown.total.max_score} points</small>
                </div>
            </div>
        </div>`;
    
    container.innerHTML = html;
}
</script>
<!-- Documents Section -->
<div class="mt-4">
    <h6 class="fw-bold text-primary mb-3">Documents</h6>
    <div class="row g-3">
        <?php foreach ($docNames as $type => $label): $has = isset($documents[$type]); ?>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center mb-1">
                            <i class="bi bi-file-earmark<?php echo $has ? '-check text-success' : ' text-muted'; ?> me-2"></i>
                            <strong class="small mb-0"><?php echo htmlspecialchars($label); ?></strong>
                        </div>
                        <small class="text-muted d-block">Status: <?php echo $has ? '<span class="text-success">Uploaded</span>' : '<span class="text-danger">Missing</span>'; ?></small>
                    </div>
                    <div class="mt-2">
                        <button type="button" class="btn btn-outline-primary btn-sm w-100" 
                                <?php if ($has): ?>onclick="viewStudentDocument('<?php echo htmlspecialchars($student['student_id']); ?>','<?php echo $type; ?>')"<?php else: ?>disabled<?php endif; ?>>
                            <i class="bi bi-eye"></i> View
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
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
