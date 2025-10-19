<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$message = '';
$messageType = '';
$stats = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    $password = $_POST['admin_password'] ?? '';
    
    // Verify admin password
    $verifyResult = pg_query_params($connection, 
        "SELECT admin_id, password FROM admins WHERE admin_id = $1",
        [$_SESSION['admin_id']]);
    
    if ($verifyResult && pg_num_rows($verifyResult) > 0) {
        $admin = pg_fetch_assoc($verifyResult);
        
        if (password_verify($password, $admin['password'])) {
            try {
                pg_query($connection, "BEGIN");
                
                // Get statistics before reset
                $statsResult = pg_query($connection,
                    "SELECT 
                        COUNT(DISTINCT student_id) as student_count,
                        COUNT(*) as distribution_count
                     FROM distributions 
                     WHERE status = 'given'");
                $stats = pg_fetch_assoc($statsResult);
                
                // 1. Reset students from 'given' back to 'active'
                pg_query($connection, "UPDATE students SET status = 'active' WHERE status = 'given'");
                
                // 2. Delete distribution records with status='given'
                pg_query($connection, "DELETE FROM distributions WHERE status = 'given'");
                
                // 3. Reset QR codes
                pg_query($connection, "UPDATE students SET qr_code = NULL WHERE status = 'active'");
                
                // 4. Reset payroll numbers (column is payroll_no, not payroll_number)
                pg_query($connection, "UPDATE students SET payroll_no = NULL WHERE status = 'active'");
                
                pg_query($connection, "COMMIT");
                
                $message = "Reset successful! {$stats['student_count']} students reset from 'given' to 'active'. 
                           {$stats['distribution_count']} distribution records deleted. QR codes and payroll numbers reset.";
                $messageType = 'success';
                
            } catch (Exception $e) {
                pg_query($connection, "ROLLBACK");
                $message = "Error during reset: " . $e->getMessage();
                $messageType = 'danger';
            }
        } else {
            $message = "Incorrect password. Reset cancelled.";
            $messageType = 'danger';
        }
    } else {
        $message = "Admin not found.";
        $messageType = 'danger';
    }
}

// Get current statistics
$currentStatsResult = pg_query($connection,
    "SELECT 
        COUNT(*) FILTER (WHERE status = 'active') as active_count,
        COUNT(*) FILTER (WHERE status = 'given') as given_count,
        COUNT(*) FILTER (WHERE qr_code IS NOT NULL) as has_qr_count,
        COUNT(*) FILTER (WHERE payroll_no IS NOT NULL) as has_payroll_count
     FROM students");
$currentStats = pg_fetch_assoc($currentStatsResult);

$distStatsResult = pg_query($connection,
    "SELECT 
        COUNT(*) FILTER (WHERE COALESCE(status, 'active') = 'active') as active_distributions,
        COUNT(*) FILTER (WHERE status = 'given') as given_distributions
     FROM distributions");
$distStats = pg_fetch_assoc($distStatsResult);

$pageTitle = "Reset Distribution (DEV)";
?>
<?php $page_title='Reset Distribution'; include '../../includes/admin/admin_head.php'; ?>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
        <?php include '../../includes/admin/admin_header.php'; ?>
        <section class="home-section" id="mainContent">
            <div class="container-fluid py-4 px-4">
    <style>
        .warning-box {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .dev-badge {
            background: #ffc107;
            color: #000;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    
    <div class="main-content">
        <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>
                    <i class="bi bi-arrow-counterclockwise"></i> Reset Distribution
                    <span class="dev-badge ms-2">DEVELOPMENT TOOL</span>
                </h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Reset Distribution</li>
                    </ol>
                </nav>
            </div>

            <!-- Warning Box -->
            <div class="warning-box">
                <h3><i class="bi bi-exclamation-triangle-fill"></i> DANGER: Development Reset Tool</h3>
                <p class="mb-0">
                    <strong>This tool will:</strong>
                </p>
                <ul>
                    <li>Reset all students with status='given' back to status='active'</li>
                    <li>Delete all distribution records with status='given'</li>
                    <li>Clear QR codes for active students</li>
                    <li>Clear payroll numbers for active students</li>
                </ul>
                <p class="mb-0 mt-3">
                    <strong>Use this only for testing purposes!</strong> This action cannot be undone.
                </p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php if ($messageType === 'success'): ?>
                        <i class="bi bi-check-circle-fill"></i>
                    <?php else: ?>
                        <i class="bi bi-exclamation-circle-fill"></i>
                    <?php endif; ?>
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Current Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card border-success">
                        <div class="card-body text-center">
                            <h3 class="text-success"><?php echo $currentStats['active_count']; ?></h3>
                            <p class="mb-0 text-muted">Active Students</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card border-warning">
                        <div class="card-body text-center">
                            <h3 class="text-warning"><?php echo $currentStats['given_count']; ?></h3>
                            <p class="mb-0 text-muted">Given Status (Will be Reset)</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card border-info">
                        <div class="card-body text-center">
                            <h3 class="text-info"><?php echo $currentStats['has_qr_count']; ?></h3>
                            <p class="mb-0 text-muted">Students with QR Codes</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card border-primary">
                        <div class="card-body text-center">
                            <h3 class="text-primary"><?php echo $currentStats['has_payroll_count']; ?></h3>
                            <p class="mb-0 text-muted">Students with Payroll #</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Distribution Statistics -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-box-seam"></i> Distribution Records</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h4><?php echo $distStats['active_distributions']; ?></h4>
                            <p class="text-muted">Active Distributions</p>
                        </div>
                        <div class="col-md-6">
                            <h4 class="text-warning"><?php echo $distStats['given_distributions']; ?></h4>
                            <p class="text-muted">Given Distributions (Will be Deleted)</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reset Form -->
            <?php if ($currentStats['given_count'] > 0 || $distStats['given_distributions'] > 0): ?>
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-shield-lock-fill"></i> Confirm Reset</h5>
                </div>
                <div class="card-body">
                    <p class="text-danger">
                        <strong>⚠️ This will reset <?php echo $currentStats['given_count']; ?> students 
                        and delete <?php echo $distStats['given_distributions']; ?> distribution records!</strong>
                    </p>
                    
                    <form method="POST" onsubmit="return confirm('Are you absolutely sure you want to reset? This cannot be undone!');">
                        <div class="mb-3">
                            <label for="admin_password" class="form-label">Enter Your Password to Confirm:</label>
                            <input type="password" 
                                   class="form-control" 
                                   id="admin_password" 
                                   name="admin_password" 
                                   required
                                   placeholder="Your admin password">
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" name="confirm_reset" class="btn btn-danger">
                                <i class="bi bi-arrow-counterclockwise"></i> Confirm Reset
                            </button>
                            <a href="dashboard.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <strong>Nothing to reset!</strong> There are no students with 'given' status or 'given' distributions.
            </div>
            <?php endif; ?>
            </div>
        </section>
    </div>

    <script>