<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>About EducAid – City of General Trias</title>
  <meta name="description" content="Learn more about EducAid - Educational Assistance Management System" />

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="assets/css/website/landing_page.css" rel="stylesheet" />
</head>
<body>
  <?php
  // Custom navigation for about page
  $custom_nav_links = [
    ['href' => 'landingpage.php', 'label' => 'Home', 'active' => false],
    ['href' => 'about.php', 'label' => 'About', 'active' => true],
    ['href' => '#services', 'label' => 'Services', 'active' => false],
    ['href' => '#contact', 'label' => 'Contact', 'active' => false]
  ];
  
  include 'includes/website/topbar.php';
  include 'includes/website/navbar.php';
  ?>

  <!-- About Content -->
  <section class="py-5">
    <div class="container">
      <h1>About EducAid</h1>
      <p>This is an example of how to use the modular navbar with custom navigation links.</p>
    </div>
  </section>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>