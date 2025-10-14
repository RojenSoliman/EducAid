<?php
/**
 * Distribution Control Center
 * Main hub for managing distribution lifecycle
 */
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

include '../../config/database.php';
include '../../includes/permissions.php';
include '../../includes/workflow_control.php';
include '../../includes/CSRFProtection.php';

// Check if user is super admin
$admin_role = getCurrentAdminRole($connection);
if ($admin_role !== 'super_admin') {
    header("Location: dashboard.php");
    exit;
}

$workflow_status = getWorkflowStatus($connection);
$student_counts = getStudentCounts($connection);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !CSRFProtection::validateToken('distribution_control', $_POST['csrf_token'])) {
        $success = false;
        $message = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'];
        $success = false;
        $message = '';
    
    switch ($action) {
        case 'start_distribution':
            // Set distribution status to preparing
            $query = "INSERT INTO config (key, value) VALUES ('distribution_status', 'preparing') 
                      ON CONFLICT (key) DO UPDATE SET value = 'preparing'";
            if (pg_query($connection, $query)) {
                // Enable uploads by default when starting
                $upload_query = "INSERT INTO config (key, value) VALUES ('uploads_enabled', '1') 
                                ON CONFLICT (key) DO UPDATE SET value = '1'";
                pg_query($connection, $upload_query);
                
                $success = true;
                $message = 'Distribution cycle started successfully! You can now optionally open slots for new registrations.';
            } else {
                $message = 'Failed to start distribution cycle.';
            }
            break;
            
        case 'activate_distribution':
            // Set distribution to active (ready for operations)
            $query = "INSERT INTO config (key, value) VALUES ('distribution_status', 'active') 
                      ON CONFLICT (key) DO UPDATE SET value = 'active'";
            if (pg_query($connection, $query)) {
                $success = true;
                $message = 'Distribution activated! All systems are now operational.';
            } else {
                $message = 'Failed to activate distribution.';
            }
            break;
            
        case 'open_slots':
            $query = "INSERT INTO config (key, value) VALUES ('slots_open', '1') 
                      ON CONFLICT (key) DO UPDATE SET value = '1'";
            if (pg_query($connection, $query)) {
                $success = true;
                $message = 'Registration slots opened! New students can now register.';
            } else {
                $message = 'Failed to open registration slots.';
            }
            break;
            
        case 'close_slots':
            $query = "INSERT INTO config (key, value) VALUES ('slots_open', '0') 
                      ON CONFLICT (key) DO UPDATE SET value = '0'";
            if (pg_query($connection, $query)) {
                $success = true;
                $message = 'Registration slots closed.';
            } else {
                $message = 'Failed to close registration slots.';
            }
            break;
            
        case 'disable_uploads':
            $query = "INSERT INTO config (key, value) VALUES ('uploads_enabled', '0') 
                      ON CONFLICT (key) DO UPDATE SET value = '0'";
            if (pg_query($connection, $query)) {
                $success = true;
                $message = 'Document uploads disabled for all students.';
            } else {
                $message = 'Failed to disable uploads.';
            }
            break;
            
        case 'enable_uploads':
            $query = "INSERT INTO config (key, value) VALUES ('uploads_enabled', '1') 
                      ON CONFLICT (key) DO UPDATE SET value = '1'";
            if (pg_query($connection, $query)) {
                $success = true;
                $message = 'Document uploads enabled for existing students.';
            } else {
                $message = 'Failed to enable uploads.';
            }
            break;
            
        case 'finalize_distribution':
            // Archive documents, close everything, set to finalized
            $begin_result = pg_query($connection, "BEGIN");
            if (!$begin_result) {
                $message = 'Failed to start transaction: ' . pg_last_error($connection);
                break;
            }
            
            try {
                // Check if necessary tables exist before proceeding
                $tables_check = pg_query($connection, "
                    SELECT 
                        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name='uploaded_documents') as has_uploads,
                        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name='document_archives') as has_archives,
                        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name='distribution_snapshots') as has_snapshots
                ");
                
                if (!$tables_check) {
                    throw new Exception('Failed to check table existence: ' . pg_last_error($connection));
                }
                
                $table_status = pg_fetch_assoc($tables_check);
                $archived_count = 0;
                
                // Archive documents only if both tables exist and there are documents to archive
                if ($table_status['has_uploads'] === 't' && $table_status['has_archives'] === 't') {
                    // Check if there are any documents to archive
                    $count_result = pg_query($connection, "SELECT COUNT(*) as doc_count FROM uploaded_documents");
                    if ($count_result) {
                        $count_data = pg_fetch_assoc($count_result);
                        $archived_count = intval($count_data['doc_count']);
                        
                        if ($archived_count > 0) {
                            // Archive all current documents
                            $archive_query = "
                                INSERT INTO document_archives (
                                    student_id, document_type, file_path, uploaded_date,
                                    academic_year, semester, archived_date
                                )
                                SELECT 
                                    student_id, document_type, file_path, uploaded_date,
                                    EXTRACT(YEAR FROM CURRENT_DATE)::text as academic_year,
                                    CASE 
                                        WHEN EXTRACT(MONTH FROM CURRENT_DATE) BETWEEN 1 AND 5 THEN 'Spring'
                                        WHEN EXTRACT(MONTH FROM CURRENT_DATE) BETWEEN 6 AND 8 THEN 'Summer'
                                        ELSE 'Fall'
                                    END as semester,
                                    CURRENT_TIMESTAMP
                                FROM uploaded_documents
                            ";
                            $archive_result = pg_query($connection, $archive_query);
                            if (!$archive_result) {
                                throw new Exception('Failed to archive documents: ' . pg_last_error($connection));
                            }
                        }
                        
                        // Clear current documents
                        $clear_result = pg_query($connection, "DELETE FROM uploaded_documents");
                        if (!$clear_result) {
                            throw new Exception('Failed to clear documents: ' . pg_last_error($connection));
                        }
                    }
                }
                
                // Create distribution snapshot only if table exists
                if ($table_status['has_snapshots'] === 't') {
                    $snapshot_query = "
                        INSERT INTO distribution_snapshots (
                            distribution_date, academic_year, semester, 
                            total_students_count, location, notes
                        ) VALUES (
                            CURRENT_DATE,
                            EXTRACT(YEAR FROM CURRENT_DATE)::text,
                            CASE 
                                WHEN EXTRACT(MONTH FROM CURRENT_DATE) BETWEEN 1 AND 5 THEN 'Spring'
                                WHEN EXTRACT(MONTH FROM CURRENT_DATE) BETWEEN 6 AND 8 THEN 'Summer'
                                ELSE 'Fall'
                            END,
                            (SELECT COUNT(*) FROM students WHERE status = 'active'),
                            'Main Distribution Center',
                            'Automated distribution finalization'
                        )
                    ";
                    $snapshot_result = pg_query($connection, $snapshot_query);
                    if (!$snapshot_result) {
                        throw new Exception('Failed to create distribution snapshot: ' . pg_last_error($connection));
                    }
                }
                
                // Set status to finalized and close everything
                $status_queries = [
                    "INSERT INTO config (key, value) VALUES ('distribution_status', 'finalized') ON CONFLICT (key) DO UPDATE SET value = 'finalized'",
                    "INSERT INTO config (key, value) VALUES ('slots_open', '0') ON CONFLICT (key) DO UPDATE SET value = '0'",
                    "INSERT INTO config (key, value) VALUES ('uploads_enabled', '0') ON CONFLICT (key) DO UPDATE SET value = '0'"
                ];
                
                foreach ($status_queries as $query) {
                    $result = pg_query($connection, $query);
                    if (!$result) {
                        throw new Exception('Failed to update configuration: ' . pg_last_error($connection));
                    }
                }
                
                $commit_result = pg_query($connection, "COMMIT");
                if (!$commit_result) {
                    throw new Exception('Failed to commit transaction: ' . pg_last_error($connection));
                }
                
                $success = true;
                $message = "Distribution finalized successfully! $archived_count documents archived and system reset for next cycle.";
                
            } catch (Exception $e) {
                pg_query($connection, "ROLLBACK");
                $message = 'Failed to finalize distribution: ' . $e->getMessage();
            }
            break;
    }
    
        // Refresh workflow status after action
        $workflow_status = getWorkflowStatus($connection);
        $student_counts = getStudentCounts($connection);
    }
}

// Get distribution history
$history_query = "
    SELECT * FROM distribution_snapshots 
    ORDER BY distribution_date DESC 
    LIMIT 5
";
$history_result = pg_query($connection, $history_query);
?>

<!DOCTYPE html>
<html lang="en">
<?php $page_title='Distribution Control Center'; include '../../includes/admin/admin_head.php'; ?>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>

<div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>
    
    <section class="home-section" id="mainContent">
        <div class="container-fluid py-4">
            <!-- Status Messages -->
            <?php if (isset($message)): ?>
                <div class="alert alert-<?= $success ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <div>
                                    <h1 class="h3 mb-1">
                                        <i class="bi bi-gear-fill text-primary me-2"></i>
                                        Distribution Control Center
                                    </h1>
                                    <p class="text-muted mb-0">Manage the complete distribution lifecycle</p>
                                </div>
                                <div class="text-end">
                                    <?php
                                    $statusColors = [
                                        'inactive' => 'secondary',
                                        'preparing' => 'warning',
                                        'active' => 'success',
                                        'finalizing' => 'info',
                                        'finalized' => 'primary'
                                    ];
                                    $statusColor = $statusColors[$workflow_status['distribution_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $statusColor ?> fs-6">
                                        Status: <?= ucfirst($workflow_status['distribution_status']) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Current Status Overview -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <i class="bi bi-people-fill display-6 text-info mb-2"></i>
                                            <h5 class="mb-1"><?= $student_counts['active_count'] ?></h5>
                                            <small class="text-muted">Active Students</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <i class="bi bi-door-<?= $workflow_status['slots_open'] ? 'open' : 'closed' ?> display-6 text-<?= $workflow_status['slots_open'] ? 'success' : 'danger' ?> mb-2"></i>
                                            <h5 class="mb-1"><?= $workflow_status['slots_open'] ? 'Open' : 'Closed' ?></h5>
                                            <small class="text-muted">Registration Slots</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <i class="bi bi-cloud-upload display-6 text-<?= $workflow_status['uploads_enabled'] ? 'success' : 'danger' ?> mb-2"></i>
                                            <h5 class="mb-1"><?= $workflow_status['uploads_enabled'] ? 'Enabled' : 'Disabled' ?></h5>
                                            <small class="text-muted">Document Uploads</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <i class="bi bi-person-plus display-6 text-warning mb-2"></i>
                                            <h5 class="mb-1"><?= $student_counts['applicant_count'] ?></h5>
                                            <small class="text-muted">Pending Applicants</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="row g-3">
                                <?php if ($workflow_status['can_start_distribution']): ?>
                                    <div class="col-md-6">
                                        <div class="card border-success">
                                            <div class="card-body">
                                                <h5 class="card-title text-success">
                                                    <i class="bi bi-play-circle me-2"></i>Start New Distribution
                                                </h5>
                                                <p class="card-text">Begin a new distribution cycle. This will enable document uploads and prepare the system.</p>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="start_distribution">
                                                    <?= CSRFProtection::getTokenField('distribution_control') ?>
                                                    <button type="submit" class="btn btn-success" onclick="return confirm('Start a new distribution cycle?')">
                                                        <i class="bi bi-play-fill me-1"></i>Start Distribution
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($workflow_status['distribution_status'] === 'preparing'): ?>
                                    <div class="col-md-6">
                                        <div class="card border-primary">
                                            <div class="card-body">
                                                <h5 class="card-title text-primary">
                                                    <i class="bi bi-check-circle me-2"></i>Activate Distribution
                                                </h5>
                                                <p class="card-text">Activate the distribution to make all systems operational.</p>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="activate_distribution">
                                                    <?= CSRFProtection::getTokenField('distribution_control') ?>
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="bi bi-lightning-fill me-1"></i>Activate
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($workflow_status['can_open_slots']): ?>
                                    <div class="col-md-6">
                                        <div class="card border-info">
                                            <div class="card-body">
                                                <h5 class="card-title text-info">
                                                    <i class="bi bi-door-open me-2"></i>Registration Slots
                                                </h5>
                                                <p class="card-text">Control new student registration availability (Optional).</p>
                                                <?php if (!$workflow_status['slots_open']): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="open_slots">
                                                        <?= CSRFProtection::getTokenField('distribution_control') ?>
                                                        <button type="submit" class="btn btn-info">
                                                            <i class="bi bi-door-open me-1"></i>Open Slots
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="close_slots">
                                                        <?= CSRFProtection::getTokenField('distribution_control') ?>
                                                        <button type="submit" class="btn btn-outline-info">
                                                            <i class="bi bi-door-closed me-1"></i>Close Slots
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (in_array($workflow_status['distribution_status'], ['preparing', 'active'])): ?>
                                    <div class="col-md-6">
                                        <div class="card border-warning">
                                            <div class="card-body">
                                                <h5 class="card-title text-warning">
                                                    <i class="bi bi-cloud-upload me-2"></i>Document Uploads
                                                </h5>
                                                <p class="card-text">Control document upload availability for existing students.</p>
                                                <?php if (!$workflow_status['uploads_enabled']): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="enable_uploads">
                                                        <?= CSRFProtection::getTokenField('distribution_control') ?>
                                                        <button type="submit" class="btn btn-warning">
                                                            <i class="bi bi-cloud-upload me-1"></i>Enable Uploads
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="disable_uploads">
                                                        <?= CSRFProtection::getTokenField('distribution_control') ?>
                                                        <button type="submit" class="btn btn-outline-warning">
                                                            <i class="bi bi-cloud-slash me-1"></i>Disable Uploads
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($workflow_status['can_finalize_distribution']): ?>
                                    <div class="col-md-12">
                                        <div class="card border-danger">
                                            <div class="card-body">
                                                <h5 class="card-title text-danger">
                                                    <i class="bi bi-check2-square me-2"></i>Finalize Distribution
                                                </h5>
                                                <p class="card-text">
                                                    <strong>Warning:</strong> This will archive all current documents, close registration and uploads, 
                                                    and prepare the system for the next cycle. This action cannot be undone.
                                                </p>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="finalize_distribution">
                                                    <?= CSRFProtection::getTokenField('distribution_control') ?>
                                                    <button type="submit" class="btn btn-danger" 
                                                            onclick="return confirm('Are you sure you want to finalize this distribution? All documents will be archived and the system will reset.')">
                                                        <i class="bi bi-archive me-1"></i>Finalize Distribution
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Distribution History -->
                            <?php if ($history_result && pg_num_rows($history_result) > 0): ?>
                                <div class="mt-5">
                                    <h4 class="mb-3">
                                        <i class="bi bi-clock-history me-2"></i>Recent Distribution History
                                    </h4>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Academic Period</th>
                                                    <th>Students</th>
                                                    <th>Location</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($hist = pg_fetch_assoc($history_result)): ?>
                                                    <tr>
                                                        <td><?= date('M j, Y', strtotime($hist['distribution_date'])) ?></td>
                                                        <td><?= htmlspecialchars($hist['academic_year']) ?> <?= htmlspecialchars($hist['semester']) ?></td>
                                                        <td><?= $hist['total_students_count'] ?></td>
                                                        <td><?= htmlspecialchars($hist['location']) ?></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script>
// CSRF Token Management for Distribution Control
document.addEventListener('DOMContentLoaded', function() {
    // Add loading states to form submissions for better UX
    const forms = document.querySelectorAll('form[method="POST"]');
    
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Processing...';
                
                // Re-enable after 3 seconds to prevent permanent lock
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = submitBtn.innerHTML.replace('Processing...', submitBtn.textContent.includes('Start') ? 'Start Distribution' : 
                                                                   submitBtn.textContent.includes('Activate') ? 'Activate' :
                                                                   submitBtn.textContent.includes('Open') ? 'Open Slots' :
                                                                   submitBtn.textContent.includes('Close') ? 'Close Slots' :
                                                                   submitBtn.textContent.includes('Enable') ? 'Enable Uploads' :
                                                                   submitBtn.textContent.includes('Disable') ? 'Disable Uploads' :
                                                                   'Finalize Distribution');
                }, 3000);
            }
        });
    });
    
    // Auto-refresh status every 30 seconds (useful during active distributions)
    let refreshInterval;
    const isActive = <?= json_encode($workflow_status['distribution_status'] === 'active') ?>;
    
    if (isActive) {
        refreshInterval = setInterval(() => {
            // Only refresh if no form submission is in progress
            const hasDisabledButtons = Array.from(forms).some(form => 
                form.querySelector('button[type="submit"]:disabled')
            );
            
            if (!hasDisabledButtons) {
                window.location.reload();
            }
        }, 30000);
    }
});
</script>
</body>
</html>

<?php 
if ($history_result) pg_free_result($history_result);
pg_close($connection); 
?>