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
  
  // Handle capacity updates
  elseif (isset($_POST['set_capacity'])) {
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
  
  // Handle email change OTP request
  elseif (isset($_POST['email_otp_request'])) {
    $current_password = $_POST['current_password'];
    $new_email = trim($_POST['new_email']);
    
    // Get admin info
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id && isset($_SESSION['admin_username'])) {
      $adminQuery = pg_query_params($connection, "SELECT admin_id, password, email FROM admins WHERE username = $1", [$_SESSION['admin_username']]);
      $adminData = pg_fetch_assoc($adminQuery);
      $admin_id = $adminData['admin_id'];
    } else {
      $adminQuery = pg_query_params($connection, "SELECT password, email FROM admins WHERE admin_id = $1", [$admin_id]);
      $adminData = pg_fetch_assoc($adminQuery);
    }
    
    $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
    
    if (!$adminData) {
      $response['message'] = 'Session error. Please login again.';
    } elseif (!password_verify($current_password, $adminData['password'])) {
      $response['message'] = 'Current password is incorrect.';
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
      $response['message'] = 'Please enter a valid email address.';
    } elseif ($new_email === $adminData['email']) {
      $response['message'] = 'New email must be different from current email.';
    } else {
      // Store temp data and send OTP
      $_SESSION['temp_new_email'] = $new_email;
      $_SESSION['temp_admin_id'] = $admin_id;
      
      if ($otpService->sendOTP($new_email, 'email_change', $admin_id)) {
        $response = ['status' => 'success', 'message' => 'Verification code sent to your new email address.'];
      } else {
        $response['message'] = 'Failed to send verification code. Please try again.';
      }
    }
    
    if ($isAjaxRequest) {
      header('Content-Type: application/json');
      echo json_encode($response);
      exit();
    }
  }
  
  // Handle email change OTP verification
  elseif (isset($_POST['email_otp_verify'])) {
    $otp = trim($_POST['otp']);
    $admin_id = $_SESSION['temp_admin_id'] ?? null;
    $new_email = $_SESSION['temp_new_email'] ?? null;
    
    $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
    
    if (!$admin_id || !$new_email) {
      $response['message'] = 'Session expired. Please start the process again.';
    } elseif (empty($otp) || !preg_match('/^\d{6}$/', $otp)) {
      $response['message'] = 'Please enter a valid 6-digit verification code.';
    } elseif ($otpService->verifyOTP($admin_id, $otp, 'email_change')) {
      // Update email
      $result = pg_query_params($connection, "UPDATE admins SET email = $1 WHERE admin_id = $2", [$new_email, $admin_id]);
      
      if ($result) {
        // Clear temp data
        unset($_SESSION['temp_new_email'], $_SESSION['temp_admin_id']);
        
        // Add notification
        $notification_msg = "Admin email updated to " . $new_email;
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        
        $response = ['status' => 'success', 'message' => 'Email address updated successfully!', 'redirect' => true];
      } else {
        $response['message'] = 'Database error. Please try again.';
      }
    } else {
      $response['message'] = 'Invalid or expired verification code. Please try again.';
    }
    
    if ($isAjaxRequest) {
      header('Content-Type: application/json');
      echo json_encode($response);
      exit();
    }
  }
  
  // Handle password change OTP request
  elseif (isset($_POST['password_otp_request'])) {
    $current_password = $_POST['current_password'];
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    // Get admin info
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id && isset($_SESSION['admin_username'])) {
      $adminQuery = pg_query_params($connection, "SELECT admin_id, password, email FROM admins WHERE username = $1", [$_SESSION['admin_username']]);
      $adminData = pg_fetch_assoc($adminQuery);
      $admin_id = $adminData['admin_id'];
    } else {
      $adminQuery = pg_query_params($connection, "SELECT password, email FROM admins WHERE admin_id = $1", [$admin_id]);
      $adminData = pg_fetch_assoc($adminQuery);
    }
    
    $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
    
    if (!$adminData) {
      $response['message'] = 'Session error. Please login again.';
    } elseif (!password_verify($current_password, $adminData['password'])) {
      $response['message'] = 'Current password is incorrect.';
    } elseif (strlen($new_password) < 8) {
      $response['message'] = 'New password must be at least 8 characters long.';
    } elseif ($new_password !== $confirm_password) {
      $response['message'] = 'Password confirmation does not match.';
    } elseif (password_verify($new_password, $adminData['password'])) {
      $response['message'] = 'New password must be different from current password.';
    } else {
      // Store temp data and send OTP
      $_SESSION['temp_new_password'] = password_hash($new_password, PASSWORD_DEFAULT);
      $_SESSION['temp_admin_id'] = $admin_id;
      
      if ($otpService->sendOTP($adminData['email'], 'password_change', $admin_id)) {
        $response = ['status' => 'success', 'message' => 'Verification code sent to your email address.'];
      } else {
        $response['message'] = 'Failed to send verification code. Please try again.';
      }
    }
    
    if ($isAjaxRequest) {
      header('Content-Type: application/json');
      echo json_encode($response);
      exit();
    }
  }
  
  // Handle password change OTP verification
  elseif (isset($_POST['password_otp_verify'])) {
    $otp = trim($_POST['otp']);
    $admin_id = $_SESSION['temp_admin_id'] ?? null;
    $new_password_hash = $_SESSION['temp_new_password'] ?? null;
    
    $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
    
    if (!$admin_id || !$new_password_hash) {
      $response['message'] = 'Session expired. Please start the process again.';
    } elseif (empty($otp) || !preg_match('/^\d{6}$/', $otp)) {
      $response['message'] = 'Please enter a valid 6-digit verification code.';
    } elseif ($otpService->verifyOTP($admin_id, $otp, 'password_change')) {
      // Update password
      $result = pg_query_params($connection, "UPDATE admins SET password = $1 WHERE admin_id = $2", [$new_password_hash, $admin_id]);
      
      if ($result) {
        // Clear temp data
        unset($_SESSION['temp_new_password'], $_SESSION['temp_admin_id']);
        
        // Add notification
        $notification_msg = "Admin password updated";
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        
        $response = ['status' => 'success', 'message' => 'Password updated successfully!', 'redirect' => true];
      } else {
        $response['message'] = 'Database error. Please try again.';
      }
    } else {
      $response['message'] = 'Invalid or expired verification code. Please try again.';
    }
    
    if ($isAjaxRequest) {
      header('Content-Type: application/json');
      echo json_encode($response);
      exit();
    }
  }
}
?><?php $page_title='Settings'; $extra_css=[]; include '../../includes/admin/admin_head.php'; ?>
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
          
          <!-- Current Profile Information -->
          <?php if ($currentAdmin): ?>
          <div class="row mb-4">
            <div class="col-12">
              <div class="bg-light p-3 rounded">
                <h6 class="text-primary mb-3"><i class="bi bi-person me-2"></i>Current Profile Information</h6>
                <div class="row">
                <div class="col-md-4">
                    <strong>Name:</strong><br>
                    <span class="text-muted"><?= htmlspecialchars(trim(($currentAdmin['first_name'] ?? '') . ' ' . ($currentAdmin['middle_name'] ?? '') . ' ' . ($currentAdmin['last_name'] ?? '')) ?: 'Not set') ?></span>
                  </div>
                  <div class="col-md-4">
                    <strong>Username:</strong><br>
                    <span class="text-muted"><?= htmlspecialchars($currentAdmin['username']) ?></span>
                  </div>
                  <div class="col-md-4">
                    <strong>Email:</strong><br>
                    <span class="text-muted"><?= htmlspecialchars($currentAdmin['email'] ?? 'Not set') ?></span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Profile Actions -->
          <div class="row mt-4">
            <div class="col-12">
              <h6 class="text-primary mb-3"><i class="bi bi-gear me-2"></i>Profile Actions</h6>
              <div class="row">
                <div class="col-md-6">
                  <div class="d-flex justify-content-between align-items-center p-3 border rounded">
                    <div>
                      <h6 class="mb-1">Email Address</h6>
                      <small class="text-muted">Update your email address</small>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="showChangeEmailModal()">
                      <i class="bi bi-envelope me-1"></i> Change Email
                    </button>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="d-flex justify-content-between align-items-center p-3 border rounded">
                    <div>
                      <h6 class="mb-1">Password</h6>
                      <small class="text-muted">Update your password</small>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="showChangePasswordModal()">
                      <i class="bi bi-key me-1"></i> Change Password
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      
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

<!-- Email Change Modal -->
<div class="modal fade" id="changeEmailModal" tabindex="-1" aria-labelledby="changeEmailModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <!-- Step 1: Current Password & New Email -->
      <div id="emailStep1">
        <div class="modal-header">
          <h5 class="modal-title" id="changeEmailModalLabel">
            <i class="bi bi-envelope me-2"></i>Change Email Address
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="emailStep1Form" onsubmit="handleEmailStep1(event)">
          <div class="modal-body">
            <div id="emailStep1Error" class="alert alert-danger d-none"></div>
            
            <div class="mb-3">
              <label for="currentEmailPassword" class="form-label">Current Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" class="form-control" id="currentEmailPassword" name="current_password" required>
                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('currentEmailPassword', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <small class="text-muted">Enter your current password to verify your identity</small>
            </div>
            
            <div class="mb-3">
              <label for="newEmail" class="form-label">New Email Address <span class="text-danger">*</span></label>
              <input type="email" class="form-control" id="newEmail" name="new_email" required>
              <div class="invalid-feedback">Please enter a valid email address.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-send me-1"></i> Send Verification Code
            </button>
          </div>
        </form>
      </div>
      
      <!-- Step 2: OTP Verification -->
      <div id="emailStep2" class="d-none">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-shield-check me-2"></i>Verify Email Change
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="emailStep2Form" onsubmit="handleEmailStep2(event)">
          <div class="modal-body">
            <div id="emailStep2Error" class="alert alert-danger d-none"></div>
            
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              We've sent a 6-digit verification code to your new email address. Please check your inbox and enter the code below.
            </div>
            
            <div class="mb-3">
              <label for="emailOTP" class="form-label">Verification Code <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-center" id="emailOTP" name="otp" 
                     maxlength="6" pattern="[0-9]{6}" required placeholder="000000"
                     style="font-size: 18px; letter-spacing: 3px;">
              <small class="text-muted">Enter the 6-digit code sent to your email</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="backToEmailStep1()">Back</button>
            <button type="submit" class="btn btn-success">
              <i class="bi bi-check-circle me-1"></i> Update Email
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Password Change Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <!-- Step 1: Current Password & New Password -->
      <div id="passwordStep1">
        <div class="modal-header">
          <h5 class="modal-title" id="changePasswordModalLabel">
            <i class="bi bi-key me-2"></i>Change Password
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="passwordStep1Form" onsubmit="handlePasswordStep1(event)">
          <div class="modal-body">
            <div id="passwordStep1Error" class="alert alert-danger d-none"></div>
            
            <div class="mb-3">
              <label for="currentPassword" class="form-label">Current Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" class="form-control" id="currentPassword" name="current_password" required>
                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('currentPassword', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
            
            <div class="mb-3">
              <label for="newPassword" class="form-label">New Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" class="form-control" id="newPassword" name="new_password" minlength="8" required>
                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('newPassword', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <small class="text-muted">Minimum 8 characters</small>
            </div>
            
            <div class="mb-3">
              <label for="confirmPassword" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" class="form-control" id="confirmPassword" name="confirm_password" minlength="8" required>
                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('confirmPassword', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <div class="invalid-feedback">Passwords do not match.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-send me-1"></i> Send Verification Code
            </button>
          </div>
        </form>
      </div>
      
      <!-- Step 2: OTP Verification -->
      <div id="passwordStep2" class="d-none">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-shield-check me-2"></i>Verify Password Change
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="passwordStep2Form" onsubmit="handlePasswordStep2(event)">
          <div class="modal-body">
            <div id="passwordStep2Error" class="alert alert-danger d-none"></div>
            
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              We've sent a 6-digit verification code to your email address. Please check your inbox and enter the code below.
            </div>
            
            <div class="mb-3">
              <label for="passwordOTP" class="form-label">Verification Code <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-center" id="passwordOTP" name="otp" 
                     maxlength="6" pattern="[0-9]{6}" required placeholder="000000"
                     style="font-size: 18px; letter-spacing: 3px;">
              <small class="text-muted">Enter the 6-digit code sent to your email</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="backToPasswordStep1()">Back</button>
            <button type="submit" class="btn btn-success">
              <i class="bi bi-check-circle me-1"></i> Update Password
            </button>
          </div>
        </form>
      </div>
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

// Email and Password Change Functions
function showChangeEmailModal() {
  // Reset modal to step 1
  document.getElementById('emailStep1').classList.remove('d-none');
  document.getElementById('emailStep2').classList.add('d-none');
  
  // Clear forms and errors
  document.getElementById('emailStep1Form').reset();
  document.getElementById('emailStep2Form').reset();
  hideError('emailStep1Error');
  hideError('emailStep2Error');
  
  const modal = new bootstrap.Modal(document.getElementById('changeEmailModal'));
  modal.show();
}

function showChangePasswordModal() {
  // Reset modal to step 1
  document.getElementById('passwordStep1').classList.remove('d-none');
  document.getElementById('passwordStep2').classList.add('d-none');
  
  // Clear forms and errors
  document.getElementById('passwordStep1Form').reset();
  document.getElementById('passwordStep2Form').reset();
  hideError('passwordStep1Error');
  hideError('passwordStep2Error');
  
  const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
  modal.show();
}

function togglePasswordVisibility(inputId, button) {
  const input = document.getElementById(inputId);
  const icon = button.querySelector('i');
  
  if (input.type === 'password') {
    input.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    input.type = 'password';
    icon.className = 'bi bi-eye';
  }
}

function showError(errorId, message) {
  const errorDiv = document.getElementById(errorId);
  errorDiv.textContent = message;
  errorDiv.classList.remove('d-none');
}

function hideError(errorId) {
  const errorDiv = document.getElementById(errorId);
  errorDiv.classList.add('d-none');
}

function handleEmailStep1(event) {
  event.preventDefault();
  
  const formData = new FormData(event.target);
  formData.append('email_otp_request', '1');
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
  // Disable button and show loading
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Sending...';
  
  hideError('emailStep1Error');
  
  fetch(window.location.href, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.status === 'success') {
      // Move to step 2
      document.getElementById('emailStep1').classList.add('d-none');
      document.getElementById('emailStep2').classList.remove('d-none');
    } else {
      showError('emailStep1Error', data.message);
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showError('emailStep1Error', 'Network error. Please try again.');
  })
  .finally(() => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  });
}

function handleEmailStep2(event) {
  event.preventDefault();
  
  const formData = new FormData(event.target);
  formData.append('email_otp_verify', '1');
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
  // Disable button and show loading
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Verifying...';
  
  hideError('emailStep2Error');
  
  fetch(window.location.href, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.status === 'success') {
      if (data.redirect) {
        // Redirect with success parameter
        window.location.href = window.location.pathname + '?success=1&msg=email';
      } else {
        // Close modal and reload page to show success message
        const modal = bootstrap.Modal.getInstance(document.getElementById('changeEmailModal'));
        modal.hide();
        location.reload();
      }
    } else {
      showError('emailStep2Error', data.message);
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showError('emailStep2Error', 'Network error. Please try again.');
  })
  .finally(() => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  });
}

function handlePasswordStep1(event) {
  event.preventDefault();
  
  const newPassword = document.getElementById('newPassword').value;
  const confirmPassword = document.getElementById('confirmPassword').value;
  
  // Client-side validation
  if (newPassword !== confirmPassword) {
    showError('passwordStep1Error', 'Password confirmation does not match.');
    return;
  }
  
  const formData = new FormData(event.target);
  formData.append('password_otp_request', '1');
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
  // Disable button and show loading
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Sending...';
  
  hideError('passwordStep1Error');
  
  fetch(window.location.href, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.status === 'success') {
      // Move to step 2
      document.getElementById('passwordStep1').classList.add('d-none');
      document.getElementById('passwordStep2').classList.remove('d-none');
    } else {
      showError('passwordStep1Error', data.message);
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showError('passwordStep1Error', 'Network error. Please try again.');
  })
  .finally(() => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  });
}

function handlePasswordStep2(event) {
  event.preventDefault();
  
  const formData = new FormData(event.target);
  formData.append('password_otp_verify', '1');
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
  // Disable button and show loading
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Verifying...';
  
  hideError('passwordStep2Error');
  
  fetch(window.location.href, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.status === 'success') {
      if (data.redirect) {
        // Redirect with success parameter
        window.location.href = window.location.pathname + '?success=1&msg=password';
      } else {
        // Close modal and reload page to show success message
        const modal = bootstrap.Modal.getInstance(document.getElementById('changePasswordModal'));
        modal.hide();
        location.reload();
      }
    } else {
      showError('passwordStep2Error', data.message);
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showError('passwordStep2Error', 'Network error. Please try again.');
  })
  .finally(() => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  });
}

function backToEmailStep1() {
  document.getElementById('emailStep2').classList.add('d-none');
  document.getElementById('emailStep1').classList.remove('d-none');
  hideError('emailStep2Error');
}

function backToPasswordStep1() {
  document.getElementById('passwordStep2').classList.add('d-none');
  document.getElementById('passwordStep1').classList.remove('d-none');
  hideError('passwordStep2Error');
}

// Real-time password confirmation validation
document.addEventListener('DOMContentLoaded', function() {
  const newPasswordInput = document.getElementById('newPassword');
  const confirmPasswordInput = document.getElementById('confirmPassword');
  
  function validatePasswordMatch() {
    if (newPasswordInput.value && confirmPasswordInput.value) {
      if (newPasswordInput.value !== confirmPasswordInput.value) {
        confirmPasswordInput.setCustomValidity('Passwords do not match');
        confirmPasswordInput.classList.add('is-invalid');
      } else {
        confirmPasswordInput.setCustomValidity('');
        confirmPasswordInput.classList.remove('is-invalid');
      }
    }
  }
  
  if (newPasswordInput && confirmPasswordInput) {
    newPasswordInput.addEventListener('input', validatePasswordMatch);
    confirmPasswordInput.addEventListener('input', validatePasswordMatch);
  }
  
  // Format OTP inputs to only accept numbers
  const otpInputs = ['emailOTP', 'passwordOTP'];
  otpInputs.forEach(inputId => {
    const input = document.getElementById(inputId);
    if (input) {
      input.addEventListener('input', function(e) {
        this.value = this.value.replace(/\D/g, '');
      });
    }
  });
});

// Capacity Management Functions
document.getElementById('modal_confirm_password').addEventListener('input', function() {
  const newPassword = document.getElementById('modal_new_password').value;
  const confirmPassword = this.value;
  
  if (newPassword !== confirmPassword) {
    this.setCustomValidity('Passwords do not match');
  } else {
    this.setCustomValidity('');
  }
});

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
