<?php
/** @phpstan-ignore-file */
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}

// Path to JSON settings
$jsonPath = __DIR__ . '/../../data/deadlines.json';
$deadlines = file_exists($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Handle profile updates
  if (isset($_POST['update_profile'])) {
    $new_email = trim($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    // Get current admin info
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id && isset($_SESSION['admin_username'])) {
      $adminQuery = pg_query_params($connection, "SELECT admin_id, password FROM admins WHERE username = $1", [$_SESSION['admin_username']]);
      $adminData = pg_fetch_assoc($adminQuery);
      $admin_id = $adminData['admin_id'];
    } else {
      $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE admin_id = $1", [$admin_id]);
      $adminData = pg_fetch_assoc($adminQuery);
    }
    
    if (!$adminData || !password_verify($current_password, $adminData['password'])) {
      $profile_error = "Current password is incorrect.";
    } elseif ($new_password && $new_password !== $confirm_password) {
      $profile_error = "New passwords do not match.";
    } elseif ($new_password && strlen($new_password) < 6) {
      $profile_error = "New password must be at least 6 characters.";
    } else {
      $updates = [];
      $params = [];
      $paramCount = 0;
      
      if ($new_email && $new_email !== '') {
        $updates[] = "email = $" . (++$paramCount);
        $params[] = $new_email;
      }
      
      if ($new_password && $new_password !== '') {
        $updates[] = "password = $" . (++$paramCount);
        $params[] = password_hash($new_password, PASSWORD_DEFAULT);
      }
      
      if (!empty($updates)) {
        $params[] = $admin_id;
        $updateQuery = "UPDATE admins SET " . implode(", ", $updates) . " WHERE admin_id = $" . (++$paramCount);
        $result = pg_query_params($connection, $updateQuery, $params);
        
        if ($result) {
          // Add admin notification
          $notification_msg = "Admin profile updated (email/password changed)";
          pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
          
          $profile_success = "Profile updated successfully!";
        } else {
          $profile_error = "Failed to update profile.";
        }
      }
    }
  }
  
  // Handle deadline updates
  elseif (isset($_POST['update_deadlines'])) {
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
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Deadlines</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
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
      <h4 class="fw-bold mb-4"><i class="bi bi-gear me-2 text-primary"></i>Settings</h4>
      
      <!-- Profile Management Section -->
      <div class="card mb-4">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0"><i class="bi bi-person-circle me-2"></i>Profile Management</h5>
        </div>
        <div class="card-body">
          <?php if (isset($profile_success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($profile_success) ?></div>
          <?php endif; ?>
          <?php if (isset($profile_error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($profile_error) ?></div>
          <?php endif; ?>
          
          <form method="POST">
            <div class="row">
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="email" class="form-label">Email Address</label>
                  <input type="email" class="form-control" id="email" name="email" placeholder="Enter new email (optional)">
                  <small class="text-muted">Leave blank to keep current email</small>
                </div>
              </div>
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
                  <input type="password" class="form-control" id="current_password" name="current_password" required>
                </div>
              </div>
            </div>
            <div class="row">
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="new_password" class="form-label">New Password</label>
                  <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Enter new password (optional)" minlength="6">
                  <small class="text-muted">Leave blank to keep current password</small>
                </div>
              </div>
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="confirm_password" class="form-label">Confirm New Password</label>
                  <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm new password">
                </div>
              </div>
            </div>
            <button type="submit" name="update_profile" class="btn btn-primary">
              <i class="bi bi-save me-1"></i> Update Profile
            </button>
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
<script>
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
