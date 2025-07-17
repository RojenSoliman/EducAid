<!-- Sidebar -->
<div class="sidebar close" id="sidebar">
  <div class="logo-details">
    <i class="bi bi-mortarboard-fill icon"></i>
    <span class="logo_name">EducAid</span>
  </div>
  <ul class="nav-list">
    <li class="nav-item <?php echo ($_SERVER['PHP_SELF'] == 'modules/student/student_homepage.php') ? 'active' : ''; ?>">
      <a href="student_homepage.php">
        <i class="bi bi-house-door icon"></i>
        <span class="links_name">Dashboard</span>
      </a>
    </li>
    <li class="nav-item <?php echo ($_SERVER['PHP_SELF'] == 'upload_document.php') ? 'active' : ''; ?>">
      <a href="upload_document.php">
        <i class="bi bi-upload icon"></i>
        <span class="links_name">Upload Documents</span>
      </a>
    </li>
    <li class="nav-item <?php echo ($_SERVER['PHP_SELF'] == '/path/to/qr_code_page.php') ? 'active' : ''; ?>">
      <a href="#">
        <i class="bi bi-qr-code-scan icon"></i>
        <span class="links_name">My QR Code</span>
      </a>
    </li>
    <li class="nav-item <?php echo ($_SERVER['PHP_SELF'] == '/path/to/notification_page.php') ? 'active' : ''; ?>">
      <a href="notificaton.html">
        <i class="bi bi-bell icon"></i>
        <span class="links_name">Notifications</span>
      </a>
    </li>
    <li class="nav-item <?php echo ($_SERVER['PHP_SELF'] == '/path/to/profile_page.php') ? 'active' : ''; ?>">
      <a href="#">
        <i class="bi bi-person icon"></i>
        <span class="links_name">Profile</span>
      </a>
    </li>
    <li class="nav-item logout">
      <a href="#" onclick="confirmLogout(event)">
        <i class="bi bi-box-arrow-right icon"></i>
        <span class="links_name">Logout</span>
      </a>
    </li>
  </ul>
</div>
<div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>