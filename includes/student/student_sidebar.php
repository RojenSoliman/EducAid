<?php
// student_sidebar.php — Modern sidebar design matching admin interface
// Prevent duplicate inclusion
if (defined('STUDENT_SIDEBAR_LOADED')) {
  return;
}
define('STUDENT_SIDEBAR_LOADED', true);

$student_name = 'Student';
$student_role = 'Student';

// Check if student needs upload documents tab
$needs_upload_tab = false;

if (isset($_SESSION['student_id'])) {
    include_once __DIR__ . '/../../config/database.php';
    include_once __DIR__ . '/../workflow_control.php';
    
    // Check if distribution is active and uploads are enabled
    $workflow = getWorkflowStatus($connection);
    $distribution_status = $workflow['distribution_status'] ?? 'inactive';
    $uploads_enabled = $workflow['uploads_enabled'] ?? false;
    
    // Only show upload tab if distribution is preparing or active AND uploads are enabled
    $show_uploads = in_array($distribution_status, ['preparing', 'active']) && $uploads_enabled;
    
    // Fetch student name and registration date
    $studentRes = pg_query_params(
        $connection,
  "SELECT TRIM(BOTH FROM CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS display_name, 
    application_date
         FROM students WHERE student_id = $1 LIMIT 1",
        [$_SESSION['student_id']]
    );
    
    if ($studentRes && ($studentRow = pg_fetch_assoc($studentRes))) {
        $candidate = trim($studentRow['display_name'] ?? '');
        if ($candidate !== '') { $student_name = $candidate; }
        
        // Show upload tab only when distribution is active
        $needs_upload_tab = $show_uploads;
    } elseif (!empty($_SESSION['student_username'])) {
        $student_name = $_SESSION['student_username'];
        // For fallback case, check distribution status
        $needs_upload_tab = $show_uploads;
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

$current = basename($_SERVER['PHP_SELF']);

/** Helpers */
if (!function_exists('is_active_student')) {
  function is_active_student(string $file, string $current): string {
    return $current === $file ? 'active' : '';
  }
}
if (!function_exists('student_menu_link')) {
  function student_menu_link(string $href, string $icon, string $label, string $activeClass = '', ?array $badge = null): string {
    $html  = '<li class="nav-item ' . $activeClass . '">';
    $html .=   '<a href="' . $href . '">';
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
?>

<!-- student_sidebar.php -->
<div class="sidebar student-sidebar" id="sidebar">
  <div class="sidebar-profile" role="region" aria-label="Signed in user">
    <div class="avatar-circle" aria-hidden="true" title="<?= htmlspecialchars($student_name) ?>">
      <?php $initials = strtoupper(mb_substr($student_name,0,1)); echo htmlspecialchars($initials); ?>
    </div>
    <div class="profile-text">
      <span class="name" title="<?= htmlspecialchars($student_name) ?>"><?= htmlspecialchars($student_name) ?></span>
      <span class="role" title="<?= htmlspecialchars($student_role) ?>"><?= htmlspecialchars($student_role) ?></span>
    </div>
  </div>

  <ul class="nav-list flex-grow-1 d-flex flex-column">
    <!-- Dashboard -->
    <?= student_menu_link('student_homepage.php', 'bi bi-house-door', 'Dashboard', is_active_student('student_homepage.php', $current)); ?>

    <!-- Upload Documents (only show if student needs to upload documents) -->
    <?php if ($needs_upload_tab): ?>
    <?= student_menu_link('upload_document.php', 'bi bi-upload', 'Upload Documents', is_active_student('upload_document.php', $current)); ?>
    <?php endif; ?>

    <!-- My QR Code -->
    <?= student_menu_link('qr_code.php', 'bi bi-qr-code-scan', 'My QR Code', is_active_student('qr_code.php', $current)); ?>

    <!-- Notifications -->
    <?= student_menu_link('student_notifications.php', 'bi bi-bell', 'Notifications', is_active_student('student_notifications.php', $current)); ?>

  

  

    <!-- Profile -->
    <?= student_menu_link('student_profile.php', 'bi bi-person-circle', 'Profile', is_active_student('student_profile.php', $current)); ?>

    <!-- Filler flex spacer -->
    <li class="mt-auto p-0 m-0"></li>

    <!-- Logout at bottom -->
    <li class="nav-item logout mt-2 pt-1">
      <a href="../../unified_login.php?logout=true" onclick="return confirmLogout();" class="logout-link">
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
.student-sidebar {
    background: linear-gradient(180deg, <?= htmlspecialchars($sidebarBgStart) ?> 0%, <?= htmlspecialchars($sidebarBgEnd) ?> 100%);
    border-right: 1px solid <?= htmlspecialchars($sidebarBorder) ?>;
}
.student-sidebar .nav-item a {
    border-radius: 10px;
    margin: 2px 12px;
    padding: 10px 14px;
    font-size: .9rem;
    font-weight: 500;
    color: <?= htmlspecialchars($navTextColor) ?>;
}
.student-sidebar .nav-item a .icon {
    color: <?= htmlspecialchars($navIconColor) ?>;
    transition: .2s;
    font-size: 1.1rem;
}
.student-sidebar .nav-item a:hover {
    background: <?= htmlspecialchars($navHoverBg) ?>;
    color: <?= htmlspecialchars($navHoverText) ?>;
}
.student-sidebar .nav-item a:hover .icon {
    color: <?= htmlspecialchars($navHoverText) ?>;
}
.student-sidebar .nav-item.active > a {
    background: <?= htmlspecialchars($navActiveBg) ?>;
    color: <?= htmlspecialchars($navActiveText) ?>;
    box-shadow: 0 2px 4px rgba(0,0,0,.15);
}
.student-sidebar .nav-item.active > a .icon {
    color: <?= htmlspecialchars($navActiveText) ?>;
}
.student-sidebar .nav-item.active > a::before {
    background: <?= htmlspecialchars($navActiveBg) ?>;
}
.student-sidebar .nav-item.logout a.logout-link {
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
.student-sidebar .nav-item.logout a.logout-link:hover {
    background: #ffcdd2;
    color: #b71c1c;
}
@media (max-width:768px) {
    .student-sidebar .nav-item a { margin: 2px 8px; }
    .student-sidebar .nav-item.logout a.logout-link { margin: 6px 8px 8px; }
}
/* Profile block */
.student-sidebar .sidebar-profile {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: 0 1rem 1rem 1rem;
    margin-bottom: .35rem;
    border-bottom: 1px solid <?= adjustColorOpacity($profileBorderColor, 0.4) ?>;
}
.student-sidebar.close .sidebar-profile .profile-text { display: none; }
.student-sidebar .sidebar-profile .avatar-circle {
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
.student-sidebar .sidebar-profile .profile-text {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
    min-width: 0;
}
.student-sidebar .sidebar-profile .profile-text .name {
    font-size: .9rem;
    font-weight: 600;
    color: <?= htmlspecialchars($profileNameColor) ?>;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 140px;
}
.student-sidebar .sidebar-profile .profile-text .role {
    font-size: .6rem;
    letter-spacing: .75px;
    text-transform: uppercase;
    color: <?= htmlspecialchars($profileRoleColor) ?>;
    font-weight: 600;
    opacity: .85;
}
</style>