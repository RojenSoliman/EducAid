<!-- <?php //include '../../includes/admin/admin_sidebar.php' ?> -->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>EducAid Dashboard</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/homepage.css" />
</head>
<body>
  <div class="container-fluid">
    <div class="row">
      <!-- Include Sidebar -->
      <?php include '../../includes/student/student_sidebar.php' ?>
      
      <!-- Main Content Area -->
      <section class="home-section" id="page-content-wrapper">
        <nav>
            <div class="sidebar-toggle px-4 py-3">
            <i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i>
            </div>
        </nav>

        
        </section>
    </div>
  </div>
    <script src="../../assets/js/homepage.js"></script>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>