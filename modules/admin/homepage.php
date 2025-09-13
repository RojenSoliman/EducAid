<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}

$municipality_id = 1; // Default municipality

// Fetch municipality max capacity
$capacityResult = pg_query_params($connection, "SELECT max_capacity FROM municipalities WHERE municipality_id = $1", [$municipality_id]);
$maxCapacity = null;
if ($capacityResult && pg_num_rows($capacityResult) > 0) {
    $capacityRow = pg_fetch_assoc($capacityResult);
    $maxCapacity = $capacityRow['max_capacity'];
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
  WHERE sex IS NOT NULL
  GROUP BY sex
  HAVING COUNT(*) > 0
  ORDER BY sex");
$genderLabels = $genderVerified = $genderApplicant = [];
while ($row = pg_fetch_assoc($genderRes)) {
    $genderLabels[] = $row['gender'];
    $genderVerified[] = (int)$row['verified'];
    $genderApplicant[] = (int)$row['applicant'];
}

// Fetch university distribution
$universityRes = pg_query($connection, "
  SELECT u.name AS university,
         SUM(CASE WHEN st.status='active' THEN 1 ELSE 0 END) AS verified,
         SUM(CASE WHEN st.status='applicant' THEN 1 ELSE 0 END) AS applicant
  FROM students st
  JOIN universities u ON st.university_id = u.university_id
  GROUP BY u.name
  HAVING COUNT(*) > 0
  ORDER BY u.name");
$universityLabels = $universityVerified = $universityApplicant = [];
while ($row = pg_fetch_assoc($universityRes)) {
    $universityLabels[] = $row['university'];
    $universityVerified[] = (int)$row['verified'];
    $universityApplicant[] = (int)$row['applicant'];
}

// Fetch year level distribution
$yearLevelRes = pg_query($connection, "
  SELECT yl.name AS year_level,
         SUM(CASE WHEN st.status='active' THEN 1 ELSE 0 END) AS verified,
         SUM(CASE WHEN st.status='applicant' THEN 1 ELSE 0 END) AS applicant
  FROM students st
  JOIN year_levels yl ON st.year_level_id = yl.year_level_id
  GROUP BY yl.name, yl.sort_order
  HAVING COUNT(*) > 0
  ORDER BY yl.sort_order");
$yearLevelLabels = $yearLevelVerified = $yearLevelApplicant = [];
while ($row = pg_fetch_assoc($yearLevelRes)) {
    $yearLevelLabels[] = $row['year_level'];
    $yearLevelVerified[] = (int)$row['verified'];
    $yearLevelApplicant[] = (int)$row['applicant'];
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

        <!-- Dashboard Tiles -->
        <div class="dashboard-tile-row">
          <div class="dashboard-tile tile-blue">
            <div class="tile-icon"><i class="bi bi-people-fill"></i></div>
            <div class="tile-number">
              <?php
                $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status IN ('applicant', 'active')");
                $row = pg_fetch_assoc($result);
                $totalStudents = $row['total'];
                if ($maxCapacity !== null) {
                    echo $totalStudents . '/' . $maxCapacity;
                } else {
                    echo $totalStudents;
                }
              ?>
            </div>
            <div class="tile-label">
              <?php echo $maxCapacity !== null ? 'Students / Max Capacity' : 'Total Students'; ?>
            </div>
          </div>

          <div class="dashboard-tile tile-orange">
            <div class="tile-icon"><i class="bi bi-clipboard-check"></i></div>
            <div class="tile-number">
              <?php
                $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'under_registration'");
                $row = pg_fetch_assoc($result);
                echo $row['total'];
              ?>
            </div>
            <div class="tile-label">Still on Registration</div>
          </div>

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

        <!-- Unified Chart with Filters -->
        <div class="row g-4 mt-4">
          <div class="col-12">
            <div class="custom-card">
              <div class="custom-card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-bar-chart-fill me-2"></i>Student Distribution</h5>
                <div class="d-flex gap-2">
                  <select id="chartFilter" class="form-select form-select-sm text-dark" style="width: auto;">
                    <option value="gender">By Gender</option>
                    <option value="barangay">By Barangay</option>
                    <option value="university">By University</option>
                    <option value="yearLevel">By Year Level</option>
                  </select>
                </div>
              </div>
              <div class="custom-card-body">
                <canvas id="unifiedChart"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/sidebar.js"></script>

  <!-- Chart.js Data -->
  <script>
    // All chart data
    window.chartData = {
      gender: {
        labels: <?php echo json_encode($genderLabels); ?>,
        verified: <?php echo json_encode($genderVerified); ?>,
        applicant: <?php echo json_encode($genderApplicant); ?>,
        title: "Gender Distribution",
        icon: "bi-gender-ambiguous"
      },
      barangay: {
        labels: <?php echo json_encode($barangayLabels); ?>,
        verified: <?php echo json_encode($barangayVerified); ?>,
        applicant: <?php echo json_encode($barangayApplicant); ?>,
        title: "Barangay Distribution",
        icon: "bi-house-door-fill"
      },
      university: {
        labels: <?php echo json_encode($universityLabels); ?>,
        verified: <?php echo json_encode($universityVerified); ?>,
        applicant: <?php echo json_encode($universityApplicant); ?>,
        title: "University Distribution",
        icon: "bi-building"
      },
      yearLevel: {
        labels: <?php echo json_encode($yearLevelLabels); ?>,
        verified: <?php echo json_encode($yearLevelVerified); ?>,
        applicant: <?php echo json_encode($yearLevelApplicant); ?>,
        title: "Year Level Distribution",
        icon: "bi-mortarboard"
      }
    };
  </script>

  <script>
    let unifiedChart = null;

    function isDataEmpty(datasets) {
      return datasets.every(ds => Array.isArray(ds.data) && ds.data.every(val => val === 0));
    }

    function showNoDataMessage(canvasId, message = "No data available") {
      const container = document.getElementById(canvasId)?.parentElement;
      if (container) {
        // Remove any existing no-data message
        const existingMsg = container.querySelector('.no-data-message');
        if (existingMsg) existingMsg.remove();
        
        const msg = document.createElement("p");
        msg.innerHTML = `<i class="bi bi-info-circle me-2"></i>${message}`;
        msg.className = "text-center text-muted mt-3 no-data-message";
        container.appendChild(msg);
      }
    }

    function removeNoDataMessage(canvasId) {
      const container = document.getElementById(canvasId)?.parentElement;
      if (container) {
        const existingMsg = container.querySelector('.no-data-message');
        if (existingMsg) existingMsg.remove();
      }
    }

    function updateChartTitle(filterType) {
      const headerElement = document.querySelector('.custom-card-header h5');
      const data = window.chartData[filterType];
      if (headerElement && data) {
        headerElement.innerHTML = `<i class="bi ${data.icon} me-2"></i>${data.title}`;
      }
    }

    function createUnifiedChart(filterType = 'gender') {
      const canvas = document.getElementById('unifiedChart');
      if (!canvas) return;

      const data = window.chartData[filterType];
      if (!data) return;

      // Update chart title
      updateChartTitle(filterType);

      const datasets = [
        {
          label: "Verified",
          data: data.verified,
          backgroundColor: "#3b8efc",
          borderRadius: 8,
          borderSkipped: false,
        },
        {
          label: "Applicant",
          data: data.applicant,
          backgroundColor: "#ff9800",
          borderRadius: 8,
          borderSkipped: false,
        }
      ];

      // Check if data is empty
      if (isDataEmpty(datasets)) {
        if (unifiedChart) {
          unifiedChart.destroy();
          unifiedChart = null;
        }
        showNoDataMessage('unifiedChart', `No ${filterType.toLowerCase()} data available`);
        return;
      }

      // Remove no-data message if exists
      removeNoDataMessage('unifiedChart');

      // Destroy existing chart
      if (unifiedChart) {
        unifiedChart.destroy();
      }

      // Create new chart
      unifiedChart = new Chart(canvas.getContext("2d"), {
        type: "bar",
        data: { 
          labels: data.labels, 
          datasets: datasets 
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: "top",
              labels: {
                boxWidth: 12,
                font: { weight: "500" }
              }
            }
          },
          scales: {
            x: { 
              grid: { color: "#f1f1f1" },
              ticks: {
                maxRotation: filterType === 'university' ? 45 : 0,
                font: {
                  size: filterType === 'university' ? 10 : 12
                }
              }
            },
            y: { 
              beginAtZero: true, 
              grid: { color: "#f1f1f1" }
            }
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

    document.addEventListener("DOMContentLoaded", () => {
      // Set canvas height
      const canvas = document.getElementById('unifiedChart');
      if (canvas) {
        canvas.style.height = '400px';
      }

      // Initialize chart with default filter
      createUnifiedChart('gender');

      // Add event listener for filter change
      const filterSelect = document.getElementById('chartFilter');
      if (filterSelect) {
        filterSelect.addEventListener('change', (e) => {
          createUnifiedChart(e.target.value);
        });
      }
    });
  </script>
</body>
</html>

<?php pg_close($connection); ?>
