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
    if (isset($_POST['create_admin'])) {
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $role = $_POST['role'];
        
        if (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $municipality_id = 1; // Default municipality
            
            $insertQuery = "INSERT INTO admins (municipality_id, first_name, middle_name, last_name, email, username, password, role) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)";
            $result = pg_query_params($connection, $insertQuery, [$municipality_id, $first_name, $middle_name, $last_name, $email, $username, $hashed_password, $role]);
            
            if ($result) {
                // Add admin notification
                $notification_msg = "New " . ($role === 'super_admin' ? 'Super Admin' : 'Sub Admin') . " created: " . $first_name . " " . $last_name . " (" . $username . ")";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                
                $success = "Admin created successfully!";
            } else {
                $error = "Failed to create admin. Username or email may already exist.";
            }
        }
    }
    
    if (isset($_POST['toggle_status'])) {
        $admin_id = intval($_POST['admin_id']);
        $new_status = $_POST['new_status'] === 'true';
        
        $updateQuery = "UPDATE admins SET is_active = $1 WHERE admin_id = $2";
        $result = pg_query_params($connection, $updateQuery, [$new_status ? 'true' : 'false', $admin_id]);
        
        if ($result) {
            // Add admin notification
            $statusText = $new_status ? 'activated' : 'deactivated';
            $notification_msg = "Admin account " . $statusText . " (ID: " . $admin_id . ")";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            
            $success = "Admin status updated successfully!";
        }
    }
}

// Fetch all admins
$adminsQuery = "SELECT admin_id, first_name, middle_name, last_name, email, username, role, is_active, created_at, last_login FROM admins ORDER BY created_at DESC";
$adminsResult = pg_query($connection, $adminsQuery);
$admins = pg_fetch_all($adminsResult) ?: [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Management</title>
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
            <h4 class="fw-bold mb-4"><i class="bi bi-people-fill me-2 text-primary"></i>Admin Management</h4>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <!-- Create New Admin -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>Create New Admin</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="middle_name" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="email" name="email" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="username" name="username" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" id="password" name="password" required minlength="6">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                    <select class="form-select" id="role" name="role" required>
                                        <option value="sub_admin">Sub Admin (Limited Access)</option>
                                        <option value="super_admin">Super Admin (Full Access)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="create_admin" class="btn btn-success">
                            <i class="bi bi-person-plus me-1"></i> Create Admin
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Admin List -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Existing Admins</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admins as $admin): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['middle_name'] . ' ' . $admin['last_name']) ?></td>
                                        <td><?= htmlspecialchars($admin['username']) ?></td>
                                        <td><?= htmlspecialchars($admin['email']) ?></td>
                                        <td>
                                            <span class="badge <?= $admin['role'] === 'super_admin' ? 'bg-danger' : 'bg-info' ?>">
                                                <?= $admin['role'] === 'super_admin' ? 'Super Admin' : 'Sub Admin' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $admin['is_active'] === 't' ? 'bg-success' : 'bg-secondary' ?>">
                                                <?= $admin['is_active'] === 't' ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($admin['created_at'])) ?></td>
                                        <td><?= $admin['last_login'] ? date('M d, Y H:i', strtotime($admin['last_login'])) : 'Never' ?></td>
                                        <td>
                                            <?php if ($admin['admin_id'] != ($_SESSION['admin_id'] ?? 0)): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="admin_id" value="<?= $admin['admin_id'] ?>">
                                                    <input type="hidden" name="new_status" value="<?= $admin['is_active'] === 't' ? 'false' : 'true' ?>">
                                                    <button type="submit" name="toggle_status" class="btn btn-sm <?= $admin['is_active'] === 't' ? 'btn-outline-danger' : 'btn-outline-success' ?>" onclick="return confirm('Are you sure you want to <?= $admin['is_active'] === 't' ? 'deactivate' : 'activate' ?> this admin?')">
                                                        <i class="bi <?= $admin['is_active'] === 't' ? 'bi-person-x' : 'bi-person-check' ?>"></i>
                                                        <?= $admin['is_active'] === 't' ? 'Deactivate' : 'Activate' ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">Current User</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Role Permissions Info -->
            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Role Permissions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-success"><i class="bi bi-shield-check me-1"></i>Super Admin</h6>
                            <ul class="list-unstyled">
                                <li><i class="bi bi-check-circle text-success me-1"></i> Full system access</li>
                                <li><i class="bi bi-check-circle text-success me-1"></i> Manage all students</li>
                                <li><i class="bi bi-check-circle text-success me-1"></i> Slot management</li>
                                <li><i class="bi bi-check-circle text-success me-1"></i> Schedule publishing</li>
                                <li><i class="bi bi-check-circle text-success me-1"></i> Admin management</li>
                                <li><i class="bi bi-check-circle text-success me-1"></i> System settings</li>
                                <li><i class="bi bi-check-circle text-success me-1"></i> Data management</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-info"><i class="bi bi-shield me-1"></i>Sub Admin</h6>
                            <ul class="list-unstyled">
                                <li><i class="bi bi-check-circle text-success me-1"></i> View dashboard</li>
                                <li><i class="bi bi-check-circle text-success me-1"></i> Review registrations</li>
                                <li><i class="bi bi-check-circle text-success me-1"></i> Manage applicants</li>
                                <li><i class="bi bi-check-circle text-success me-1"></i> View notifications</li>
                                <li><i class="bi bi-x-circle text-danger me-1"></i> Slot management</li>
                                <li><i class="bi bi-x-circle text-danger me-1"></i> Schedule publishing</li>
                                <li><i class="bi bi-x-circle text-danger me-1"></i> Admin management</li>
                                <li><i class="bi bi-x-circle text-danger me-1"></i> System settings</li>
                            </ul>
                        </div>
                    </div>
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
