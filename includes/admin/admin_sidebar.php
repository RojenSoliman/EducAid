<?php
// admin_sidebar.php — flat for sub_admin, dropbar for super_admin

include_once __DIR__ . '/../permissions.php';
include_once __DIR__ . '/../workflow_control.php';

$admin_role = 'super_admin'; // fallback
$workflow_status = ['can_schedule' => false, 'can_scan_qr' => false];

if (isset($_SESSION['admin_id'])) {
    include_once __DIR__ . '/../../config/database.php';
    $admin_role = getCurrentAdminRole($connection);
    $workflow_status = getWorkflowStatus($connection);
}

$current = basename($_SERVER['PHP_SELF']);
$canSchedule = (bool)($workflow_status['can_schedule'] ?? false);
$canScanQR   = (bool)($workflow_status['can_scan_qr'] ?? false);

/** Helpers */
function is_active(string $file, string $current): string {
    return $current === $file ? 'active' : '';
}
function menu_link(string $href, string $icon, string $label, string $activeClass = '', ?array $badge = null, bool $disabled = false, string $lockedMsg = ''): string {
    $safeMsg = htmlspecialchars($lockedMsg, ENT_QUOTES);
    $aClass  = $disabled ? ' class="text-muted"' : '';
    $aHref   = $disabled ? '#' : $href;
    $aOnclk  = $disabled ? " onclick=\"alert('{$safeMsg}'); return false;\"" : '';

    $html  = '<li class="nav-item ' . ($disabled ? 'disabled ' : '') . $activeClass . '">';
    $html .=   '<a href="' . $aHref . '"' . $aOnclk . $aClass . '>';
    $html .=     '<i class="' . $icon . ' icon"></i>';
    $html .=     '<span class="links_name">' . $label . '</span>';
    if ($badge && !empty($badge['text']) && !empty($badge['class'])) {
        $html .= '<span class="badge ' . $badge['class'] . ' ms-2">' . $badge['text'] . '</span>';
    }
    $html .=   '</a>';
    $html .= '</li>';
    return $html;
}

/** Submenu membership for “System Controls” (super_admin) */
$sysControlsFiles = [
    'manage_slots.php',
    'verify_students.php',
    'manage_schedules.php',
    'scan_qr.php',
    'manage_distributions.php',
    'blacklist_archive.php',
    'admin_management.php',
    'system_data.php',
    'settings.php',
];
$isSysControlsActive = in_array($current, $sysControlsFiles, true);
?>

<!-- admin_sidebar.php -->
<div class="sidebar" id="sidebar">
  <div class="logo-details">
    <i class="bi bi-person-gear icon"></i>
    <span class="logo_name">Admin</span>
  </div>

  <ul class="nav-list">

    <!-- Dashboard -->
    <?= menu_link('homepage.php', 'bi bi-house-door', 'Dashboard', is_active('homepage.php', $current)); ?>

    <!-- Review Registrations -->
    <?= menu_link('review_registrations.php', 'bi bi-clipboard-check', 'Review Registrations', is_active('review_registrations.php', $current)); ?>

    <!-- Manage Applicants -->
    <?= menu_link('manage_applicants.php', 'bi bi-people', 'Manage Applicants', is_active('manage_applicants.php', $current)); ?>

    <!-- Validate Grades -->
    <?php if ($admin_role === 'super_admin'): ?>
      <?= menu_link('validate_grades.php', 'bi bi-file-earmark-check', 'Validate Grades', is_active('validate_grades.php', $current)); ?>
    <?php endif; ?>

    <!-- System Controls (super_admin only) -->
    <?php if ($admin_role === 'super_admin'): ?>
      <li class="nav-item dropdown <?= $isSysControlsActive ? 'active' : '' ?>">
        <a href="#submenu-sys" data-bs-toggle="collapse" class="dropdown-toggle">
          <i class="bi bi-gear-wide-connected icon"></i>
          <span class="links_name">System Controls</span>
          <i class="bi bi-chevron-down ms-auto small"></i>
        </a>

        <ul class="collapse list-unstyled ms-3 <?= $isSysControlsActive ? 'show' : '' ?>" id="submenu-sys">
          <li>
            <a class="submenu-link <?= is_active('manage_slots.php', $current) ? 'active' : '' ?>" href="manage_slots.php">
              <i class="bi bi-sliders me-2"></i> Signup Slots
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('verify_students.php', $current) ? 'active' : '' ?>" href="verify_students.php">
              <i class="bi bi-person-check me-2"></i> Verify Students
            </a>
          </li>
          <li>
            <?php if ($canSchedule): ?>
              <a class="submenu-link <?= is_active('manage_schedules.php', $current) ? 'active' : '' ?>" href="manage_schedules.php">
                <i class="bi bi-calendar me-2"></i> Scheduling
                <span class="badge bg-info ms-2">Ready</span>
              </a>
            <?php else: ?>
              <a class="submenu-link text-muted" href="#"
                 onclick="alert('Please generate payroll numbers and QR codes first before scheduling.'); return false;">
                <i class="bi bi-calendar me-2"></i> Scheduling
                <span class="badge bg-secondary ms-2">Locked</span>
              </a>
            <?php endif; ?>
          </li>
          <li>
            <?php if ($canScanQR): ?>
              <a class="submenu-link <?= is_active('scan_qr.php', $current) ? 'active' : '' ?>" href="scan_qr.php">
                <i class="bi bi-qr-code-scan me-2"></i> Scan QR
                <span class="badge bg-success ms-2">Ready</span>
              </a>
            <?php else: ?>
              <a class="submenu-link text-muted" href="#"
                 onclick="alert('Please generate payroll numbers and QR codes first before scanning.'); return false;">
                <i class="bi bi-qr-code-scan me-2"></i> Scan QR
                <span class="badge bg-secondary ms-2">Locked</span>
              </a>
            <?php endif; ?>
          </li>
          <li>
            <a class="submenu-link <?= is_active('manage_distributions.php', $current) ? 'active' : '' ?>" href="manage_distributions.php">
              <i class="bi bi-box-seam me-2"></i> Manage Distributions
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('blacklist_archive.php', $current) ? 'active' : '' ?>" href="blacklist_archive.php">
              <i class="bi bi-person-x-fill me-2"></i> Blacklist Archive
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('admin_management.php', $current) ? 'active' : '' ?>" href="admin_management.php">
              <i class="bi bi-people-fill me-2"></i> Admin Management
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('system_data.php', $current) ? 'active' : '' ?>" href="system_data.php">
              <i class="bi bi-database me-2"></i> System Data
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('settings.php', $current) ? 'active' : '' ?>" href="settings.php">
              <i class="bi bi-gear me-2"></i> Settings
            </a>
          </li>
        </ul>
      </li>
    <?php endif; ?>

    <!-- Announcements -->
    <?php if ($admin_role === 'super_admin'): ?>
      <?= menu_link('manage_announcements.php', 'bi bi-megaphone', 'Announcements', is_active('manage_announcements.php', $current)); ?>
    <?php endif; ?>

    <!-- Notifications -->
    <?= menu_link('admin_notifications.php', 'bi bi-bell', 'Notifications', is_active('admin_notifications.php', $current)); ?>

    <!-- Logout -->
    <li class="nav-item logout">
      <a href="logout.php" onclick="return confirmLogout();">
        <i class="bi bi-box-arrow-right icon"></i>
        <span class="links_name">Logout</span>
      </a>
    </li>
  </ul>
</div>

<div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

<script>
function confirmLogout() {
  return confirm('Are you sure you want to logout?\n\nThis will clear all your session data and return you to the login page.');
}
</script>

<style>
/* Optional styling tweaks for submenu */
.sidebar .dropdown > a { display:flex; align-items:center; gap:.5rem; }
.sidebar .submenu-link { display:flex; align-items:center; padding:.5rem .75rem; border-radius:.6rem; }
.sidebar .submenu-link.active { background: rgba(40, 167, 69, .15); font-weight: 600; }
.sidebar .submenu-link .bi { width: 1.1rem; text-align:center; }
.sidebar .dropdown.active > a { background: rgba(40, 167, 69, .15); border-radius:.8rem; }
</style>
