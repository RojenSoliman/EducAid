<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: index.php");
  exit;
}

// Fetch barangay distribution
$barangayRes = pg_query($connection, "
  SELECT b.name AS barangay,
         SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS verified,
         SUM(CASE WHEN status='applicant' THEN 1 ELSE 0 END) AS applicant
  FROM students st
  JOIN barangays b ON st.barangay_id = b.barangay_id
  GROUP BY b.name
  HAVING COUNT(*) > 0
  ORDER BY b.name");
$barangayLabels = $barangayVerified = $barangayApplicant = [];
while ($row = pg_fetch_assoc($barangayRes)) {
    $barangayLabels[] = $row['barangay'];
    $barangayVerified[] = (int)$row['verified'];
    $barangayApplicant[] = (int)$row['applicant'];
}

// Fetch gender distribution
$genderRes = pg_query($connection, "
  SELECT sex AS gender,
         SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS verified,
         SUM(CASE WHEN status='applicant' THEN 1 ELSE 0 END) AS applicant
  FROM students
  GROUP BY sex
  HAVING COUNT(*) > 0
  ORDER BY sex");
$genderLabels = $genderVerified = $genderApplicant = [];
while ($row = pg_fetch_assoc($genderRes)) {
    $genderLabels[] = $row['gender'];
    $genderVerified[] = (int)$row['verified'];
    $genderApplicant[] = (int)$row['applicant'];
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
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
  <div id="wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

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
            <div class="dashboard-tile tile-blue">
              <div class="tile-icon"><i class="bi bi-people-fill"></i></div>
              <div class="tile-number">
                <?php
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                ?>
              </div>
              <div class="tile-label">Total Students</div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="dashboard-tile tile-yellow">
              <div class="tile-icon"><i class="bi bi-hourglass-split"></i></div>
              <div class="tile-number">
                <?php
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'applicant'");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                ?>
              </div>
              <div class="tile-label">Pending Applications</div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="dashboard-tile tile-green">
              <div class="tile-icon"><i class="bi bi-check-circle"></i></div>
              <div class="tile-number">
                <?php
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'active'");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                ?>
              </div>
              <div class="tile-label">Verified Students</div>
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
  <!-- Chart Data from PHP -->
   <script>
  function isDataEmpty(datasets) {
    return datasets.every(ds =>
      Array.isArray(ds.data) && ds.data.every(val => val === 0)
    );
  }

  function showNoDataMessage(canvasId, message = "No data available") {
    const container = document.getElementById(canvasId)?.parentElement;
    if (container) {
      const msg = document.createElement("p");
      msg.innerHTML = `<i class="bi bi-info-circle me-2"></i>${message}`;
      msg.className = "text-center text-muted mt-3";
      container.appendChild(msg);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    const barangayChartEl = document.getElementById('barangayChart');
    const genderChartEl = document.getElementById('genderChart');

    // BARANGAY CHART
    if (barangayChartEl) {
      const barangayData = {
        labels: window.barangayLabels || [],
        datasets: [
          {
            label: 'Verified',
            data: window.barangayVerified || [],
            backgroundColor: '#3b8efc',
            borderRadius: 8,
            borderSkipped: false
          },
          {
            label: 'Applicant',
            data: window.barangayApplicant || [],
            backgroundColor: '#ff9800',
            borderRadius: 8,
            borderSkipped: false
          }
        ]
      };

      if (isDataEmpty(barangayData.datasets)) {
        showNoDataMessage('barangayChart');
      } else {
        new Chart(barangayChartEl.getContext('2d'), {
          type: 'bar',
          data: barangayData,
          options: {
            responsive: true,
            plugins: {
              legend: {
                position: 'top',
                labels: {
                  boxWidth: 12,
                  font: { weight: '500' }
                }
              }
            },
            scales: {
              x: { grid: { color: '#f1f1f1' } },
              y: { beginAtZero: true, grid: { color: '#f1f1f1' } }
            },
            elements: {
              bar: {
                borderRadius: 8,
                barPercentage: 0.6,
                categoryPercentage: 0.5
              }
            }
          }
        });
      }
    }

    // GENDER CHART
    if (genderChartEl) {
      const genderData = {
        labels: window.genderLabels || [],
        datasets: [
          {
            label: 'Verified',
            data: window.genderVerified || [],
            backgroundColor: '#3b8efc',
            borderRadius: 8,
            borderSkipped: false
          },
          {
            label: 'Applicant',
            data: window.genderApplicant || [],
            backgroundColor: '#ff9800',
            borderRadius: 8,
            borderSkipped: false
          }
        ]
      };

      if (isDataEmpty(genderData.datasets)) {
        showNoDataMessage('genderChart');
      } else {
        new Chart(genderChartEl.getContext('2d'), {
          type: 'bar',
          data: genderData,
          options: {
            responsive: true,
            plugins: {
              legend: {
                position: 'top',
                labels: {
                  boxWidth: 12,
                  font: { weight: '500' }
                }
              }
            },
            scales: {
              x: { grid: { color: '#f1f1f1' } },
              y: { beginAtZero: true, grid: { color: '#f1f1f1' } }
            },
            elements: {
              bar: {
                borderRadius: 8,
                barPercentage: 0.6,
                categoryPercentage: 0.5
              }
            }
          }
        });
      }
    }
  });
</script>

<script>
  window.barangayLabels = <?php echo json_encode($barangayLabels); ?>;
  window.barangayVerified = <?php echo json_encode($barangayVerified); ?>;
  window.barangayApplicant = <?php echo json_encode($barangayApplicant); ?>;

  window.genderLabels = <?php echo json_encode($genderLabels); ?>;
  window.genderVerified = <?php echo json_encode($genderVerified); ?>;
  window.genderApplicant = <?php echo json_encode($genderApplicant); ?>;
</script>




</body>
</html>

<?php pg_close($connection); ?>
