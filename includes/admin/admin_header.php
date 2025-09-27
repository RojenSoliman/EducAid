<?php
// Admin Header (green themed) similar to student header
// Requires: $_SESSION['admin_username']
$adminDisplay = htmlspecialchars($_SESSION['admin_username'] ?? 'Admin');
?>
<div class="admin-main-header">
  <div class="container-fluid px-4">
    <div class="admin-header-content">
      <div class="admin-header-left d-flex align-items-center">
        <div class="sidebar-toggle me-2"><i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i></div>
        <h5 class="mb-0 fw-semibold d-none d-md-inline text-success-emphasis">Dashboard</h5>
      </div>
      <div class="admin-header-actions">
        <button class="admin-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
          <i class="bi bi-bell"></i>
          <span class="badge rounded-pill bg-danger">3</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
          <li><h6 class="dropdown-header">Notifications</h6></li>
          <li><a class="dropdown-item" href="admin_notifications.php"><i class="bi bi-info-circle me-2"></i>System update pending</a></li>
          <li><a class="dropdown-item" href="review_registrations.php"><i class="bi bi-person-check me-2"></i>3 applicants awaiting review</a></li>
          <li><a class="dropdown-item" href="manage_distributions.php"><i class="bi bi-box-seam me-2"></i>Distribution summary exported</a></li>
          <li><hr class="dropdown-divider"/></li>
          <li><a class="dropdown-item text-center" href="admin_notifications.php">View all</a></li>
        </ul>
        <div class="dropdown">
          <button class="admin-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Profile">
            <i class="bi bi-person-circle"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><h6 class="dropdown-header"><?=$adminDisplay?></h6></li>
            <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
            <li><hr class="dropdown-divider"/></li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>
<style>
.admin-main-header {background:#ffffff;border-bottom:1px solid #e1e7e3;box-shadow:0 2px 4px rgba(0,0,0,.06);padding:.55rem 0;position:relative;z-index:1030;margin-top:0;}
.admin-header-content{display:flex;align-items:center;justify-content:space-between;}
.admin-header-actions{display:flex;align-items:center;gap:1rem;}
.admin-icon-btn{background:#f8fbf8;border:1px solid #d9e4d8;border-radius:10px;padding:.55rem .65rem;position:relative;cursor:pointer;transition:.2s;color:#2e7d32;}
.admin-icon-btn .bi{font-size:1.05rem;}
.admin-icon-btn:hover{background:#e9f5e9;border-color:#43a047;color:#1b5e20;}
.admin-icon-btn .badge{position:absolute;top:-6px;right:-6px;font-size:.55rem;}
#menu-toggle{font-size:30px;cursor:pointer;color:#2e7d32;border-radius:8px;padding:4px 8px;transition:.2s;}#menu-toggle:hover{background:#e9f5e9;color:#1b5e20;}
@media (max-width: 576px){.admin-main-header{padding:.4rem 0;}#menu-toggle{font-size:26px;} .admin-header-actions{gap:.65rem;} }
</style>
