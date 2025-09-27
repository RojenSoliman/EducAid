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
<div class="sidebar admin-sidebar" id="sidebar">
  <div class="logo-details">
    <i class="bi bi-speedometer2 icon" aria-hidden="true"></i>
    <span class="logo_name">Admin Panel</span>
  </div>

  <ul class="nav-list flex-grow-1 d-flex flex-column">

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
  <li class="nav-item dropdown">
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

    <!-- Filler flex spacer -->
    <li class="mt-auto p-0 m-0"></li>

    <!-- Logout at bottom -->
    <li class="nav-item logout mt-2 pt-1">
      <a href="logout.php" onclick="return confirmLogout();" class="logout-link">
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
.admin-sidebar {background:linear-gradient(180deg,#e8f5e9 0%,#ffffff 60%);border-right:1px solid #c8e6c9;}
.admin-sidebar .logo-details{padding:0 1rem 1rem 1rem;}
.admin-sidebar .logo-details .icon{color:#2e7d32;}
.admin-sidebar .logo-details .logo_name{color:#1b5e20;font-weight:600;}
.admin-sidebar .nav-list{padding-bottom:.75rem;}
.admin-sidebar .nav-item a{border-radius:10px;margin:2px 12px; padding:10px 14px; font-size:.9rem; font-weight:500;}
.admin-sidebar .nav-item a .icon{color:#2e7d32;transition:.2s;font-size:1.1rem;}
.admin-sidebar .nav-item a:hover{background:#c8e6c9;color:#1b5e20;}
.admin-sidebar .nav-item a:hover .icon{color:#1b5e20;}
.admin-sidebar .nav-item.active > a{background:#2e7d32;color:#fff;box-shadow:0 2px 4px rgba(0,0,0,.15);} 
.admin-sidebar .nav-item.active > a .icon{color:#fff;}
.admin-sidebar .nav-item.active > a::before{background:#66bb6a;}
.admin-sidebar .dropdown > a{display:flex;align-items:center;gap:.55rem;margin:4px 12px;padding:10px 14px;border-radius:10px;}
/* Removed parent highlight; parent stays neutral so only submenu item shows active state */
.admin-sidebar .submenu-link{display:flex;align-items:center;padding:.4rem .75rem .4rem 2.1rem;margin:2px 0;border-radius:8px;font-size:.8rem;}
.admin-sidebar .submenu-link.active{background:rgba(76,175,80,.18);font-weight:600;color:#1b5e20;}
.admin-sidebar .submenu-link:hover{background:rgba(129,199,132,.35);color:#1b5e20;}
.admin-sidebar .submenu-link .bi{width:1.05rem;text-align:center;font-size:.9rem;}
.admin-sidebar .nav-item.logout a.logout-link{background:#ffebee;color:#c62828;border:1px solid #ffcdd2;margin:4px 12px 6px;padding:10px 14px;border-radius:10px;font-weight:600;display:flex;align-items:center;}
.admin-sidebar .nav-item.logout a.logout-link:hover{background:#ffcdd2;color:#b71c1c;}
@media (max-width:768px){.admin-sidebar .nav-item a{margin:2px 8px;} .admin-sidebar .dropdown > a{margin:4px 8px;} .admin-sidebar .nav-item.logout a.logout-link{margin:6px 8px 8px;}}
/* Collapse behavior for system controls submenu when sidebar collapsed */
.admin-sidebar.close #submenu-sys {display:none !important;}
.admin-sidebar.close .dropdown > a {background:transparent;}
.admin-sidebar.close .dropdown > a .bi-chevron-down{display:none;}
</style>

<script>
// Auto-hide System Controls submenu when sidebar collapses; restore if active when expanded
document.addEventListener('DOMContentLoaded', function(){
  const sidebar = document.getElementById('sidebar');
  const sysMenu = document.getElementById('submenu-sys');
  if(!sidebar || !sysMenu) return;
  const parentLi = sysMenu.parentElement;
  function hasActiveChild(){
    return !!sysMenu.querySelector('.submenu-link.active');
  }
  function syncSysMenu(){
    if(sidebar.classList.contains('close')){
      sysMenu.classList.remove('show');
    } else if(hasActiveChild()) {
      if(!sysMenu.classList.contains('show')) sysMenu.classList.add('show');
    }
  }
  // Observe class changes (JS animation toggles .close)
  const observer = new MutationObserver(syncSysMenu);
  observer.observe(sidebar,{attributes:true, attributeFilter:['class']});
  syncSysMenu();
});
</script>

</style>
