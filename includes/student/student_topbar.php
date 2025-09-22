<?php
// Student Topbar Component
// This topbar is specifically designed for student dashboard pages
?>
<!-- Student Topbar -->
<div class="topbar">
  <div class="container-fluid d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-3">
      <i class="bi bi-envelope-paper"></i>
      <a href="mailto:educaid@generaltrias.gov.ph">educaid@generaltrias.gov.ph</a>
      <span class="vr mx-2 d-none d-md-inline"></span>
      <i class="bi bi-telephone"></i>
      <span>(046) 886-4454</span>
    </div>
    <div class="d-flex align-items-center gap-3">
      <i class="bi bi-clock"></i>
      <span class="d-none d-md-inline">Mon-Fri 8:00AM - 5:00PM</span>
      <span class="d-md-none">Office Hours</span>
    </div>
  </div>
</div>

<style>
/* Topbar styles */
.topbar {
  background: linear-gradient(135deg, #0068da 0%, #004aa3 100%);
  color: white;
  font-size: 0.875rem;
  z-index: 1050;
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  height: 44px; /* Fixed height for consistent spacing */
}
.topbar .container-fluid { height: 44px; display: flex; align-items: center; }
.topbar a {
  color: rgba(255, 255, 255, 0.9);
  text-decoration: none;
}
.topbar a:hover {
  color: white;
}
.topbar .bi {
  color: rgba(255, 255, 255, 0.8);
}
</style>