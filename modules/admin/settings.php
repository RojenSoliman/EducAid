<?php
/** @phpstan-ignore-file */
include __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../services/OTPService.php';

session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}

$municipality_id = 1; // Default municipality
$otpService = new OTPService($connection);

// Ensure OTP verification table exists
$createTableQuery = "
CREATE TABLE IF NOT EXISTS admin_otp_verifications (
    id SERIAL PRIMARY KEY,
    admin_id INTEGER NOT NULL,
    otp_code VARCHAR(6) NOT NULL,
    email VARCHAR(255) NOT NULL,
    purpose VARCHAR(50) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_admin_otp_admin_id ON admin_otp_verifications(admin_id);
CREATE INDEX IF NOT EXISTS idx_admin_otp_expires ON admin_otp_verifications(expires_at);
";
pg_query($connection, $createTableQuery);

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

// Clean up stale OTP sessions on normal page load (GET request)
// This prevents issues when users navigate back to the page after some time
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  // Check if there are any OTP sessions without an active OTP process
  if (isset($_SESSION['otp_step'])) {
    // If we're not in the middle of an OTP verification process, clear stale data
    $clearStaleSession = true;
    
    // Don't clear if we have temp data that suggests an active process
    if ((isset($_SESSION['temp_new_email']) || isset($_SESSION['temp_new_password']))) {
      // Allow keeping session if OTP was recently sent (within last 10 minutes)
      // This is a safety check - in normal flow, the session should be cleared properly
      $clearStaleSession = false;
    }
    
    if ($clearStaleSession) {
      unset($_SESSION['otp_step']);
      unset($_SESSION['temp_new_email']);
      unset($_SESSION['temp_new_password']);
      unset($_SESSION['otp_retry_count']);
    }
  }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Handle clearing OTP session when modal is closed
  if (isset($_POST['clear_otp_session'])) {
    unset($_SESSION['otp_step']);
    unset($_SESSION['temp_new_email']);
    unset($_SESSION['temp_new_password']);
    unset($_SESSION['otp_retry_count']);
    exit(); // Just exit, no response needed
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
  
  // Handle OTP generation for email change
  elseif (isset($_POST['send_email_otp'])) {
    $new_email = trim($_POST['new_email']);
    $current_password = $_POST['current_password'];
    
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
      $otp_error = "Current password is incorrect.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
      $otp_error = "Please enter a valid email address.";
    } else {
      // Send OTP to new email
      if ($otpService->sendOTP($new_email, 'email_change', $admin_id)) {
        $_SESSION['temp_new_email'] = $new_email;
        $_SESSION['otp_step'] = 'email_verification';
        $otp_success = "OTP has been sent to your new email address. Please check your inbox.";
      } else {
        $otp_error = "Failed to send OTP. Please try again.";
      }
    }
  }
  
  // Handle OTP generation for password change
  elseif (isset($_POST['send_password_otp'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Get current admin info
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id && isset($_SESSION['admin_username'])) {
      $adminQuery = pg_query_params($connection, "SELECT admin_id, password, email FROM admins WHERE username = $1", [$_SESSION['admin_username']]);
      $adminData = pg_fetch_assoc($adminQuery);
      $admin_id = $adminData['admin_id'];
    } else {
      $adminQuery = pg_query_params($connection, "SELECT password, email FROM admins WHERE admin_id = $1", [$admin_id]);
      $adminData = pg_fetch_assoc($adminQuery);
    }
    
    if (!$adminData || !password_verify($current_password, $adminData['password'])) {
      $password_error = "Current password is incorrect.";
    } elseif ($current_password === $new_password) {
      $password_error = "New password cannot be the same as your current password.";
    } elseif ($new_password !== $confirm_password) {
      $password_error = "New passwords do not match.";
    } elseif (strlen($new_password) < 8) {
      $password_error = "New password must be at least 8 characters.";
    } elseif (empty($adminData['email'])) {
      $password_error = "No email address found in your profile. Please contact administrator.";
    } else {
      // Store new password temporarily in session
      $_SESSION['temp_new_password'] = $new_password;
      
      // Send OTP to current email
      if ($otpService->sendOTP($adminData['email'], 'password_change', $admin_id)) {
        $_SESSION['otp_step'] = 'password_verification';
        $password_success = "OTP has been sent to your email address. Please check your inbox.";
      } else {
        $password_error = "Failed to send OTP. Please try again.";
      }
    }
  }
  
  // Handle email change with OTP verification
  elseif (isset($_POST['verify_email_otp'])) {
    $otp = trim($_POST['otp']);
    $new_email = $_SESSION['temp_new_email'] ?? '';
    
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id && isset($_SESSION['admin_username'])) {
      $adminQuery = pg_query_params($connection, "SELECT admin_id FROM admins WHERE username = $1", [$_SESSION['admin_username']]);
      $adminData = pg_fetch_assoc($adminQuery);
      $admin_id = $adminData['admin_id'];
    }

    // Check if this is an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    if (empty($new_email)) {
      $otp_error = "Session expired. Please start the email change process again.";
      if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $otp_error]);
        exit();
      }
    } else {
      // Debug: Log the verification attempt
      error_log("OTP Verification attempt: admin_id=$admin_id, otp=$otp, purpose=email_change");
      
      // Temporary debug output for troubleshooting
      $debug_info = "DEBUG: admin_id=$admin_id, otp=$otp, new_email=$new_email, purpose=email_change";
      
      $otpVerified = $otpService->verifyOTP($admin_id, $otp, 'email_change');
      error_log("OTP Verification result: " . ($otpVerified ? 'SUCCESS' : 'FAILED'));
      
      // Add more detailed debugging
      $debug_info .= " | OTP Verified: " . ($otpVerified ? 'YES' : 'NO');
      
      if ($otpVerified) {
        $debug_info .= " | Attempting database update...";
      // Update email
      $result = pg_query_params($connection, "UPDATE admins SET email = $1 WHERE admin_id = $2", [$new_email, $admin_id]);
      
      if ($result) {
        // Check if any rows were actually updated
        $affectedRows = pg_affected_rows($result);
        $debug_info .= " | Query executed, affected rows: $affectedRows";
        
        if ($affectedRows > 0) {
          $debug_info .= " | SUCCESS: Email updated";
          // Clear session variables
          unset($_SESSION['temp_new_email']);
          unset($_SESSION['otp_step']);
          unset($_SESSION['otp_retry_count']);
          
          // Add admin notification
          $notification_msg = "Admin email updated to " . $new_email;
          pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
          
          if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Email address updated successfully!']);
            exit();
          } else {
            // Set success message and redirect
            $_SESSION['success_message'] = "Email address updated successfully!";
            header("Location: settings.php");
            exit();
          }
        } else {
          $debug_info .= " | FAILED: No rows updated (admin_id may not exist)";
          $otp_error = "Failed to update email address - no records updated.";
          if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $otp_error]);
            exit();
          }
        }
      } else {
        $pg_error = pg_last_error($connection);
        $debug_info .= " | DATABASE ERROR: " . $pg_error;
        $otp_error = "Database error: " . $pg_error;
        if ($isAjax) {
          header('Content-Type: application/json');
          echo json_encode(['status' => 'error', 'message' => $otp_error]);
          exit();
        }
      }
    } else {
      $debug_info .= " | OTP VERIFICATION FAILED";
      
      // Provide user-friendly error messages
      if (empty($otp)) {
        $otp_error = "Please enter the verification code.";
      } else {
        $otp_error = "The verification code you entered is incorrect or has expired. Please check your email for the latest code and try again.";
      }
      // Keep the session variables for retry, but add a counter to prevent infinite loops
      if (!isset($_SESSION['otp_retry_count'])) {
        $_SESSION['otp_retry_count'] = 1;
      } else {
        $_SESSION['otp_retry_count']++;
        if ($_SESSION['otp_retry_count'] > 3) {
          // Too many failed attempts, clear everything
          unset($_SESSION['temp_new_email']);
          unset($_SESSION['otp_step']);
          unset($_SESSION['otp_retry_count']);
          $otp_error = "Too many failed attempts. Please start the process again.";
        }
      }
      
      if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $otp_error]);
        exit();
      }
    }
  }
  }
  
  // Handle password change with OTP verification
  elseif (isset($_POST['verify_password_otp'])) {
    $otp = trim($_POST['otp']);
    $new_password = $_SESSION['temp_new_password'] ?? '';
    
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id && isset($_SESSION['admin_username'])) {
      $adminQuery = pg_query_params($connection, "SELECT admin_id FROM admins WHERE username = $1", [$_SESSION['admin_username']]);
      $adminData = pg_fetch_assoc($adminQuery);
      $admin_id = $adminData['admin_id'];
    }

    // Check if this is an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    if (empty($new_password)) {
      $password_error = "Session expired. Please try again.";
      if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $password_error]);
        exit();
      }
    } else {
      // Debug: Log the verification attempt
      error_log("Password OTP Verification attempt: admin_id=$admin_id, otp=$otp, purpose=password_change");
      
      // Temporary debug output for troubleshooting
      $debug_password_info = "DEBUG PASSWORD: admin_id=$admin_id, otp=$otp, purpose=password_change";
      
      $otpVerified = $otpService->verifyOTP($admin_id, $otp, 'password_change');
      error_log("Password OTP Verification result: " . ($otpVerified ? 'SUCCESS' : 'FAILED'));
      
      // Add more detailed debugging
      $debug_password_info .= " | OTP Verified: " . ($otpVerified ? 'YES' : 'NO');
      
      if ($otpVerified) {
        $debug_password_info .= " | Attempting password update...";
        // Update password
        $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
        $result = pg_query_params($connection, "UPDATE admins SET password = $1 WHERE admin_id = $2", [$hashedPassword, $admin_id]);
        
        if ($result) {
          // Check if any rows were actually updated
          $affectedRows = pg_affected_rows($result);
          $debug_password_info .= " | Query executed, affected rows: $affectedRows";
          
          if ($affectedRows > 0) {
            $debug_password_info .= " | SUCCESS: Password updated";
            // Clear session variables
            unset($_SESSION['temp_new_password']);
            unset($_SESSION['otp_step']);
            unset($_SESSION['otp_retry_count']);
            
            // Add admin notification
            $notification_msg = "Admin password updated";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            
            if ($isAjax) {
              header('Content-Type: application/json');
              echo json_encode(['status' => 'success', 'message' => 'Password updated successfully!']);
              exit();
            } else {
              // Set success message and redirect
              $_SESSION['success_message'] = "Password updated successfully!";
              header("Location: settings.php");
              exit();
            }
          } else {
            $debug_password_info .= " | FAILED: No rows updated";
            $password_error = "Failed to update password - no records updated.";
            if ($isAjax) {
              header('Content-Type: application/json');
              echo json_encode(['status' => 'error', 'message' => $password_error]);
              exit();
            }
          }
        } else {
          $pg_error = pg_last_error($connection);
          $debug_password_info .= " | DATABASE ERROR: " . $pg_error;
          $password_error = "Database error: " . $pg_error;
          if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $password_error]);
            exit();
          }
        }
      } else {
        $debug_password_info .= " | OTP VERIFICATION FAILED";
        
        // Provide user-friendly error messages
        if (empty($otp)) {
          $password_error = "Please enter the verification code.";
        } else {
          $password_error = "The verification code you entered is incorrect or has expired. Please check your email for the latest code and try again.";
        }
        // Keep the session variables for retry, but add a counter to prevent infinite loops
        if (!isset($_SESSION['otp_retry_count'])) {
          $_SESSION['otp_retry_count'] = 1;
        } else {
          $_SESSION['otp_retry_count']++;
          if ($_SESSION['otp_retry_count'] > 3) {
            // Too many failed attempts, clear everything
            unset($_SESSION['temp_new_password']);
            unset($_SESSION['otp_step']);
            unset($_SESSION['otp_retry_count']);
            $password_error = "Too many failed attempts. Please start the process again.";
          }
        }
        
        if ($isAjax) {
          header('Content-Type: application/json');
          echo json_encode(['status' => 'error', 'message' => $password_error]);
          exit();
        }
      }
    }
  }
  
  // Handle error session clearing (when error alerts are dismissed)
  elseif (isset($_POST['clear_error_session'])) {
    unset($_SESSION['otp_step']);
    unset($_SESSION['temp_new_email']);
    unset($_SESSION['temp_new_password']);
    unset($_SESSION['otp_retry_count']);
    // Return success response for AJAX
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
    exit();
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
      
      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
          <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success_message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>
      
      <!-- Email Change Error Alert -->
      <?php if (isset($otp_error) && isset($_SESSION['otp_step']) && $_SESSION['otp_step'] === 'email_verification'): ?>
        <div class="alert alert-danger alert-dismissible fade show">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <strong>Email Change Error:</strong> <?= htmlspecialchars($otp_error) ?>
          <br><small class="text-muted">Click "Change Email" below to try again with a new verification code.</small>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <!-- Password Change Error Alert -->
      <?php if (isset($password_error) && isset($_SESSION['otp_step']) && $_SESSION['otp_step'] === 'password_verification'): ?>
        <div class="alert alert-danger alert-dismissible fade show">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <strong>Password Change Error:</strong> <?= htmlspecialchars($password_error) ?>
          <br><small class="text-muted">Click "Change Password" below to try again with a new verification code.</small>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <!-- General OTP Error Alert -->
      <?php if ((isset($otp_error) && !isset($_SESSION['otp_step'])) || (isset($password_error) && !isset($_SESSION['otp_step']))): ?>
        <div class="alert alert-danger alert-dismissible fade show">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <?php if (isset($otp_error)): ?>
            <strong>Email Verification Error:</strong> <?= htmlspecialchars($otp_error) ?>
          <?php elseif (isset($password_error)): ?>
            <strong>Password Verification Error:</strong> <?= htmlspecialchars($password_error) ?>
          <?php endif; ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
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
          <?php endif; ?>
          
          <div class="row">
            <div class="col-md-6">
              <div class="d-flex justify-content-between align-items-center p-3 border rounded">
                <div>
                  <h6 class="mb-1">Email Address</h6>
                  <small class="text-muted">Update your email address</small>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="showEmailModal()">
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
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="showPasswordModal()">
                  <i class="bi bi-key me-1"></i> Change Password
                </button>
              </div>
            </div>
          </div>
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

<!-- Email Update Modal -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Update Email Address</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      
      <!-- Step 1: Email and Password -->
      <div id="emailStep1">
        <form method="POST">
          <div class="modal-body">
            <?php if (isset($otp_error)): ?>
              <div class="alert alert-danger"><?= htmlspecialchars($otp_error) ?></div>
            <?php endif; ?>
            <?php if (isset($otp_success)): ?>
              <div class="alert alert-success"><?= htmlspecialchars($otp_success) ?></div>
            <?php endif; ?>
            
            <div class="mb-3">
              <label for="modal_new_email" class="form-label">New Email Address <span class="text-danger">*</span></label>
              <input type="email" class="form-control" id="modal_new_email" name="new_email" required>
            </div>
            <div class="mb-3">
              <label for="modal_current_password_email" class="form-label">Current Password <span class="text-danger">*</span></label>
              <input type="password" class="form-control" id="modal_current_password_email" name="current_password" required>
              <small class="text-muted">Required for security verification</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" name="send_email_otp" class="btn btn-primary">
              <i class="bi bi-send me-1"></i> Send OTP
            </button>
          </div>
        </form>
      </div>
      
      <!-- Step 2: OTP Verification -->
      <div id="emailStep2" style="display: none;">
        <form onsubmit="event.preventDefault(); handleOTPVerification(this, 'email');">
          <input type="hidden" name="verify_email_otp" value="1">
          <div class="modal-body">
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              We've sent a verification code to your new email address. Please check your inbox and enter the 6-digit code below.
            </div>
            
            <!-- OTP Error Display -->
            <div id="emailOtpErrorDiv" style="display: none;">
              <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <span id="emailOtpErrorMessage"></span>
              </div>
            </div>
            
            <div class="mb-3">
              <label for="modal_email_otp" class="form-label">Verification Code <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-center" id="modal_email_otp" name="otp" 
                     maxlength="6" pattern="[0-9]{6}" required placeholder="000000"
                     style="font-size: 18px; letter-spacing: 3px;"
                     oninput="hideModalError('email')">
              <small class="text-muted">Enter the 6-digit code sent to your email</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="backToEmailStep1()">Back</button>
            <button type="submit" class="btn btn-success">
              <i class="bi bi-check-circle me-1"></i> Verify & Update
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Password Update Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Update Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      
      <!-- Step 1: Password Change Request -->
      <div id="passwordStep1">
        <form method="POST">
          <div class="modal-body">
            <?php if (isset($password_error)): ?>
              <div class="alert alert-danger"><?= htmlspecialchars($password_error) ?></div>
            <?php endif; ?>
            <?php if (isset($password_success)): ?>
              <div class="alert alert-success"><?= htmlspecialchars($password_success) ?></div>
            <?php endif; ?>
            
            <div class="mb-3">
              <label for="modal_current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
              <input type="password" class="form-control" id="modal_current_password" name="current_password" required>
            </div>
            <div class="mb-3">
              <label for="modal_new_password" class="form-label">New Password <span class="text-danger">*</span></label>
              <input type="password" class="form-control" id="modal_new_password" name="new_password" 
                     minlength="8" required>
              <small class="text-muted">Minimum 8 characters</small>
            </div>
            <div class="mb-3">
              <label for="modal_confirm_password" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
              <input type="password" class="form-control" id="modal_confirm_password" name="confirm_password" 
                     minlength="8" required>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" name="send_password_otp" class="btn btn-primary">
              <i class="bi bi-send me-1"></i> Send OTP
            </button>
          </div>
        </form>
      </div>
      
      <!-- Step 2: OTP Verification -->
      <div id="passwordStep2" style="display: none;">
        <form onsubmit="event.preventDefault(); handleOTPVerification(this, 'password');">
          <input type="hidden" name="verify_password_otp" value="1">
          <div class="modal-body">
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              We've sent a verification code to your email address. Please check your inbox and enter the 6-digit code below.
            </div>
            
            <!-- OTP Error Display -->
            <div id="passwordOtpErrorDiv" style="display: none;">
              <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <span id="passwordOtpErrorMessage"></span>
              </div>
            </div>
            
            <div class="mb-3">
              <label for="modal_password_otp" class="form-label">Verification Code <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-center" id="modal_password_otp" name="otp" 
                     maxlength="6" pattern="[0-9]{6}" required placeholder="000000"
                     style="font-size: 18px; letter-spacing: 3px;"
                     oninput="hideModalError('password')">
              <small class="text-muted">Enter the 6-digit code sent to your email</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="backToPasswordStep1()">Back</button>
            <button type="submit" class="btn btn-success">
              <i class="bi bi-check-circle me-1"></i> Verify & Update
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
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
// Profile Management Functions
function showEmailModal() {
  const modal = new bootstrap.Modal(document.getElementById('emailModal'));
  modal.show();
}

function showPasswordModal() {
  const modal = new bootstrap.Modal(document.getElementById('passwordModal'));
  modal.show();
}

// Capacity Management Functions
function showCapacityModal() {
  const capacityInput = document.getElementById('max_capacity');
  const modalCapacityInput = document.getElementById('modal_capacity');
  
  // Copy current value to modal
  modalCapacityInput.value = capacityInput.value;
  
  const modal = new bootstrap.Modal(document.getElementById('capacityModal'));
  modal.show();
}

// Password confirmation validation
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

// OTP Modal Management

// Show inline error in modal
function showModalError(modalType, message) {
  const errorDiv = document.getElementById(modalType + 'OtpErrorDiv');
  const errorMessage = document.getElementById(modalType + 'OtpErrorMessage');
  
  if (errorDiv && errorMessage) {
    errorMessage.textContent = message;
    errorDiv.style.display = 'block';
  }
}

// Hide inline error in modal
function hideModalError(modalType) {
  const errorDiv = document.getElementById(modalType + 'OtpErrorDiv');
  if (errorDiv) {
    errorDiv.style.display = 'none';
  }
}

// Handle OTP verification with AJAX
function handleOTPVerification(formElement, verificationType) {
  const formData = new FormData(formElement);
  const submitButton = formElement.querySelector('button[type="submit"]');
  const originalText = submitButton.innerHTML;
  
  // Disable button and show loading
  submitButton.disabled = true;
  submitButton.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Verifying...';
  
  // Hide any existing error
  hideModalError(verificationType);
  
  fetch(window.location.href, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: formData
  })
  .then(response => {
    const contentType = response.headers.get('content-type');
    if (contentType && contentType.includes('application/json')) {
      return response.json();
    }
    return response.text();
  })
  .then(data => {
    if (typeof data === 'object') {
      // JSON response from AJAX handler
      if (data.status === 'success') {
        // Success - reload page to show success message
        window.location.reload();
      } else if (data.status === 'error') {
        // Show error in modal
        showModalError(verificationType, data.message);
        
        // Handle special cases that should close modal
        if (data.message.includes('Too many failed attempts') || data.message.includes('Session expired')) {
          setTimeout(() => {
            const modal = bootstrap.Modal.getInstance(formElement.closest('.modal'));
            if (modal) modal.hide();
          }, 3000);
        }
      }
    } else {
      // Text response - check for success redirect
      if (data.includes('success_message') || data.includes('Location: settings.php')) {
        // Success - reload page to show success message
        window.location.reload();
      } else {
        // Parse response for error message
        let errorMessage = 'Verification failed. Please try again.';
        
        // Check for specific error patterns in response
        if (data.includes('incorrect or has expired')) {
          errorMessage = 'The verification code you entered is incorrect or has expired. Please check your email for the latest code and try again.';
        } else if (data.includes('too many failed attempts') || data.includes('Too many failed attempts')) {
          errorMessage = 'Too many failed attempts. Please start the process again.';
          // Clear form and close modal after showing error
          setTimeout(() => {
            const modal = bootstrap.Modal.getInstance(formElement.closest('.modal'));
            if (modal) modal.hide();
          }, 3000);
        } else if (data.includes('Session expired')) {
          errorMessage = 'Session expired. Please start the process again.';
          setTimeout(() => {
            const modal = bootstrap.Modal.getInstance(formElement.closest('.modal'));
            if (modal) modal.hide();
          }, 3000);
        }
        
        // Show error in modal
        showModalError(verificationType, errorMessage);
      }
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showModalError(verificationType, 'Network error. Please check your connection and try again.');
  })
  .finally(() => {
    // Re-enable button
    submitButton.disabled = false;
    submitButton.disabled = false;
    submitButton.innerHTML = originalText;
  });
}

function showEmailOTPStep() {
  document.getElementById('emailStep1').style.display = 'none';
  document.getElementById('emailStep2').style.display = 'block';
}

function backToEmailStep1() {
  document.getElementById('emailStep2').style.display = 'none';
  document.getElementById('emailStep1').style.display = 'block';
}

function showPasswordOTPStep() {
  document.getElementById('passwordStep1').style.display = 'none';
  document.getElementById('passwordStep2').style.display = 'block';
}

function backToPasswordStep1() {
  document.getElementById('passwordStep2').style.display = 'none';
  document.getElementById('passwordStep1').style.display = 'block';
}

// Reset modal states when modals are closed
document.getElementById('emailModal').addEventListener('hidden.bs.modal', function () {
  document.getElementById('emailStep1').style.display = 'block';
  document.getElementById('emailStep2').style.display = 'none';
  this.querySelectorAll('form')[0].reset();
  this.querySelectorAll('form')[1].reset();
  
  // Clear any remaining OTP session data when user manually closes modal
  fetch('settings.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: 'clear_otp_session=1'
  });
});

document.getElementById('passwordModal').addEventListener('hidden.bs.modal', function () {
  document.getElementById('passwordStep1').style.display = 'block';
  document.getElementById('passwordStep2').style.display = 'none';
  this.querySelectorAll('form')[0].reset();
  this.querySelectorAll('form')[1].reset();
  
  // Clear any remaining OTP session data when user manually closes modal
  fetch('settings.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: 'clear_otp_session=1'
  });
});

// Auto-show OTP step if OTP was sent
<?php if (isset($_SESSION['otp_step'])): ?>
  <?php if ($_SESSION['otp_step'] === 'email_verification'): ?>
    document.addEventListener('DOMContentLoaded', function() {
      var emailModal = new bootstrap.Modal(document.getElementById('emailModal'));
      emailModal.show();
      showEmailOTPStep();
    });
  <?php elseif ($_SESSION['otp_step'] === 'password_verification'): ?>
    document.addEventListener('DOMContentLoaded', function() {
      var passwordModal = new bootstrap.Modal(document.getElementById('passwordModal'));
      passwordModal.show();
      showPasswordOTPStep();
    });
  <?php endif; ?>
  
  <?php 
    // Clear the session flag more intelligently
    // Keep the session during error states so the appropriate modal can be reopened
    if (!isset($_POST['verify_email_otp']) && !isset($_POST['verify_password_otp']) && 
        !isset($_POST['send_email_otp']) && !isset($_POST['send_password_otp'])) {
      // Clear session if we're not in an active OTP process and not showing an error
      if (!isset($otp_error) && !isset($password_error)) {
        unset($_SESSION['otp_step']);
      }
    }
  ?>
<?php endif; ?>

// OTP input formatting
document.getElementById('modal_email_otp')?.addEventListener('input', function(e) {
  this.value = this.value.replace(/\D/g, '');
});

document.getElementById('modal_password_otp')?.addEventListener('input', function(e) {
  this.value = this.value.replace(/\D/g, '');
});

// Clear OTP session when error alerts are dismissed
document.addEventListener('DOMContentLoaded', function() {
  const errorAlerts = document.querySelectorAll('.alert-danger .btn-close');
  errorAlerts.forEach(function(closeBtn) {
    closeBtn.addEventListener('click', function() {
      // Clear OTP session when error alert is dismissed
      fetch(window.location.href, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'clear_error_session=1'
      });
    });
  });
});
</script>
<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>
