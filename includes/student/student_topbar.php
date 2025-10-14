<?php
// includes/student/student_topbar.php
// Student topbar with municipality logo and contact info

require_once __DIR__ . '/../../config/database.php';

$topbar_settings = [
  'topbar_email' => 'educaid@generaltrias.gov.ph',
  'topbar_phone' => '(046) 886-4454',
  'topbar_office_hours' => 'Mon–Fri 8:00AM - 5:00PM',
  'topbar_bg_color' => '#1565c0',
  'topbar_bg_gradient' => '#0d47a1',
  'topbar_text_color' => '#ffffff',
  'topbar_link_color' => '#e3f2fd'
];

if (isset($connection)) {
  $muni_id = isset($_SESSION['active_municipality_id']) ? (int)$_SESSION['active_municipality_id'] : 1;

  $result = pg_query_params(
    $connection,
    "SELECT topbar_email, topbar_phone, topbar_office_hours, topbar_bg_color, topbar_bg_gradient, topbar_text_color, topbar_link_color
     FROM theme_settings
     WHERE municipality_id = $1 AND is_active = TRUE
     LIMIT 1",
    [$muni_id]
  );

  if ($result && pg_num_rows($result) > 0) {
    $db_settings = pg_fetch_assoc($result);
    foreach ($db_settings as $key => $value) {
      if ($key === 'topbar_bg_gradient') {
        $topbar_settings[$key] = $value;
        continue;
      }
      if ($value !== null && $value !== '') {
        $topbar_settings[$key] = $value;
      }
    }
    pg_free_result($result);
  }
}

$bg_color = $topbar_settings['topbar_bg_color'] ?? '#1565c0';
$bg_gradient = $topbar_settings['topbar_bg_gradient'] ?? null;
$topbar_background_css = ($bg_gradient && trim($bg_gradient) !== '')
  ? sprintf('linear-gradient(135deg, %s 0%%, %s 100%%)', $bg_color, $bg_gradient)
  : $bg_color;

// Fetch active municipality logo and name
$active_municipality_logo = null;
$active_municipality_name = null;

if (isset($connection)) {
    $muni_id = isset($_SESSION['active_municipality_id']) ? (int)$_SESSION['active_municipality_id'] : 1;
    
    if ($muni_id) {
        $muni_result = pg_query_params(
            $connection,
            "SELECT name 
             FROM municipalities 
             WHERE municipality_id = $1 
             LIMIT 1",
            [$muni_id]
        );
        
        if ($muni_result && pg_num_rows($muni_result) > 0) {
            $muni_data = pg_fetch_assoc($muni_result);
            $active_municipality_name = $muni_data['name'];
            
            // Use default logo for now (columns custom_logo_image/preset_logo_image don't exist yet)
            $active_municipality_logo = '/assets/City Logos/General_Trias_City_Logo.png';
            
            // Build logo path
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
                    
                    // From includes/student/, need ../../ to reach project root
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
<div class="student-topbar">
  <div class="container-fluid d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-3 small">
      <i class="bi bi-mortarboard-fill"></i>
      <span>Student Portal</span>
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
.student-topbar {
  background: <?= htmlspecialchars($topbar_background_css, ENT_QUOTES) ?>;
  color: <?= htmlspecialchars($topbar_settings['topbar_text_color']) ?>;
  font-size: 0.775rem;
  z-index: 1050;
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  min-height: 44px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, .15);
}

.student-topbar .container-fluid {
  min-height: 44px;
  display: flex;
  align-items: center;
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
}

.student-topbar a {
  color: <?= htmlspecialchars($topbar_settings['topbar_link_color']) ?>;
  text-decoration: none;
}

.student-topbar a:hover {
  color: #fff;
}

.student-topbar .bi {
  color: rgba(255, 255, 255, .85);
}

/* Mobile responsive adjustments */
@media (max-width: 767.98px) {
  .student-topbar {
    font-size: 0.7rem;
  }
  .student-topbar .container-fluid {
    gap: 0.5rem !important;
    row-gap: 0.5rem;
    justify-content: center !important;
  }
  .student-topbar .d-flex.align-items-center.gap-3 {
    gap: 0.5rem !important;
    flex-wrap: wrap;
    justify-content: center;
  }
  .student-topbar a {
    word-break: break-all;
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

/* Municipality Badge Styling */
.student-topbar .municipality-badge {
  background: rgba(255,255,255,.15);
  padding: 3px 10px 3px 3px;
  border-radius: 20px;
  border: 1px solid rgba(255,255,255,.25);
  transition: all 0.3s ease;
}
.student-topbar .municipality-badge:hover {
  background: rgba(255,255,255,.25);
  border-color: rgba(255,255,255,.4);
}
.student-topbar .municipality-logo {
  width: 28px;
  height: 28px;
  object-fit: contain;
  border-radius: 50%;
  background: white;
  padding: 2px;
  box-shadow: 0 2px 4px rgba(0,0,0,.2);
}
.student-topbar .municipality-name {
  font-weight: 600;
  color: #fff;
  font-size: 0.8rem;
}

/* Mobile optimization for municipality badge */
@media (max-width: 767.98px) {
  .student-topbar .municipality-badge {
    padding: 2px 6px 2px 2px;
  }
  .student-topbar .municipality-logo {
    width: 22px;
    height: 22px;
  }
  .student-topbar .municipality-name {
    font-size: 0.7rem;
    max-width: 100px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

@media (max-width: 767.98px) {
  .student-topbar {
    font-size: 0.7rem;
  }

  .student-topbar .container-fluid {
    justify-content: center !important;
    row-gap: 0.5rem;
  }

  .student-topbar .d-flex.align-items-center.gap-3 {
    gap: 0.5rem !important;
    text-align: center;
  }

  .student-topbar a {
    word-break: break-word;
  }
}
</style>

<script>
(function () {
  function updateTopbarHeight() {
    var topbar = document.querySelector('.student-topbar');
    if (!topbar) { return; }
    var height = topbar.offsetHeight;
    if (height > 0) {
      document.documentElement.style.setProperty('--topbar-h', height + 'px');
    }
  }

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    updateTopbarHeight();
  } else {
    document.addEventListener('DOMContentLoaded', updateTopbarHeight);
  }

  window.addEventListener('resize', updateTopbarHeight);
})();
</script>