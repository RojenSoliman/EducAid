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
<nav class="navbar navbar-expand-lg bg-white sticky-top" style="z-index: 1030;">
  <div class="container">
    <?php
      // Unified brand: one editable block (nav_brand_wrapper) containing full title text.
      $brandDefault = htmlspecialchars($brand_config['name']);
      $brandText = function_exists('lp_block') ? lp_block('nav_brand_wrapper', $brandDefault) : $brandDefault;
      $logoPath = $brand_config['logo'];
    ?>
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo $brand_config['href']; ?>" data-lp-key="nav_brand_wrapper"<?php echo function_exists('lp_block_style')? lp_block_style('nav_brand_wrapper'):''; ?>>
      <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="EducAid Logo" class="brand-logo" style="height:32px;width:auto;object-fit:contain;" onerror="this.style.display='none';">
      <span class="brand-text m-0 p-0"><?php echo $brandText; ?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
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
      <div class="navbar-nav ms-lg-3 mt-2 mt-lg-0">
        <a href="<?php echo $base_path; ?>unified_login.php" class="btn btn-outline-primary btn-sm me-2 mb-2 mb-lg-0">
          <i class="bi bi-box-arrow-in-right"></i><span class="d-none d-sm-inline ms-1">Sign In</span>
        </a>
        <a href="<?php echo $base_path; ?>register.php" class="btn btn-primary btn-sm">
          <i class="bi bi-journal-text"></i><span class="d-none d-sm-inline ms-1">Apply</span>
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</nav>