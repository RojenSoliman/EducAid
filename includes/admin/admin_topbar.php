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
    foreach ($db_settings as $key => $value) {
      if ($key === 'topbar_bg_gradient') {
        $topbar_settings[$key] = $value; // allow null to disable gradient
        continue;
      }
      if ($value !== null && $value !== '') {
        $topbar_settings[$key] = $value;
      }
    }
  }
}

$bg_color = $topbar_settings['topbar_bg_color'] ?? '#2e7d32';
$bg_gradient = $topbar_settings['topbar_bg_gradient'] ?? null;
$topbar_background_css = ($bg_gradient && trim($bg_gradient) !== '')
  ? sprintf('linear-gradient(135deg, %s 0%%, %s 100%%)', $bg_color, $bg_gradient)
  : $bg_color;

// Fetch active municipality logo and name
$active_municipality_logo = null;
$active_municipality_name = null;

if (isset($connection)) {
    // First try to get from session
    $muni_id = isset($_SESSION['active_municipality_id']) ? (int)$_SESSION['active_municipality_id'] : null;
    
    // If no session, get the admin's first assigned municipality
    if (!$muni_id && isset($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
        $assign_result = pg_query_params(
            $connection,
            "SELECT municipality_id FROM admin_municipality_assignments 
             WHERE admin_id = $1 
             ORDER BY municipality_id ASC 
             LIMIT 1",
            [$admin_id]
        );
        
        if ($assign_result && pg_num_rows($assign_result) > 0) {
            $assign_data = pg_fetch_assoc($assign_result);
            $muni_id = (int)$assign_data['municipality_id'];
            pg_free_result($assign_result);
        }
    }
    
    // Fetch municipality logo if we have an ID
    if ($muni_id) {
        $muni_result = pg_query_params(
            $connection,
            "SELECT name, 
                    COALESCE(custom_logo_image, preset_logo_image) AS active_logo
             FROM municipalities 
             WHERE municipality_id = $1 
             LIMIT 1",
            [$muni_id]
        );
        
        if ($muni_result && pg_num_rows($muni_result) > 0) {
            $muni_data = pg_fetch_assoc($muni_result);
            $active_municipality_name = $muni_data['name'];
            
            // Build logo path (same logic as municipality_content.php)
            if (!empty($muni_data['active_logo'])) {
                $logo_path = trim($muni_data['active_logo']);
                
                // Handle base64 data URIs
                if (preg_match('#^data:image/[^;]+;base64,#i', $logo_path)) {
                    $active_municipality_logo = $logo_path;
                }
                // Handle external URLs
                elseif (preg_match('#^(?:https?:)?//#i', $logo_path)) {
                    $active_municipality_logo = $logo_path;
                }
                // Handle local paths
                else {
                    // Normalize and encode path
                    $normalized = str_replace('\\', '/', $logo_path);
                    $normalized = preg_replace('#(?<!:)/{2,}#', '/', $normalized);
                    $encoded = implode('/', array_map('rawurlencode', explode('/', $normalized)));
                    
                    // From includes/admin/, need ../../ to reach project root
                    if (str_starts_with($normalized, '/')) {
                        $active_municipality_logo = '../..' . $encoded;
                    } else {
                        $relative = ltrim($normalized, '/');
                        $active_municipality_logo = '../../' . implode('/', array_map('rawurlencode', explode('/', $relative)));
                    }
                }
            }
            pg_free_result($muni_result);
        }
    }
}
?>
<div class="admin-topbar" id="adminTopbar" data-topbar-container>
  <div class="container-fluid d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-3 small">
      <i class="bi bi-shield-lock"></i>
      <span>Administrative Panel</span>
      <span class="vr mx-2 d-none d-md-inline"></span>
      <i class="bi bi-envelope"></i>
      <a href="mailto:<?= htmlspecialchars($topbar_settings['topbar_email']) ?>" class="admin-topbar-email" data-topbar-email>
        <?= htmlspecialchars($topbar_settings['topbar_email']) ?>
      </a>
      <span class="vr mx-2 d-none d-lg-inline"></span>
      <i class="bi bi-telephone"></i>
      <span class="d-none d-sm-inline" data-topbar-phone><?= htmlspecialchars($topbar_settings['topbar_phone']) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3 small">
      <?php if ($active_municipality_logo && $active_municipality_name): ?>
        <div class="d-flex align-items-center gap-2 municipality-badge">
          <img src="<?= htmlspecialchars($active_municipality_logo) ?>" 
               alt="<?= htmlspecialchars($active_municipality_name) ?>" 
               class="municipality-logo"
               onerror="this.style.display='none'">
          <span class="municipality-name"><?= htmlspecialchars($active_municipality_name) ?></span>
        </div>
        <span class="vr mx-2 d-none d-md-inline"></span>
      <?php endif; ?>
      <i class="bi bi-clock"></i>
      <span class="d-none d-md-inline" data-topbar-hours><?= htmlspecialchars($topbar_settings['topbar_office_hours']) ?></span>
      <span class="d-md-none" data-topbar-hours-mobile>Office Hours</span>
    </div>
  </div>
</div>

<style>
.admin-topbar {
  background: <?= htmlspecialchars($topbar_background_css, ENT_QUOTES) ?>;
  color: <?= htmlspecialchars($topbar_settings['topbar_text_color']) ?>;
  font-size:0.775rem;
  z-index:1050;
  position:fixed;top:0;left:0;right:0;
  min-height:44px;
  box-shadow:0 2px 4px rgba(0,0,0,.15);
}
.admin-topbar .container-fluid{
  min-height:44px;
  display:flex;
  align-items:center;
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
}
.admin-topbar a{color: <?= htmlspecialchars($topbar_settings['topbar_link_color']) ?>;text-decoration:none;}
.admin-topbar a:hover{color: <?= htmlspecialchars($topbar_settings['topbar_text_color']) ?>;opacity:0.85;}
.admin-topbar .bi{color: <?= htmlspecialchars($topbar_settings['topbar_text_color']) ?>;opacity:0.9;}

/* Mobile responsive adjustments */
@media (max-width: 767.98px) {
  .admin-topbar {
    font-size: 0.7rem;
  }
  .admin-topbar .container-fluid {
    gap: 0.5rem !important;
    row-gap: 0.5rem;
    justify-content: center !important;
  }
  .admin-topbar .d-flex.align-items-center.gap-3 {
    gap: 0.5rem !important;
    flex-wrap: wrap;
    justify-content: center;
  }
  .admin-topbar a {
    word-break: break-all;
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

/* Municipality Badge Styling */
<?php
// Create semi-transparent background based on text color
$text_color = $topbar_settings['topbar_text_color'];
// Extract RGB from hex
$r = hexdec(substr($text_color, 1, 2));
$g = hexdec(substr($text_color, 3, 2));
$b = hexdec(substr($text_color, 5, 2));
?>
.admin-topbar .municipality-badge {
  background: rgba(<?= "$r,$g,$b" ?>,.15);
  padding: 3px 10px 3px 3px;
  border-radius: 20px;
  border: 1px solid rgba(<?= "$r,$g,$b" ?>,.25);
  transition: all 0.3s ease;
}
.admin-topbar .municipality-badge:hover {
  background: rgba(<?= "$r,$g,$b" ?>,.25);
  border-color: rgba(<?= "$r,$g,$b" ?>,.4);
}
.admin-topbar .municipality-logo {
  width: 28px;
  height: 28px;
  object-fit: contain;
  border-radius: 50%;
  background: white;
  padding: 2px;
  box-shadow: 0 2px 4px rgba(0,0,0,.2);
}
.admin-topbar .municipality-name {
  font-weight: 600;
  color: <?= htmlspecialchars($topbar_settings['topbar_text_color']) ?>;
  font-size: 0.8rem;
}

/* Mobile optimization for municipality badge */
@media (max-width: 767.98px) {
  .admin-topbar .municipality-badge {
    padding: 2px 6px 2px 2px;
  }
  .admin-topbar .municipality-logo {
    width: 22px;
    height: 22px;
  }
  .admin-topbar .municipality-name {
    font-size: 0.7rem;
    max-width: 100px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

</style>
