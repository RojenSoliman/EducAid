<?php
// Default navigation links for website
$nav_links = [
  ['href' => 'landingpage.php#home', 'label' => 'Home', 'active' => true],
  ['href' => 'about.php', 'label' => 'About', 'active' => false],
  ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => false],
  ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => false],
  ['href' => 'announcements.php', 'label' => 'Announcements', 'active' => false],
  ['href' => 'contact.php', 'label' => 'Contact', 'active' => false]
];

// Override nav_links if custom ones are provided
if (isset($custom_nav_links)) {
  $nav_links = $custom_nav_links;
}

// Brand configuration (single editable text block; logo image is static, not inline editable)
$brand_config = [
  'name' => 'EducAid • City of General Trias',
  'href' => '#',
  'logo' => 'assets/images/educaid-logo.png' // fallback logo path
];

// Override brand config if custom one is provided
if (isset($custom_brand_config)) {
  $brand_config = array_merge($brand_config, $custom_brand_config);
}

// Determine if we're in a subfolder and calculate relative path to root
$base_path = '';
if (strpos($_SERVER['PHP_SELF'], '/website/') !== false) {
  $base_path = '../';
} elseif (strpos($_SERVER['PHP_SELF'], '/modules/student/') !== false) {
  $base_path = '../../';
} elseif (strpos($_SERVER['PHP_SELF'], '/modules/admin/') !== false) {
  $base_path = '../../';
}

// Check if we're in edit mode (set by parent page)
$in_edit_mode = isset($edit_mode) && $edit_mode === true;
$is_edit_mode = isset($IS_EDIT_MODE) && $IS_EDIT_MODE === true;
$is_edit_super_admin = isset($IS_EDIT_SUPER_ADMIN) && $IS_EDIT_SUPER_ADMIN === true;
$navbar_edit_mode = $in_edit_mode || $is_edit_mode || $is_edit_super_admin;

// Check if user is super admin - multiple ways depending on how page sets it
$navbar_is_super_admin = false;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
    $navbar_is_super_admin = true;
} elseif (isset($is_super_admin) && $is_super_admin === true) {
    $navbar_is_super_admin = true;
} elseif (isset($IS_EDIT_SUPER_ADMIN) && $IS_EDIT_SUPER_ADMIN === true) {
    $navbar_is_super_admin = true;
} elseif (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
    // Fallback: check role dynamically
    if (isset($connection)) {
        $role = @getCurrentAdminRole($connection);
        if ($role === 'super_admin') {
            $navbar_is_super_admin = true;
        }
    }
}

// Fetch active municipality logo for super admin
$navbar_municipality_logo = null;
$navbar_municipality_name = null;

if ($navbar_is_super_admin && isset($connection)) {
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
            $navbar_municipality_name = $muni_data['name'];
            
            // Build logo path using the same base_path logic
            if (!empty($muni_data['active_logo'])) {
                $logo_path = trim($muni_data['active_logo']);
                
                // Handle base64 data URIs
                if (preg_match('#^data:image/[^;]+;base64,#i', $logo_path)) {
                    $navbar_municipality_logo = $logo_path;
                }
                // Handle external URLs
                elseif (preg_match('#^(?:https?:)?//#i', $logo_path)) {
                    $navbar_municipality_logo = $logo_path;
                }
                // Handle absolute web paths (start with /)
                elseif (str_starts_with($logo_path, '/')) {
                    // Absolute paths from web root - just need to make them relative using base_path
                    // Remove leading slash and add base_path
                    $relative = ltrim($logo_path, '/');
                    $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));
                    $navbar_municipality_logo = $base_path . $encoded;
                }
                // Handle relative paths
                else {
                    // Normalize path
                    $normalized = str_replace('\\', '/', $logo_path);
                    $normalized = preg_replace('#(?<!:)/{2,}#', '/', $normalized);
                    
                    // URL encode each segment while preserving slashes
                    $encoded = implode('/', array_map('rawurlencode', explode('/', $normalized)));
                    
                    // Use base_path to create correct relative path
                    $navbar_municipality_logo = $base_path . $encoded;
                }
            }
            pg_free_result($muni_result);
        }
    }
}

// Define which pages are editable (for red outline indication)
$editable_page_slugs = ['landingpage.php', 'about.php', 'how-it-works.php', 'requirements.php', 'announcements.php', 'contact.php'];

// Helper function to check if a nav link is editable
function is_editable_page($href) {
    global $editable_page_slugs;
    foreach ($editable_page_slugs as $slug) {
        if (strpos($href, $slug) !== false) {
            return true;
        }
    }
    return false;
}

// Helper function to convert regular link to edit link
function make_edit_link($href) {
    // Remove any hash fragments
    $href = strtok($href, '#');
    // Add ?edit=1 parameter
    if (strpos($href, '?') !== false) {
        return $href . '&edit=1';
    } else {
        return $href . '?edit=1';
    }
}
?>

<style>
:root {
  --topbar-height: 0px;
  --navbar-height: 0px;
}

body.has-header-offset {
  padding-top: calc(var(--topbar-height, 0px) + var(--navbar-height, 0px));
}

nav.navbar.fixed-header {
  position: fixed;
  top: var(--topbar-height, 0px);
  left: 0;
  right: 0;
  width: 100%;
  z-index: 1040;
}

/* Municipality logo styling - Simple and clean like generaltrias.gov.ph */
.municipality-badge-navbar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.municipality-logo-navbar {
  max-height: 48px;
  width: auto;
  object-fit: contain;
}

nav.navbar.fixed-header .navbar-brand {
  gap: 0.75rem;
  flex-wrap: nowrap;
}

nav.navbar.fixed-header .navbar-brand .brand-logo {
  height: 44px;
  width: auto;
  object-fit: contain;
}

nav.navbar.fixed-header .navbar-brand .brand-text {
  font-size: 1.05rem;
  font-weight: 600;
  line-height: 1.2;
}

nav.navbar.fixed-header .navbar-nav.spread-nav {
  gap: 0.5rem;
  flex-wrap: nowrap;
  min-width: 0;
}

nav.navbar.fixed-header .navbar-nav.spread-nav .nav-link {
  white-space: nowrap;
  font-size: 0.95rem;
  padding-left: 0.5rem;
  padding-right: 0.5rem;
}

@media (min-width: 992px) {
  nav.navbar.fixed-header .navbar-nav.spread-nav {
    flex: 0 1 auto;
    justify-content: center;
    gap: 0.75rem;
  }

  nav.navbar.fixed-header .navbar-nav.spread-nav .nav-item {
    display: flex;
    align-items: center;
  }

  nav.navbar.fixed-header .navbar-nav.spread-nav .nav-link {
    font-size: 0.95rem;
    font-weight: 500;
    padding-top: 0.75rem;
    padding-bottom: 0.75rem;
    padding-left: 0.65rem;
    padding-right: 0.65rem;
  }
}

@media (min-width: 1200px) {
  nav.navbar.fixed-header .navbar-nav.spread-nav {
    gap: 1.25rem;
  }

  nav.navbar.fixed-header .navbar-nav.spread-nav .nav-link {
    padding-left: 0.85rem;
    padding-right: 0.85rem;
  }
}

nav.navbar.fixed-header .navbar-collapse {
  width: 100%;
  align-items: center;
  gap: 0.75rem;
}

.navbar-actions {
  flex-shrink: 0;
  white-space: nowrap;
}

@media (min-width: 992px) {
  .w-lg-auto {
    width: auto !important;
  }
}
</style>

<?php if ($navbar_edit_mode && $navbar_is_super_admin): ?>
<style>
  /* Red outline for editable navigation items */
  .nav-link.editable-page {
    position: relative;
    padding-bottom: 0.5rem !important;
  }
  
  .nav-link.editable-page::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 80%;
    height: 3px;
    background: linear-gradient(90deg, transparent, #dc2626, transparent);
    border-radius: 2px;
    animation: pulse-underline 2s ease-in-out infinite;
  }
  
  .nav-link.editable-page:hover::after {
    background: linear-gradient(90deg, transparent, #991b1b, transparent);
    animation: none;
  }
  
  @keyframes pulse-underline {
    0%, 100% {
      opacity: 0.7;
      height: 3px;
    }
    50% {
      opacity: 1;
      height: 4px;
    }
  }
  
  /* Tooltip to show it's editable */
  .nav-link.editable-page {
    cursor: pointer;
  }
  
  .nav-link.editable-page:hover {
    color: #dc2626 !important;
  }
  
  /* Small edit icon indicator */
  .nav-link.editable-page .edit-indicator {
    font-size: 0.7rem;
    margin-left: 0.25rem;
    color: #dc2626;
    opacity: 0.6;
  }
  
  .nav-link.editable-page:hover .edit-indicator {
    opacity: 1;
  }
</style>
<?php endif; ?>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg bg-white fixed-header">
  <div class="container-fluid px-3 px-lg-4 px-xl-5">
    <?php
      // Unified brand: one editable block (nav_brand_wrapper) containing full title text.
      $brandDefault = htmlspecialchars($brand_config['name']);
      $brandText = function_exists('lp_block') ? lp_block('nav_brand_wrapper', $brandDefault) : $brandDefault;
      $logoPath = $brand_config['logo'];
    ?>
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo $brand_config['href']; ?>" data-lp-key="nav_brand_wrapper"<?php echo function_exists('lp_block_style')? lp_block_style('nav_brand_wrapper'):''; ?>>
  <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="EducAid Logo" class="brand-logo" style="height:48px;width:auto;object-fit:contain;" onerror="this.style.display='none';">
      <?php if ($navbar_municipality_logo && $navbar_municipality_name): ?>
      <div class="municipality-badge-navbar" title="<?php echo htmlspecialchars($navbar_municipality_name); ?>">
        <img src="<?php echo htmlspecialchars($navbar_municipality_logo); ?>" 
             alt="<?php echo htmlspecialchars($navbar_municipality_name); ?>" 
             class="municipality-logo-navbar"
             onerror="this.style.display='none';">
      </div>
      <?php endif; ?>
      <span class="brand-text m-0 p-0"><?php echo $brandText; ?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
  <div class="collapse navbar-collapse align-items-lg-center justify-content-lg-center" id="nav">
  <ul class="navbar-nav spread-nav mx-lg-auto mb-2 mb-lg-0">
        <?php foreach ($nav_links as $link): ?>
        <li class="nav-item">
          <?php
          // Check if this page is editable and we're in super admin edit mode
          $is_editable = is_editable_page($link['href']);
          $link_href = ($navbar_edit_mode && $navbar_is_super_admin && $is_editable) ? make_edit_link($link['href']) : $link['href'];
          $editable_class = ($navbar_edit_mode && $navbar_is_super_admin && $is_editable) ? ' editable-page' : '';
          ?>
          <a class="nav-link<?php echo $link['active'] ? ' active' : ''; ?><?php echo $editable_class; ?>" 
             href="<?php echo $link_href; ?>"
             <?php if ($navbar_edit_mode && $navbar_is_super_admin && $is_editable): ?>
             title="Click to edit this page"
             <?php endif; ?>>
            <?php echo $link['label']; ?>
            <?php if ($navbar_edit_mode && $navbar_is_super_admin && $is_editable): ?>
            <i class="bi bi-pencil-fill edit-indicator"></i>
            <?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php if (!isset($hide_auth_buttons) || !$hide_auth_buttons): ?>
  <div class="navbar-actions d-flex flex-column flex-lg-row align-items-center gap-2 ms-lg-4 mt-2 mt-lg-0 ms-lg-auto">
        <a href="<?php echo $base_path; ?>unified_login.php" class="btn btn-outline-primary btn-sm d-flex align-items-center justify-content-center gap-2 w-100 w-lg-auto">
          <i class="bi bi-box-arrow-in-right"></i><span class="d-none d-sm-inline ms-1">Sign In</span>
        </a>
        <a href="<?php echo $base_path; ?>register.php" class="btn btn-primary btn-sm d-flex align-items-center justify-content-center gap-2 w-100 w-lg-auto">
          <i class="bi bi-journal-text"></i><span class="d-none d-sm-inline ms-1">Apply</span>
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</nav>
<script>
(function () {
  const root = document.documentElement;

  function getTopbar() {
    return document.querySelector('.landing-topbar, .student-topbar, .admin-topbar');
  }

  function getNavbar() {
    return document.querySelector('nav.navbar.fixed-header');
  }

  function updateOffsets() {
    const topbar = getTopbar();
    const navbar = getNavbar();
    const topbarHeight = topbar ? topbar.offsetHeight : 0;
    const navbarHeight = navbar ? navbar.offsetHeight : 0;

    root.style.setProperty('--topbar-height', `${topbarHeight}px`);
    root.style.setProperty('--navbar-height', `${navbarHeight}px`);

    if (topbarHeight || navbarHeight) {
      document.body.classList.add('has-header-offset');
    } else {
      document.body.classList.remove('has-header-offset');
    }
  }

  let resizeObserver;
  const supportsResizeObserver = typeof ResizeObserver !== 'undefined';

  function observeElements() {
    if (!supportsResizeObserver) {
      return;
    }

    if (resizeObserver) {
      resizeObserver.disconnect();
    }

    resizeObserver = new ResizeObserver(updateOffsets);

    const topbar = getTopbar();
    const navbar = getNavbar();

    if (topbar) {
      resizeObserver.observe(topbar);
    }

    if (navbar) {
      resizeObserver.observe(navbar);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    updateOffsets();
    observeElements();

    const navbarCollapse = document.getElementById('nav');
    if (navbarCollapse) {
      ['shown.bs.collapse', 'hidden.bs.collapse'].forEach(eventName => {
        navbarCollapse.addEventListener(eventName, updateOffsets);
      });
    }
  });

  window.addEventListener('load', updateOffsets);
  window.addEventListener('resize', updateOffsets);
})();
</script>