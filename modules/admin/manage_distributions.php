<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $query = "
        SELECT 
            s.student_id,
            s.payroll_no,
            CONCAT(s.first_name, ' ', s.last_name) as full_name,
            s.email,
            s.mobile,
            b.name as barangay,
            u.name as university,
            yl.name as year_level,
            d.date_given,
            a.first_name as distributed_by
        FROM students s
        LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
        LEFT JOIN universities u ON s.university_id = u.university_id
        LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
        LEFT JOIN distributions d ON s.student_id = d.student_id
        LEFT JOIN admins a ON d.verified_by = a.admin_id
        WHERE s.status = 'given'
        ORDER BY d.date_given DESC
    ";
    
    $result = pg_query($connection, $query);
    
    if ($result) {
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="distributions_' . date('Y-m-d_H-i-s') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // CSV Headers
        fputcsv($output, [
            'Student ID',
            'Payroll Number', 
            'Full Name',
            'Email',
            'Mobile',
            'Barangay',
            'University',
            'Year Level',
            'Distribution Date',
            'Distributed By'
        ]);
        
        // CSV Data
        while ($row = pg_fetch_assoc($result)) {
            fputcsv($output, [
                $row['student_id'],
                $row['payroll_no'],
                $row['full_name'],
                $row['email'],
                $row['mobile'],
                $row['barangay'],
                $row['university'],
                $row['year_level'],
                $row['date_given'] ? date('Y-m-d', strtotime($row['date_given'])) : '',
                $row['distributed_by']
            ]);
        }
        
        fclose($output);
        exit;
    }
}

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Handle revert all students to applicant action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revert_all_to_applicant'])) {
    try {
        // Begin transaction
        pg_query($connection, "BEGIN");
        
        // Update all students with 'given' status to 'applicant' and clear payroll numbers and QR codes
        $update_students = pg_query($connection, "UPDATE students SET status = 'applicant', payroll_no = NULL, qr_code = NULL WHERE status = 'given'");
        
        // Delete all distribution records for these students
        $delete_distributions = pg_query($connection, "DELETE FROM distributions WHERE student_id IN (SELECT student_id FROM students WHERE status = 'applicant')");
        
        // Delete all QR codes
        $delete_qr_codes = pg_query($connection, "DELETE FROM qr_codes");
        
        // Delete all schedules
        $delete_schedules = pg_query($connection, "DELETE FROM schedules");
        
        if ($update_students && $delete_distributions && $delete_qr_codes && $delete_schedules) {
            pg_query($connection, "COMMIT");
            $_SESSION['success_message'] = 'All students reverted to applicant status. Payroll numbers, QR codes, distribution records, and schedules cleared.';
        } else {
            pg_query($connection, "ROLLBACK");
            $_SESSION['error_message'] = 'Failed to revert all students. Transaction rolled back.';
        }
    } catch (Exception $e) {
        pg_query($connection, "ROLLBACK");
        $_SESSION['error_message'] = 'Error occurred: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
    exit;
}
$barangay_filter = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query with filters
$where_conditions = ["s.status = 'given'"];
$params = [];
$param_count = 0;

if (!empty($search)) {
    $param_count++;
    $where_conditions[] = "(CONCAT(s.first_name, ' ', s.last_name) ILIKE $" . $param_count . " OR s.payroll_no::text ILIKE $" . $param_count . " OR s.student_id::text ILIKE $" . $param_count . ")";
    $params[] = "%$search%";
}

if (!empty($barangay_filter)) {
    $param_count++;
    $where_conditions[] = "b.barangay_id = $" . $param_count;
    $params[] = $barangay_filter;
}

if (!empty($date_from)) {
    $param_count++;
    $where_conditions[] = "DATE(d.date_given) >= $" . $param_count;
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $param_count++;
    $where_conditions[] = "DATE(d.date_given) <= $" . $param_count;
    $params[] = $date_to;
}

$where_clause = implode(' AND ', $where_conditions);

// Main query
$query = "
    SELECT 
        s.student_id,
        s.payroll_no,
        CONCAT(s.first_name, ' ', s.last_name) as full_name,
        s.email,
        s.mobile,
        b.name as barangay,
        u.name as university,
        yl.name as year_level,
        d.date_given,
        a.first_name as distributed_by
    FROM students s
    LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
    LEFT JOIN universities u ON s.university_id = u.university_id
    LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
    LEFT JOIN distributions d ON s.student_id = d.student_id
    LEFT JOIN admins a ON d.verified_by = a.admin_id
    WHERE $where_clause
    ORDER BY d.date_given DESC
";

if (!empty($params)) {
    $result = pg_query_params($connection, $query, $params);
} else {
    $result = pg_query($connection, $query);
}

// Get barangays for filter dropdown
$barangays_query = "SELECT barangay_id, name FROM barangays ORDER BY name";
$barangays_result = pg_query($connection, $barangays_query);

// Count total distributions
$count_query = "SELECT COUNT(*) as total FROM students WHERE status = 'given'";
$count_result = pg_query($connection, $count_query);
$total_distributions = pg_fetch_assoc($count_result)['total'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Distributions - Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../assets/css/admin/homepage.css">
    <link rel="stylesheet" href="../../assets/css/admin/sidebar.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .filter-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .table-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .table-responsive {
            border-radius: 15px;
        }
        .btn-export {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            border: none;
            color: white;
            border-radius: 25px;
            padding: 0.5rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            color: white;
        }
        .badge-distribution {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-radius: 15px;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <div id="wrapper">
        <?php include '../../includes/admin/admin_sidebar.php'; ?>
        
        <section class="home-section" id="mainContent">
            <nav>
                <div class="sidebar-toggle px-4 py-3">
                    <i class="bi bi-list" id="menu-toggle"></i>
                </div>
            </nav>

            <div class="container-fluid py-4 px-4">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="fw-bold mb-1">Manage Distributions</h1>
                        <p class="text-muted mb-0">Track and manage distributed aid to students</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="?export=csv<?php echo !empty($_SERVER['QUERY_STRING']) ? '&' . http_build_query(array_diff_key($_GET, ['export' => ''])) : ''; ?>" 
                           class="btn btn-export">
                            <i class="bi bi-download me-2"></i>Export to CSV
                        </a>
                        <form method="POST" style="display: inline;">
                            <button type="submit" name="revert_all_to_applicant" class="btn btn-danger" 
                                    onclick="return confirm('WARNING: This will revert ALL students to applicant status and remove all payroll numbers, QR codes, and distribution records. This action cannot be undone. Are you sure?');">
                                <i class="bi bi-arrow-counterclockwise me-2"></i>Revert All to Applicant
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Flash Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>

                <!-- Statistics Card -->
                <div class="stats-card">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h3 class="fw-bold mb-2">
                                <i class="bi bi-box-seam me-2"></i>
                                Total Distributions
                            </h3>
                            <p class="mb-0 opacity-75">Students who have received their aid packages</p>
                        </div>
                        <div class="col-md-4 text-end">
                            <h2 class="fw-bold mb-0 display-4"><?php echo $total_distributions; ?></h2>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Search</label>
                            <input type="text" class="form-control" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Name, Student ID, or Payroll #">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Barangay</label>
                            <select class="form-select" name="barangay">
                                <option value="">All Barangays</option>
                                <?php while ($barangay = pg_fetch_assoc($barangays_result)): ?>
                                    <option value="<?php echo $barangay['barangay_id']; ?>" 
                                            <?php echo $barangay_filter == $barangay['barangay_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Date From</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Date To</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search"></i> Filter
                            </button>
                            <a href="manage_distributions.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Results Table -->
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Student ID</th>
                                    <th>Payroll #</th>
                                    <th>Name</th>
                                    <th>Contact</th>
                                    <th>Barangay</th>
                                    <th>Education Details</th>
                                    <th>Distribution Date</th>
                                    <th>Distributed By</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result && pg_num_rows($result) > 0): ?>
                                    <?php while ($row = pg_fetch_assoc($result)): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($row['student_id']); ?></td>
                                            <td>
                                                <code class="bg-light px-2 py-1 rounded"><?php echo htmlspecialchars($row['payroll_no']); ?></code>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                            </td>
                                            <td>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($row['email']); ?></small>
                                                <small class="text-muted"><?php echo htmlspecialchars($row['mobile']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['barangay']); ?></td>
                                            <td>
                                                <div class="small">
                                                    <strong><?php echo htmlspecialchars($row['university']); ?></strong><br>
                                                    <span class="text-muted"><?php echo htmlspecialchars($row['year_level']); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($row['date_given']): ?>
                                                    <div class="small">
                                                        <?php echo date('M d, Y', strtotime($row['date_given'])); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $row['distributed_by'] ? htmlspecialchars($row['distributed_by']) : '<span class="text-muted">-</span>'; ?>
                                            </td>
                                            <td>
                                                <span class="badge-distribution">
                                                    <i class="bi bi-check-circle me-1"></i>Distributed
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-5">
                                            <div class="text-muted">
                                                <i class="bi bi-inbox display-1 mb-3"></i>
                                                <h5>No distributions found</h5>
                                                <p>No students have received their aid packages yet.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- System Reset Section -->
                <div class="table-card mt-4">
                    <div class="p-4">
                        <h5 class="mb-3"><i class="bi bi-exclamation-triangle text-warning me-2"></i>System Reset</h5>
                        <div class="alert alert-warning mb-3">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This action will reset the entire system by:
                            <ul class="mb-0 mt-2">
                                <li>Reverting ALL students to applicant status</li>
                                <li>Clearing all payroll numbers</li>
                                <li>Deleting all QR codes</li>
                                <li>Removing all distribution records</li>
                                <li>Deleting all schedules</li>
                            </ul>
                            <strong class="text-danger">This action cannot be undone!</strong>
                        </div>
                        <form method="POST" onsubmit="return confirmSystemReset()" class="d-inline">
                            <button type="submit" name="revert_all_to_applicant" class="btn btn-danger">
                                <i class="bi bi-arrow-counterclockwise me-2"></i>Reset Entire System
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin/sidebar.js"></script>
    <script>
    function confirmSystemReset() {
        return confirm('⚠️ CRITICAL WARNING ⚠️\n\nYou are about to RESET THE ENTIRE SYSTEM!\n\nThis will:\n• Revert ALL students to applicant status\n• Clear all payroll numbers\n• Delete all QR codes\n• Remove all distribution records\n• Delete all schedules\n\nTHIS ACTION CANNOT BE UNDONE!\n\nAre you absolutely sure you want to proceed?');
    }
    </script>
</body>
</html>

<?php 
if ($result) pg_free_result($result);
if ($barangays_result) pg_free_result($barangays_result);
pg_close($connection); 
?>
