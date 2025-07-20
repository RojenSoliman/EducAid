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
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage_applicants.php' ? 'active' : ''; ?>">
      <a href="manage_applicants.php">
        <i class="bi bi-people icon"></i>
        <span class="links_name">Manage Applicants</span>
      </a>
    </li>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'verify_students.php' ? 'active' : ''; ?>">
      <a href="verify_students.php">
        <i class="bi bi-person-check icon"></i>
        <span class="links_name">Verify Students</span>
      </a>
    </li>
    <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage_schedules.php' ? 'active' : ''; ?>">
      <a href="manage_schedules.php">
        <i class="bi bi-calendar icon"></i>
        <span class="links_name">Scheduling</span>
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
