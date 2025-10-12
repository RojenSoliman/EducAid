<?php
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
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

// Generate CSRF tokens for forms
$csrfTokenCreateAdmin = CSRFProtection::generateToken('create_admin');
$csrfTokenToggleStatus = CSRFProtection::generateToken('toggle_admin_status');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_admin'])) {
        // CSRF validation
        $token = $_POST['csrf_token'] ?? '';
        if (!CSRFProtection::validateToken('create_admin', $token)) {
            $error = "Security validation failed. Please refresh the page.";
        } else {
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
    }
    
    if (isset($_POST['toggle_status'])) {
        // CSRF validation
        $token = $_POST['csrf_token'] ?? '';
        if (!CSRFProtection::validateToken('toggle_admin_status', $token)) {
            echo json_encode(['success' => false, 'error' => 'Invalid security token']);
            exit;
        }
        
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

<?php $page_title='Admin Management'; include '../../includes/admin/admin_head.php'; ?>
</head>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>
    <section class="home-section" id="mainContent">
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
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createAdminModal">
                        <i class="bi bi-person-plus"></i> Create New Admin
                    </button>
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
                                                <button type="button" class="btn btn-sm <?= $admin['is_active'] === 't' ? 'btn-outline-danger' : 'btn-outline-success' ?>" onclick="showToggleStatusModal(<?= $admin['admin_id'] ?>, '<?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name'], ENT_QUOTES) ?>', '<?= $admin['is_active'] === 't' ? 'deactivate' : 'activate' ?>')">
                                                    <i class="bi <?= $admin['is_active'] === 't' ? 'bi-person-x' : 'bi-person-check' ?>"></i>
                                                    <?= $admin['is_active'] === 't' ? 'Deactivate' : 'Activate' ?>
                                                </button>
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

<!-- Create Admin Modal -->
<div class="modal fade" id="createAdminModal" tabindex="-1" aria-labelledby="createAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createAdminModalLabel"><i class="bi bi-person-plus me-2"></i>Create New Admin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="createAdminForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenCreateAdmin) ?>">
                <div class="modal-body">
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
                                <small class="text-muted">Minimum 6 characters</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="confirm_password" required minlength="6">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" id="role" name="role" required>
                                    <option value="sub_admin">Sub Admin (Limited Access)</option>
                                    <option value="super_admin">Super Admin (Full Access)</option>
                                </select>
                                <small class="text-muted">Choose carefully - this determines what features the admin can access</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_admin" class="btn btn-success">
                        <i class="bi bi-person-plus me-1"></i> Create Admin
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Status Modal -->
<div class="modal fade" id="toggleStatusModal" tabindex="-1" aria-labelledby="toggleStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="toggleStatusModalLabel"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="toggleStatusForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenToggleStatus) ?>">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <span id="statusMessage"></span>
                    </div>
                    <p id="confirmationText"></p>
                    <input type="hidden" id="toggleAdminId" name="admin_id">
                    <input type="hidden" id="toggleNewStatus" name="new_status">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="toggle_status" class="btn" id="confirmActionBtn">
                        <i id="confirmActionIcon"></i> <span id="confirmActionText"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>

<script>
// Function for toggle status modal
function showToggleStatusModal(adminId, adminName, action) {
    const isDeactivate = action === 'deactivate';
    
    document.getElementById('toggleAdminId').value = adminId;
    document.getElementById('toggleNewStatus').value = isDeactivate ? 'false' : 'true';
    
    const statusMessage = document.getElementById('statusMessage');
    const confirmationText = document.getElementById('confirmationText');
    const confirmBtn = document.getElementById('confirmActionBtn');
    const confirmIcon = document.getElementById('confirmActionIcon');
    const confirmText = document.getElementById('confirmActionText');
    
    if (isDeactivate) {
        statusMessage.textContent = 'This will prevent the admin from logging in and accessing the system.';
        confirmationText.innerHTML = `Are you sure you want to <strong>deactivate</strong> ${adminName}?`;
        confirmBtn.className = 'btn btn-danger';
        confirmIcon.className = 'bi bi-person-x';
        confirmText.textContent = 'Deactivate';
    } else {
        statusMessage.textContent = 'This will allow the admin to log in and access the system again.';
        confirmationText.innerHTML = `Are you sure you want to <strong>activate</strong> ${adminName}?`;
        confirmBtn.className = 'btn btn-success';
        confirmIcon.className = 'bi bi-person-check';
        confirmText.textContent = 'Activate';
    }
    
    new bootstrap.Modal(document.getElementById('toggleStatusModal')).show();
}

// Form validation for create admin
document.getElementById('createAdminForm').addEventListener('submit', function(e) {
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (password !== confirmPassword) {
        e.preventDefault();
        alert('Passwords do not match. Please try again.');
        return false;
    }
    
    if (password.length < 6) {
        e.preventDefault();
        alert('Password must be at least 6 characters long.');
        return false;
    }
    
    // Check required fields
    const requiredFields = ['first_name', 'last_name', 'email', 'username'];
    for (let field of requiredFields) {
        if (!document.getElementById(field).value.trim()) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return false;
        }
    }
});

// Clear form when modal is closed
document.getElementById('createAdminModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('createAdminForm').reset();
});

// Real-time password confirmation check
document.getElementById('confirm_password').addEventListener('input', function() {
    const password = document.getElementById('password').value;
    const confirmPassword = this.value;
    const submitBtn = document.querySelector('#createAdminForm button[type="submit"]');
    
    if (password && confirmPassword) {
        if (password === confirmPassword) {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
            submitBtn.disabled = false;
        } else {
            this.classList.remove('is-valid');
            this.classList.add('is-invalid');
            submitBtn.disabled = true;
        }
    } else {
        this.classList.remove('is-valid', 'is-invalid');
        submitBtn.disabled = false;
    }
});
</script>
</body>
</html>

<?php pg_close($connection); ?>
