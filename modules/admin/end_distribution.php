<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/DistributionManager.php';
require_once __DIR__ . '/../../services/FileCompressionService.php';

/**
 * Delete all student uploaded documents from the file system
 */
function deleteAllStudentUploads() {
    $uploadsPath = __DIR__ . '/../../assets/uploads/student';
    $documentTypes = ['enrollment_forms', 'grades', 'id_pictures', 'indigency', 'letter_to_mayor'];
    
    $totalDeleted = 0;
    $errors = [];
    
    foreach ($documentTypes as $type) {
        $folderPath = $uploadsPath . '/' . $type;
        if (is_dir($folderPath)) {
            $files = glob($folderPath . '/*.*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    if (unlink($file)) {
                        $totalDeleted++;
                    } else {
                        $errors[] = "Failed to delete: " . basename($file);
                    }
                }
            }
        }
    }
    
    error_log("Deleted $totalDeleted student upload files during distribution end");
    if (!empty($errors)) {
        error_log("File deletion errors: " . implode(', ', $errors));
    }
    
    return $totalDeleted;
}

/**
 * Reset students who received aid back to applicant status, clearing payroll numbers, QR codes, and uploaded documents.
 * Handles schema differences (payroll_no vs payroll_number, qr_code vs qr_code_path).
 * Dynamically discovers document path column names.
 */
function resetGivenStudents($connection) {
    static $columnCache = null;

    if ($columnCache === null) {
        $columnCache = [
            'payroll_no' => false,
            'payroll_number' => false,
            'qr_code_path' => false,
            'qr_code' => false,
        ];

        // Check for payroll and QR code columns
        $columnQuery = "SELECT column_name FROM information_schema.columns WHERE table_name = 'students' AND column_name IN ('payroll_no','payroll_number','qr_code_path','qr_code')";
        $columnResult = pg_query($connection, $columnQuery);
        if ($columnResult) {
            while ($row = pg_fetch_assoc($columnResult)) {
                $name = $row['column_name'];
                if (array_key_exists($name, $columnCache)) {
                    $columnCache[$name] = true;
                }
            }
        }
        
        // Find all columns that likely contain document paths
        // Look for columns ending in _path, _file, or containing 'document', 'upload', etc.
        $docColumnsQuery = "SELECT column_name FROM information_schema.columns 
                           WHERE table_name = 'students' 
                           AND (column_name LIKE '%_path' 
                                OR column_name LIKE '%_file' 
                                OR column_name LIKE '%document%'
                                OR column_name LIKE '%upload%')";
        $docColumnsResult = pg_query($connection, $docColumnsQuery);
        $columnCache['document_columns'] = [];
        if ($docColumnsResult) {
            while ($row = pg_fetch_assoc($docColumnsResult)) {
                $columnCache['document_columns'][] = $row['column_name'];
            }
        }
    }

    // IMPORTANT: Clear document records BEFORE changing status
    // Delete document records for students who have 'given' status (they're about to be reset)
    // This ensures upload pages show clean slate for next cycle
    $deleteDocuments = pg_query($connection, "DELETE FROM documents WHERE student_id IN (SELECT student_id FROM students WHERE status = 'given')");
    if ($deleteDocuments === false) {
        error_log('Warning: Failed to clear document records: ' . pg_last_error($connection));
        $docsDeleted = 0;
    } else {
        $docsDeleted = pg_affected_rows($deleteDocuments);
        error_log("Cleared $docsDeleted document records from documents table");
    }
    
    // Clear grade_uploads table records as well (before status change)
    $deleteGradeUploads = pg_query($connection, "DELETE FROM grade_uploads WHERE student_id IN (SELECT student_id FROM students WHERE status = 'given')");
    if ($deleteGradeUploads === false) {
        error_log('Warning: Failed to clear grade_uploads records: ' . pg_last_error($connection));
        $gradesDeleted = 0;
    } else {
        $gradesDeleted = pg_affected_rows($deleteGradeUploads);
        error_log("Cleared $gradesDeleted grade upload records from grade_uploads table");
    }

    // Build SET clause for resetting student data
    $setParts = ["status = 'applicant'"];

    if ($columnCache['payroll_no']) {
        $setParts[] = 'payroll_no = NULL';
    } elseif ($columnCache['payroll_number']) {
        $setParts[] = 'payroll_number = NULL';
    }

    if ($columnCache['qr_code_path']) {
        $setParts[] = 'qr_code_path = NULL';
    } elseif ($columnCache['qr_code']) {
        $setParts[] = 'qr_code = NULL';
    }
    
    // Reset all discovered document path columns
    foreach ($columnCache['document_columns'] as $docColumn) {
        $setParts[] = pg_escape_identifier($connection, $docColumn) . " = NULL";
    }

    $query = "UPDATE students SET " . implode(', ', $setParts) . " WHERE status = 'given'";
    $result = pg_query($connection, $query);

    if (!$result) {
        throw new Exception('Failed to reset students: ' . pg_last_error($connection));
    }

    $affectedRows = pg_affected_rows($result);

    // Remove generated QR codes for all students
    $deleteQr = pg_query($connection, "DELETE FROM qr_codes");
    if ($deleteQr === false) {
        throw new Exception('Failed to clear QR codes: ' . pg_last_error($connection));
    }
    
    // Delete all uploaded files from the file system
    // Note: This happens AFTER compression, so files are already archived
    $filesDeleted = deleteAllStudentUploads();
    
    error_log("Reset $affectedRows students, cleared " . count($columnCache['document_columns']) . " document columns, deleted $docsDeleted document records, $gradesDeleted grade records, and $filesDeleted upload files");

    return $affectedRows;
}

/**
 * Clear schedule records and reset schedule metadata.
 */
function clearScheduleData($connection) {
    $deleteResult = pg_query($connection, "DELETE FROM schedules");
    if ($deleteResult === false) {
        throw new Exception('Failed to clear schedules: ' . pg_last_error($connection));
    }

    $settingsPath = __DIR__ . '/../../data/municipal_settings.json';
    $settings = [];
    if (file_exists($settingsPath)) {
        $decoded = json_decode(file_get_contents($settingsPath), true);
        if (is_array($decoded)) {
            $settings = $decoded;
        }
    }

    $settings['schedule_published'] = false;
    if (isset($settings['schedule_meta'])) {
        unset($settings['schedule_meta']);
    }

    $encoded = json_encode($settings, JSON_PRETTY_PRINT);
    if ($encoded === false) {
        throw new Exception('Failed to encode schedule settings to JSON');
    }

    if (file_put_contents($settingsPath, $encoded) === false) {
        throw new Exception('Failed to update schedule settings file');
    }
}

$distManager = new DistributionManager();
$compressionService = new FileCompressionService();

// Handle AJAX requests BEFORE workflow check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'verify_password') {
        $password = $_POST['password'] ?? '';
        $adminId = $_SESSION['admin_id'];
        
        // Verify admin password
        $query = "SELECT password FROM admins WHERE admin_id = $1";
        $result = pg_query_params($connection, $query, [$adminId]);
        
        if ($result && $row = pg_fetch_assoc($result)) {
            if (password_verify($password, $row['password'])) {
                echo json_encode(['success' => true, 'message' => 'Password verified']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Incorrect password']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Authentication failed']);
        }
        exit();
    }
    
    if ($action === 'end_distribution') {
        $distributionId = $_POST['distribution_id'] ?? '';
        $password = $_POST['password'] ?? '';
        $allowEmptyOverride = isset($_POST['allow_empty']) && $_POST['allow_empty'] === '1';
        $adminId = $_SESSION['admin_id'];
        
        // Log what we're receiving
        error_log("End Distribution Request - ID: " . $distributionId . ", Admin: " . $adminId);
        
        // Verify password before proceeding
        $query = "SELECT password FROM admins WHERE admin_id = $1";
        $result = pg_query_params($connection, $query, [$adminId]);
        
        if (!$result || !($row = pg_fetch_assoc($result)) || !password_verify($password, $row['password'])) {
            echo json_encode(['success' => false, 'message' => 'Password verification failed']);
            exit();
        }
        
        // For config-based distributions, use FileCompressionService directly
        try {
            pg_query($connection, "BEGIN");
            
            // Compress files FIRST (while students still have 'given' status)
            $compressionResult = $compressionService->compressDistribution($distributionId, $adminId);

            if (!$compressionResult['success']) {
                $compressionMessage = $compressionResult['message'] ?? 'Compression failed';
                $emptyFailure = stripos($compressionMessage, 'no files') !== false || stripos($compressionMessage, "no students") !== false;

                if ($emptyFailure && !$allowEmptyOverride) {
                    pg_query($connection, "ROLLBACK");
                    echo json_encode([
                        'success' => false,
                        'message' => $compressionMessage,
                        'can_override' => true,
                        'override_reason' => $compressionMessage
                    ]);
                    exit();
                }

                if (!$emptyFailure || !$allowEmptyOverride) {
                    pg_query($connection, "ROLLBACK");
                    echo json_encode(['success' => false, 'message' => 'Compression failed: ' . $compressionMessage]);
                    exit();
                }

                // Developer override: proceed even if no files were found
                $compressionResult['skipped'] = true;
                $compressionResult['override_used'] = true;
                if (empty($compressionResult['message'])) {
                    $compressionResult['message'] = 'Compression skipped: no files were detected.';
                }
            }
            
            // Reset all students with 'given' status back to 'applicant'
            // Payroll numbers and QR codes are cleared for the next cycle
            $studentsReset = resetGivenStudents($connection);
            
            error_log("End Distribution: Reset $studentsReset students from 'given' to 'applicant' and cleared payroll/QR codes");

            // Clear all schedule data for the next cycle
            clearScheduleData($connection);
            
            // Set global distribution status to inactive
            pg_query($connection, "
                INSERT INTO config (key, value) VALUES ('distribution_status', 'inactive')
                ON CONFLICT (key) DO UPDATE SET value = 'inactive'
            ");
            
            pg_query($connection, "COMMIT");
            
            $resultMessage = (!empty($compressionResult['skipped']))
                ? 'Distribution ended successfully (compression skipped)'
                : 'Distribution ended successfully and status set to inactive';

            $result = [
                'success' => true,
                'message' => $resultMessage,
                'distribution_id' => $distributionId,
                'students_reset' => $studentsReset,
                'compression' => $compressionResult
            ];
            
            echo json_encode($result);
            
        } catch (Exception $e) {
            pg_query($connection, "ROLLBACK");
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    if ($action === 'compress_distribution') {
        $distributionId = intval($_POST['distribution_id'] ?? 0);
        $result = $compressionService->compressDistribution($distributionId, $_SESSION['admin_id']);
        echo json_encode($result);
        exit();
    }
}

// NOW check workflow permissions for regular page loads
require_once __DIR__ . '/../../includes/workflow_control.php';
$workflow_status = getWorkflowStatus($connection);
if (!$workflow_status['can_manage_applicants']) {
    $_SESSION['error_message'] = "Please start a distribution first before accessing end distribution. Go to Distribution Control to begin.";
    header("Location: distribution_control.php");
    exit;
}

// Get current distribution info from config and students
$activeDistributions = [];
$distribution_status = $workflow_status['distribution_status'] ?? 'inactive';

if (in_array($distribution_status, ['preparing', 'active'])) {
    // Get academic period from config
    $academic_year = '';
    $semester = '';
    $config_query = pg_query($connection, "SELECT key, value FROM config WHERE key IN ('current_academic_year', 'current_semester')");
    if ($config_query) {
        while ($row = pg_fetch_assoc($config_query)) {
            if ($row['key'] === 'current_academic_year') $academic_year = $row['value'];
            if ($row['key'] === 'current_semester') $semester = $row['value'];
        }
    }
    
    // Generate distribution ID based on municipality and date
    $municipality_name = 'GENERALTRIAS'; // You can get this from config or database
    $distribution_id = "#{$municipality_name}-DISTR-" . date('Y-m-d-His');
    
    // Count students with 'given' status and their files
    $student_count_query = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE status = 'given'");
    $student_count = 0;
    if ($student_count_query) {
        $row = pg_fetch_assoc($student_count_query);
        $student_count = intval($row['count']);
    }
    
    // Count total files in student folders
    $file_count = 0;
    $total_size = 0;
    $document_types = ['enrollment_forms', 'grades', 'id_pictures', 'indigency', 'letter_to_mayor'];
    foreach ($document_types as $type) {
        $folder = __DIR__ . "/../../assets/uploads/student/{$type}";
        if (is_dir($folder)) {
            $files = glob($folder . '/*');
            $file_count += count($files);
            foreach ($files as $file) {
                if (is_file($file)) {
                    $total_size += filesize($file);
                }
            }
        }
    }
    
    $activeDistributions[] = [
        'id' => $distribution_id,
        'created_at' => date('Y-m-d H:i:s'), // Using current date as placeholder
        'year_level' => null,
        'semester' => $semester,
        'student_count' => $student_count,
        'file_count' => $file_count,
        'total_size' => $total_size
    ];
}

$endedAwaitingCompression = []; // Not needed for config-based system

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
                    <div>
                        <h2 class="mb-1"><i class="bi bi-stop-circle"></i> End Distribution</h2>
                        <p class="text-muted mb-0">Archive and compress distribution files</p>
                    </div>
                </div>

                <!-- Active Distributions Card -->
                <div class="card shadow mb-4">
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
                                            <td><span class="badge bg-success">Active</span></td>
                                            <td class="action-buttons">
                                                <button class="btn btn-danger btn-sm" 
                                                        onclick="showPasswordModal('<?php echo $dist['id']; ?>')"
                                                        title="End distribution and compress files">
                                                    <i class="bi bi-lock-fill"></i> End & Compress
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
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-hourglass-split"></i> Ended Distributions Awaiting Compression</h5>
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

    <!-- Password Confirmation Modal -->
    <div class="modal fade" id="passwordModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-shield-lock-fill"></i> Confirm Action</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle-fill"></i> <strong>Critical Action!</strong>
                        <p class="mb-0 mt-2">This will:</p>
                        <ul class="mb-0 mt-2">
                            <li>End the distribution cycle</li>
                            <li>Compress all student files into a ZIP archive</li>
                            <li>Delete original student uploads</li>
                            <li>Set distribution status to inactive</li>
                            <li>Lock the workflow</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirmPassword" class="form-label">
                            <i class="bi bi-key-fill"></i> Enter your password to confirm:
                        </label>
                        <input type="password" 
                               class="form-control form-control-lg" 
                               id="confirmPassword" 
                               placeholder="Enter your password"
                               autocomplete="current-password">
                        <div id="passwordError" class="text-danger mt-2" style="display: none;">
                            <i class="bi bi-x-circle"></i> <span id="passwordErrorText"></span>
                        </div>
                    </div>
                    
                    <div class="text-muted small">
                        <i class="bi bi-info-circle"></i> This action cannot be undone. Please ensure all data is correct before proceeding.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmEndBtn" onclick="confirmEndDistribution()">
                        <i class="bi bi-lock-fill"></i> Confirm & Proceed
                    </button>
                </div>
            </div>
        </div>
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

    <style>
        .badge {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
        }
        
        .action-buttons .btn {
            min-width: 140px;
        }
        
        .progress-log {
            max-height: 300px;
            overflow-y: auto;
            background: #f8f9fa;
            border-radius: 0.375rem;
            padding: 1rem;
        }
        
        .log-entry {
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            padding: 0.25rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .log-entry:last-child {
            border-bottom: none;
        }
        
        #passwordError {
            animation: shake 0.3s;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let progressModal;
        let passwordModal;
        let currentDistributionId = null;

        document.addEventListener('DOMContentLoaded', () => {
            const progressModalEl = document.getElementById('progressModal');
            const passwordModalEl = document.getElementById('passwordModal');

            if (progressModalEl && window.bootstrap?.Modal) {
                progressModal = new bootstrap.Modal(progressModalEl);
            }

            if (passwordModalEl && window.bootstrap?.Modal) {
                passwordModal = new bootstrap.Modal(passwordModalEl);
            }

            const confirmPasswordInput = document.getElementById('confirmPassword');
            if (confirmPasswordInput) {
                confirmPasswordInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        confirmEndDistribution();
                    }
                });
            }

            const closeProgressBtn = document.getElementById('closeProgressBtn');
            if (closeProgressBtn) {
                closeProgressBtn.addEventListener('click', () => {
                    progressModal?.hide();
                    location.reload();
                });
            }
        });

        function showPasswordModal(distId) {
            currentDistributionId = distId;

            const passwordInput = document.getElementById('confirmPassword');
            const errorDiv = document.getElementById('passwordError');

            if (passwordInput) {
                passwordInput.value = '';
            }

            if (errorDiv) {
                errorDiv.style.display = 'none';
            }

            if (passwordModal) {
                passwordModal.show();

                // Focus on password field after modal is shown
                if (passwordInput) {
                    setTimeout(() => passwordInput.focus(), 500);
                }
            }
        }

        function confirmEndDistribution() {
            const passwordInput = document.getElementById('confirmPassword');
            const password = passwordInput ? passwordInput.value : '';
            const errorDiv = document.getElementById('passwordError');
            const errorText = document.getElementById('passwordErrorText');
            const confirmBtn = document.getElementById('confirmEndBtn');
            
            if (!password) {
                errorText.textContent = 'Password is required';
                errorDiv.style.display = 'block';
                return;
            }
            
            // Disable button and show loading
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';
            
            // Verify password first
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=verify_password&password=' + encodeURIComponent(password)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Server returned ' + response.status);
                }
                return response.text();
            })
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Invalid server response');
                }
                
                if (data.success) {
                    // Password verified, proceed with ending distribution
                    passwordModal?.hide();
                    endDistribution(currentDistributionId, password);
                } else {
                    // Show error
                    errorText.textContent = data.message || 'Incorrect password';
                    errorDiv.style.display = 'block';
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="bi bi-lock-fill"></i> Confirm & Proceed';
                    
                    // Clear password field
                    if (passwordInput) {
                        passwordInput.value = '';
                        passwordInput.focus();
                    }
                }
            })
            .catch(error => {
                console.error('Password verification error:', error);
                errorText.textContent = error.message || 'Network error. Please try again.';
                errorDiv.style.display = 'block';
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="bi bi-lock-fill"></i> Confirm & Proceed';
            });
        }
        
        function updateProgress(percent, message, logEntry = null) {
            const progressBar = document.getElementById('progressBar');
            if (progressBar && typeof percent === 'number') {
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '%';
            }

            const statusMessage = document.getElementById('statusMessage');
            if (statusMessage && message) {
                statusMessage.innerHTML = '<i class="bi bi-info-circle"></i> ' + message;
            }
            
            if (logEntry) {
                const logDiv = document.getElementById('progressLog');
                const entry = document.createElement('div');
                entry.className = 'log-entry';
                entry.textContent = logEntry;
                logDiv?.appendChild(entry);
                if (logDiv) {
                    logDiv.scrollTop = logDiv.scrollHeight;
                }
            }
        }
        
        function endDistribution(distId, password, allowEmpty = false) {
            progressModal?.show();

            const logDiv = document.getElementById('progressLog');
            if (!allowEmpty && logDiv) {
                logDiv.innerHTML = '';
            }

            document.getElementById('closeProgressBtn').disabled = true;

            if (allowEmpty) {
                updateProgress(15, 'Continuing without compression files', 'Developer override enabled. Proceeding without archive.');
            } else {
                updateProgress(10, 'Ending distribution...', 'Starting end & compress process...');
            }

            const params = new URLSearchParams();
            params.append('action', 'end_distribution');
            params.append('distribution_id', distId);
            params.append('password', password);
            if (allowEmpty) {
                params.append('allow_empty', '1');
            }

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateProgress(50, data.message || 'Distribution ended successfully', '✓ Distribution marked as ended');

                    if (data.compression) {
                        if (data.compression.success) {
                            updateProgress(60, 'Starting compression...', 'Compressing and archiving files...');
                            const stats = data.compression.statistics;
                            updateProgress(90, 'Compression completed', '✓ Files compressed successfully');
                            updateProgress(100, 'Complete!', 
                                `✓ Processed ${stats.students_processed} students, ${stats.files_compressed} files`);
                            updateProgress(null, null, 
                                `✓ Space saved: ${(stats.space_saved / 1024 / 1024).toFixed(2)} MB (${stats.compression_ratio}% compression)`);
                            updateProgress(null, null, 
                                `✓ Archive: ${stats.archive_location}`);
                            
                            document.getElementById('statusMessage').innerHTML = 
                                '<i class="bi bi-check-circle"></i> <strong>Distribution Ended & Compressed!</strong><br>' +
                                'Archive location: ' + stats.archive_location + '<br>Student uploads have been deleted.';
                            document.getElementById('statusMessage').className = 'alert alert-success';
                        } else if (data.compression.skipped) {
                            updateProgress(90, 'Compression skipped (no files detected)', '⚠️ ' + (data.compression.message || 'No files were available for compression.'));
                            updateProgress(100, 'Complete!', '✓ Distribution ended without compression');
                            document.getElementById('statusMessage').innerHTML =
                                '<i class="bi bi-check-circle"></i> <strong>Distribution ended.</strong><br>' +
                                'No files were available for compression. Developer override completed the workflow.';
                            document.getElementById('statusMessage').className = 'alert alert-success';
                        } else {
                            updateProgress(100, 'Ended but compression failed', '⚠️ ' + data.compression.message);
                            document.getElementById('statusMessage').className = 'alert alert-warning';
                        }
                    }

                    document.getElementById('closeProgressBtn').disabled = false;
                    setTimeout(() => location.reload(), 3000);
                } else if (data.can_override) {
                    const reason = data.override_reason || data.message || 'No files were found to compress.';
                    updateProgress(0, 'Override available', '⚠️ ' + reason);
                    document.getElementById('statusMessage').innerHTML =
                        '<i class="bi bi-exclamation-triangle"></i> No files were detected for compression. You can override this check for development purposes.';
                    document.getElementById('statusMessage').className = 'alert alert-warning';
                    document.getElementById('closeProgressBtn').disabled = false;

                    if (confirm(reason + '\n\nProceed without compression?')) {
                        endDistribution(distId, password, true);
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
        
        // Fallback in case DOMContentLoaded didn't attach listener (script defer issues)
        if (!document.getElementById('closeProgressBtn')?.onclick) {
            document.getElementById('closeProgressBtn')?.addEventListener('click', () => {
                progressModal?.hide();
                location.reload();
            });
        }
    </script>
</body>
</html>
