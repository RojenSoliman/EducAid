<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: index.php");
  exit;
}
// Fetch barangay distribution (only barangays with students)
$barangayRes = pg_query($connection, "SELECT b.name AS barangay, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS verified, SUM(CASE WHEN status='applicant' THEN 1 ELSE 0 END) AS applicant FROM students st JOIN barangays b ON st.barangay_id = b.barangay_id GROUP BY b.name HAVING COUNT(*)>0 ORDER BY b.name");
$barangayLabels = $barangayVerified = $barangayApplicant = [];
while ($row = pg_fetch_assoc($barangayRes)) {
    $barangayLabels[] = $row['barangay'];
    $barangayVerified[] = intval($row['verified']);
    $barangayApplicant[] = intval($row['applicant']);
}
// Fetch gender distribution
$genderRes = pg_query($connection, "SELECT sex AS gender, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS verified, SUM(CASE WHEN status='applicant' THEN 1 ELSE 0 END) AS applicant FROM students GROUP BY sex HAVING COUNT(*)>0 ORDER BY sex");
$genderLabels = $genderVerified = $genderApplicant = [];
while ($row = pg_fetch_assoc($genderRes)) {
    $genderLabels[] = $row['gender'];
    $genderVerified[] = intval($row['verified']);
    $genderApplicant[] = intval($row['applicant']);
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
  <!-- Chart.js for dashboard charts -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        <div class="row g-4 mt-4">
          <div class="col-md-6">
            <div class="custom-card">
              <div class="custom-card-header bg-info text-white">
                <h5><i class="bi bi-house-door-fill me-2"></i>Barangay Distribution</h5>
              </div>
              <div class="custom-card-body">
                <canvas id="barangayChart"></canvas>
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="custom-card">
              <div class="custom-card-header bg-danger text-white">
                <h5><i class="bi bi-gender-ambiguous me-2"></i>Gender Distribution</h5>
              </div>
              <div class="custom-card-body">
                <canvas id="genderChart"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/sidebar.js"></script>
  <script>
    // Barangay Distribution Chart
    const barangayCtx = document.getElementById('barangayChart').getContext('2d');
    const barangayChart = new Chart(barangayCtx, {
      type: 'bar',
      data: {
        labels: <?php echo json_encode($barangayLabels); ?>,
        datasets: [{
          label: 'Verified',
          data: <?php echo json_encode($barangayVerified); ?>,
          backgroundColor: 'rgba(25, 135, 84, 0.7)',
          borderColor: 'rgba(25, 135, 84, 1)',
          borderWidth: 1
        }, {
          label: 'Pending',
          data: <?php echo json_encode($barangayApplicant); ?>,
          backgroundColor: 'rgba(255, 193, 7, 0.7)',
          borderColor: 'rgba(255, 193, 7, 1)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        scales: {
          x: {
            beginAtZero: true
          },
          y: {
            beginAtZero: true
          }
        }
      }
    });

    // Gender Distribution Chart
    const genderCtx = document.getElementById('genderChart').getContext('2d');
    const genderChart = new Chart(genderCtx, {
      type: 'bar',
      data: {
        labels: <?php echo json_encode($genderLabels); ?>,
        datasets: [
          {
            label: 'Verified',
            data: <?php echo json_encode($genderVerified); ?>,
            backgroundColor: 'rgba(25, 135, 84, 0.7)',
            borderColor: 'rgba(25, 135, 84, 1)',
            borderWidth: 1
          },
          {
            label: 'Applicant',
            data: <?php echo json_encode($genderApplicant); ?>,
            backgroundColor: 'rgba(255, 193, 7, 0.7)',
            borderColor: 'rgba(255, 193, 7, 1)',
            borderWidth: 1
          }
        ]
      },
      options: {
        responsive: true,
        scales: {
          x: { beginAtZero: true },
          y: { beginAtZero: true }
        }
      }
    });
  </script>
</body>
</html>

<?php pg_close($connection); ?>
