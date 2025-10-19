<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';

// Check workflow permissions - must have active distribution
require_once __DIR__ . '/../../includes/workflow_control.php';
$workflow_status = getWorkflowStatus($connection);
if (!$workflow_status['can_manage_applicants']) {
    $_SESSION['error_message'] = "Please start a distribution first before accessing end distribution. Go to Distribution Control to begin.";
    header("Location: distribution_control.php");
    exit;
}

require_once __DIR__ . '/../../services/DistributionManager.php';
require_once __DIR__ . '/../../services/FileCompressionService.php';

$distManager = new DistributionManager();
$compressionService = new FileCompressionService();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'end_distribution') {
        $distributionId = intval($_POST['distribution_id'] ?? 0);
        $result = $distManager->endDistribution($distributionId, $_SESSION['admin_id'], false);
        echo json_encode($result);
        exit();
    }
    
    if ($action === 'compress_distribution') {
        $distributionId = intval($_POST['distribution_id'] ?? 0);
        $result = $compressionService->compressDistribution($distributionId, $_SESSION['admin_id']);
        echo json_encode($result);
        exit();
    }
}

// Get active and ended distributions
$activeDistributions = $distManager->getActiveDistributions();
$endedDistributions = $distManager->getEndedDistributions();
$endedAwaitingCompression = array_filter($endedDistributions, function($dist) {
    return !$dist['files_compressed'];
});

$pageTitle = "End Distribution";
?>
<?php $page_title='End Distribution'; include '../../includes/admin/admin_head.php'; ?>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
        <?php include '../../includes/admin/admin_header.php'; ?>
        <section class="home-section" id="mainContent">
            <div class="container-fluid py-4 px-4">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-stop-circle"></i> End Distribution</h2>
                </div>
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-play-circle"></i> Active Distributions</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($activeDistributions)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No active distributions found.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Created</th>
                                        <th>Year Level</th>
                                        <th>Semester</th>
                                        <th>Students</th>
                                        <th>Files</th>
                                        <th>Total Size</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeDistributions as $dist): ?>
                                        <tr>
                                            <td>#<?php echo $dist['id']; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($dist['created_at'])); ?></td>
                                            <td><?php echo $dist['year_level'] ?: 'N/A'; ?></td>
                                            <td><?php echo $dist['semester'] ?: 'N/A'; ?></td>
                                            <td><?php echo $dist['student_count']; ?></td>
                                            <td><?php echo $dist['file_count']; ?></td>
                                            <td><?php echo number_format($dist['total_size'] / 1024 / 1024, 2); ?> MB</td>
                                            <td><span class="badge badge-active">Active</span></td>
                                            <td class="action-buttons">
                                                <button class="btn btn-sm btn-warning" 
                                                        onclick="endDistribution(<?php echo $dist['id']; ?>, false)">
                                                    <i class="bi bi-stop-circle"></i> End Only
                                                </button>
                                                <button class="btn btn-sm btn-danger" 
                                                        onclick="endDistribution(<?php echo $dist['id']; ?>, true)">
                                                    <i class="bi bi-file-zip"></i> End & Compress
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>

                <!-- Ended Distributions Awaiting Compression -->
                <?php if (!empty($endedAwaitingCompression)): ?>
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-warning">
                        <h6 class="m-0 font-weight-bold"><i class="bi bi-hourglass-split"></i> Ended Distributions Awaiting Compression</h6>
                    </div>
                    <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Created</th>
                                    <th>Ended</th>
                                    <th>Year Level</th>
                                    <th>Semester</th>
                                    <th>Students</th>
                                    <th>Files</th>
                                    <th>Total Size</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($endedAwaitingCompression as $dist): ?>
                                    <tr>
                                        <td>#<?php echo $dist['id']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($dist['created_at'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($dist['ended_at'])); ?></td>
                                        <td><?php echo $dist['year_level'] ?: 'N/A'; ?></td>
                                        <td><?php echo $dist['semester'] ?: 'N/A'; ?></td>
                                        <td><?php echo $dist['student_count']; ?></td>
                                        <td><?php echo $dist['file_count']; ?></td>
                                        <td><?php echo number_format($dist['original_size'] / 1024 / 1024, 2); ?> MB</td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" 
                                                    onclick="compressDistribution(<?php echo $dist['id']; ?>)">
                                                <i class="bi bi-file-zip"></i> Compress Now
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Progress Modal -->
    <div class="modal fade" id="progressModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-gear-fill"></i> Processing Distribution</h5>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="progress">
                            <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                                 role="progressbar" style="width: 0%">0%</div>
                        </div>
                    </div>
                    <div id="statusMessage" class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Initializing...
                    </div>
                    <div class="progress-log" id="progressLog"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="closeProgressBtn" disabled>Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const progressModal = new bootstrap.Modal(document.getElementById('progressModal'));
        
        function updateProgress(percent, message, logEntry = null) {
            const progressBar = document.getElementById('progressBar');
            progressBar.style.width = percent + '%';
            progressBar.textContent = percent + '%';
            
            document.getElementById('statusMessage').innerHTML = 
                '<i class="bi bi-info-circle"></i> ' + message;
            
            if (logEntry) {
                const logDiv = document.getElementById('progressLog');
                const entry = document.createElement('div');
                entry.className = 'log-entry';
                entry.textContent = logEntry;
                logDiv.appendChild(entry);
                logDiv.scrollTop = logDiv.scrollHeight;
            }
        }
        
        function endDistribution(distId, compress) {
            progressModal.show();
            document.getElementById('progressLog').innerHTML = '';
            document.getElementById('closeProgressBtn').disabled = true;
            
            updateProgress(10, 'Ending distribution...', 'Starting end distribution process...');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=end_distribution&distribution_id=' + distId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateProgress(50, 'Distribution ended successfully', '✓ Distribution marked as ended');
                    
                    if (compress) {
                        updateProgress(60, 'Starting compression...', 'Compressing files...');
                        compressDistributionInternal(distId);
                    } else {
                        updateProgress(100, 'Complete!', '✓ Operation completed successfully');
                        document.getElementById('statusMessage').className = 'alert alert-success';
                        document.getElementById('closeProgressBtn').disabled = false;
                        setTimeout(() => location.reload(), 2000);
                    }
                } else {
                    updateProgress(0, 'Error: ' + data.message, '✗ Failed: ' + data.message);
                    document.getElementById('statusMessage').className = 'alert alert-danger';
                    document.getElementById('closeProgressBtn').disabled = false;
                }
            })
            .catch(error => {
                updateProgress(0, 'Network error', '✗ Error: ' + error);
                document.getElementById('statusMessage').className = 'alert alert-danger';
                document.getElementById('closeProgressBtn').disabled = false;
            });
        }
        
        function compressDistribution(distId) {
            progressModal.show();
            document.getElementById('progressLog').innerHTML = '';
            document.getElementById('closeProgressBtn').disabled = true;
            updateProgress(10, 'Starting compression...', 'Initializing compression service...');
            compressDistributionInternal(distId);
        }
        
        function compressDistributionInternal(distId) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=compress_distribution&distribution_id=' + distId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateProgress(90, 'Compression completed', '✓ Files compressed successfully');
                    
                    if (data.log) {
                        data.log.forEach(entry => updateProgress(null, null, entry));
                    }
                    
                    const stats = data.statistics;
                    updateProgress(100, 'Complete!', 
                        `✓ Processed ${stats.students_processed} students, ${stats.files_compressed} files`);
                    updateProgress(null, null, 
                        `✓ Space saved: ${(stats.space_saved / 1024 / 1024).toFixed(2)} MB (${stats.compression_ratio}% compression)`);
                    
                    document.getElementById('statusMessage').innerHTML = 
                        '<i class="bi bi-check-circle"></i> <strong>Compression Complete!</strong><br>' +
                        'Archive location: ' + stats.archive_location;
                    document.getElementById('statusMessage').className = 'alert alert-success';
                    document.getElementById('closeProgressBtn').disabled = false;
                    
                    setTimeout(() => location.reload(), 3000);
                } else {
                    updateProgress(0, 'Compression failed: ' + data.message, '✗ Error: ' + data.message);
                    document.getElementById('statusMessage').className = 'alert alert-danger';
                    document.getElementById('closeProgressBtn').disabled = false;
                }
            })
            .catch(error => {
                updateProgress(0, 'Network error', '✗ Error: ' + error);
                document.getElementById('statusMessage').className = 'alert alert-danger';
                document.getElementById('closeProgressBtn').disabled = false;
            });
        }
        
        document.getElementById('closeProgressBtn').addEventListener('click', () => {
            progressModal.hide();
            location.reload();
        });
    </script>
</body>
</html>
