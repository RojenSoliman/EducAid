<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
include '../../config/database.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

// Handle validation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('validate_grades', $token)) {
        $_SESSION['error_message'] = 'Security validation failed. Please refresh and try again.';
        header('Location: validate_grades.php');
        exit;
    }
    
    $uploadId = intval($_POST['upload_id']);
    $action = $_POST['action'];
    $adminNotes = $_POST['admin_notes'] ?? '';
    $adminId = $_SESSION['admin_id'];
    
    try {
        if ($action === 'approve') {
            $status = 'passed';
            $message = 'Grades approved by administrator';
        } elseif ($action === 'reject') {
            $status = 'failed';
            $message = 'Grades rejected by administrator';
        } else {
            throw new Exception('Invalid action');
        }
        
        // Update grade upload
        $updateQuery = "UPDATE grade_uploads SET 
                       validation_status = $1,
                       admin_reviewed = TRUE,
                       admin_notes = $2,
                       reviewed_by = $3,
                       reviewed_at = CURRENT_TIMESTAMP
                       WHERE upload_id = $4";
        
        $result = pg_query_params($connection, $updateQuery, [$status, $adminNotes, $adminId, $uploadId]);
        
        if (!$result) {
            throw new Exception('Database update failed');
        }
        
        // Get student info and send notification
        $studentQuery = "SELECT s.student_id, s.first_name, s.last_name 
                        FROM students s 
                        JOIN grade_uploads gu ON s.student_id = gu.student_id 
                        WHERE gu.upload_id = $1";
        $studentResult = pg_query_params($connection, $studentQuery, [$uploadId]);
        $student = pg_fetch_assoc($studentResult);
        
        if ($student) {
            $notificationMsg = $message . ($adminNotes ? '. Note: ' . $adminNotes : '');
            $notifQuery = "INSERT INTO notifications (student_id, message) VALUES ($1, $2)";
            pg_query_params($connection, $notifQuery, [$student['student_id'], $notificationMsg]);
        }
        
        $_SESSION['success_message'] = ucfirst($action) . ' completed successfully';
        header('Location: validate_grades.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    }
}

// Fetch all grade uploads needing review
$query = "
    SELECT gu.*, s.first_name, s.last_name, s.student_id,
           COUNT(eg.grade_id) as total_grades,
           COUNT(CASE WHEN eg.is_passing = TRUE THEN 1 END) as passing_grades,
           AVG(eg.grade_numeric) as average_gpa,
           AVG(eg.grade_percentage) as average_percentage
    FROM grade_uploads gu
    JOIN students s ON gu.student_id = s.student_id
    LEFT JOIN extracted_grades eg ON gu.upload_id = eg.upload_id
    WHERE gu.admin_reviewed = FALSE
    GROUP BY gu.upload_id, s.student_id, s.first_name, s.last_name, s.student_id
    ORDER BY gu.upload_date DESC
";

$uploads = pg_query($connection, $query);

// Generate CSRF token for all forms on this page
$csrfToken = CSRFProtection::generateToken('validate_grades');
?>

<?php $page_title='Validate Grades'; $extra_css=[]; include '../../includes/admin/admin_head.php'; ?>
<style>
        .confidence-high { color: #28a745; font-weight: 600; }
        .confidence-medium { color: #ffc107; font-weight: 600; }
        .confidence-low { color: #dc3545; font-weight: 600; }
        .status-passed { background-color: #d4edda; color: #155724; }
        .status-failed { background-color: #f8d7da; color: #721c24; }
        .status-review { background-color: #fff3cd; color: #856404; }
        .grade-detail { font-size: 0.9em; }
        .gpa-indicator { 
            padding: 8px 12px; 
            border-radius: 15px; 
            font-weight: 600; 
            font-size: 0.9em;
        }
        .gpa-excellent { background: #d4edda; color: #155724; }
        .gpa-good { background: #cce5ff; color: #004085; }
        .gpa-fair { background: #fff3cd; color: #856404; }
        .gpa-poor { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>
    
    <section class="home-section" id="mainContent">
        
    <div class="container-fluid py-4 px-4">
            <div class="section-header mb-4">
                <h2 class="fw-bold text-primary">
                    <i class="bi bi-file-earmark-check me-2"></i>
                    Validate Student Grades
                </h2>
                <p class="text-muted">Review OCR-processed grades using Philippine grading standards (75% / 3.00 minimum)</p>
            </div>
            
            <!-- Flash Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i>
                    <?= htmlspecialchars($_SESSION['success_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?= htmlspecialchars($_SESSION['error_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>
            
            <div class="row">
                <?php if (pg_num_rows($uploads) === 0): ?>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="bi bi-inbox display-4 text-muted mb-3"></i>
                                <h4>No Grades to Review</h4>
                                <p class="text-muted">All uploaded grades have been reviewed.</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php while ($upload = pg_fetch_assoc($uploads)): ?>
                        <div class="col-lg-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <?= htmlspecialchars($upload['first_name'] . ' ' . $upload['last_name']) ?>
                                    </h5>
                                    <span class="badge <?= 
                                        $upload['validation_status'] === 'passed' ? 'bg-success' : 
                                        ($upload['validation_status'] === 'failed' ? 'bg-danger' : 'bg-warning') 
                                    ?>">
                                        <?= ucfirst($upload['validation_status']) ?>
                                    </span>
                                </div>
                                
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-sm-6">
                                            <small class="text-muted">Student ID:</small><br>
                                            <strong><?= htmlspecialchars($upload['student_id']) ?></strong>
                                        </div>
                                        <div class="col-sm-6">
                                            <small class="text-muted">OCR Confidence:</small><br>
                                            <strong class="<?= 
                                                $upload['ocr_confidence'] >= 80 ? 'confidence-high' : 
                                                ($upload['ocr_confidence'] >= 60 ? 'confidence-medium' : 'confidence-low') 
                                            ?>">
                                                <?= round($upload['ocr_confidence'], 1) ?>%
                                            </strong>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-sm-6">
                                            <small class="text-muted">Total Subjects:</small><br>
                                            <strong><?= $upload['total_grades'] ?></strong>
                                        </div>
                                        <div class="col-sm-6">
                                            <small class="text-muted">Passing Grades:</small><br>
                                            <strong class="<?= $upload['passing_grades'] >= $upload['total_grades'] * 0.7 ? 'text-success' : 'text-danger' ?>">
                                                <?= $upload['passing_grades'] ?>/<?= $upload['total_grades'] ?>
                                            </strong>
                                        </div>
                                    </div>
                                    
                                    <?php if ($upload['average_gpa']): ?>
                                        <div class="row mb-3">
                                            <div class="col-sm-6">
                                                <small class="text-muted">Average GPA:</small><br>
                                                <span class="gpa-indicator <?= 
                                                    $upload['average_gpa'] <= 1.5 ? 'gpa-excellent' : 
                                                    ($upload['average_gpa'] <= 2.5 ? 'gpa-good' : 
                                                    ($upload['average_gpa'] <= 3.0 ? 'gpa-fair' : 'gpa-poor'))
                                                ?>">
                                                    <?= round($upload['average_gpa'], 2) ?>
                                                </span>
                                            </div>
                                            <div class="col-sm-6">
                                                <small class="text-muted">Average %:</small><br>
                                                <span class="gpa-indicator <?= 
                                                    $upload['average_percentage'] >= 90 ? 'gpa-excellent' : 
                                                    ($upload['average_percentage'] >= 80 ? 'gpa-good' : 
                                                    ($upload['average_percentage'] >= 75 ? 'gpa-fair' : 'gpa-poor'))
                                                ?>">
                                                    <?= round($upload['average_percentage'], 1) ?>%
                                                </span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted">Upload Date:</small><br>
                                        <strong><?= date('M j, Y g:i A', strtotime($upload['upload_date'])) ?></strong>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <button class="btn btn-outline-primary btn-sm" 
                                                onclick="viewGradeDetails(<?= $upload['upload_id'] ?>)">
                                            <i class="bi bi-eye me-1"></i>View Details
                                        </button>
                                        <button class="btn btn-outline-secondary btn-sm" 
                                                onclick="viewOriginalDocument('<?= htmlspecialchars($upload['file_path']) ?>')">
                                            <i class="bi bi-file-text me-1"></i>Original Document
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="card-footer">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="upload_id" value="<?= $upload['upload_id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="admin_notes" value="">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <button type="submit" class="btn btn-success btn-sm" 
                                                onclick="return confirm('Approve these grades?')">
                                            <i class="bi bi-check-circle me-1"></i>Approve
                                        </button>
                                    </form>
                                    
                                    <form method="POST" class="d-inline ms-2">
                                        <input type="hidden" name="upload_id" value="<?= $upload['upload_id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="admin_notes" value="">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" 
                                                onclick="return confirm('Reject these grades?')">
                                            <i class="bi bi-x-circle me-1"></i>Reject
                                        </button>
                                    </form>
                                    
                                    <button class="btn btn-warning btn-sm ms-2" 
                                            onclick="showNotesModal(<?= $upload['upload_id'] ?>)">
                                        <i class="bi bi-chat-text me-1"></i>Add Notes
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<!-- Notes Modal -->
<div class="modal fade" id="notesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Review Notes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="notesForm">
                <div class="modal-body">
                    <input type="hidden" name="upload_id" id="modal_upload_id">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="mb-3">
                        <label class="form-label">Action:</label>
                        <select name="action" class="form-select" required>
                            <option value="">Select action...</option>
                            <option value="approve">Approve</option>
                            <option value="reject">Reject</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes (optional):</label>
                        <textarea name="admin_notes" class="form-control" rows="3" 
                                placeholder="Add any comments about this grade review..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Review</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Grade Details Modal -->
<div class="modal fade" id="gradeDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Grade Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="gradeDetailsContent">
                <div class="text-center">
                    <div class="spinner-border" role="status"></div>
                    <p>Loading grade details...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script>
function showNotesModal(uploadId) {
    document.getElementById('modal_upload_id').value = uploadId;
    new bootstrap.Modal(document.getElementById('notesModal')).show();
}

function viewOriginalDocument(filePath) {
    window.open(filePath, '_blank');
}

async function viewGradeDetails(uploadId) {
    const modal = new bootstrap.Modal(document.getElementById('gradeDetailsModal'));
    const content = document.getElementById('gradeDetailsContent');
    
    modal.show();
    
    try {
        const response = await fetch(`get_grade_details.php?upload_id=${uploadId}`);
        const data = await response.json();
        
        if (data.success) {
            content.innerHTML = `
                <div class="row mb-3">
                    <div class="col-md-4">
                        <h6>OCR Information</h6>
                        <p><strong>Confidence:</strong> ${data.confidence}%</p>
                        <p><strong>Status:</strong> <span class="badge status-${data.status}">${data.status}</span></p>
                    </div>
                    <div class="col-md-4">
                        <h6>Grade Summary</h6>
                        <p><strong>Total Subjects:</strong> ${data.grades.length}</p>
                        <p><strong>Passing:</strong> ${data.grades.filter(g => g.is_passing).length}</p>
                    </div>
                    <div class="col-md-4">
                        <h6>Averages</h6>
                        <p><strong>GPA:</strong> ${data.average_gpa}</p>
                        <p><strong>Percentage:</strong> ${data.average_percentage}%</p>
                    </div>
                </div>
                
                <h6>Extracted Grades:</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Original Grade</th>
                                <th>GPA</th>
                                <th>Percentage</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.grades.map(grade => `
                                <tr>
                                    <td>${grade.subject_name}</td>
                                    <td>${grade.grade_value}</td>
                                    <td>${grade.grade_numeric}</td>
                                    <td>${grade.grade_percentage}%</td>
                                    <td>
                                        <span class="badge ${grade.is_passing ? 'bg-success' : 'bg-danger'}">
                                            ${grade.is_passing ? 'Pass' : 'Fail'}
                                        </span>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                
                <h6>Extracted Text:</h6>
                <div class="border rounded p-3 bg-light" style="max-height: 200px; overflow-y: auto;">
                    <pre style="white-space: pre-wrap; margin: 0; font-size: 0.9em;">${data.extracted_text}</pre>
                </div>
            `;
        } else {
            content.innerHTML = `<div class="alert alert-danger">Error loading grade details: ${data.message}</div>`;
        }
    } catch (error) {
        content.innerHTML = `<div class="alert alert-danger">Error loading grade details: ${error.message}</div>`;
    }
}
</script>
</body>
</html>
