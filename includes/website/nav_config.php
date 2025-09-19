<?php
// Example usage: Include this file to set up custom navigation

// Custom navigation links for different pages
$student_nav_links = [
  ['href' => 'dashboard.php', 'label' => 'Dashboard', 'active' => true],
  ['href' => 'profile.php', 'label' => 'Profile', 'active' => false],
  ['href' => 'application.php', 'label' => 'Application', 'active' => false],
  ['href' => 'documents.php', 'label' => 'Documents', 'active' => false],
  ['href' => 'status.php', 'label' => 'Status', 'active' => false],
  ['href' => 'logout.php', 'label' => 'Logout', 'active' => false]
];

$about_page_nav_links = [
  ['href' => 'index.php', 'label' => 'Home', 'active' => false],
  ['href' => 'about.php', 'label' => 'About', 'active' => true],
  ['href' => 'services.php', 'label' => 'Services', 'active' => false],
  ['href' => 'contact.php', 'label' => 'Contact', 'active' => false]
];

// Custom brand configurations for different sections
$admin_brand_config = [
  'badge' => 'EA',
  'name' => 'EducAid Admin',
  'subtitle' => '• Management Portal',
  'href' => 'dashboard.php'
];

$student_brand_config = [
  'badge' => 'EA',
  'name' => 'EducAid Student',
  'subtitle' => '• Student Portal',
  'href' => 'dashboard.php'
];

// Example function to set active nav item
function setActiveNavItem($nav_links, $current_page) {
  foreach ($nav_links as &$link) {
    $link['active'] = (basename($link['href']) === $current_page);
  }
  return $nav_links;
}
?>