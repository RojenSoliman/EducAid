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

// Include SessionManager for session management
require_once __DIR__ . '/../../includes/SessionManager.php';
$sessionManager = new SessionManager($connection);

// --------- Handle Session Management Actions -----------
// Revoke a specific session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_session'])) {
    $sessionToRevoke = $_POST['session_id'] ?? '';
    
    if ($sessionManager->revokeSession($student_id, $sessionToRevoke)) {
        $_SESSION['session_flash'] = 'Session signed out successfully.';
        $_SESSION['session_flash_type'] = 'success';
    } else {
        $_SESSION['session_flash'] = 'Failed to sign out session.';
        $_SESSION['session_flash_type'] = 'error';
    }
    
    header("Location: active_sessions.php");
    exit;
}

// Revoke all other sessions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_all_sessions'])) {
    $count = $sessionManager->revokeAllOtherSessions($student_id, session_id());
    
    if ($count > 0) {
        $_SESSION['session_flash'] = "Signed out from $count other device(s) successfully.";
        $_SESSION['session_flash_type'] = 'success';
    } else {
        $_SESSION['session_flash'] = 'No other active sessions found.';
        $_SESSION['session_flash_type'] = 'info';
    }
    
    header("Location: active_sessions.php");
    exit;
}

// Fetch active sessions
$activeSessions = $sessionManager->getActiveSessions($student_id);
$currentSessionId = session_id();
$otherSessionsCount = 0;
foreach ($activeSessions as $session) {
    if ($session['session_id'] !== $currentSessionId) {
        $otherSessionsCount++;
    }
}

// Get student info for header dropdown
$student_info_query = "SELECT first_name, last_name FROM students WHERE student_id = $1";
$student_info_result = pg_query_params($connection, $student_info_query, [$student_id]);
$student_info = pg_fetch_assoc($student_info_result);

// Flash message
$flash = $_SESSION['session_flash'] ?? '';
$flash_type = $_SESSION['session_flash_type'] ?? '';
unset($_SESSION['session_flash'], $_SESSION['session_flash_type']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Active Sessions - EducAid</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" />
  <link href="../../assets/css/student/sidebar.css" rel="stylesheet" />
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
    
    .active-sessions-list {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }
    
    .session-item {
      display: flex;
      align-items: center;
      gap: 1rem;
      padding: 1rem;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      background: #ffffff;
      transition: all 0.2s ease;
    }
    
    .session-item:hover {
      border-color: #cbd5e0;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }
    
    .session-item.current-session {
      background: #f0fdf4;
      border-color: #86efac;
    }
    
    .session-icon {
      flex-shrink: 0;
      width: 48px;
      height: 48px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f7fafc;
      border-radius: 10px;
      color: #4a5568;
      font-size: 1.5rem;
    }
    
    .current-session .session-icon {
      background: #dcfce7;
      color: #16a34a;
    }
    
    .session-details {
      flex: 1;
      min-width: 0;
    }
    
    .session-device {
      color: #2d3748;
      font-size: 0.95rem;
      margin-bottom: 0.25rem;
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.5rem;
    }
    
    .session-meta {
      font-size: 0.85rem;
      color: #718096;
    }
    
    .session-action {
      flex-shrink: 0;
    }
    
    @media (max-width: 576px) {
      .session-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
      }
      
      .session-icon {
        width: 40px;
        height: 40px;
        font-size: 1.25rem;
      }

      .session-device {
        font-size: 0.9rem;
      }

      .session-meta {
        font-size: 0.8rem;
      }
      
      .session-action {
        width: 100%;
      }
      
      .session-action .btn {
        width: 100%;
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
              <a href="active_sessions.php" class="settings-nav-item active">
                <i class="bi bi-laptop me-2"></i>
                Active Sessions
              </a>
              <a href="security_activity.php" class="settings-nav-item">
                <i class="bi bi-clock-history me-2"></i>
                Security Activity
              </a>
            </div>
          </div>

          <!-- Main Content -->
          <div class="col-12 col-lg-9">
            <!-- Active Sessions Section -->
            <div class="settings-content-section">
              <h2 class="section-title">Active Sessions</h2>
              <p class="section-description">Manage devices where you're currently logged in</p>
              
        <!-- Flash Messages -->
        <?php if ($flash): ?>
          <div class="alert alert-<?php echo $flash_type === 'success' ? 'success' : ($flash_type === 'info' ? 'info' : 'danger'); ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-<?php echo $flash_type === 'success' ? 'check-circle' : ($flash_type === 'info' ? 'info-circle' : 'exclamation-triangle'); ?> me-2"></i>
            <?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <!-- Content Card -->
        <div class="content-card">
          <?php if (empty($activeSessions)): ?>
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              No active sessions found.
            </div>
          <?php else: ?>
            <div class="active-sessions-list">
              <?php foreach ($activeSessions as $session): ?>
                <?php 
                  $isCurrent = $session['session_id'] === $currentSessionId;
                  $deviceIcon = '';
                  switch ($session['device_type']) {
                    case 'mobile':
                      $deviceIcon = 'bi-phone';
                      break;
                    case 'tablet':
                      $deviceIcon = 'bi-tablet';
                      break;
                    default:
                      $deviceIcon = 'bi-laptop';
                  }
                  
                  $browserInfo = $session['browser'] ?: 'Unknown Browser';
                  $osInfo = $session['os'] ?: 'Unknown OS';
                  $lastActivity = $session['last_activity'] ? date('M d, Y h:i A', strtotime($session['last_activity'])) : 'Unknown';
                ?>
                <div class="session-item <?php echo $isCurrent ? 'current-session' : ''; ?>">
                  <div class="session-icon">
                    <i class="bi <?php echo $deviceIcon; ?>"></i>
                  </div>
                  <div class="session-details">
                    <div class="session-device">
                      <strong><?php echo htmlspecialchars($browserInfo); ?></strong> on <?php echo htmlspecialchars($osInfo); ?>
                      <?php if ($isCurrent): ?>
                        <span class="badge bg-success ms-2">
                          <i class="bi bi-check-circle me-1"></i>Current Device
                        </span>
                      <?php endif; ?>
                    </div>
                    <div class="session-meta text-muted">
                      <small>
                        <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($session['ip_address']); ?>
                        <span class="mx-2">•</span>
                        <i class="bi bi-clock me-1"></i>Last active: <?php echo $lastActivity; ?>
                      </small>
                    </div>
                  </div>
                  <div class="session-action">
                    <?php if (!$isCurrent): ?>
                      <form method="POST" action="active_sessions.php" style="display:inline;">
                        <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($session['session_id']); ?>">
                        <button type="submit" name="revoke_session" class="btn btn-sm btn-outline-danger">
                          <i class="bi bi-box-arrow-right me-1"></i>Sign Out
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($otherSessionsCount > 0): ?>
              <div class="mt-4 pt-3 border-top">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                  <div>
                    <h6 class="mb-1">Sign Out All Other Devices</h6>
                    <small class="text-muted">
                      You have <?php echo $otherSessionsCount; ?> other active session<?php echo $otherSessionsCount > 1 ? 's' : ''; ?>
                    </small>
                  </div>
                  <form method="POST" action="active_sessions.php" style="display:inline;">
                    <button type="submit" name="revoke_all_sessions" class="btn btn-danger" onclick="return confirm('Are you sure you want to sign out from all other devices?');">
                      <i class="bi bi-power me-2"></i>Sign Out All
                    </button>
                  </form>
                </div>
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
