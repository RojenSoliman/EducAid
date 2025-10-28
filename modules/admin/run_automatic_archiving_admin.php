<?php
/**
 * Run Automatic Archiving - Admin Interface
 * 
 * Web-based interface for running automatic student archiving.
 * No cron job required - admins can run on-demand.
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/AuditLogger.php';

// Check if user is logged in as super admin
if (!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../../login.php");
    exit();
}

$auditLogger = new AuditLogger($connection);
$adminId = $_SESSION['admin_id'];
$adminUsername = $_SESSION['admin_username'];

// Initialize variables
$step = $_GET['step'] ?? 'check';
$eligibleStudents = [];
$stats = [];
$error = null;

// Step 1: Check eligibility
if ($step === 'check') {
    // Get statistics
    $statsQuery = "
        SELECT 
            COUNT(*) as eligible_count,
            COUNT(CASE WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > expected_graduation_year THEN 1 END) as graduated_past_count,
            COUNT(CASE WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6 THEN 1 END) as graduated_current_count,
            COUNT(CASE WHEN last_login IS NOT NULL AND last_login < (CURRENT_DATE - INTERVAL '2 years') THEN 1 END) as inactive_login_count,
            COUNT(CASE WHEN last_login IS NULL AND application_date < (CURRENT_DATE - INTERVAL '2 years') THEN 1 END) as never_login_count
        FROM students
        WHERE is_archived = FALSE
          AND status NOT IN ('blacklisted')
          AND (
              EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > expected_graduation_year
              OR (EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6)
              OR (last_login IS NOT NULL AND last_login < (CURRENT_DATE - INTERVAL '2 years'))
              OR (last_login IS NULL AND application_date < (CURRENT_DATE - INTERVAL '2 years'))
          )
    ";
    
    $statsResult = pg_query($connection, $statsQuery);
    $stats = pg_fetch_assoc($statsResult);
    
    // Get detailed list
    if ($stats['eligible_count'] > 0) {
        $detailsQuery = "
            SELECT 
                s.student_id,
                s.first_name,
                s.last_name,
                s.email,
                s.expected_graduation_year,
                s.last_login,
                s.application_date,
                yl.name as year_level,
                CASE 
                    WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > s.expected_graduation_year THEN 'Graduated (past expected year)'
                    WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = s.expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6 THEN 'Graduated (current year)'
                    WHEN s.last_login IS NOT NULL AND s.last_login < (CURRENT_DATE - INTERVAL '2 years') THEN 'Inactive (no login 2+ years)'
                    WHEN s.last_login IS NULL AND s.application_date < (CURRENT_DATE - INTERVAL '2 years') THEN 'Inactive (never logged in)'
                    ELSE 'Other'
                END as reason
            FROM students s
            LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
            WHERE s.is_archived = FALSE
              AND s.status NOT IN ('blacklisted')
              AND (
                  EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > s.expected_graduation_year
                  OR (EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = s.expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6)
                  OR (s.last_login IS NOT NULL AND s.last_login < (CURRENT_DATE - INTERVAL '2 years'))
                  OR (s.last_login IS NULL AND s.application_date < (CURRENT_DATE - INTERVAL '2 years'))
              )
            ORDER BY s.expected_graduation_year, s.last_name
            LIMIT 100
        ";
        
        $detailsResult = pg_query($connection, $detailsQuery);
        while ($student = pg_fetch_assoc($detailsResult)) {
            $eligibleStudents[] = $student;
        }
    }
}

// Step 2: Execute archiving
if ($step === 'execute' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Execute archiving function
        $archiveResult = pg_query($connection, "SELECT * FROM archive_graduated_students()");
        
        if (!$archiveResult) {
            throw new Exception("Archiving function failed: " . pg_last_error($connection));
        }
        
        $result = pg_fetch_assoc($archiveResult);
        $archivedCount = $result['archived_count'];
        $studentIds = $result['student_ids'];
        
        // Parse student IDs
        if ($studentIds) {
            $studentIds = trim($studentIds, '{}');
            $studentIdsArray = $studentIds ? explode(',', $studentIds) : [];
        } else {
            $studentIdsArray = [];
        }
        
        // Log to audit trail
        $auditLogger->logBulkArchiving(
            $archivedCount,
            $studentIdsArray,
            $adminUsername
        );
        
        // Redirect to success page
        header("Location: run_automatic_archiving_admin.php?step=success&count=" . $archivedCount);
        exit();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        $step = 'error';
    }
}

// Get last run information
$lastRunQuery = "
    SELECT created_at, details
    FROM audit_logs
    WHERE event_category = 'archive' 
      AND event_type = 'bulk_archiving_executed'
    ORDER BY created_at DESC
    LIMIT 1
";
$lastRunResult = pg_query($connection, $lastRunQuery);
$lastRun = $lastRunResult ? pg_fetch_assoc($lastRunResult) : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automatic Student Archiving - EducAid Admin</title>
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .main-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .card {
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stat-card {
            text-align: center;
            padding: 20px;
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #0d6efd;
        }
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
        }
        .student-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .student-item {
            padding: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        .student-item:last-child {
            border-bottom: none;
        }
        .badge-reason {
            font-size: 0.75rem;
        }
        .warning-box {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
        }
        .success-box {
            background-color: #d1e7dd;
            border-left: 4px solid #198754;
            padding: 15px;
            margin-bottom: 20px;
        }
        .error-box {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-0">
                    <i class="bi bi-archive-fill text-primary"></i>
                    Automatic Student Archiving
                </h1>
                <p class="text-muted mb-0">Archive graduated and inactive students</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if ($lastRun): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            <strong>Last Run:</strong> <?php echo date('F j, Y g:i A', strtotime($lastRun['created_at'])); ?>
        </div>
        <?php endif; ?>

        <?php if ($step === 'check'): ?>
            <?php if ($stats['eligible_count'] == 0): ?>
                <!-- No students to archive -->
                <div class="success-box">
                    <h5><i class="bi bi-check-circle"></i> No Students Need Archiving</h5>
                    <p class="mb-0">All students are up to date. No automatic archiving is needed at this time.</p>
                </div>
                
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                        <h4 class="mt-3">System is Up to Date</h4>
                        <p class="text-muted">Check again after the next graduation season.</p>
                        <a href="dashboard.php" class="btn btn-primary mt-3">Return to Dashboard</a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Eligible students found -->
                <div class="warning-box">
                    <h5><i class="bi bi-exclamation-triangle"></i> Students Eligible for Archiving</h5>
                    <p class="mb-0">The following students meet the criteria for automatic archiving. Please review the list and confirm.</p>
                </div>

                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="stat-number"><?php echo $stats['eligible_count']; ?></div>
                            <div class="stat-label text-white-50">Total Eligible</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card">
                            <div class="stat-number text-success"><?php echo $stats['graduated_past_count']; ?></div>
                            <div class="stat-label">Graduated (Past)</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card">
                            <div class="stat-number text-info"><?php echo $stats['graduated_current_count']; ?></div>
                            <div class="stat-label">Graduated (Current)</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card">
                            <div class="stat-number text-warning"><?php echo $stats['inactive_login_count'] + $stats['never_login_count']; ?></div>
                            <div class="stat-label">Inactive</div>
                        </div>
                    </div>
                </div>

                <!-- Student List -->
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Students to be Archived</h5>
                        <small class="text-muted">Showing <?php echo min(count($eligibleStudents), 100); ?> of <?php echo $stats['eligible_count']; ?> students</small>
                    </div>
                    <div class="card-body p-0">
                        <div class="student-list">
                            <?php foreach ($eligibleStudents as $index => $student): ?>
                            <div class="student-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($student['student_id']); ?> • 
                                            <?php echo htmlspecialchars($student['email']); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge badge-reason <?php 
                                            echo strpos($student['reason'], 'Graduated') !== false ? 'bg-success' : 'bg-warning text-dark';
                                        ?>">
                                            <?php echo htmlspecialchars($student['reason']); ?>
                                        </span>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($student['year_level']); ?> • 
                                            Grad: <?php echo $student['expected_graduation_year'] ?? 'N/A'; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Confirmation -->
                <div class="card">
                    <div class="card-body">
                        <h5>Confirm Archiving</h5>
                        <p>This action will:</p>
                        <ul>
                            <li>Change the status of <strong><?php echo $stats['eligible_count']; ?> students</strong> to "archived"</li>
                            <li>Prevent these students from logging in</li>
                            <li>Remove them from active student lists</li>
                            <li>Create audit trail entries for all actions</li>
                        </ul>
                        <p class="text-danger mb-3">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <strong>Note:</strong> You can unarchive students later if needed, but it's recommended to review this list carefully before proceeding.
                        </p>
                        
                        <form method="POST" action="run_automatic_archiving_admin.php?step=execute" onsubmit="return confirm('Are you sure you want to archive <?php echo $stats['eligible_count']; ?> students? This action will prevent them from logging in.');">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-danger btn-lg">
                                    <i class="bi bi-archive"></i>
                                    Archive <?php echo $stats['eligible_count']; ?> Students
                                </button>
                                <a href="dashboard.php" class="btn btn-outline-secondary btn-lg">
                                    <i class="bi bi-x"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif ($step === 'success'): ?>
            <!-- Success -->
            <?php $count = $_GET['count'] ?? 0; ?>
            <div class="success-box">
                <h5><i class="bi bi-check-circle"></i> Archiving Complete!</h5>
                <p class="mb-0">Successfully archived <strong><?php echo $count; ?> students</strong>.</p>
            </div>

            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                    <h4 class="mt-3">Automatic Archiving Completed</h4>
                    <p class="text-muted"><strong><?php echo $count; ?></strong> students have been successfully archived.</p>
                    
                    <div class="mt-4">
                        <a href="archived_students.php" class="btn btn-primary me-2">
                            <i class="bi bi-list"></i> View Archived Students
                        </a>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-house"></i> Return to Dashboard
                        </a>
                    </div>
                </div>
            </div>

        <?php elseif ($step === 'error'): ?>
            <!-- Error -->
            <div class="error-box">
                <h5><i class="bi bi-x-circle"></i> Archiving Failed</h5>
                <p class="mb-0"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
            </div>

            <div class="card">
                <div class="card-body">
                    <h5>Troubleshooting Steps:</h5>
                    <ol>
                        <li>Check that the archiving system is properly installed (run SQL migration)</li>
                        <li>Verify database connection is working</li>
                        <li>Check PostgreSQL error logs</li>
                        <li>Contact technical support if the issue persists</li>
                    </ol>
                    
                    <div class="mt-3">
                        <a href="run_automatic_archiving_admin.php" class="btn btn-primary">
                            <i class="bi bi-arrow-clockwise"></i> Try Again
                        </a>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-house"></i> Return to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php pg_close($connection); ?>
