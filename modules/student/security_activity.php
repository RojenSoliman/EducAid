<?php
/** @phpstan-ignore-file */
include '../../config/database.php';
session_start();
if (!isset($_SESSION['student_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
$student_id = $_SESSION['student_id'];

// Track session activity
include __DIR__ . '/../../includes/student_session_tracker.php';

// Include SessionManager
require_once __DIR__ . '/../../includes/SessionManager.php';
$sessionManager = new SessionManager($connection);

// Fetch login history (last 10 successful logins only)
$loginHistory = $sessionManager->getLoginHistory($student_id, 50);

// Count failed attempts for notice, but always display both success and failed attempts
$failedCount = 0;
foreach ($loginHistory as $log) {
  if (($log['status'] ?? '') === 'failed') { $failedCount++; }
}

// Get student info for header dropdown
$student_info_query = "SELECT first_name, last_name FROM students WHERE student_id = $1";
$student_info_result = pg_query_params($connection, $student_info_query, [$student_id]);
$student_info = pg_fetch_assoc($student_info_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Security Activity - EducAid</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" />
  <link href="../../assets/css/student/sidebar.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/accessibility.css" />
  <script src="../../assets/js/student/accessibility.js"></script>
  <style>
    body { background: #f7fafc; }
    
    /* Main Content Area Layout */
    .home-section {
      margin-left: 250px;
      width: calc(100% - 250px);
      min-height: calc(100vh - var(--topbar-h, 60px));
      background: #f7fafc;
      padding-top: 56px; /* Account for fixed header height */
      position: relative;
      z-index: 1;
      box-sizing: border-box;
    }

    .sidebar.close ~ .home-section {
      margin-left: 70px;
      width: calc(100% - 70px);
    }

    @media (max-width: 768px) {
      .home-section {
        margin-left: 0 !important;
        width: 100% !important;
      }
    }

    /* Settings Header */
    .settings-header {
      background: transparent;
      border-bottom: none;
      padding: 0;
      margin-bottom: 2rem;
    }
    
    .settings-header h1 {
      color: #1a202c;
      font-weight: 600;
      font-size: 2rem;
      margin: 0;
    }

    /* YouTube-Style Settings Navigation */
    .settings-nav {
      background: #f7fafc;
      border-radius: 12px;
      padding: 0.5rem;
      border: 1px solid #e2e8f0;
    }

    .settings-nav-item {
      display: flex;
      align-items: center;
      padding: 0.75rem 1rem;
      color: #4a5568;
      text-decoration: none;
      border-radius: 8px;
      font-weight: 500;
      font-size: 0.95rem;
      transition: all 0.2s ease;
      margin-bottom: 0.25rem;
    }

    .settings-nav-item:last-child {
      margin-bottom: 0;
    }

    .settings-nav-item:hover {
      background: #edf2f7;
      color: #2d3748;
      text-decoration: none;
    }

    .settings-nav-item.active {
      background: #4299e1;
      color: white;
    }

    .settings-nav-item.active:hover {
      background: #3182ce;
    }

    /* Settings Content Sections */
    .settings-content-section {
      margin-bottom: 3rem;
    }

    .section-title {
      color: #1a202c;
      font-weight: 600;
      font-size: 1.5rem;
      margin: 0 0 0.5rem 0;
    }

    .section-description {
      color: #718096;
      font-size: 0.95rem;
      margin: 0 0 1.5rem 0;
    }
    
    .page-header {
      background: white;
      padding: 2rem 0;
      margin-bottom: 2rem;
      border-bottom: 1px solid #e2e8f0;
    }
    
    .page-title {
      font-size: 1.75rem;
      font-weight: 600;
      color: #1a202c;
      margin: 0;
    }
    
    .page-description {
      color: #718096;
      margin: 0.5rem 0 0 0;
    }
    
    .content-card {
      background: white;
      border-radius: 12px;
      padding: 2rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      border: 1px solid #e2e8f0;
    }
    
    .login-history-list {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }
    
    .history-item {
      display: flex;
      align-items: flex-start;
      gap: 1rem;
      padding: 1rem;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: #ffffff;
      transition: all 0.2s ease;
    }
    
    .history-item:hover {
      border-color: #cbd5e0;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }
    
    .history-item.failed {
      background: #fef2f2;
      border-color: #fecaca;
    }
    
    .history-icon {
      flex-shrink: 0;
      font-size: 1.5rem;
      padding-top: 0.25rem;
    }
    
    .history-details {
      flex: 1;
      min-width: 0;
    }
    
    .history-status {
      color: #2d3748;
      font-size: 0.95rem;
      margin-bottom: 0.25rem;
      display: flex;
      align-items: center;
      flex-wrap: wrap;
    }
    
    .history-meta {
      font-size: 0.85rem;
      color: #718096;
      line-height: 1.5;
    }
    
    @media (max-width: 576px) {
      .history-item {
        padding: 0.75rem;
      }
      
      .history-icon {
        font-size: 1.25rem;
      }

      .history-status {
        font-size: 0.9rem;
      }

      .history-meta {
        font-size: 0.8rem;
      }

      .history-meta .mx-2 {
        display: none;
      }
      
      .history-meta small {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
      }
    }
  </style>
</head>
<body>
  <!-- Student Topbar -->
  <?php include __DIR__ . '/../../includes/student/student_topbar.php'; ?>

  <div id="wrapper" style="padding-top: var(--topbar-h);">
    <!-- Include Sidebar -->
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    
    <!-- Student Header -->
    <?php include __DIR__ . '/../../includes/student/student_header.php'; ?>
    
    <!-- Main Content Area -->
    <section class="home-section" id="page-content-wrapper">
      <div class="container-fluid py-4 px-4">
        <!-- Settings Header -->
        <div class="settings-header mb-4">
          <h1 class="mb-1">Settings</h1>
        </div>

        <!-- YouTube-style Layout: Sidebar + Content -->
        <div class="row g-4">
          <!-- Settings Navigation Sidebar -->
          <div class="col-12 col-lg-3">
            <div class="settings-nav sticky-top" style="top: 100px;">
              <a href="student_settings.php#account" class="settings-nav-item">
                <i class="bi bi-person-circle me-2"></i>
                Account
              </a>
              <a href="student_settings.php#security" class="settings-nav-item">
                <i class="bi bi-shield-lock me-2"></i>
                Security & Privacy
              </a>
              <a href="accessibility.php" class="settings-nav-item">
                <i class="bi bi-universal-access me-2"></i>
                Accessibility
              </a>
              <a href="active_sessions.php" class="settings-nav-item">
                <i class="bi bi-laptop me-2"></i>
                Active Sessions
              </a>
              <a href="security_activity.php" class="settings-nav-item active">
                <i class="bi bi-clock-history me-2"></i>
                Security Activity
              </a>
            </div>
          </div>

          <!-- Main Content -->
          <div class="col-12 col-lg-9">
            <!-- Security Activity Section -->
            <div class="settings-content-section">
              <h2 class="section-title">Security Activity</h2>
              <p class="section-description">Recent login attempts on your account</p>
              
        <!-- Content Card -->
        <div class="content-card">
          <?php if ($failedCount >= 3): ?>
            <div class="alert alert-warning mb-3">
              <i class="bi bi-exclamation-triangle me-2"></i>
              <strong>Security Notice:</strong> We detected <?php echo $failedCount; ?> recent failed login attempt(s). 
              If this wasn't you, consider changing your password immediately.
            </div>
          <?php endif; ?>

          <?php if (empty($loginHistory)): ?>
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              No recent activity to display.
            </div>
          <?php else: ?>
            <div class="login-history-list">
              <?php foreach (array_slice($loginHistory, 0, 20) as $log): ?>
                <?php 
                  $isSuccess = $log['status'] === 'success';
                  $deviceIcon = '';
                  switch ($log['device_type']) {
                    case 'mobile':
                      $deviceIcon = 'bi-phone';
                      break;
                    case 'tablet':
                      $deviceIcon = 'bi-tablet';
                      break;
                    default:
                      $deviceIcon = 'bi-laptop';
                  }
                  
                  $browserInfo = $log['browser'] ?: 'Unknown Browser';
                  $osInfo = $log['os'] ?: 'Unknown OS';
                  $loginTime = $log['login_time'] ? date('M d, Y h:i A', strtotime($log['login_time'])) : 'Unknown';
                ?>
                <div class="history-item <?php echo $isSuccess ? 'success' : 'failed'; ?>">
                  <div class="history-icon">
                    <i class="bi <?php echo $isSuccess ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'; ?>"></i>
                  </div>
                  <div class="history-details">
                    <div class="history-status">
                      <strong><?php echo $isSuccess ? 'Successful Login' : 'Failed Login Attempt'; ?></strong>
                      <?php if (!$isSuccess && $log['failure_reason']): ?>
                        <span class="text-muted ms-2">
                          <small>(<?php echo htmlspecialchars($log['failure_reason']); ?>)</small>
                        </span>
                      <?php endif; ?>
                    </div>
                    <div class="history-meta text-muted">
                      <small>
                        <i class="bi <?php echo $deviceIcon; ?> me-1"></i><?php echo htmlspecialchars($browserInfo); ?> on <?php echo htmlspecialchars($osInfo); ?>
                        <span class="mx-2">•</span>
                        <i class="bi bi-clock me-1"></i><?php echo $loginTime; ?>
                        <span class="mx-2">•</span>
                        <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($log['ip_address']); ?>
                      </small>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if (count($loginHistory) > 20): ?>
              <div class="text-center mt-3">
                <small class="text-muted">
                  Showing 20 most recent activities
                </small>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/student/sidebar.js"></script>
</body>
</html>
