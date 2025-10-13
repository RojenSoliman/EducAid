<?php
/** @phpstan-ignore-file */
include __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../services/OTPService.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}

$otpService = new OTPService($connection);

// Get current admin information
$currentAdmin = null;
$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id && isset($_SESSION['admin_username'])) {
  $adminQuery = pg_query_params($connection, "SELECT admin_id, username, email, first_name, middle_name, last_name FROM admins WHERE username = $1", [$_SESSION['admin_username']]);
  if ($adminQuery && pg_num_rows($adminQuery) > 0) {
    $currentAdmin = pg_fetch_assoc($adminQuery);
    $_SESSION['admin_id'] = $currentAdmin['admin_id'];
  }
} else if ($admin_id) {
  $adminQuery = pg_query_params($connection, "SELECT admin_id, username, email, first_name, middle_name, last_name FROM admins WHERE admin_id = $1", [$admin_id]);
  if ($adminQuery && pg_num_rows($adminQuery) > 0) {
    $currentAdmin = pg_fetch_assoc($adminQuery);
  }
}

// Handle success parameter from redirect
if (isset($_GET['success']) && isset($_GET['msg'])) {
  $successMessages = [
    'email' => 'Email address updated successfully!',
    'password' => 'Password updated successfully!'
  ];
  
  if (isset($successMessages[$_GET['msg']])) {
    $_SESSION['success_message'] = $successMessages[$_GET['msg']];
  } else {
    $_SESSION['success_message'] = "Profile updated successfully!";
  }
  
  header("Location: admin_profile.php");
  exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $isAjaxRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
  
  // Handle email change OTP request
  if (isset($_POST['email_otp_request'])) {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!CSRFProtection::validateToken('email_otp_request', $token)) {
      $response = [
        'status' => 'error',
        'message' => 'Security verification failed. Please refresh and try again.',
        'next_token' => CSRFProtection::generateToken('email_otp_request')
      ];
      
      if ($isAjaxRequest) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
      }
    }
    
    $current_password = $_POST['current_password'];
    $new_email = trim($_POST['new_email']);
    
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
      $response['next_token'] = CSRFProtection::generateToken('email_otp_request');
    } elseif (!password_verify($current_password, $adminData['password'])) {
      $response['message'] = 'Current password is incorrect.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_request');
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
      $response['message'] = 'Please enter a valid email address.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_request');
    } elseif ($new_email === $adminData['email']) {
      $response['message'] = 'New email must be different from current email.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_request');
    } else {
      $_SESSION['temp_new_email'] = $new_email;
      $_SESSION['temp_admin_id'] = $admin_id;
      
      if ($otpService->sendOTP($new_email, 'email_change', $admin_id)) {
        $response = [
          'status' => 'success', 
          'message' => 'Verification code sent to your new email address.',
          'next_token' => CSRFProtection::generateToken('email_otp_verify')
        ];
      } else {
        $response['message'] = 'Failed to send verification code. Please try again.';
        $response['next_token'] = CSRFProtection::generateToken('email_otp_request');
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
    $token = $_POST['csrf_token'] ?? '';
    
    if (!CSRFProtection::validateToken('email_otp_verify', $token)) {
      $response = [
        'status' => 'error',
        'message' => 'Security verification failed. Please refresh and try again.',
        'next_token' => CSRFProtection::generateToken('email_otp_verify')
      ];
      
      if ($isAjaxRequest) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
      }
    }
    
    $otp = trim($_POST['otp']);
    $admin_id = $_SESSION['temp_admin_id'] ?? null;
    $new_email = $_SESSION['temp_new_email'] ?? null;
    
    $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
    
    if (!$admin_id || !$new_email) {
      $response['message'] = 'Session expired. Please start the process again.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_verify');
    } elseif (empty($otp) || !preg_match('/^\d{6}$/', $otp)) {
      $response['message'] = 'Please enter a valid 6-digit verification code.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_verify');
    } elseif ($otpService->verifyOTP($admin_id, $otp, 'email_change')) {
      $result = pg_query_params($connection, "UPDATE admins SET email = $1 WHERE admin_id = $2", [$new_email, $admin_id]);
      
      if ($result) {
        unset($_SESSION['temp_new_email'], $_SESSION['temp_admin_id']);
        
        $notification_msg = "Admin email updated to " . $new_email;
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        
        if ($isAjaxRequest) {
          $response = ['status' => 'success', 'message' => 'Email updated successfully!', 'redirect' => 'admin_profile.php?success=1&msg=email'];
        } else {
          $_SESSION['success_message'] = 'Email address updated successfully!';
          header('Location: admin_profile.php?success=1&msg=email');
          exit();
        }
      } else {
        $response['message'] = 'Failed to update email. Please try again.';
        $response['next_token'] = CSRFProtection::generateToken('email_otp_verify');
      }
    } else {
      $response['message'] = 'Invalid or expired verification code.';
      $response['next_token'] = CSRFProtection::generateToken('email_otp_verify');
    }
    
    if ($isAjaxRequest) {
      header('Content-Type: application/json');
      echo json_encode($response);
      exit();
    }
  }
  
  // Handle password change OTP request
  elseif (isset($_POST['password_otp_request'])) {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!CSRFProtection::validateToken('password_otp_request', $token)) {
      $response = [
        'status' => 'error',
        'message' => 'Security verification failed. Please refresh and try again.',
        'next_token' => CSRFProtection::generateToken('password_otp_request')
      ];
      
      if ($isAjaxRequest) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
      }
    }
    
    $current_password = $_POST['current_password'];
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
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
      $response['next_token'] = CSRFProtection::generateToken('password_otp_request');
    } elseif (!password_verify($current_password, $adminData['password'])) {
      $response['message'] = 'Current password is incorrect.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_request');
    } elseif ($new_password !== $confirm_password) {
      $response['message'] = 'New passwords do not match.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_request');
    } elseif (strlen($new_password) < 8) {
      $response['message'] = 'New password must be at least 8 characters.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_request');
    } else {
      $_SESSION['temp_new_password'] = password_hash($new_password, PASSWORD_DEFAULT);
      $_SESSION['temp_admin_id'] = $admin_id;
      
      if ($otpService->sendOTP($adminData['email'], 'password_change', $admin_id)) {
        $response = [
          'status' => 'success', 
          'message' => 'Verification code sent to your email address.',
          'next_token' => CSRFProtection::generateToken('password_otp_verify')
        ];
      } else {
        $response['message'] = 'Failed to send verification code. Please try again.';
        $response['next_token'] = CSRFProtection::generateToken('password_otp_request');
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
    $token = $_POST['csrf_token'] ?? '';
    
    if (!CSRFProtection::validateToken('password_otp_verify', $token)) {
      $response = [
        'status' => 'error',
        'message' => 'Security verification failed. Please refresh and try again.',
        'next_token' => CSRFProtection::generateToken('password_otp_verify')
      ];
      
      if ($isAjaxRequest) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
      }
    }
    
    $otp = trim($_POST['otp']);
    $admin_id = $_SESSION['temp_admin_id'] ?? null;
    $new_password_hash = $_SESSION['temp_new_password'] ?? null;
    
    $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
    
    if (!$admin_id || !$new_password_hash) {
      $response['message'] = 'Session expired. Please start the process again.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_verify');
    } elseif (empty($otp) || !preg_match('/^\d{6}$/', $otp)) {
      $response['message'] = 'Please enter a valid 6-digit verification code.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_verify');
    } elseif ($otpService->verifyOTP($admin_id, $otp, 'password_change')) {
      $result = pg_query_params($connection, "UPDATE admins SET password = $1 WHERE admin_id = $2", [$new_password_hash, $admin_id]);
      
      if ($result) {
        unset($_SESSION['temp_new_password'], $_SESSION['temp_admin_id']);
        
        $notification_msg = "Admin password updated";
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        
        if ($isAjaxRequest) {
          $response = ['status' => 'success', 'message' => 'Password updated successfully!', 'redirect' => 'admin_profile.php?success=1&msg=password'];
        } else {
          $_SESSION['success_message'] = 'Password updated successfully!';
          header('Location: admin_profile.php?success=1&msg=password');
          exit();
        }
      } else {
        $response['message'] = 'Failed to update password. Please try again.';
        $response['next_token'] = CSRFProtection::generateToken('password_otp_verify');
      }
    } else {
      $response['message'] = 'Invalid or expired verification code.';
      $response['next_token'] = CSRFProtection::generateToken('password_otp_verify');
    }
    
    if ($isAjaxRequest) {
      header('Content-Type: application/json');
      echo json_encode($response);
      exit();
    }
  }
}

// Generate initial CSRF tokens for the page
$csrf_email_token = CSRFProtection::generateToken('email_otp_request');
$csrf_password_token = CSRFProtection::generateToken('password_otp_request');
?>

<?php $page_title = 'My Profile'; include '../../includes/admin/admin_head.php'; ?>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
  <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
  <?php include '../../includes/admin/admin_header.php'; ?>
  
  <section class="home-section" id="page-content-wrapper">
    <div class="container py-5">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-person-circle me-2"></i>My Profile</h1>
      </div>

      <!-- Success/Error Messages -->
      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
          <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success_message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>
      
      <!-- Profile Information Card -->
      <div class="card mb-4">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Profile Information</h5>
        </div>
        <div class="card-body">
          <?php if ($currentAdmin): ?>
          <div class="row">
            <!-- Profile Avatar Section -->
            <div class="col-md-3 text-center mb-4 mb-md-0">
              <div class="avatar-circle-large mx-auto mb-3" style="width: 120px; height: 120px; background: linear-gradient(135deg, #0d6efd, #0b5ed7); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 48px; color: white; font-weight: bold; box-shadow: 0 4px 8px rgba(0,0,0,0.15);">
                <?php 
                $fullName = trim(($currentAdmin['first_name'] ?? '') . ' ' . ($currentAdmin['last_name'] ?? ''));
                $initials = strtoupper(mb_substr($currentAdmin['first_name'] ?? 'A', 0, 1) . mb_substr($currentAdmin['last_name'] ?? 'D', 0, 1));
                echo htmlspecialchars($initials); 
                ?>
              </div>
              <h5><?= htmlspecialchars($fullName ?: 'Administrator') ?></h5>
              <p class="text-muted small mb-0">@<?= htmlspecialchars($currentAdmin['username']) ?></p>
            </div>
            
            <!-- Profile Details Section -->
            <div class="col-md-9">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="text-muted small mb-1">First Name</label>
                  <div class="p-2 bg-light rounded">
                    <strong><?= htmlspecialchars($currentAdmin['first_name'] ?? 'Not set') ?></strong>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="text-muted small mb-1">Middle Name</label>
                  <div class="p-2 bg-light rounded">
                    <strong><?= htmlspecialchars($currentAdmin['middle_name'] ?? 'Not set') ?></strong>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="text-muted small mb-1">Last Name</label>
                  <div class="p-2 bg-light rounded">
                    <strong><?= htmlspecialchars($currentAdmin['last_name'] ?? 'Not set') ?></strong>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="text-muted small mb-1">Username</label>
                  <div class="p-2 bg-light rounded">
                    <strong><?= htmlspecialchars($currentAdmin['username']) ?></strong>
                  </div>
                </div>
                <div class="col-12">
                  <label class="text-muted small mb-1">Email Address</label>
                  <div class="p-2 bg-light rounded d-flex justify-content-between align-items-center">
                    <strong><?= htmlspecialchars($currentAdmin['email'] ?? 'Not set') ?></strong>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="showChangeEmailModal()">
                      <i class="bi bi-pencil me-1"></i> Change
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <?php else: ?>
          <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>Unable to load profile information. Please refresh the page.
          </div>
          <?php endif; ?>
        </div>
      </div>
      
      <!-- Security Settings Card -->
      <div class="card">
        <div class="card-header bg-warning text-dark">
          <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Security Settings</h5>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="border rounded p-3 h-100">
                <div class="d-flex align-items-start">
                  <div class="flex-shrink-0">
                    <div class="rounded-circle bg-warning bg-opacity-25 p-3">
                      <i class="bi bi-key-fill text-warning" style="font-size: 24px;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <h6 class="mb-1">Password</h6>
                    <p class="text-muted small mb-3">Change your account password to keep your account secure</p>
                    <button type="button" class="btn btn-warning btn-sm" onclick="showChangePasswordModal()">
                      <i class="bi bi-key me-1"></i> Change Password
                    </button>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="col-md-6">
              <div class="border rounded p-3 h-100">
                <div class="d-flex align-items-start">
                  <div class="flex-shrink-0">
                    <div class="rounded-circle bg-info bg-opacity-25 p-3">
                      <i class="bi bi-shield-fill-check text-info" style="font-size: 24px;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <h6 class="mb-1">Two-Factor Authentication</h6>
                    <p class="text-muted small mb-3">All profile changes require OTP verification via email</p>
                    <span class="badge bg-success">
                      <i class="bi bi-check-circle me-1"></i> Enabled
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
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
// CSRF Token Management
let csrfTokens = {
  email: '<?php echo $csrf_email_token; ?>',
  password: '<?php echo $csrf_password_token; ?>'
};

function updateCSRFToken(type, newToken) {
  if (newToken) {
    csrfTokens[type] = newToken;
  }
}

// Email and Password Change Functions
function showChangeEmailModal() {
  document.getElementById('emailStep1').classList.remove('d-none');
  document.getElementById('emailStep2').classList.add('d-none');
  
  document.getElementById('emailStep1Form').reset();
  document.getElementById('emailStep2Form').reset();
  hideError('emailStep1Error');
  hideError('emailStep2Error');
  
  const modal = new bootstrap.Modal(document.getElementById('changeEmailModal'));
  modal.show();
}

function showChangePasswordModal() {
  document.getElementById('passwordStep1').classList.remove('d-none');
  document.getElementById('passwordStep2').classList.add('d-none');
  
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
  formData.append('csrf_token', csrfTokens.email);
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
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
    // Update CSRF token
    if (data.next_token) {
      updateCSRFToken('email', data.next_token);
    }
    
    if (data.status === 'success') {
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
  formData.append('csrf_token', csrfTokens.email);
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
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
    // Update CSRF token
    if (data.next_token) {
      updateCSRFToken('email', data.next_token);
    }
    
    if (data.status === 'success') {
      if (data.redirect) {
        window.location.href = data.redirect;
      } else {
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
  
  if (newPassword !== confirmPassword) {
    showError('passwordStep1Error', 'Password confirmation does not match.');
    return;
  }
  
  const formData = new FormData(event.target);
  formData.append('password_otp_request', '1');
  formData.append('csrf_token', csrfTokens.password);
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
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
    // Update CSRF token
    if (data.next_token) {
      updateCSRFToken('password', data.next_token);
    }
    
    if (data.status === 'success') {
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
  formData.append('csrf_token', csrfTokens.password);
  
  const submitBtn = event.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  
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
    // Update CSRF token
    if (data.next_token) {
      updateCSRFToken('password', data.next_token);
    }
    
    if (data.status === 'success') {
      if (data.redirect) {
        window.location.href = data.redirect;
      } else {
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
</script>
<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>
