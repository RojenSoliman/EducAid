<?php
// admin_sidebar.php
include_once __DIR__ . '/../permissions.php';
include_once __DIR__ . '/../workflow_control.php';
$admin_role = 'super_admin'; // Default
$workflow_status = [];
if (isset($_SESSION['admin_id'])) {
    include_once __DIR__ . '/../../config/database.php';
    $admin_role = getCurrentAdminRole($connection);
    $workflow_status = getWorkflowStatus($connection);
}
?>
<!-- admin_sidebar.php -->
<div class="sidebar" id="sidebar">
  <div class="logo-details">
    <i class="bi bi-person-gear icon"></i>
    <span class="logo_name">Admin</span>
  </div>
  <ul class="nav-list">
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'homepage.php' ? 'active' : ''; ?>">
      <a href="homepage.php">
        <i class="bi bi-house-door icon"></i>
        <span class="links_name">Dashboard</span>
      </a>
    </li>
    <?php if ($admin_role === 'super_admin'): ?>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage_announcements.php' ? 'active' : ''; ?>">
      <a href="manage_announcements.php">
        <i class="bi bi-megaphone icon"></i>
        <span class="links_name">Announcements</span>
      </a>
    </li>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage_slots.php' ? 'active' : ''; ?>">
      <a href="manage_slots.php">
        <i class="bi bi-sliders icon"></i>
        <span class="links_name">Signup Slots</span>
      </a>
    </li>
    <?php endif; ?>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'review_registrations.php' ? 'active' : ''; ?>">
      <a href="review_registrations.php">
        <i class="bi bi-clipboard-check icon"></i>
        <span class="links_name">Review Registrations</span>
      </a>
    </li>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage_applicants.php' ? 'active' : ''; ?>">
      <a href="manage_applicants.php">
        <i class="bi bi-people icon"></i>
        <span class="links_name">Manage Applicants</span>
      </a>
    </li>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'validate_grades.php' ? 'active' : ''; ?>">
      <a href="validate_grades.php">
        <i class="bi bi-file-earmark-check icon"></i>
        <span class="links_name">Validate Grades</span>
      </a>
    </li>
    <?php if ($admin_role === 'super_admin'): ?>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'verify_students.php' ? 'active' : ''; ?>">
      <a href="verify_students.php">
        <i class="bi bi-person-check icon"></i>
        <span class="links_name">Verify Students</span>
      </a>
    </li>
    <?php if ($workflow_status['can_schedule'] ?? false): ?>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage_schedules.php' ? 'active' : ''; ?>">
      <a href="manage_schedules.php">
        <i class="bi bi-calendar icon"></i>
        <span class="links_name">Scheduling</span>
        <span class="badge bg-info ms-2">Ready</span>
      </a>
    </li>
    <?php else: ?>
    <li class="nav-item disabled">
      <a href="#" onclick="alert('Please generate payroll numbers and QR codes first before scheduling.'); return false;" class="text-muted">
        <i class="bi bi-calendar icon"></i>
        <span class="links_name">Scheduling</span>
        <span class="badge bg-secondary ms-2">Locked</span>
      </a>
    </li>
    <?php endif; ?>
    <?php if ($workflow_status['can_scan_qr'] ?? false): ?>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'scan_qr.php' ? 'active' : ''; ?>">
      <a href="scan_qr.php">
        <i class="bi bi-qr-code-scan icon"></i> 
        <span class="links_name">Scan QR</span>
        <span class="badge bg-success ms-2">Ready</span>
      </a>
    </li>
    <?php else: ?>
    <li class="nav-item disabled">
      <a href="#" onclick="alert('Please generate payroll numbers and QR codes first before scanning.'); return false;" class="text-muted">
        <i class="bi bi-qr-code-scan icon"></i>
        <span class="links_name">Scan QR</span>
        <span class="badge bg-secondary ms-2">Locked</span>
      </a>
    </li>
    <?php endif; ?>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage_distributions.php' ? 'active' : ''; ?>">
      <a href="manage_distributions.php">
        <i class="bi bi-box-seam icon"></i>
        <span class="links_name">Manage Distributions</span>
      </a>
    </li>
    <?php endif; ?>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'admin_notifications.php' ? 'active' : ''; ?>">
      <a href="admin_notifications.php">
        <i class="bi bi-bell icon"></i>
        <span class="links_name">Notifications</span>
      </a>
    </li>
    <?php if ($admin_role === 'super_admin'): ?>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'blacklist_archive.php' ? 'active' : ''; ?>">
      <a href="blacklist_archive.php">
        <i class="bi bi-person-x-fill icon"></i>
        <span class="links_name">Blacklist Archive</span>
      </a>
    </li>
    <?php endif; ?>
    <?php if ($admin_role === 'super_admin'): ?>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'admin_management.php' ? 'active' : ''; ?>">
      <a href="admin_management.php">
        <i class="bi bi-people-fill icon"></i>
        <span class="links_name">Admin Management</span>
      </a>
    </li>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'system_data.php' ? 'active' : ''; ?>">
      <a href="system_data.php">
        <i class="bi bi-database icon"></i>
        <span class="links_name">System Data</span>
      </a>
    </li>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'active' : ''; ?>">
      <a href="settings.php">
        <i class="bi bi-gear icon"></i>
        <span class="links_name">Settings</span>
      </a>
    </li>
    <?php endif; ?>
    <li class="nav-item logout">
      <a href="logout.php" onclick="return confirm('Are you sure you want to logout?');">
        <i class="bi bi-box-arrow-right icon"></i>
        <span class="links_name">Logout</span>
      </a>
    </li>
  </ul>
</div>
<div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
