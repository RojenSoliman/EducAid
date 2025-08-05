<?php
// student_sidebar.php
?>
<div class="sidebar" id="sidebar">
  <div class="logo-details">
    <i class="bi bi-mortarboard-fill icon"></i>
    <span class="logo_name">EducAid</span>
  </div>
  <ul class="nav-list">
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'student_homepage.php' ? 'active' : ''; ?>">
      <a href="student_homepage.php">
        <i class="bi bi-house-door icon"></i>
        <span class="links_name">Dashboard</span>
      </a>
    </li>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'upload_document.php' ? 'active' : ''; ?>">
      <a href="upload_document.php">
        <i class="bi bi-upload icon"></i>
        <span class="links_name">Upload Documents</span>
      </a>
    </li>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'qr_code.php' ? 'active' : ''; ?>">
      <a href="qr_code.php">
        <i class="bi bi-qr-code-scan icon"></i>
        <span class="links_name">My QR Code</span>
      </a>
    </li>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'student_notifications.php' ? 'active' : ''; ?>">
      <a href="student_notifications.php">
        <i class="bi bi-bell icon"></i>
        <span class="links_name">Notifications</span>
      </a>
    </li>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'student_profile.php' ? 'active' : ''; ?>">
      <a href="student_profile.php">
        <i class="bi bi-person icon"></i>
        <span class="links_name">Profile</span>
      </a>
    </li>
    <li class="nav-item logout">
      <a href="logout.php" onclick="return confirm('Are you sure you want to logout?');">
        <i class="bi bi-box-arrow-right icon"></i>
        <span class="links_name">Logout</span>
      </a>
    </li>
  </ul>
</div>
<div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>