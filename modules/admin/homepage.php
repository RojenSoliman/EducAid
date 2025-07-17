<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: index.php");
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
</head>
<body>
  <div id="wrapper">
    
    <!-- Sidebar (comes first for layout logic to work) -->
    <?php include '../../includes/admin/admin_sidebar.php'; ?>

    <!-- Backdrop for mobile sidebar -->
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

    <!-- Main Section -->
    <section class="home-section" id="mainContent">
      <nav>
        <div class="sidebar-toggle px-4 py-3">
          <i class="bi bi-list" id="menu-toggle"></i>
        </div>
      </nav>

      <div class="container-fluid py-4 px-4">
        <h1 class="fw-bold mb-3">Welcome, <?php echo htmlspecialchars($_SESSION['admin_username']); ?></h1>
        <p class="text-muted mb-4">Here you can manage student registrations, verify applicants, and more.</p>

        <div class="row g-4">
          <div class="col-md-4">
            <div class="custom-card">
              <div class="custom-card-header bg-primary text-white">
                <h5><i class="bi bi-people-fill me-2"></i>Total Students</h5>
              </div>
              <div class="custom-card-body">
                <?php
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                ?>
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="custom-card">
              <div class="custom-card-header bg-warning text-dark">
                <h5><i class="bi bi-hourglass-split me-2"></i>Pending Applications</h5>
              </div>
              <div class="custom-card-body">
                <?php
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'applicant'");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                ?>
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="custom-card">
              <div class="custom-card-header bg-success text-white">
                <h5><i class="bi bi-check-circle me-2"></i>Verified Students</h5>
              </div>
              <div class="custom-card-body">
                <?php
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'active'");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>

<?php pg_close($connection); ?>
