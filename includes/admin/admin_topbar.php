<?php
// Admin Topbar (mirroring student topbar structure but green-dominant)
?>
<div class="admin-topbar">
  <div class="container-fluid d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-3 small">
      <i class="bi bi-shield-lock"></i>
      <span>Administrative Panel</span>
      <span class="vr mx-2 d-none d-md-inline"></span>
      <i class="bi bi-envelope"></i>
      <a href="mailto:educaid@generaltrias.gov.ph">educaid@generaltrias.gov.ph</a>
      <span class="vr mx-2 d-none d-lg-inline"></span>
      <i class="bi bi-telephone"></i>
      <span class="d-none d-sm-inline">(046) 886-4454</span>
    </div>
    <div class="d-flex align-items-center gap-3 small">
      <i class="bi bi-clock"></i>
      <span class="d-none d-md-inline">Mon–Fri 8:00AM - 5:00PM</span>
      <span class="d-md-none">Office Hours</span>
    </div>
  </div>
</div>

<style>
.admin-topbar {
  background: linear-gradient(135deg,#2e7d32 0%,#1b5e20 100%);
  color:#fff;
  font-size:0.775rem;
  z-index:1050;
  position:fixed;top:0;left:0;right:0;height:44px;
  box-shadow:0 2px 4px rgba(0,0,0,.15);
}
.admin-topbar .container-fluid{height:44px;display:flex;align-items:center;}
.admin-topbar a{color:#e8f5e9;text-decoration:none;}
.admin-topbar a:hover{color:#fff;}
.admin-topbar .bi{color:rgba(255,255,255,.85);}
</style>
