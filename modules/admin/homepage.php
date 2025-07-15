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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
</head>
<body>
  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar -->
      <nav class="col-md-2 d-flex flex-column sidebar" id="sidebar">
        <div class="text-center mb-4">
          <h4>Admin Dashboard</h4>
        </div>
        <ul class="nav flex-column px-2">
          <li class="nav-item">
            <a class="nav-link active" href="homepage.php">
              <i class="bi bi-house-door"></i>
              <span>Home</span>
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="verify_students.php">
              <i class="bi bi-person-check"></i>
              <span>Verify Students</span>
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#">
              <i class="bi bi-folder2-open"></i>
              <span>Manage Applicants</span>
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="logout.php">
              <i class="bi bi-box-arrow-right"></i>
              <span>Logout</span>
            </a>
          </li>
        </ul>
      </nav>

      <!-- Main content -->
      <main class="col-md-10 ms-auto px-4 main-content" id="mainContent">
        <div class="pt-4 pb-2 mb-3 border-bottom d-flex align-items-center justify-content-between">
          <h2 class="welcome-msg">Welcome, Admin</h2>
          <button id="sidebarToggle"><i class="bi bi-list"></i></button>
        </div>

        <div class="mb-4">
          <h4 class="section-title">Dashboard Overview</h4>
          <p class="section-description">Here you can manage student registrations, verify applicants, and more.</p>
        </div>

        <div class="row">
          <!-- Total Students -->
          <div class="col-md-4">
            <div class="card mb-4">
              <div class="card-body">
                <h5 class="card-title">Total Students</h5>
                <p class="card-text">
                  <?php
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                  ?>
                </p>
              </div>
            </div>
          </div>

          <!-- Pending Applications -->
          <div class="col-md-4">
            <div class="card mb-4">
              <div class="card-body">
                <h5 class="card-title">Pending Applications</h5>
                <p class="card-text">
                  <?php
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'applicant'");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                  ?>
                </p>
              </div>
            </div>
          </div>

          <!-- Verified Students -->
          <div class="col-md-4">
            <div class="card mb-4">
              <div class="card-body">
                <h5 class="card-title">Verified Students</h5>
                <p class="card-text">
                  <?php
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'active'");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                  ?>
                </p>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/homepage.js"></script>
</body>
</html>

<?php
pg_close($connection);
?>
