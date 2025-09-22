<?php
// Student Header Component
// Reusable header with sidebar toggle, notifications, and profile dropdown
// Requires: $student_info array with first_name and last_name
?>

<!-- Main Header -->
<div class="main-header">
  <div class="container-fluid px-4">
    <div class="header-content">
      <div class="header-left d-flex align-items-center">
        <div class="sidebar-toggle me-2">
          <i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i>
        </div>
      </div>
      <div class="header-actions">
        <button class="notification-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-bell"></i>
          <span class="badge rounded-pill bg-danger">3</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><h6 class="dropdown-header">Notifications</h6></li>
          <li><a class="dropdown-item" href="#"><i class="bi bi-info-circle me-2"></i>New announcement available</a></li>
          <li><a class="dropdown-item" href="#"><i class="bi bi-upload me-2"></i>Document review completed</a></li>
          <li><a class="dropdown-item" href="#"><i class="bi bi-calendar me-2"></i>Deadline reminder</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-center" href="#">View all notifications</a></li>
        </ul>
        
        <div class="profile-dropdown">
          <button class="profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-circle"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><h6 class="dropdown-header"><?php echo htmlspecialchars($student_info['first_name'] . ' ' . $student_info['last_name']); ?></h6></li>
            <li><a class="dropdown-item" href="student_profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
            <li><a class="dropdown-item" href="student_settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="../../unified_login.php?logout=true"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
/* Main header styles */
.main-header {
  background: white;
  border-bottom: 1px solid #e9ecef;
  box-shadow: 0 2px 4px rgba(0,0,0,0.08);
  padding: 0.5rem 0;
  z-index: 1030;
  position: relative;
}
.main-header .header-content {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.main-header .container-fluid { padding-left: 1rem; padding-right: 1rem; }
.main-header .header-actions {
  display: flex;
  align-items: center;
  gap: 1rem;
}
.notification-btn, .profile-btn {
  background: none;
  border: 1px solid #dee2e6;
  border-radius: 8px;
  padding: 0.5rem;
  color: #6c757d;
  transition: all 0.2s;
  position: relative;
}
.notification-btn .bi, .profile-btn .bi { font-size: 1rem; }
.notification-btn:hover, .profile-btn:hover {
  background: #f8f9fa;
  border-color: #0068da;
  color: #0068da;
}
.notification-btn .badge {
  position: absolute;
  top: -5px;
  right: -8px;
}
.profile-dropdown {
  position: relative;
}
/* Sidebar toggle inside header */
.header-left .sidebar-toggle { padding: 0.25rem 0.5rem; }
</style>