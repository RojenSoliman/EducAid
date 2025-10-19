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

// Auto-migrate existing distribution if needed
$migration_needed = false;
$distribution_status_check = pg_query($connection, "SELECT value FROM config WHERE key = 'distribution_status'");
if ($distribution_status_check && ($status_row = pg_fetch_assoc($distribution_status_check))) {
    $current_status = $status_row['value'];
    if (in_array($current_status, ['preparing', 'active'])) {
        // Check if academic period is missing
        $period_check = pg_query($connection, "SELECT key FROM config WHERE key IN ('current_academic_year', 'current_semester')");
        $existing_keys = [];
        if ($period_check) {
            while ($key_row = pg_fetch_assoc($period_check)) {
                $existing_keys[] = $key_row['key'];
            }
        }
        
        if (!in_array('current_academic_year', $existing_keys) || !in_array('current_semester', $existing_keys)) {
            $migration_needed = true;
            
            // Set default academic period for existing distribution
            $current_year = date('Y');
            $current_month = date('n');
            
            // Determine semester based on current date
            if ($current_month >= 8 || $current_month <= 12) {
                $default_semester = '1st Semester';
                $default_academic_year = $current_year . '-' . ($current_year + 1);
            } else {
                $default_semester = '2nd Semester'; 
                $default_academic_year = ($current_year - 1) . '-' . $current_year;
            }
            
            // Insert missing config keys
            if (!in_array('current_academic_year', $existing_keys)) {
                pg_query_params($connection, "INSERT INTO config (key, value) VALUES ($1, $2)", 
                              ['current_academic_year', $default_academic_year]);
            }
            if (!in_array('current_semester', $existing_keys)) {
                pg_query_params($connection, "INSERT INTO config (key, value) VALUES ($1, $2)", 
                              ['current_semester', $default_semester]);
            }
        }
    }
}

$workflow_status = getWorkflowStatus($connection);
$student_counts = getStudentCounts($connection);

// Ensure student_counts has all required keys
$student_counts = array_merge([
    'total_students' => 0,
    'active_count' => 0,
    'applicant_count' => 0,
    'verified_students' => 0,
    'pending_verification' => 0
], $student_counts);

// Extract status variables for easy access
$distribution_status = $workflow_status['distribution_status'] ?? 'inactive';
$uploads_enabled = $workflow_status['uploads_enabled'] ?? false;

// Get current academic period
$current_academic_year = '';
$current_semester = '';
$period_query = "SELECT key, value FROM config WHERE key IN ('current_academic_year', 'current_semester')";
$period_result = pg_query($connection, $period_query);
if ($period_result) {
    while ($row = pg_fetch_assoc($period_result)) {
        if ($row['key'] === 'current_academic_year') {
            $current_academic_year = $row['value'];
        } elseif ($row['key'] === 'current_semester') {
            $current_semester = $row['value'];
        }
    }
}

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
            // Validate required fields
            $academic_year = trim($_POST['academic_year'] ?? '');
            $semester = trim($_POST['semester'] ?? '');
            $documents_deadline = trim($_POST['documents_deadline'] ?? '');
            
            if (empty($academic_year) || empty($semester)) {
                $message = 'Academic year and semester are required to start distribution.';
                break;
            }
            // Validate documents deadline (optional but recommended) - must be a valid date when provided
            if (!empty($documents_deadline)) {
                $d = DateTime::createFromFormat('Y-m-d', $documents_deadline);
                $dateValid = $d && $d->format('Y-m-d') === $documents_deadline;
                if (!$dateValid) {
                    $message = 'Invalid documents deadline date. Use a valid date (YYYY-MM-DD).';
                    break;
                }
            }
            
            // Validate academic year format
            if (!preg_match('/^\d{4}-\d{4}$/', $academic_year)) {
                $message = 'Invalid academic year format. Use YYYY-YYYY (e.g., 2025-2026).';
                break;
            }
            
            // Validate year logic
            $year_parts = explode('-', $academic_year);
            if (intval($year_parts[1]) !== intval($year_parts[0]) + 1) {
                $message = 'Invalid academic year. End year must be exactly one year after start year.';
                break;
            }
            
            // Set distribution status to preparing
            $begin_result = pg_query($connection, "BEGIN");
            if (!$begin_result) {
                $message = 'Failed to start transaction: ' . pg_last_error($connection);
                break;
            }
            
            try {
                // Store academic period for this distribution
                // Set status directly to 'active' for simplified workflow
                $config_settings = [
                    ['distribution_status', 'active'],
                    ['current_academic_year', $academic_year],
                    ['current_semester', $semester],
                    ['uploads_enabled', '1']
                ];
                if (!empty($documents_deadline)) {
                    $config_settings[] = ['documents_deadline', $documents_deadline];
                }
                
                foreach ($config_settings as [$key, $value]) {
                    $query = "INSERT INTO config (key, value) VALUES ($1, $2) ON CONFLICT (key) DO UPDATE SET value = $2";
                    $result = pg_query_params($connection, $query, [$key, $value]);
                    if (!$result) {
                        throw new Exception("Failed to update configuration key '$key': " . pg_last_error($connection));
                    }
                }
                
                pg_query($connection, "COMMIT");
                $success = true;
                $message = "Distribution cycle started for $semester $academic_year!"
                    . (!empty($documents_deadline) ? " Document deadline set to $documents_deadline." : "")
                    . " The distribution is now active and all features are unlocked!";
                
            } catch (Exception $e) {
                pg_query($connection, "ROLLBACK");
                $message = 'Failed to start distribution: ' . $e->getMessage();
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
            

            
        case 'finalize_distribution':
            // Finalize distribution - simplified and robust approach
            try {
                // Start transaction
                $begin_result = pg_query($connection, "BEGIN");
                if (!$begin_result) {
                    throw new Exception('Failed to start transaction: ' . pg_last_error($connection));
                }
                
                $archived_count = 0;
                $snapshot_created = false;
                
                // Get current academic period from config (we know this exists)
                $academic_year = $current_academic_year ?: (date('Y') . '-' . (date('Y') + 1));
                $semester = $current_semester ?: '1st Semester';
                
                // Step 1: Archive documents from documents table
                try {
                    // Check if both documents and document_archives tables exist
                    $table_check = pg_query($connection, "
                        SELECT 
                            EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name='documents') as has_documents,
                            EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name='document_archives') as has_archives
                    ");
                    
                    if ($table_check) {
                        $tables = pg_fetch_assoc($table_check);
                        
                        // Only proceed if both tables exist
                        if ($tables['has_documents'] === 't' && $tables['has_archives'] === 't') {
                            // Check for documents to archive
                            $doc_check = pg_query($connection, "SELECT COUNT(*) as doc_count FROM documents");
                            
                            if ($doc_check) {
                                $doc_data = pg_fetch_assoc($doc_check);
                                $archived_count = intval($doc_data['doc_count']);
                                
                                if ($archived_count > 0) {
                                    // Archive documents from documents table
                                    $archive_result = pg_query_params($connection, "
                                        INSERT INTO document_archives (
                                            student_id, original_document_id, document_type, file_path, 
                                            original_upload_date, academic_year, semester, archived_date
                                        )
                                        SELECT 
                                            student_id, 
                                            document_id as original_document_id,
                                            type as document_type, 
                                            file_path, 
                                            upload_date as original_upload_date,
                                            $1, $2, CURRENT_TIMESTAMP
                                        FROM documents
                                        WHERE student_id IS NOT NULL
                                    ", [$academic_year, $semester]);
                                    
                                    if ($archive_result) {
                                        // Clear current documents after successful archive
                                        $clear_result = pg_query($connection, "DELETE FROM documents");
                                        if (!$clear_result) {
                                            error_log("Warning: Failed to clear documents table after archiving: " . pg_last_error($connection));
                                        }
                                    }
                                } else {
                                    $archived_count = 0; // No documents to archive
                                }
                            }
                        }
                    }
                } catch (Exception $doc_error) {
                    // Document archiving failed - log but continue
                    error_log("Document archiving failed during finalization: " . $doc_error->getMessage());
                    $archived_count = 0;
                }
                
                // Step 2: Create distribution snapshot (optional)
                try {
                    // First check if snapshot table exists
                    $snapshot_table_check = pg_query($connection, "
                        SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name='distribution_snapshots') as has_snapshots
                    ");
                    
                    if ($snapshot_table_check) {
                        $snapshot_table = pg_fetch_assoc($snapshot_table_check);
                        
                        if ($snapshot_table['has_snapshots'] === 't') {
                            // Get student count safely
                            $student_count_query = pg_query($connection, "SELECT COUNT(*) as total FROM students WHERE status = 'active'");
                            $student_count = 0;
                            if ($student_count_query) {
                                $student_data = pg_fetch_assoc($student_count_query);
                                $student_count = intval($student_data['total']);
                            }
                            
                            // Create snapshot
                            $snapshot_result = pg_query_params($connection, "
                                INSERT INTO distribution_snapshots (
                                    distribution_date, academic_year, semester, 
                                    total_students_count, location, notes
                                ) VALUES (
                                    CURRENT_DATE, $1, $2, $3, $4, $5
                                )
                            ", [
                                $academic_year, 
                                $semester, 
                                $student_count,
                                'Main Distribution Center',
                                'Distribution finalized via Distribution Control Center'
                            ]);
                            
                            $snapshot_created = $snapshot_result !== false;
                        }
                    }
                } catch (Exception $snapshot_error) {
                    // Snapshot creation failed - log but continue
                    error_log("Snapshot creation failed during finalization: " . $snapshot_error->getMessage());
                    $snapshot_created = false;
                }
                
                // Step 3: Update configuration (this is critical and must succeed)
                $status_configs = [
                    ['distribution_status', 'finalized'],
                    ['uploads_enabled', '0']
                ];
                
                foreach ($status_configs as [$key, $value]) {
                    $config_result = pg_query_params($connection, "
                        INSERT INTO config (key, value) VALUES ($1, $2) 
                        ON CONFLICT (key) DO UPDATE SET value = $2
                    ", [$key, $value]);
                    
                    if (!$config_result) {
                        throw new Exception("Critical error: Failed to update configuration key '$key': " . pg_last_error($connection));
                    }
                }
                
                // Step 4: Deactivate any open slots (optional but recommended)
                try {
                    pg_query($connection, "UPDATE signup_slots SET is_active = FALSE WHERE is_active = TRUE");
                } catch (Exception $slot_error) {
                    // Non-critical - log but continue
                    error_log("Failed to deactivate slots during finalization: " . $slot_error->getMessage());
                }
                
                // Commit transaction
                $commit_result = pg_query($connection, "COMMIT");
                if (!$commit_result) {
                    throw new Exception('Failed to commit transaction: ' . pg_last_error($connection));
                }
                
                // Build success message
                $message_parts = ["Distribution finalized successfully!"];
                if ($archived_count > 0) {
                    $message_parts[] = "$archived_count documents archived from documents table.";
                } else {
                    $message_parts[] = "No documents found to archive.";
                }
                if ($snapshot_created) {
                    $message_parts[] = "Distribution snapshot created.";
                }
                $message_parts[] = "System ready for next cycle.";
                
                $success = true;
                $message = implode(' ', $message_parts);
                
            } catch (Exception $e) {
                // Rollback transaction
                pg_query($connection, "ROLLBACK");
                
                // Log detailed error information
                $error_details = [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ];
                error_log("Distribution finalization failed: " . json_encode($error_details));
                
                $message = 'Failed to finalize distribution: ' . $e->getMessage();
                
                // Check if this was a transaction abort error
                if (strpos($e->getMessage(), 'transaction is aborted') !== false) {
                    $message .= ' (This usually indicates a database table or column doesn\'t exist. Please check your database schema.)';
                }
            }
            break;
            
        case 'reset_distribution':
            // Emergency reset for stuck distributions
            try {
                $reset_configs = [
                    ['distribution_status', 'inactive'],
                    ['uploads_enabled', '0']
                ];
                
                foreach ($reset_configs as [$key, $value]) {
                    $query = "INSERT INTO config (key, value) VALUES ($1, $2) ON CONFLICT (key) DO UPDATE SET value = $2";
                    $result = pg_query_params($connection, $query, [$key, $value]);
                    if (!$result) {
                        throw new Exception("Failed to reset configuration key '$key': " . pg_last_error($connection));
                    }
                }
                
                $success = true;
                $message = 'Distribution system has been reset to inactive state. You can now start a new distribution.';
                
            } catch (Exception $e) {
                $message = 'Failed to reset distribution: ' . $e->getMessage();
            }
            break;
    }
    
        // Refresh workflow status after action
        $workflow_status = getWorkflowStatus($connection);
        $student_counts = getStudentCounts($connection);
        
        // Ensure student_counts has all required keys
        $student_counts = array_merge([
            'total_students' => 0,
            'active_count' => 0,
            'applicant_count' => 0,
            'verified_students' => 0,
            'pending_verification' => 0
        ], $student_counts);
        
        // Update extracted status variables
        $distribution_status = $workflow_status['distribution_status'] ?? 'inactive';
        $uploads_enabled = $workflow_status['uploads_enabled'] ?? false;
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
<style>
  /* Modern Distribution Control Styling */
  .control-header {
    background: linear-gradient(145deg, #f5f7fa 0%, #eef1f4 100%);
    border: 1px solid #e3e7ec;
    border-radius: 16px;
    padding: 2rem 1.75rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
  }
  
  .control-header::before {
    content: '';
    position: absolute;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle at center, rgba(102, 126, 234, 0.08), transparent 70%);
    top: -100px;
    right: -100px;
    border-radius: 50%;
  }
  
  .status-badge-large {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border-radius: 50px;
    font-weight: 600;
    font-size: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  }
  
  .metric-card {
    background: white;
    border-radius: 12px;
    border: 1px solid #e3e7ec;
    padding: 1.5rem;
    text-align: center;
    transition: all 0.3s ease;
    height: 100%;
  }
  
  .metric-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    border-color: #d0d7de;
  }
  
  .metric-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    margin-bottom: 1rem;
  }
  
  .metric-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
    color: #2c3e50;
  }
  
  .metric-label {
    font-size: 0.875rem;
    color: #6c757d;
    font-weight: 500;
  }
  
  .action-card {
    background: white;
    border-radius: 14px;
    border: 2px solid #e3e7ec;
    padding: 2rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
  }
  
  .action-card:hover {
    box-shadow: 0 6px 24px rgba(0,0,0,0.08);
  }
  
  .action-card.border-success {
    border-color: #48bb78;
  }
  
  .action-card.border-primary {
    border-color: #667eea;
  }
  
  .action-card.border-info {
    border-color: #4299e1;
  }
  
  .action-card.border-danger {
    border-color: #f56565;
  }
  
  .action-card.border-warning {
    border-color: #ed8936;
  }
  
  .action-card-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  
  .action-card-text {
    color: #64748b;
    margin-bottom: 1.5rem;
  }
  
  .btn-modern {
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.2s ease;
    border: none;
  }
  
  .btn-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  }
  
  .history-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid #e3e7ec;
  }
  
  .history-table thead {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
  }
  
  .history-table th {
    font-weight: 600;
    padding: 1rem;
    border: none;
  }
  
  .history-table td {
    padding: 1rem;
    border-top: 1px solid #e3e7ec;
  }
  
  .info-panel {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    padding: 1.5rem;
    border: 1px solid #dee2e6;
  }
  
  .info-panel h6 {
    font-weight: 600;
    margin-bottom: 1rem;
    color: #495057;
  }
  
  .form-modern .form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
  }
  
  .form-modern .form-control,
  .form-modern .form-select {
    border-radius: 8px;
    border: 1px solid #d1d5db;
    padding: 0.75rem 1rem;
    transition: all 0.2s ease;
  }
  
  .form-modern .form-control:focus,
  .form-modern .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
  }
  
  .alert-modern {
    border-radius: 12px;
    border: none;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
  }
  
  .alert-success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
  }
  
  .alert-danger {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
  }
  
  .alert-info {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
  }
  
  @media (max-width: 768px) {
    .control-header {
      padding: 1.5rem;
    }
    
    .metric-card {
      margin-bottom: 1rem;
    }
    
    .action-card {
      padding: 1.5rem;
    }
  }
</style>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>

<div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>
    
    <section class="home-section" id="mainContent">
        <div class="container-fluid px-4">
            <!-- Status Messages -->
            <?php if (isset($message)): ?>
                <div class="alert alert-modern alert-<?= $success ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                    <i class="bi bi-<?= $success ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($migration_needed): ?>
                <div class="alert alert-modern alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Auto-Migration Applied:</strong> 
                    Existing distribution detected without academic period configuration. 
                    Default academic period has been set: 
                    <strong><?= htmlspecialchars($current_semester) ?> <?= htmlspecialchars($current_academic_year) ?></strong>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Header -->
            <div class="control-header">
                <div class="d-flex justify-content-between align-items-start position-relative" style="z-index: 2;">
                    <div>
                        <h1 class="mb-2" style="font-size: 2rem; font-weight: 700; color: #2c3e50;">
                            <i class="bi bi-diagram-3-fill text-primary me-2"></i>
                            Distribution Control Center
                        </h1>
                        <p class="text-muted mb-0" style="font-size: 1rem;">
                            Manage the complete distribution lifecycle
                            <?php if ($current_academic_year && $current_semester): ?>
                                <br><span class="badge bg-info mt-2">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <?= htmlspecialchars($current_semester) ?> <?= htmlspecialchars($current_academic_year) ?>
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <?php
                        $statusIcons = [
                            'inactive' => 'circle',
                            'preparing' => 'gear-fill',
                            'active' => 'play-circle-fill',
                            'finalizing' => 'hourglass-split',
                            'finalized' => 'check-circle-fill'
                        ];
                        $statusColors = [
                            'inactive' => 'secondary',
                            'preparing' => 'warning',
                            'active' => 'success',
                            'finalizing' => 'info',
                            'finalized' => 'primary'
                        ];
                        $statusColor = $statusColors[$workflow_status['distribution_status']] ?? 'secondary';
                        $statusIcon = $statusIcons[$workflow_status['distribution_status']] ?? 'circle';
                        ?>
                        <span class="status-badge-large bg-<?= $statusColor ?> text-white">
                            <i class="bi bi-<?= $statusIcon ?>"></i>
                            <?= ucfirst($workflow_status['distribution_status']) ?>
                        </span>
                    </div>
                </div>
            </div>
                            
            <!-- Metrics Dashboard -->
            <div class="row g-3 mb-4">
                <div class="col-md-3 col-sm-6">
                    <div class="metric-card">
                        <div class="metric-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <i class="bi bi-people-fill text-white"></i>
                        </div>
                        <div class="metric-value"><?= $student_counts['active_count'] ?></div>
                        <div class="metric-label">Active Students</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="metric-card">
                        <div class="metric-icon" style="background: linear-gradient(135deg, <?= $workflow_status['slots_open'] ? '#48bb78' : '#f56565' ?> 0%, <?= $workflow_status['slots_open'] ? '#38a169' : '#e53e3e' ?> 100%);">
                            <i class="bi bi-door-<?= $workflow_status['slots_open'] ? 'open' : 'closed' ?>-fill text-white"></i>
                        </div>
                        <div class="metric-value"><?= $workflow_status['slots_open'] ? 'Open' : 'Closed' ?></div>
                        <div class="metric-label">Registration Slots</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="metric-card">
                        <div class="metric-icon" style="background: linear-gradient(135deg, <?= $workflow_status['uploads_enabled'] ? '#4299e1' : '#ed8936' ?> 0%, <?= $workflow_status['uploads_enabled'] ? '#3182ce' : '#dd6b20' ?> 100%);">
                            <i class="bi bi-cloud-<?= $workflow_status['uploads_enabled'] ? 'upload' : 'slash' ?>-fill text-white"></i>
                        </div>
                        <div class="metric-value"><?= $workflow_status['uploads_enabled'] ? 'Enabled' : 'Disabled' ?></div>
                        <div class="metric-label">Document Uploads</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="metric-card">
                        <div class="metric-icon" style="background: linear-gradient(135deg, #f6ad55 0%, #ed8936 100%);">
                            <i class="bi bi-person-plus-fill text-white"></i>
                        </div>
                        <div class="metric-value"><?= $student_counts['applicant_count'] ?></div>
                        <div class="metric-label">Pending Applicants</div>
                    </div>
                </div>
            </div>
            
            <!-- Action Cards -->
                            <div class="row g-3">
                                <?php if ($workflow_status['can_start_distribution']): ?>
                                    <div class="col-md-12">
                                        <div class="card border-success">
                                            <div class="card-body">
                                                <h5 class="card-title text-success">
                                                    <i class="bi bi-play-circle me-2"></i>Start New Distribution
                                                </h5>
                                                <p class="card-text">Begin a new distribution cycle. Set the academic period for this distribution.</p>
                                                <form method="POST" id="startDistributionForm">
                                                    <input type="hidden" name="action" value="start_distribution">
                                                    <?= CSRFProtection::getTokenField('distribution_control') ?>
                                                    
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-md-6">
                                                            <label for="academic_year" class="form-label">Academic Year <span class="text-danger">*</span></label>
                                                            <input type="text" class="form-control" name="academic_year" id="academic_year" 
                                                                   placeholder="2025-2026" pattern="\d{4}-\d{4}" required>
                                                            <div class="form-text">Format: YYYY-YYYY (e.g., 2025-2026)</div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label for="semester" class="form-label">Semester <span class="text-danger">*</span></label>
                                                            <select class="form-select" name="semester" id="semester" required>
                                                                <option value="">Select semester</option>
                                                                <option value="1st Semester">1st Semester</option>
                                                                <option value="2nd Semester">2nd Semester</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label for="documents_deadline" class="form-label">Documents Submission Deadline</label>
                                                            <input type="date" class="form-control" name="documents_deadline" id="documents_deadline">
                                                            <div class="form-text">Students will see this deadline once the distribution is activated. Schedules cannot start before this date.</div>
                                                        </div>
                                                    </div>
                                                    
                                                    <button type="submit" class="btn btn-success" onclick="return confirm('Start a new distribution cycle with the specified academic period?')">
                                                        <i class="bi bi-play-fill me-1"></i>Start Distribution
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($workflow_status['distribution_status'] === 'active'): ?>
                                    <div class="col-md-6">
                                        <div class="card border-info">
                                            <div class="card-body">
                                                <h5 class="card-title text-info">
                                                    <i class="bi bi-gear me-2"></i>System Management
                                                </h5>
                                                <p class="card-text">Manage registration slots and scheduling through dedicated pages.</p>
                                                <div class="d-flex gap-2">
                                                    <a href="manage_slots.php" class="btn btn-outline-info btn-sm">
                                                        <i class="bi bi-sliders me-1"></i>Manage Slots
                                                    </a>
                                                    <a href="manage_schedules.php" class="btn btn-outline-info btn-sm">
                                                        <i class="bi bi-calendar me-1"></i>Scheduling
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($workflow_status['distribution_status'] === 'active'): ?>
                                    <div class="col-md-12">
                                        <div class="alert alert-info d-flex align-items-center">
                                            <i class="bi bi-info-circle-fill me-3" style="font-size: 1.5rem;"></i>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1">Distribution Active</h6>
                                                <p class="mb-0">
                                                    When you're ready to end this distribution cycle, go to the 
                                                    <a href="end_distribution.php" class="alert-link fw-bold">
                                                        <i class="bi bi-box-arrow-right me-1"></i>End Distribution
                                                    </a> 
                                                    page to finalize and archive all data.
                                                </p>
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
                        
                        <!-- System Information -->
                        <div class="card border-0 shadow-sm mt-4" style="border-radius: 12px; overflow: hidden;">
                            <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 1.25rem;">
                                <h5 class="mb-0 text-white fw-bold">
                                    <i class="fas fa-info-circle me-2"></i>
                                    System Information
                                </h5>
                            </div>
                            <div class="card-body" style="background: #f8f9fa; padding: 1.75rem;">
                                <div class="row g-4">
                                    <!-- Configuration Status Column -->
                                    <div class="col-md-6">
                                        <div class="info-section" style="background: white; padding: 1.5rem; border-radius: 10px; height: 100%; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                            <h6 class="fw-bold text-primary mb-3" style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                                <i class="fas fa-cog me-2"></i>Configuration Status
                                            </h6>
                                            <div class="info-grid">
                                                <div class="info-item d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid #e9ecef;">
                                                    <span class="text-muted" style="font-size: 0.9rem;">Distribution Status:</span>
                                                    <span class="badge <?= $distribution_status === 'active' ? 'bg-success' : ($distribution_status === 'preparing' ? 'bg-warning text-dark' : ($distribution_status === 'finalized' ? 'bg-info' : 'bg-secondary')) ?>" style="font-size: 0.85rem; padding: 0.4rem 0.8rem;">
                                                        <?= ucfirst(htmlspecialchars($distribution_status)) ?>
                                                    </span>
                                                </div>
                                                <div class="info-item d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid #e9ecef;">
                                                    <span class="text-muted" style="font-size: 0.9rem;">Uploads Status:</span>
                                                    <span class="badge <?= $uploads_enabled ? 'bg-success' : 'bg-danger' ?>" style="font-size: 0.85rem; padding: 0.4rem 0.8rem;">
                                                        <?= $uploads_enabled ? 'Enabled' : 'Disabled' ?>
                                                    </span>
                                                </div>
                                                <div class="info-item d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid #e9ecef;">
                                                    <span class="text-muted" style="font-size: 0.9rem;">Academic Year:</span>
                                                    <span class="fw-bold text-dark" style="font-size: 0.9rem;">
                                                        <?= htmlspecialchars($current_academic_year ?: 'Not Set') ?>
                                                    </span>
                                                </div>
                                                <div class="info-item d-flex justify-content-between align-items-center">
                                                    <span class="text-muted" style="font-size: 0.9rem;">Semester:</span>
                                                    <span class="fw-bold text-dark" style="font-size: 0.9rem;">
                                                        <?= htmlspecialchars($current_semester ?: 'Not Set') ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Student Counts Column -->
                                    <div class="col-md-6">
                                        <div class="info-section" style="background: white; padding: 1.5rem; border-radius: 10px; height: 100%; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                            <h6 class="fw-bold text-success mb-3" style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                                <i class="fas fa-users me-2"></i>Student Statistics
                                            </h6>
                                            <div class="info-grid">
                                                <div class="info-item d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid #e9ecef;">
                                                    <span class="text-muted" style="font-size: 0.9rem;">Total Students:</span>
                                                    <span class="badge bg-primary" style="font-size: 1rem; padding: 0.4rem 0.8rem; min-width: 50px;">
                                                        <?= number_format($student_counts['total_students']) ?>
                                                    </span>
                                                </div>
                                                <div class="info-item d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid #e9ecef;">
                                                    <span class="text-muted" style="font-size: 0.9rem;">Verified Students:</span>
                                                    <span class="badge bg-success" style="font-size: 1rem; padding: 0.4rem 0.8rem; min-width: 50px;">
                                                        <?= number_format($student_counts['verified_students']) ?>
                                                    </span>
                                                </div>
                                                <div class="info-item d-flex justify-content-between align-items-center">
                                                    <span class="text-muted" style="font-size: 0.9rem;">Pending Verification:</span>
                                                    <span class="badge bg-warning text-dark" style="font-size: 1rem; padding: 0.4rem 0.8rem; min-width: 50px;">
                                                        <?= number_format($student_counts['pending_verification']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Emergency Reset Option -->
                        <?php if ($distribution_status === 'active' || $distribution_status === 'finalized'): ?>
                            <div class="card border-0 shadow-sm mt-4" style="border-radius: 12px; overflow: hidden; border-left: 5px solid #dc3545;">
                                <div class="card-header" style="background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%); padding: 1.25rem;">
                                    <h5 class="mb-0 text-white fw-bold">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Emergency Reset
                                    </h5>
                                </div>
                                <div class="card-body" style="background: #fff5f5; padding: 1.75rem;">
                                    <div class="alert alert-danger d-flex align-items-start" style="border-left: 4px solid #dc3545; background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(220,53,69,0.1);">
                                        <div class="me-3" style="font-size: 2rem; color: #dc3545;">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="fw-bold text-danger mb-2">⚠️ Critical Action Warning</h6>
                                            <p class="mb-0 text-dark" style="font-size: 0.95rem;">
                                                This will <strong>immediately reset</strong> the distribution system to inactive state. 
                                                Use only if the system is stuck, experiencing critical errors, or requires emergency intervention.
                                            </p>
                                            <p class="mb-0 mt-2 text-muted small">
                                                <i class="fas fa-info-circle me-1"></i>
                                                This action cannot be undone. All active distribution workflows will be terminated.
                                            </p>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-end mt-3">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <button type="submit" name="action" value="reset_distribution" 
                                                    class="btn btn-danger btn-lg fw-bold" 
                                                    style="background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%); border: none; padding: 0.75rem 2rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(220,53,69,0.3); transition: all 0.3s ease;"
                                                    onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(220,53,69,0.4)';"
                                                    onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(220,53,69,0.3)';"
                                                    onclick="return confirm('⚠️ CRITICAL WARNING ⚠️\n\nAre you absolutely sure you want to reset the distribution system?\n\nThis will:\n• Deactivate the current distribution\n• Stop all active workflows\n• Reset system to inactive state\n\nThis action CANNOT be undone!\n\nClick OK to proceed or Cancel to abort.')">
                                                <i class="fas fa-power-off me-2"></i>
                                                Reset Distribution System
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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