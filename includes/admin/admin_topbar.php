<?php
// Admin Topbar (mirroring student topbar structure but green-dominant)
// Get dynamic topbar settings from database
$topbar_settings = [
  'topbar_email' => 'educaid@generaltrias.gov.ph',
  'topbar_phone' => '(046) 886-4454',
  'topbar_office_hours' => 'Mon–Fri 8:00AM - 5:00PM',
  'topbar_bg_color' => '#2e7d32',
  'topbar_bg_gradient' => '#1b5e20',
  'topbar_text_color' => '#ffffff',
  'topbar_link_color' => '#e8f5e9'
];

if (isset($connection)) {
  $result = pg_query($connection, "SELECT topbar_email, topbar_phone, topbar_office_hours, topbar_bg_color, topbar_bg_gradient, topbar_text_color, topbar_link_color FROM theme_settings WHERE municipality_id = 1 AND is_active = TRUE LIMIT 1");
  if ($result && pg_num_rows($result) > 0) {
    $db_settings = pg_fetch_assoc($result);
    $topbar_settings = array_merge($topbar_settings, array_filter($db_settings, function($value) {
      return $value !== null && $value !== '';
    }));
  }
}
?>
<div class="admin-topbar">
  <div class="container-fluid d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-3 small">
      <i class="bi bi-shield-lock"></i>
      <span>Administrative Panel</span>
      <span class="vr mx-2 d-none d-md-inline"></span>
      <i class="bi bi-envelope"></i>
      <a href="mailto:<?= htmlspecialchars($topbar_settings['topbar_email']) ?>"><?= htmlspecialchars($topbar_settings['topbar_email']) ?></a>
      <span class="vr mx-2 d-none d-lg-inline"></span>
      <i class="bi bi-telephone"></i>
      <span class="d-none d-sm-inline"><?= htmlspecialchars($topbar_settings['topbar_phone']) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3 small">
      <i class="bi bi-clock"></i>
      <span class="d-none d-md-inline"><?= htmlspecialchars($topbar_settings['topbar_office_hours']) ?></span>
      <span class="d-md-none">Office Hours</span>
    </div>
  </div>
</div>

<style>
.admin-topbar {
  background: linear-gradient(135deg, <?= htmlspecialchars($topbar_settings['topbar_bg_color']) ?> 0%, <?= htmlspecialchars($topbar_settings['topbar_bg_gradient']) ?> 100%);
  color: <?= htmlspecialchars($topbar_settings['topbar_text_color']) ?>;
  font-size:0.775rem;
  z-index:1050;
  position:fixed;top:0;left:0;right:0;height:44px;
  box-shadow:0 2px 4px rgba(0,0,0,.15);
}
.admin-topbar .container-fluid{height:44px;display:flex;align-items:center;}
.admin-topbar a{color: <?= htmlspecialchars($topbar_settings['topbar_link_color']) ?>;text-decoration:none;}
.admin-topbar a:hover{color:#fff;}
.admin-topbar .bi{color:rgba(255,255,255,.85);}
</style>
