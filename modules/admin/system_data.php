<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Check if current admin is super_admin
$current_admin_role = 'super_admin'; // Default for backward compatibility
if (isset($_SESSION['admin_id'])) {
    $roleQuery = pg_query_params($connection, "SELECT role FROM admins WHERE admin_id = $1", [$_SESSION['admin_id']]);
    $roleData = pg_fetch_assoc($roleQuery);
    $current_admin_role = $roleData['role'] ?? 'super_admin';
}

// Only super_admin can access this page
if ($current_admin_role !== 'super_admin') {
    header("Location: homepage.php");
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add University
    if (isset($_POST['add_university'])) {
        $name = trim($_POST['university_name']);
        $code = trim(strtoupper($_POST['university_code']));
        
        if (!empty($name) && !empty($code)) {
            $insertQuery = "INSERT INTO universities (name, code) VALUES ($1, $2)";
            $result = pg_query_params($connection, $insertQuery, [$name, $code]);
            
            if ($result) {
                $notification_msg = "New university added: " . $name . " (" . $code . ")";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                $success = "University added successfully!";
            } else {
                $error = "Failed to add university. Code may already exist.";
            }
        }
    }
    
    // Add Barangay
    if (isset($_POST['add_barangay'])) {
        $name = trim($_POST['barangay_name']);
        $municipality_id = 1; // Default municipality
        
        if (!empty($name)) {
            $insertQuery = "INSERT INTO barangays (municipality_id, name) VALUES ($1, $2)";
            $result = pg_query_params($connection, $insertQuery, [$municipality_id, $name]);
            
            if ($result) {
                $notification_msg = "New barangay added: " . $name;
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                $success = "Barangay added successfully!";
            } else {
                $error = "Failed to add barangay.";
            }
        }
    }
    
    // Delete University
    if (isset($_POST['delete_university'])) {
        $university_id = intval($_POST['university_id']);
        
        // Check if university is being used by students
        $checkQuery = "SELECT COUNT(*) as count FROM students WHERE university_id = $1";
        $checkResult = pg_query_params($connection, $checkQuery, [$university_id]);
        $checkData = pg_fetch_assoc($checkResult);
        
        if ($checkData['count'] > 0) {
            $error = "Cannot delete university. It is currently assigned to " . $checkData['count'] . " student(s).";
        } else {
            $deleteQuery = "DELETE FROM universities WHERE university_id = $1";
            $result = pg_query_params($connection, $deleteQuery, [$university_id]);
            
            if ($result) {
                $notification_msg = "University deleted (ID: " . $university_id . ")";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                $success = "University deleted successfully!";
            }
        }
    }
    
    // Delete Barangay
    if (isset($_POST['delete_barangay'])) {
        $barangay_id = intval($_POST['barangay_id']);
        
        // Check if barangay is being used by students
        $checkQuery = "SELECT COUNT(*) as count FROM students WHERE barangay_id = $1";
        $checkResult = pg_query_params($connection, $checkQuery, [$barangay_id]);
        $checkData = pg_fetch_assoc($checkResult);
        
        if ($checkData['count'] > 0) {
            $error = "Cannot delete barangay. It is currently assigned to " . $checkData['count'] . " student(s).";
        } else {
            $deleteQuery = "DELETE FROM barangays WHERE barangay_id = $1";
            $result = pg_query_params($connection, $deleteQuery, [$barangay_id]);
            
            if ($result) {
                $notification_msg = "Barangay deleted (ID: " . $barangay_id . ")";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                $success = "Barangay deleted successfully!";
            }
        }
    }
}

// Fetch data
$universitiesQuery = "SELECT u.university_id, u.name, u.code, u.created_at, COUNT(s.student_id) as student_count FROM universities u LEFT JOIN students s ON u.university_id = s.university_id GROUP BY u.university_id, u.name, u.code, u.created_at ORDER BY u.name";
$universitiesResult = pg_query($connection, $universitiesQuery);
$universities = pg_fetch_all($universitiesResult) ?: [];

$barangaysQuery = "SELECT b.barangay_id, b.name, COUNT(s.student_id) as student_count FROM barangays b LEFT JOIN students s ON b.barangay_id = s.barangay_id GROUP BY b.barangay_id, b.name ORDER BY b.name";
$barangaysResult = pg_query($connection, $barangaysQuery);
$barangays = pg_fetch_all($barangaysResult) ?: [];

$yearLevelsQuery = "SELECT * FROM year_levels ORDER BY sort_order";
$yearLevelsResult = pg_query($connection, $yearLevelsQuery);
$yearLevels = pg_fetch_all($yearLevelsResult) ?: [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Data Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
    <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
    <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
</head>
<body>
<div id="wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
    
    <section class="home-section" id="mainContent">
        <nav>
            <div class="sidebar-toggle px-4 py-3">
                <i class="bi bi-list" id="menu-toggle"></i>
            </div>
        </nav>
        
        <div class="container-fluid py-4 px-4">
            <h4 class="fw-bold mb-4"><i class="bi bi-database me-2 text-primary"></i>System Data Management</h4>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <!-- Universities Management -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-building me-2"></i>Universities Management</h5>
                </div>
                <div class="card-body">
                    <!-- Add University Form -->
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <form method="POST" class="d-flex gap-2">
                                <input type="text" class="form-control" name="university_name" placeholder="University Name" required>
                                <input type="text" class="form-control" name="university_code" placeholder="Code (e.g., UST)" maxlength="10" required>
                                <button type="submit" name="add_university" class="btn btn-primary">
                                    <i class="bi bi-plus"></i> Add
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Universities List -->
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>University Name</th>
                                    <th>Code</th>
                                    <th>Students</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($universities as $university): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($university['name']) ?></td>
                                        <td><span class="badge bg-info"><?= htmlspecialchars($university['code']) ?></span></td>
                                        <td><?= $university['student_count'] ?> students</td>
                                        <td><?= date('M d, Y', strtotime($university['created_at'])) ?></td>
                                        <td>
                                            <?php if ($university['student_count'] == 0): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="university_id" value="<?= $university['university_id'] ?>">
                                                    <button type="submit" name="delete_university" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this university?')">
                                                        <i class="bi bi-trash"></i> Delete
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">Cannot delete (has students)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Barangays Management -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Barangays Management</h5>
                </div>
                <div class="card-body">
                    <!-- Add Barangay Form -->
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <form method="POST" class="d-flex gap-2">
                                <input type="text" class="form-control" name="barangay_name" placeholder="Barangay Name" required>
                                <button type="submit" name="add_barangay" class="btn btn-success">
                                    <i class="bi bi-plus"></i> Add
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Barangays List -->
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Barangay Name</th>
                                    <th>Students</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($barangays as $barangay): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($barangay['name']) ?></td>
                                        <td><?= $barangay['student_count'] ?> students</td>
                                        <td>
                                            <?php if ($barangay['student_count'] == 0): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="barangay_id" value="<?= $barangay['barangay_id'] ?>">
                                                    <button type="submit" name="delete_barangay" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this barangay?')">
                                                        <i class="bi bi-trash"></i> Delete
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">Cannot delete (has students)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Year Levels (Read-only) -->
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-layers me-2"></i>Year Levels (System Defined)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Year Level</th>
                                    <th>Code</th>
                                    <th>Order</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($yearLevels as $yearLevel): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($yearLevel['name']) ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($yearLevel['code']) ?></span></td>
                                        <td><?= $yearLevel['sort_order'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <small class="text-muted">Year levels are system-defined and cannot be modified to maintain data integrity.</small>
                </div>
            </div>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>

<?php pg_close($connection); ?>
