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
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// Handle finalize distribution action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_distribution'])) {
    // Verify admin password
    $password = $_POST['admin_password'] ?? '';
    $location = $_POST['distribution_location'] ?? '';
    $notes = $_POST['distribution_notes'] ?? '';
    $academic_year = trim($_POST['academic_year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    
    if (empty($password)) {
        $_SESSION['error_message'] = 'Password is required to finalize distribution.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
        exit;
    }
    
    if (empty($location)) {
        $_SESSION['error_message'] = 'Distribution location is required.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
        exit;
    }
    
    if (empty($academic_year)) {
        $_SESSION['error_message'] = 'Academic year is required to finalize distribution.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
        exit;
    }
    
    if (empty($semester)) {
        $_SESSION['error_message'] = 'Semester is required to finalize distribution.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
        exit;
    }
    
    // Verify admin password
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id) {
        // Try to get admin_id from admin_username if not set
        $username = $_SESSION['admin_username'] ?? null;
        if ($username) {
            $admin_lookup = pg_query_params($connection, 
                "SELECT admin_id FROM admins WHERE username = $1", 
                [$username]
            );
            if ($admin_lookup && pg_num_rows($admin_lookup) > 0) {
                $admin_data_lookup = pg_fetch_assoc($admin_lookup);
                $admin_id = $admin_data_lookup['admin_id'];
                $_SESSION['admin_id'] = $admin_id; // Set for future use
            }
        }
        
        if (!$admin_id) {
            $_SESSION['error_message'] = 'Admin session invalid.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit;
        }
    }
    
    $password_check = pg_query_params($connection, 
        "SELECT password FROM admins WHERE admin_id = $1", 
        [$admin_id]
    );
    
    if (!$password_check || pg_num_rows($password_check) === 0) {
        $_SESSION['error_message'] = 'Admin not found.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
        exit;
    }
    
    $admin_data = pg_fetch_assoc($password_check);
    if (!password_verify($password, $admin_data['password'])) {
        $_SESSION['error_message'] = 'Incorrect password.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
        exit;
    }
    
    try {
        // Begin transaction
        pg_query($connection, "BEGIN");
        
        // Get current distribution data before clearing
        $students_query = "
            SELECT 
                s.student_id, s.payroll_no, s.first_name, s.last_name, s.email, s.mobile,
                b.name as barangay, u.name as university, yl.name as year_level,
                d.date_given, d.remarks as distribution_remarks
            FROM students s
            LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
            LEFT JOIN universities u ON s.university_id = u.university_id
            LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
            LEFT JOIN distributions d ON s.student_id = d.student_id
            WHERE s.status = 'given'
            ORDER BY s.payroll_no
        ";
        
        $schedules_query = "
            SELECT 
                schedule_id, student_id, payroll_no, batch_no, distribution_date,
                time_slot, location as schedule_location, status
            FROM schedules
            ORDER BY distribution_date, time_slot
        ";
        
        $students_result = pg_query($connection, $students_query);
        $schedules_result = pg_query($connection, $schedules_query);
        
        $students_data = [];
        $schedules_data = [];
        $total_students = 0;
        
        if ($students_result) {
            while ($row = pg_fetch_assoc($students_result)) {
                $students_data[] = $row;
                $total_students++;
            }
        }
        
        if ($schedules_result) {
            while ($row = pg_fetch_assoc($schedules_result)) {
                $schedules_data[] = $row;
            }
        }
        
        // Get current active slot info (for reference, but prioritize manual input)
        $slot_query = "SELECT slot_id, academic_year, semester FROM signup_slots WHERE is_active = true LIMIT 1";
        $slot_result = pg_query($connection, $slot_query);
        $slot_data = $slot_result ? pg_fetch_assoc($slot_result) : null;
        
        // Create distribution snapshot using manual input for academic period
        $snapshot_query = "
            INSERT INTO distribution_snapshots 
            (distribution_date, location, total_students_count, active_slot_id, academic_year, semester, 
             finalized_by, notes, schedules_data, students_data)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
        ";
        
        $snapshot_result = pg_query_params($connection, $snapshot_query, [
            date('Y-m-d'),
            $location,
            $total_students,
            $slot_data['slot_id'] ?? null,
            $academic_year,  // Use manual input
            $semester,       // Use manual input
            $admin_id,
            $notes,
            json_encode($schedules_data),
            json_encode($students_data)
        ]);
        
        if (!$snapshot_result) {
            throw new Exception('Failed to create distribution snapshot.');
        }
        
        // Update all students with 'given' status to 'applicant' and clear payroll numbers and QR codes
        $update_students = pg_query($connection, "UPDATE students SET status = 'applicant', payroll_no = NULL, qr_code = NULL WHERE status = 'given'");
        
        // Delete all distribution records for these students
        $delete_distributions = pg_query($connection, "DELETE FROM distributions");
        
        // Delete all QR codes
        $delete_qr_codes = pg_query($connection, "DELETE FROM qr_codes");
        
        // Delete all schedules
        $delete_schedules = pg_query($connection, "DELETE FROM schedules");
        
        if ($update_students && $delete_distributions && $delete_qr_codes && $delete_schedules) {
            // Reset schedule settings to unpublished state
            $settings_reset_path = __DIR__ . '/../../data/municipal_settings.json';
            $current_settings = file_exists($settings_reset_path) ? json_decode(file_get_contents($settings_reset_path), true) : [];
            
            // Clear schedule metadata and set published to false
            unset($current_settings['schedule_meta']);
            $current_settings['schedule_published'] = false;
            
            // Save the reset settings
            file_put_contents($settings_reset_path, json_encode($current_settings, JSON_PRETTY_PRINT));
            
            pg_query($connection, "COMMIT");
            $_SESSION['success_message'] = "Distribution finalized successfully! $total_students students reset to applicant status. Distribution snapshot created. Schedule system reset - students will not see new schedules until manually published.";
        } else {
            pg_query($connection, "ROLLBACK");
            $_SESSION['error_message'] = 'Failed to finalize distribution. Transaction rolled back.';
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

// Count total records for pagination
$count_query = "
    SELECT COUNT(*) as total
    FROM students s
    LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
    LEFT JOIN distributions d ON s.student_id = d.student_id
    WHERE $where_clause
";

if (!empty($params)) {
    $count_result = pg_query_params($connection, $count_query, $params);
} else {
    $count_result = pg_query($connection, $count_query);
}

$total_filtered_records = $count_result ? pg_fetch_assoc($count_result)['total'] : 0;
$total_pages = ceil($total_filtered_records / $records_per_page);

// Main query with pagination
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
    LIMIT $records_per_page OFFSET $offset
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

// Load municipal settings for location
$settingsPath = __DIR__ . '/../../data/municipal_settings.json';
$settings = file_exists($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : [];
$distribution_location = $settings['schedule_meta']['location'] ?? '';

// Fetch past distributions with pagination
$past_distributions_page = isset($_GET['dist_page']) ? max(1, intval($_GET['dist_page'])) : 1;
$past_distributions_per_page = 10;
$past_distributions_offset = ($past_distributions_page - 1) * $past_distributions_per_page;

$past_distributions_count_query = "SELECT COUNT(*) as total FROM distribution_snapshots";
$past_distributions_count_result = pg_query($connection, $past_distributions_count_query);
$total_past_distributions = $past_distributions_count_result ? pg_fetch_assoc($past_distributions_count_result)['total'] : 0;
$total_past_distributions_pages = ceil($total_past_distributions / $past_distributions_per_page);

$past_distributions_query = "
    SELECT 
        ds.snapshot_id,
        ds.distribution_date,
        ds.location,
        ds.total_students_count,
        ds.academic_year,
        ds.semester,
        ds.finalized_at,
        ds.notes,
        CONCAT(a.first_name, ' ', a.last_name) as finalized_by_name
    FROM distribution_snapshots ds
    LEFT JOIN admins a ON ds.finalized_by = a.admin_id
    ORDER BY ds.finalized_at DESC
    LIMIT $past_distributions_per_page OFFSET $past_distributions_offset
";
$past_distributions_result = pg_query($connection, $past_distributions_query);

// Get current active slot info for modal display
$slot_query = "SELECT slot_id, academic_year, semester FROM signup_slots WHERE is_active = true LIMIT 1";
$slot_result = pg_query($connection, $slot_query);
$slot_data = $slot_result ? pg_fetch_assoc($slot_result) : null;
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
                    
                    <!-- Pagination for Current Distributions -->
                    <?php if ($total_pages > 1): ?>
                    <div class="p-3 border-top">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted">
                                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $records_per_page, $total_filtered_records); ?> of <?php echo $total_filtered_records; ?> distributions
                            </div>
                            <nav aria-label="Distribution pagination">
                                <ul class="pagination pagination-sm mb-0">
                                    <!-- Previous Page -->
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                <i class="bi bi-chevron-left"></i>
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link"><i class="bi bi-chevron-left"></i></span>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <!-- Page Numbers -->
                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                                        </li>
                                        <?php if ($start_page > 2): ?>
                                            <li class="page-item disabled">
                                                <span class="page-link">...</span>
                                            </li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($end_page < $total_pages): ?>
                                        <?php if ($end_page < $total_pages - 1): ?>
                                            <li class="page-item disabled">
                                                <span class="page-link">...</span>
                                            </li>
                                        <?php endif; ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                                <?php echo $total_pages; ?>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <!-- Next Page -->
                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link"><i class="bi bi-chevron-right"></i></span>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Past Distributions Section -->
                <?php if ($total_past_distributions > 0): ?>
                <div class="table-card mt-4">
                    <div class="p-4 border-bottom">
                        <h5 class="mb-0"><i class="bi bi-archive text-primary me-2"></i>Past Distributions (<?php echo $total_past_distributions; ?>)</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Distribution Date</th>
                                    <th>Location</th>
                                    <th>Students Count</th>
                                    <th>Academic Period</th>
                                    <th>Finalized By</th>
                                    <th>Finalized Date</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($past_distributions_result && pg_num_rows($past_distributions_result) > 0): ?>
                                    <?php while ($dist = pg_fetch_assoc($past_distributions_result)): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo date('M d, Y', strtotime($dist['distribution_date'])); ?></strong>
                                            </td>
                                            <td>
                                                <i class="bi bi-geo-alt text-primary me-1"></i>
                                                <?php echo htmlspecialchars($dist['location']); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-people me-1"></i>
                                                    <?php echo $dist['total_students_count']; ?> students
                                                </span>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <strong>AY:</strong> <?php echo htmlspecialchars($dist['academic_year']); ?><br>
                                                    <strong>Sem:</strong> <?php echo htmlspecialchars($dist['semester']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo $dist['finalized_by_name'] ? htmlspecialchars($dist['finalized_by_name']) : '<span class="text-muted">System</span>'; ?>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <?php echo date('M d, Y g:i A', strtotime($dist['finalized_at'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($dist['notes'])): ?>
                                                    <span class="text-truncate d-inline-block" style="max-width: 200px;" 
                                                          title="<?php echo htmlspecialchars($dist['notes']); ?>">
                                                        <?php echo htmlspecialchars($dist['notes']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination for Past Distributions -->
                    <?php if ($total_past_distributions_pages > 1): ?>
                    <div class="p-3 border-top">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted">
                                Showing <?php echo $past_distributions_offset + 1; ?> to <?php echo min($past_distributions_offset + $past_distributions_per_page, $total_past_distributions); ?> of <?php echo $total_past_distributions; ?> past distributions
                            </div>
                            <nav aria-label="Past distributions pagination">
                                <ul class="pagination pagination-sm mb-0">
                                    <!-- Previous Page -->
                                    <?php if ($past_distributions_page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['dist_page' => $past_distributions_page - 1])); ?>">
                                                <i class="bi bi-chevron-left"></i>
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link"><i class="bi bi-chevron-left"></i></span>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <!-- Page Numbers -->
                                    <?php
                                    $dist_start_page = max(1, $past_distributions_page - 2);
                                    $dist_end_page = min($total_past_distributions_pages, $past_distributions_page + 2);
                                    
                                    if ($dist_start_page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['dist_page' => 1])); ?>">1</a>
                                        </li>
                                        <?php if ($dist_start_page > 2): ?>
                                            <li class="page-item disabled">
                                                <span class="page-link">...</span>
                                            </li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $dist_start_page; $i <= $dist_end_page; $i++): ?>
                                        <li class="page-item <?php echo $i == $past_distributions_page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['dist_page' => $i])); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($dist_end_page < $total_past_distributions_pages): ?>
                                        <?php if ($dist_end_page < $total_past_distributions_pages - 1): ?>
                                            <li class="page-item disabled">
                                                <span class="page-link">...</span>
                                            </li>
                                        <?php endif; ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['dist_page' => $total_past_distributions_pages])); ?>">
                                                <?php echo $total_past_distributions_pages; ?>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <!-- Next Page -->
                                    <?php if ($past_distributions_page < $total_past_distributions_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['dist_page' => $past_distributions_page + 1])); ?>">
                                                <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link"><i class="bi bi-chevron-right"></i></span>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Finalize Distribution Section -->
                <div class="table-card mt-4">
                    <div class="p-4">
                        <h5 class="mb-3"><i class="bi bi-check-circle text-success me-2"></i>Finalize Distribution</h5>
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Information:</strong> Finalizing the distribution will:
                            <ul class="mb-0 mt-2">
                                <li>Create a permanent snapshot of the current distribution</li>
                                <li>Record distribution date, location, and student details</li>
                                <li>Reset ALL students to applicant status for the next cycle</li>
                                <li>Clear all payroll numbers and QR codes</li>
                                <li>Remove all current schedules and distribution records</li>
                            </ul>
                            <strong class="text-warning">This action cannot be undone!</strong>
                        </div>
                        
                        <?php if ($total_distributions > 0): ?>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#finalizeModal">
                            <i class="bi bi-check-circle me-2"></i>Finalize Distribution (<?php echo $total_distributions; ?> students)
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-secondary" disabled>
                            <i class="bi bi-info-circle me-2"></i>No distributions to finalize
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Finalize Distribution Modal -->
                <div class="modal fade" id="finalizeModal" tabindex="-1" aria-labelledby="finalizeModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title" id="finalizeModalLabel">
                                    <i class="bi bi-check-circle me-2"></i>Finalize Distribution
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST" id="finalizeForm">
                                <div class="modal-body">
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        <strong>Final Confirmation Required</strong>
                                        <p class="mb-0 mt-2">You are about to finalize the distribution for <strong><?php echo $total_distributions; ?> students</strong>. This will create a permanent record and reset the system for the next distribution cycle.</p>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="distribution_location" class="form-label">
                                            <i class="bi bi-geo-alt me-1"></i>Distribution Location <span class="text-danger">*</span>
                                        </label>
                                        <?php if (!empty($distribution_location)): ?>
                                            <input type="text" class="form-control" id="distribution_location" name="distribution_location" 
                                                   value="<?php echo htmlspecialchars($distribution_location); ?>" readonly>
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Location set from Schedule Management. 
                                                <a href="manage_schedules.php" class="text-decoration-none">Edit in Manage Schedules</a>
                                            </div>
                                        <?php else: ?>
                                            <input type="text" class="form-control border-warning" id="distribution_location" name="distribution_location" 
                                                   placeholder="Location not set in schedule. Please enter manually." required>
                                            <div class="form-text text-warning">
                                                <i class="bi bi-exclamation-triangle me-1"></i>No location found in schedule settings. Please set location in 
                                                <a href="manage_schedules.php" class="text-warning text-decoration-none">Manage Schedules</a> or enter manually.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="academic_year" class="form-label">
                                                    <i class="bi bi-calendar-range me-1"></i>Academic Year <span class="text-danger">*</span>
                                                </label>
                                                <input type="text" class="form-control" id="academic_year" name="academic_year" 
                                                       value="<?php echo htmlspecialchars($slot_data['academic_year'] ?? ''); ?>" 
                                                       placeholder="e.g., 2024-2025" required>
                                                <div class="form-text">
                                                    <i class="bi bi-info-circle me-1"></i>Format: YYYY-YYYY (e.g., 2024-2025)
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="semester" class="form-label">
                                                    <i class="bi bi-calendar-check me-1"></i>Semester <span class="text-danger">*</span>
                                                </label>
                                                <select class="form-select" id="semester" name="semester" required>
                                                    <option value="">Select Semester</option>
                                                    <option value="1st Semester" <?php echo ($slot_data['semester'] ?? '') === '1st Semester' ? 'selected' : ''; ?>>1st Semester</option>
                                                    <option value="2nd Semester" <?php echo ($slot_data['semester'] ?? '') === '2nd Semester' ? 'selected' : ''; ?>>2nd Semester</option>
                                                </select>
                                                <div class="form-text">
                                                    <i class="bi bi-info-circle me-1"></i>Academic period for this distribution
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($slot_data['academic_year']) || !empty($slot_data['semester'])): ?>
                                    <div class="alert alert-info mb-3">
                                        <i class="bi bi-info-circle me-2"></i>
                                        <strong>Active Slot Found:</strong> Academic period pre-filled from active slot. You can modify these values if needed.
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-warning mb-3">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        <strong>No Active Slot:</strong> Please specify the academic year and semester for this distribution manually.
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="mb-3">
                                        <label for="distribution_notes" class="form-label">
                                            <i class="bi bi-sticky me-1"></i>Additional Notes (Optional)
                                        </label>
                                        <textarea class="form-control" id="distribution_notes" name="distribution_notes" rows="3" 
                                                  placeholder="Any additional information about this distribution..."></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="admin_password" class="form-label">
                                            <i class="bi bi-shield-lock me-1"></i>Your Password <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="admin_password" name="admin_password" 
                                                   placeholder="Enter your admin password to confirm" required>
                                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                                <i class="bi bi-eye" id="passwordIcon"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">
                                            <i class="bi bi-info-circle me-1"></i>Your password is required to authorize this critical action.
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                        <i class="bi bi-x-circle me-2"></i>Cancel
                                    </button>
                                    <button type="submit" name="finalize_distribution" class="btn btn-success">
                                        <i class="bi bi-check-circle me-2"></i>Finalize Distribution
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin/sidebar.js"></script>
    <script>
    // Password visibility toggle
    document.addEventListener('DOMContentLoaded', function() {
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('admin_password');
        const passwordIcon = document.getElementById('passwordIcon');
        
        if (togglePassword && passwordInput && passwordIcon) {
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                if (type === 'password') {
                    passwordIcon.className = 'bi bi-eye';
                } else {
                    passwordIcon.className = 'bi bi-eye-slash';
                }
            });
        }
        
        // Form validation
        const finalizeForm = document.getElementById('finalizeForm');
        if (finalizeForm) {
            finalizeForm.addEventListener('submit', function(e) {
                const location = document.getElementById('distribution_location').value.trim();
                const password = document.getElementById('admin_password').value.trim();
                const academicYear = document.getElementById('academic_year').value.trim();
                const semester = document.getElementById('semester').value.trim();
                
                if (!location) {
                    e.preventDefault();
                    alert('Please enter the distribution location.');
                    return false;
                }
                
                if (!academicYear) {
                    e.preventDefault();
                    alert('Please enter the academic year.');
                    return false;
                }
                
                if (!semester) {
                    e.preventDefault();
                    alert('Please select the semester.');
                    return false;
                }
                
                if (!password) {
                    e.preventDefault();
                    alert('Please enter your password to confirm.');
                    return false;
                }
                
                const confirmMessage = '⚠️ FINAL CONFIRMATION ⚠️\n\n' +
                    'You are about to FINALIZE the distribution!\n\n' +
                    'This will:\n' +
                    '• Create a permanent snapshot of current distribution\n' +
                    '• Record for Academic Year: ' + academicYear + '\n' +
                    '• Record for Semester: ' + semester + '\n' +
                    '• Reset ALL students to applicant status\n' +
                    '• Clear all payroll numbers and QR codes\n' +
                    '• Delete all schedules and distribution records\n\n' +
                    'THIS ACTION CANNOT BE UNDONE!\n\n' +
                    'Are you absolutely sure you want to proceed?';
                
                if (!confirm(confirmMessage)) {
                    e.preventDefault();
                    return false;
                }
            });
        }
    });
    </script>
</body>
</html>

<?php 
if ($result) pg_free_result($result);
if ($barangays_result) pg_free_result($barangays_result);
pg_close($connection); 
?>
