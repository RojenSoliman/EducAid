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

// Brand configuration
$brand_config = [
  'badge' => 'EA',
  'name' => 'EducAid',
  'subtitle' => '• City of General Trias',
  'href' => '#'
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
?>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg bg-white sticky-top" style="z-index: 1030;">
  <div class="container">
    <a class="navbar-brand" href="<?php echo $brand_config['href']; ?>">
      <span class="brand-badge"><?php echo $brand_config['badge']; ?></span>
      <span><?php echo $brand_config['name']; ?> <span class="text-body-secondary d-none d-sm-inline"><?php echo $brand_config['subtitle']; ?></span></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
        <?php foreach ($nav_links as $link): ?>
        <li class="nav-item">
          <a class="nav-link <?php echo $link['active'] ? 'active' : ''; ?>" href="<?php echo $link['href']; ?>">
            <?php echo $link['label']; ?>
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