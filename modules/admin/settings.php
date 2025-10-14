<?php
/** @phpstan-ignore-file */
include __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../services/OTPService.php';

session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}

// Debug session info 
error_log("DEBUG: Session admin_id = " . ($_SESSION['admin_id'] ?? 'NOT SET'));
error_log("DEBUG: Session admin_username = " . ($_SESSION['admin_username'] ?? 'NOT SET'));

$municipality_id = 1; // Default municipality
$otpService = new OTPService($connection);

// Fetch municipality max capacity
$capacityResult = pg_query_params($connection, "SELECT max_capacity FROM municipalities WHERE municipality_id = $1", [$municipality_id]);
$maxCapacity = null;
if ($capacityResult && pg_num_rows($capacityResult) > 0) {
    $capacityRow = pg_fetch_assoc($capacityResult);
    $maxCapacity = $capacityRow['max_capacity'];
}

// Get current total students count for capacity management
$currentTotalStudentsQuery = pg_query_params($connection, "
    SELECT COUNT(*) as total FROM students 
    WHERE municipality_id = $1 AND status IN ('under_registration', 'applicant', 'active')
", [$municipality_id]);
$currentTotalStudents = 0;
if ($currentTotalStudentsQuery) {
    $currentTotalRow = pg_fetch_assoc($currentTotalStudentsQuery);
    $currentTotalStudents = intval($currentTotalRow['total']);
}

// Generate form token to prevent duplicate submissions
if (!isset($_SESSION['form_token'])) {
  $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

// Get current admin information for display
$currentAdmin = null;
$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id && isset($_SESSION['admin_username'])) {
  $adminQuery = pg_query_params($connection, "SELECT admin_id, username, email, first_name, middle_name, last_name FROM admins WHERE username = $1", [$_SESSION['admin_username']]);
  if ($adminQuery && pg_num_rows($adminQuery) > 0) {
    $currentAdmin = pg_fetch_assoc($adminQuery);
    $_SESSION['admin_id'] = $currentAdmin['admin_id']; // Cache for future use
  }
} else if ($admin_id) {
  $adminQuery = pg_query_params($connection, "SELECT admin_id, username, email, first_name, middle_name, last_name FROM admins WHERE admin_id = $1", [$admin_id]);
  if ($adminQuery && pg_num_rows($adminQuery) > 0) {
    $currentAdmin = pg_fetch_assoc($adminQuery);
  }
}

// Path to JSON settings
$jsonPath = __DIR__ . '/../../data/deadlines.json';
$deadlines = file_exists($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : [];

// Handle success parameter from redirect
if (isset($_GET['success']) && isset($_GET['msg'])) {
  $successMessages = [
    'email' => 'Email address updated successfully!',
    'password' => 'Password updated successfully!'
  ];
  
  if (isset($successMessages[$_GET['msg']])) {
    $_SESSION['success_message'] = $successMessages[$_GET['msg']];
  } else {
    $_SESSION['success_message'] = "Operation completed successfully!";
  }
  
  // Redirect again to remove the parameters from URL
  header("Location: settings.php");
  exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Check if this is an AJAX request
  $isAjaxRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
  
  // Prevent duplicate form submissions by checking for a submission token (only for non-AJAX requests)
  if (isset($_POST['form_token']) && !$isAjaxRequest) {
    if (isset($_SESSION['last_form_token']) && $_SESSION['last_form_token'] === $_POST['form_token']) {
      // Duplicate submission detected - redirect to prevent resubmission
      header("Location: settings.php");
      exit();
    }
    // Store this token to prevent future duplicates
    $_SESSION['last_form_token'] = $_POST['form_token'];
  }
  
  // Handle capacity updates
  if (isset($_POST['set_capacity'])) {
    $newCapacity = intval($_POST['max_capacity']);
    $admin_password = $_POST['current_password'];

    // Get admin password using admin_id from session
    if (isset($_SESSION['admin_id'])) {
        // New unified login system
        $admin_id = $_SESSION['admin_id'];
        $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE admin_id = $1", [$admin_id]);
    } elseif (isset($_SESSION['admin_username'])) {
        // Legacy login system fallback
        $admin_username = $_SESSION['admin_username'];
        $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE username = $1", [$admin_username]);
    } else {
        $capacity_error = 'Session error. Please log out and log in again.';
    }
    
    if (!isset($capacity_error)) {
        $adminRow = pg_fetch_assoc($adminQuery);
        if (!$adminRow || !password_verify($admin_password, $adminRow['password'])) {
            $capacity_error = 'Current password is incorrect.';
        } elseif ($newCapacity <= 0) {
            $capacity_error = 'Maximum capacity cannot be zero. Please enter a valid positive number.';
        } elseif ($newCapacity < $currentTotalStudents) {
            $capacity_error = 'Maximum capacity cannot be lower than current student count (' . $currentTotalStudents . '). Please enter a higher value.';
        } else {
            // Update municipality capacity
            $updateResult = pg_query_params($connection, "
                UPDATE municipalities SET max_capacity = $1 WHERE municipality_id = $2
            ", [$newCapacity, $municipality_id]);

            if ($updateResult) {
                // Add admin notification for capacity change
                $notification_msg = "Maximum capacity updated to " . $newCapacity . " students";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                $capacity_success = "Maximum capacity updated successfully to " . number_format($newCapacity) . " students!";
                
                // Refresh capacity data
                $maxCapacity = $newCapacity;
            } else {
                $capacity_error = 'Failed to update maximum capacity. Please try again.';
            }
        }
    }
  }
  
  // Handle deadline updates
  if (isset($_POST['update_deadlines'])) {
  $out = [];
  $keys = $_POST['key'] ?? [];
  $labels = $_POST['label'] ?? [];
  $dates = $_POST['deadline_date'] ?? [];
  $actives = $_POST['active'] ?? [];
  $originalLinks = array_column($deadlines, 'link', 'key');

  foreach ($keys as $i => $key) {
    $label = trim($labels[$i] ?? '');
    $date = trim($dates[$i] ?? '');
    if ($label === '' || $date === '') continue;
    $out[] = [
      'key' => $key,
      'label' => $label,
      'deadline_date' => $date,
      'link' => $originalLinks[$key] ?? '',
      'active' => in_array($key, $actives, true)
    ];
  }

  file_put_contents($jsonPath, json_encode($out, JSON_PRETTY_PRINT));
  
  // Add admin notification for deadline changes
  $notification_msg = "System deadlines and settings updated";
  pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
  
  header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1');
  exit;
  }
}

?>
<?php $page_title='Settings'; $extra_css=[]; include '../../includes/admin/admin_head.php'; ?>
<style>
    td button.btn-outline-danger {
      padding: 4px 8px;
    }
    .table-hover tbody tr:hover {
      background-color: inherit;
    }
  </style>
</head>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
  <?php include '../../includes/admin/admin_sidebar.php'; ?>
  <?php include '../../includes/admin/admin_header.php'; ?>
  <section class="home-section" id="mainContent">
  <div class="container-fluid py-4 px-4">
      <h4 class="fw-bold mb-4"><i class="bi bi-gear me-2 text-primary"></i>Settings</h4>
      
      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
          <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success_message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>
      
      <!-- Capacity Management Section -->
      <div class="card mb-4">
        <div class="card-header bg-warning text-dark">
          <h5 class="mb-0"><i class="bi bi-gear-fill me-2"></i>Program Capacity Management</h5>
        </div>
        <div class="card-body">
          <?php if (isset($capacity_success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($capacity_success) ?></div>
          <?php endif; ?>
          <?php if (isset($capacity_error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($capacity_error) ?></div>
          <?php endif; ?>
          
          <div class="row mb-3">
            <div class="col-md-6">
              <div class="card border-info">
                <div class="card-body text-center">
                  <h6 class="card-title text-info">Current Students</h6>
                  <h3 class="text-primary"><?= number_format($currentTotalStudents) ?></h3>
                  <small class="text-muted">Total enrolled students</small>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="card border-<?= $maxCapacity !== null ? 'success' : 'warning' ?>">
                <div class="card-body text-center">
                  <h6 class="card-title text-<?= $maxCapacity !== null ? 'success' : 'warning' ?>">Maximum Capacity</h6>
                  <h3 class="text-<?= $maxCapacity !== null ? 'success' : 'warning' ?>">
                    <?= $maxCapacity !== null ? number_format($maxCapacity) : 'Not Set' ?>
                  </h3>
                  <small class="text-muted">Program limit</small>
                </div>
              </div>
            </div>
          </div>
          
          <?php if ($maxCapacity !== null): ?>
          <div class="row mb-3">
            <div class="col-12">
              <div class="progress" style="height: 20px;">
                <?php 
                $percentage = ($currentTotalStudents / max(1, $maxCapacity)) * 100;
                $barClass = 'bg-success';
                if ($percentage >= 90) $barClass = 'bg-danger';
                elseif ($percentage >= 75) $barClass = 'bg-warning';
                ?>
                <div class="progress-bar <?= $barClass ?>" style="width: <?= min(100, $percentage) ?>%">
                  <?= round($percentage, 1) ?>% (<?= $currentTotalStudents ?>/<?= number_format($maxCapacity) ?>)
                </div>
              </div>
              <?php if ($percentage >= 100): ?>
                <small class="text-danger mt-1 d-block">⚠️ Program has reached maximum capacity</small>
              <?php elseif ($percentage >= 90): ?>
                <small class="text-warning mt-1 d-block">⚠️ Program is near capacity (<?= number_format($maxCapacity - $currentTotalStudents) ?> slots remaining)</small>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
          
          <form method="POST">
            <div class="row">
              <div class="col-md-8">
                <div class="mb-3">
                  <label for="max_capacity" class="form-label">Maximum Capacity <span class="text-danger">*</span></label>
                  <input type="number" class="form-control" id="max_capacity" name="max_capacity" 
                         value="<?= htmlspecialchars($maxCapacity ?? '') ?>" 
                         min="<?= $currentTotalStudents ?>" required>
                  <small class="text-muted">Minimum allowed: <?= number_format($currentTotalStudents) ?> (current students)</small>
                </div>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <button type="button" class="btn btn-warning w-100 mb-3" onclick="showCapacityModal()">
                  <i class="bi bi-gear me-1"></i> Update Capacity
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>
      
      <!-- Deadlines Management Section -->
      <div class="card">
        <div class="card-header bg-info text-white">
          <h5 class="mb-0"><i class="bi bi-calendar2-week me-2"></i>Deadlines Management</h5>
        </div>
        <div class="card-body">
          <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success">Deadlines updated successfully!</div>
          <?php endif; ?>
          
          <form method="POST">
            <div class="table-responsive">
              <table class="table table-bordered table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Label</th>
                    <th>Deadline Date</th>
                    <th class="text-center">Active</th>
                    <th class="text-center">Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (empty($deadlines)): ?>
                  <tr><td colspan="4" class="text-muted text-center">No deadlines configured yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($deadlines as $d): ?>
                    <tr class="<?= !empty($d['active']) ? 'table-success' : '' ?>">
                      <td>
                        <input type="hidden" name="key[]" value="<?= htmlspecialchars($d['key']) ?>">
                        <input type="text" name="label[]" class="form-control form-control-sm" value="<?= htmlspecialchars($d['label']) ?>" required>
                      </td>
                      <td>
                        <input type="date" name="deadline_date[]" class="form-control form-control-sm" value="<?= htmlspecialchars($d['deadline_date']) ?>" required>
                      </td>
                      <td class="text-center">
                        <input type="checkbox" name="active[]" value="<?= htmlspecialchars($d['key']) ?>" <?= !empty($d['active']) ? 'checked' : '' ?>></td>
                      <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">
                          <i class="bi bi-trash"></i>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary" onclick="addDeadlineRow()">
              <i class="bi bi-plus-circle me-1"></i> Add Deadline
            </button>
            <button type="submit" name="update_deadlines" class="btn btn-info">
              <i class="bi bi-save me-1"></i> Save Deadlines
            </button>
          </div>
        </form>
      </div>
    </div>
    </div>
  </section>
</div>

<!-- Capacity Update Modal -->
<div class="modal fade" id="capacityModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Update Maximum Capacity</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="capacityForm">
        <div class="modal-body">
          <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Current Status:</strong> <?= number_format($currentTotalStudents) ?> students enrolled
            <?php if ($maxCapacity !== null): ?>
              | Current limit: <?= number_format($maxCapacity) ?>
            <?php endif; ?>
          </div>
          <div class="mb-3">
            <label for="modal_capacity" class="form-label">New Maximum Capacity <span class="text-danger">*</span></label>
            <input type="number" class="form-control" id="modal_capacity" name="max_capacity" 
                   min="<?= $currentTotalStudents ?>" required>
            <small class="text-muted">Must be at least <?= number_format($currentTotalStudents) ?> (current students)</small>
          </div>
          <div class="mb-3">
            <label for="modal_capacity_password" class="form-label">Current Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" id="modal_capacity_password" name="current_password" required>
            <small class="text-muted">Required for security verification</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="set_capacity" class="btn btn-warning">
            <i class="bi bi-gear me-1"></i> Update Capacity
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Capacity Management Functions
function showCapacityModal() {
  const capacityInput = document.getElementById('max_capacity');
  const modalCapacityInput = document.getElementById('modal_capacity');
  
  // Copy current value to modal
  modalCapacityInput.value = capacityInput.value;
  
  const modal = new bootstrap.Modal(document.getElementById('capacityModal'));
  modal.show();
}

// Capacity input validation
document.getElementById('modal_capacity').addEventListener('input', function() {
  const currentStudents = <?= $currentTotalStudents ?>;
  const value = parseInt(this.value);
  
  if (value < currentStudents) {
    this.setCustomValidity(`Capacity cannot be lower than current student count (${currentStudents})`);
  } else {
    this.setCustomValidity('');
  }
});

// Deadline management functions
function removeRow(btn) {
  const row = btn.closest('tr');
  row.remove();
}

function addDeadlineRow() {
  const tbody = document.querySelector('table tbody');
  const row = document.createElement('tr');
  const key = `key_${Date.now()}`;
  row.innerHTML = `
    <td>
      <input type="hidden" name="key[]" value="${key}">
      <input type="text" name="label[]" class="form-control form-control-sm" placeholder="Enter label" required>
    </td>
    <td>
      <input type="date" name="deadline_date[]" class="form-control form-control-sm" required>
    </td>
    <td class="text-center">
      <input type="checkbox" name="active[]" value="${key}">
    </td>
    <td class="text-center">
      <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">
        <i class="bi bi-trash"></i>
      </button>
    </td>
  `;
  tbody.appendChild(row);
}

</script>
<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>
