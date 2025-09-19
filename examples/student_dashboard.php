<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Student Portal – EducAid</title>

  <!-- Bootstrap & Styles -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="../assets/css/website/landing_page.css" rel="stylesheet" />
</head>
<body>
  <?php
  // Custom brand and navigation for student portal
  $custom_brand_config = [
    'badge' => 'EA',
    'name' => 'EducAid Student',
    'subtitle' => '• Student Portal',
    'href' => 'dashboard.php'
  ];

  $custom_nav_links = [
    ['href' => 'dashboard.php', 'label' => 'Dashboard', 'active' => true],
    ['href' => 'profile.php', 'label' => 'Profile', 'active' => false],
    ['href' => 'application.php', 'label' => 'Application', 'active' => false],
    ['href' => 'documents.php', 'label' => 'Documents', 'active' => false],
    ['href' => 'status.php', 'label' => 'Status', 'active' => false],
    ['href' => 'logout.php', 'label' => 'Logout', 'active' => false]
  ];
  
  // Skip topbar for student portal
  include '../includes/website/navbar.php';
  ?>

  <!-- Student Dashboard Content -->
  <section class="py-5">
    <div class="container">
      <h1>Student Dashboard</h1>
      <p>This shows how to customize the navbar for different sections with custom branding and navigation links.</p>
    </div>
  </section>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>