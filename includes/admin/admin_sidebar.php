<?php
// admin_sidebar.php — flat for sub_admin, dropbar for super_admin
// Prevent duplicate inclusion (which caused function redeclare fatal error)
if (defined('ADMIN_SIDEBAR_LOADED')) {
  return; // Stop if sidebar already included
}
define('ADMIN_SIDEBAR_LOADED', true);

include_once __DIR__ . '/../permissions.php';
include_once __DIR__ . '/../workflow_control.php';

$admin_role = 'super_admin'; // fallback
$admin_name = 'Administrator';
$workflow_status = ['can_schedule' => false, 'can_scan_qr' => false];

if (isset($_SESSION['admin_id'])) {
    include_once __DIR__ . '/../../config/database.php';
    $admin_role = getCurrentAdminRole($connection);
    $workflow_status = getWorkflowStatus($connection);
    // Fetch admin name (compose from first + last) – no full_name column assumed
    $nameRes = pg_query_params(
        $connection,
        "SELECT TRIM(BOTH FROM CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS display_name FROM admins WHERE admin_id = $1 LIMIT 1",
        [$_SESSION['admin_id']]
    );
    if ($nameRes && ($nameRow = pg_fetch_assoc($nameRes))) {
        $candidate = trim($nameRow['display_name'] ?? '');
        if ($candidate !== '') { $admin_name = $candidate; }
    } elseif (!empty($_SESSION['admin_username'])) {
        $admin_name = $_SESSION['admin_username'];
    }
    
  // Fetch theme settings for sidebar colors if table exists
  $sidebarThemeSettings = [];
  $tableExists = pg_query($connection, "SELECT 1 FROM information_schema.tables WHERE table_name='sidebar_theme_settings' LIMIT 1");
  if ($tableExists && pg_fetch_row($tableExists)) {
    $sidebarThemeQuery = pg_query_params($connection, "SELECT * FROM sidebar_theme_settings WHERE municipality_id = $1 LIMIT 1", [1]);
    if ($sidebarThemeQuery && ($sidebarThemeRow = pg_fetch_assoc($sidebarThemeQuery))) {
      $sidebarThemeSettings = $sidebarThemeRow;
    }
  }
}

$role_label = match($admin_role) {
  'super_admin' => 'Super Admin',
  'sub_admin', 'admin' => 'Administrator',
  default => ucfirst(str_replace('_',' ', $admin_role))
};

$current = basename($_SERVER['PHP_SELF']);
$canSchedule = (bool)($workflow_status['can_schedule'] ?? false);
$canScanQR   = (bool)($workflow_status['can_scan_qr'] ?? false);
$canManageApplicants = (bool)($workflow_status['can_manage_applicants'] ?? false);
$canVerifyStudents = (bool)($workflow_status['can_verify_students'] ?? false);
$canManageSlots = (bool)($workflow_status['can_manage_slots'] ?? false);
$canManageDistributions = (bool)($workflow_status['can_manage_applicants'] ?? false); // Same as manage applicants
$canEndDistribution = (bool)($workflow_status['can_manage_applicants'] ?? false); // Same as manage applicants

/** Helpers */
if (!function_exists('is_active')) {
  function is_active(string $file, string $current): string {
    return $current === $file ? 'active' : '';
  }
}
if (!function_exists('menu_link')) {
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
}

/** Submenu membership for "Distribution Management" (super_admin) */
$distributionFiles = [
    'distribution_control.php',
    'manage_slots.php',
    'verify_students.php',
    'manage_schedules.php',
    'scan_qr.php',
    'manage_distributions.php',
    'end_distribution.php',
    'distribution_archives.php',
    'storage_dashboard.php',
    'reset_distribution.php',
];
$isDistributionActive = in_array($current, $distributionFiles, true);

/** Submenu membership for "System Controls" (super_admin) */
$sysControlsFiles = [
    'blacklist_archive.php',
    'archived_students.php',
    'run_automatic_archiving_admin.php',
    'document_archives.php',
    'admin_management.php',
    'municipality_content.php',
    'system_data.php',
    'settings.php',
    'topbar_settings.php',
    'sidebar_settings.php',
    'footer_settings.php',
];
$isSysControlsActive = in_array($current, $sysControlsFiles, true);
?>

<!-- admin_sidebar.php -->
<div class="sidebar admin-sidebar" id="sidebar">
  <div class="sidebar-profile" role="region" aria-label="Signed in user">
    <div class="avatar-circle" aria-hidden="true" title="<?= htmlspecialchars($admin_name) ?>">
      <?php $initials = strtoupper(mb_substr($admin_name,0,1)); echo htmlspecialchars($initials); ?>
    </div>
    <div class="profile-text">
      <span class="name" title="<?= htmlspecialchars($admin_name) ?>"><?= htmlspecialchars($admin_name) ?></span>
      <span class="role" title="<?= htmlspecialchars($role_label) ?>"><?= htmlspecialchars($role_label) ?></span>
    </div>
  </div>

  <ul class="nav-list flex-grow-1 d-flex flex-column">

    <!-- Dashboard -->
    <?= menu_link('homepage.php', 'bi bi-house-door', 'Dashboard', is_active('homepage.php', $current)); ?>

    <!-- Review Registrations -->
    <?= menu_link('review_registrations.php', 'bi bi-clipboard-check', 'Review Registrations', is_active('review_registrations.php', $current)); ?>

    <!-- Manage Applicants -->
    <?= menu_link('manage_applicants.php', 'bi bi-people', 'Manage Applicants', is_active('manage_applicants.php', $current)); ?>

    <!-- My Profile -->
    <?= menu_link('admin_profile.php', 'bi bi-person-circle', 'My Profile', is_active('admin_profile.php', $current)); ?>

    <!-- Distribution Management (super_admin only) -->
    <?php if ($admin_role === 'super_admin'): ?>
      <li class="nav-item dropdown">
        <a href="#submenu-distribution" data-bs-toggle="collapse" class="dropdown-toggle">
          <i class="bi bi-box-seam icon"></i>
          <span class="links_name">Distribution</span>
          <i class="bi bi-chevron-down ms-auto small"></i>
        </a>

        <ul class="collapse list-unstyled ms-3 <?= $isDistributionActive ? 'show' : '' ?>" id="submenu-distribution">
          <li>
            <a class="submenu-link <?= is_active('distribution_control.php', $current) ? 'active' : '' ?>" href="distribution_control.php">
              <i class="bi bi-gear-fill me-2"></i> Distribution Control
            </a>
          </li>
          <li>
            <?php if ($canManageSlots): ?>
              <a class="submenu-link <?= is_active('manage_slots.php', $current) ? 'active' : '' ?>" href="manage_slots.php">
                <i class="bi bi-sliders me-2"></i> Signup Slots
                <span class="badge bg-info ms-2">Ready</span>
              </a>
            <?php else: ?>
              <a class="submenu-link text-muted" href="#"
                 onclick="alert('Please start a distribution first before managing slots.'); return false;">
                <i class="bi bi-sliders me-2"></i> Signup Slots
                <span class="badge bg-secondary ms-2">Locked</span>
              </a>
            <?php endif; ?>
          </li>
          <li>
            <?php if ($canVerifyStudents): ?>
              <a class="submenu-link <?= is_active('verify_students.php', $current) ? 'active' : '' ?>" href="verify_students.php">
                <i class="bi bi-person-check me-2"></i> Verify Students
                <span class="badge bg-info ms-2">Ready</span>
              </a>
            <?php else: ?>
              <a class="submenu-link text-muted" href="#"
                 onclick="alert('Please start a distribution first before verifying students.'); return false;">
                <i class="bi bi-person-check me-2"></i> Verify Students
                <span class="badge bg-secondary ms-2">Locked</span>
              </a>
            <?php endif; ?>
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
          
          <!-- Divider -->
          <li><hr class="dropdown-divider my-2"></li>
          
          <li>
            <?php if ($canEndDistribution): ?>
              <a class="submenu-link <?= is_active('end_distribution.php', $current) ? 'active' : '' ?>" href="end_distribution.php">
                <i class="bi bi-stop-circle me-2"></i> End Distribution
                <span class="badge bg-info ms-2">Ready</span>
              </a>
            <?php else: ?>
              <a class="submenu-link text-muted" href="#"
                 onclick="alert('Please start a distribution first before ending distribution.'); return false;">
                <i class="bi bi-stop-circle me-2"></i> End Distribution
                <span class="badge bg-secondary ms-2">Locked</span>
              </a>
            <?php endif; ?>
          </li>
          <li>
            <a class="submenu-link <?= is_active('distribution_archives.php', $current) ? 'active' : '' ?>" href="distribution_archives.php">
              <i class="bi bi-archive me-2"></i> Distribution Archives
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('storage_dashboard.php', $current) ? 'active' : '' ?>" href="storage_dashboard.php">
              <i class="bi bi-hdd me-2"></i> Storage Dashboard
            </a>
          </li>
          
          <!-- Divider -->
          <li><hr class="dropdown-divider my-2"></li>
          
          <li>
            <a class="submenu-link <?= is_active('reset_distribution.php', $current) ? 'active' : '' ?>" href="reset_distribution.php">
              <i class="bi bi-arrow-counterclockwise me-2"></i> Reset Distribution
              <span class="badge bg-warning ms-2">DEV</span>
            </a>
          </li>
        </ul>
      </li>
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
            <a class="submenu-link <?= is_active('blacklist_archive.php', $current) ? 'active' : '' ?>" href="blacklist_archive.php">
              <i class="bi bi-person-x-fill me-2"></i> Blacklist Archive
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('archived_students.php', $current) ? 'active' : '' ?>" href="archived_students.php">
              <i class="bi bi-archive-fill me-2"></i> Archived Students
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('run_automatic_archiving_admin.php', $current) ? 'active' : '' ?>" href="run_automatic_archiving_admin.php">
              <i class="bi bi-clock-history me-2"></i> Run Auto-Archiving
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('document_archives.php', $current) ? 'active' : '' ?>" href="document_archives.php">
              <i class="bi bi-archive me-2"></i> Document Archives
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('admin_management.php', $current) ? 'active' : '' ?>" href="admin_management.php">
              <i class="bi bi-people-fill me-2"></i> Admin Management
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('municipality_content.php', $current) ? 'active' : '' ?>" href="municipality_content.php">
              <i class="bi bi-geo-alt me-2"></i> Municipalities
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
          <li>
            <a class="submenu-link <?= is_active('topbar_settings.php', $current) ? 'active' : '' ?>" href="topbar_settings.php">
              <i class="bi bi-layout-text-window-reverse me-2"></i> Topbar Settings
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('sidebar_settings.php', $current) ? 'active' : '' ?>" href="sidebar_settings.php">
              <i class="bi bi-layout-sidebar me-2"></i> Sidebar Settings
            </a>
          </li>
          <li>
            <a class="submenu-link <?= is_active('footer_settings.php', $current) ? 'active' : '' ?>" href="footer_settings.php">
              <i class="bi bi-layout-text-sidebar-reverse me-2"></i> Footer Settings
            </a>
          </li>
        </ul>
      </li>
    <?php endif; ?>

    <!-- Announcements -->
    <?php if ($admin_role === 'super_admin'): ?>
      <?= menu_link('manage_announcements.php', 'bi bi-megaphone', 'Announcements', is_active('manage_announcements.php', $current)); ?>
    <?php endif; ?>

    <!-- Audit Trail (super_admin only) -->
    <?php if ($admin_role === 'super_admin'): ?>
      <?= menu_link('audit_logs.php', 'bi bi-shield-lock-fill', 'Audit Trail', is_active('audit_logs.php', $current)); ?>
    <?php endif; ?>

  

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
<?php
// Dynamic sidebar theming using dedicated sidebar theme settings
$sidebarBgStart = $sidebarThemeSettings['sidebar_bg_start'] ?? '#f8f9fa';
$sidebarBgEnd = $sidebarThemeSettings['sidebar_bg_end'] ?? '#ffffff';
$sidebarBorder = $sidebarThemeSettings['sidebar_border_color'] ?? '#dee2e6';
$navTextColor = $sidebarThemeSettings['nav_text_color'] ?? '#212529';
$navIconColor = $sidebarThemeSettings['nav_icon_color'] ?? '#6c757d';
$navHoverBg = $sidebarThemeSettings['nav_hover_bg'] ?? '#e9ecef';
$navHoverText = $sidebarThemeSettings['nav_hover_text'] ?? '#212529';
$navActiveBg = $sidebarThemeSettings['nav_active_bg'] ?? '#0d6efd';
$navActiveText = $sidebarThemeSettings['nav_active_text'] ?? '#ffffff';
$profileAvatarStart = $sidebarThemeSettings['profile_avatar_bg_start'] ?? '#0d6efd';
$profileAvatarEnd = $sidebarThemeSettings['profile_avatar_bg_end'] ?? '#0b5ed7';
$profileNameColor = $sidebarThemeSettings['profile_name_color'] ?? '#212529';
$profileRoleColor = $sidebarThemeSettings['profile_role_color'] ?? '#6c757d';
$profileBorderColor = $sidebarThemeSettings['profile_border_color'] ?? '#dee2e6';
$submenuBg = $sidebarThemeSettings['submenu_bg'] ?? '#f8f9fa';
$submenuTextColor = $sidebarThemeSettings['submenu_text_color'] ?? '#495057';
$submenuHoverBg = $sidebarThemeSettings['submenu_hover_bg'] ?? '#e9ecef';
$submenuActiveBg = $sidebarThemeSettings['submenu_active_bg'] ?? '#e7f3ff';
$submenuActiveText = $sidebarThemeSettings['submenu_active_text'] ?? '#0d6efd';

// Function to adjust color opacity for subtle effects
function adjustColorOpacity($color, $opacity = 0.3) {
    $color = str_replace('#', '', $color);
    if (strlen($color) === 3) {
        $color = $color[0].$color[0].$color[1].$color[1].$color[2].$color[2];
    }
    $r = hexdec(substr($color, 0, 2));
    $g = hexdec(substr($color, 2, 2));
    $b = hexdec(substr($color, 4, 2));
    return "rgba($r, $g, $b, $opacity)";
}
?>
.admin-sidebar {
    background: linear-gradient(180deg, <?= htmlspecialchars($sidebarBgStart) ?> 0%, <?= htmlspecialchars($sidebarBgEnd) ?> 100%);
    border-right: 1px solid <?= htmlspecialchars($sidebarBorder) ?>;
}
.admin-sidebar .nav-item a {
    border-radius: 10px;
    margin: 2px 12px;
    padding: 10px 14px;
    font-size: .9rem;
    font-weight: 500;
    color: <?= htmlspecialchars($navTextColor) ?>;
}
.admin-sidebar .nav-item a .icon {
    color: <?= htmlspecialchars($navIconColor) ?>;
    transition: .2s;
    font-size: 1.1rem;
}
.admin-sidebar .nav-item a:hover {
    background: <?= htmlspecialchars($navHoverBg) ?>;
    color: <?= htmlspecialchars($navHoverText) ?>;
}
.admin-sidebar .nav-item a:hover .icon {
    color: <?= htmlspecialchars($navHoverText) ?>;
}
.admin-sidebar .nav-item.active > a {
    background: <?= htmlspecialchars($navActiveBg) ?>;
    color: <?= htmlspecialchars($navActiveText) ?>;
    box-shadow: 0 2px 4px rgba(0,0,0,.15);
}
.admin-sidebar .nav-item.active > a .icon {
    color: <?= htmlspecialchars($navActiveText) ?>;
}
.admin-sidebar .nav-item.active > a::before {
    background: <?= htmlspecialchars($navActiveBg) ?>;
}
.admin-sidebar .dropdown > a {
    display: flex;
    align-items: center;
    gap: .55rem;
    margin: 4px 12px;
    padding: 10px 14px;
    border-radius: 10px;
}
.admin-sidebar .submenu-link {
    display: flex;
    align-items: center;
    padding: .4rem .75rem .4rem 2.1rem;
    margin: 2px 0;
    border-radius: 8px;
    font-size: .8rem;
    color: <?= htmlspecialchars($submenuTextColor) ?>;
}
.admin-sidebar .submenu-link.active {
    background: <?= htmlspecialchars($submenuActiveBg) ?>;
    font-weight: 600;
    color: <?= htmlspecialchars($submenuActiveText) ?>;
}
.admin-sidebar .submenu-link:hover {
    background: <?= htmlspecialchars($submenuHoverBg) ?>;
    color: <?= htmlspecialchars($submenuTextColor) ?>;
}
.admin-sidebar .submenu-link .bi {
    width: 1.05rem;
    text-align: center;
    font-size: .9rem;
}
.admin-sidebar .nav-item.logout a.logout-link {
    background: #ffebee;
    color: #c62828;
    border: 1px solid #ffcdd2;
    margin: 4px 12px 6px;
    padding: 10px 14px;
    border-radius: 10px;
    font-weight: 600;
    display: flex;
    align-items: center;
}
.admin-sidebar .nav-item.logout a.logout-link:hover {
    background: #ffcdd2;
    color: #b71c1c;
}
@media (max-width:768px) {
    .admin-sidebar .nav-item a { margin: 2px 8px; }
    .admin-sidebar .dropdown > a { margin: 4px 8px; }
    .admin-sidebar .nav-item.logout a.logout-link { margin: 6px 8px 8px; }
}
/* Collapse behavior for submenus when sidebar collapsed */
.admin-sidebar.close #submenu-sys,
.admin-sidebar.close #submenu-distribution { 
    display: none !important; 
}
.admin-sidebar.close .dropdown > a { 
    background: transparent; 
}
.admin-sidebar.close .dropdown > a .bi-chevron-down { 
    display: none; 
}
/* Profile block */
.admin-sidebar .sidebar-profile {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: 0 1rem 1rem 1rem;
    margin-bottom: .35rem;
    border-bottom: 1px solid <?= adjustColorOpacity($profileBorderColor, 0.4) ?>;
}
.admin-sidebar.close .sidebar-profile .profile-text { display: none; }
.admin-sidebar .sidebar-profile .avatar-circle {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, <?= htmlspecialchars($profileAvatarStart) ?>, <?= htmlspecialchars($profileAvatarEnd) ?>);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1rem;
    box-shadow: 0 2px 4px rgba(0,0,0,.15);
}
.admin-sidebar .sidebar-profile .profile-text {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
    min-width: 0;
}
.admin-sidebar .sidebar-profile .profile-text .name {
    font-size: .9rem;
    font-weight: 600;
    color: <?= htmlspecialchars($profileNameColor) ?>;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 140px;
}
.admin-sidebar .sidebar-profile .profile-text .role {
    font-size: .6rem;
    letter-spacing: .75px;
    text-transform: uppercase;
    color: <?= htmlspecialchars($profileRoleColor) ?>;
    font-weight: 600;
    opacity: .85;
}
</style>

<script>
// Auto-hide dropdowns when sidebar collapses; restore if active when expanded
document.addEventListener('DOMContentLoaded', function(){
  const sidebar = document.getElementById('sidebar');
  const sysMenu = document.getElementById('submenu-sys');
  const distMenu = document.getElementById('submenu-distribution');
  if(!sidebar) return;
  
  function hasActiveChild(menu){
    if(!menu) return false;
    return !!menu.querySelector('.submenu-link.active');
  }
  
  function syncMenus(){
    if(sidebar.classList.contains('close')){
      // Hide all submenus when collapsed
      if(sysMenu) sysMenu.classList.remove('show');
      if(distMenu) distMenu.classList.remove('show');
    } else {
      // Show submenus with active children when expanded
      if(sysMenu && hasActiveChild(sysMenu) && !sysMenu.classList.contains('show')) {
        sysMenu.classList.add('show');
      }
      if(distMenu && hasActiveChild(distMenu) && !distMenu.classList.contains('show')) {
        distMenu.classList.add('show');
      }
    }
  }
  
  // Observe class changes (JS animation toggles .close)
  const observer = new MutationObserver(syncMenus);
  observer.observe(sidebar,{attributes:true, attributeFilter:['class']});
  syncMenus();
});
</script>

</style>
<!-- No longer needed - Edit Landing Page moved to Content Areas -->
<script>
// Legacy modal functionality removed
// Content editing now handled through Municipality Content Hub
</script>
