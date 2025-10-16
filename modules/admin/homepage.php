  <?php
session_start();

// Demo mode handling: support a persistent toggle via session + robust query parsing
// 1) Toggle: ?toggle_demo=1 -> flips the current mode and redirects (PRG)
if (isset($_GET['toggle_demo'])) {
  $_SESSION['DEMO_MODE'] = !($_SESSION['DEMO_MODE'] ?? false);
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}

// 2) Explicit set: ?demo=1|true|yes|on or ?demo=0|false|no|off -> sets and redirects (PRG)
if (isset($_GET['demo'])) {
  $val = strtolower((string)$_GET['demo']);
  $truthy = ['1','true','yes','on'];
  $falsy  = ['0','false','no','off',''];
  if (in_array($val, $truthy, true)) {
    $_SESSION['DEMO_MODE'] = true;
  } elseif (in_array($val, $falsy, true)) {
    $_SESSION['DEMO_MODE'] = false;
  }
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}

// Effective mode comes from session (defaults to off)
$DEMO_MODE = $_SESSION['DEMO_MODE'] ?? false;

// Only include DB when not in demo mode
if (!$DEMO_MODE) {
  include __DIR__ . '/../../config/database.php';
}
if (!isset($_SESSION['admin_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}

$municipality_id = 1; // Default municipality

// Fetch municipality max capacity (or use demo value)
$maxCapacity = null;
if ($DEMO_MODE) {
  $maxCapacity = 1200;
} else {
  $capacityResult = pg_query_params($connection, "SELECT max_capacity FROM municipalities WHERE municipality_id = $1", [$municipality_id]);
  if ($capacityResult && pg_num_rows($capacityResult) > 0) {
    $capacityRow = pg_fetch_assoc($capacityResult);
    $maxCapacity = $capacityRow['max_capacity'];
  }
}

// Fetch barangay distribution (or use demo data)
$barangayLabels = $barangayVerified = $barangayApplicant = [];
if ($DEMO_MODE) {
    $barangayLabels = ['Santiago','San Roque','Poblacion','Mataas na Bayan','Bucal','Tamacan'];
    $barangayVerified = [84, 67, 112, 93, 45, 61];
    $barangayApplicant = [40, 25, 58, 32, 18, 27];
} else {
    $barangayRes = pg_query($connection, "
      SELECT b.name AS barangay,
             SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS verified,
             SUM(CASE WHEN status='applicant' THEN 1 ELSE 0 END) AS applicant
      FROM students st
      JOIN barangays b ON st.barangay_id = b.barangay_id
      GROUP BY b.name
      HAVING COUNT(*) > 0
      ORDER BY b.name");
    while ($row = pg_fetch_assoc($barangayRes)) {
        $barangayLabels[] = $row['barangay'];
        $barangayVerified[] = (int)$row['verified'];
        $barangayApplicant[] = (int)$row['applicant'];
    }
}

// Fetch gender distribution (or use demo data)
$genderLabels = $genderVerified = $genderApplicant = [];
if ($DEMO_MODE) {
    $genderLabels = ['Male','Female'];
    $genderVerified = [260, 224];
    $genderApplicant = [130, 228];
} else {
    $genderRes = pg_query($connection, "
      SELECT sex AS gender,
             SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS verified,
             SUM(CASE WHEN status='applicant' THEN 1 ELSE 0 END) AS applicant
      FROM students
      WHERE sex IS NOT NULL
      GROUP BY sex
      HAVING COUNT(*) > 0
      ORDER BY sex");
    while ($row = pg_fetch_assoc($genderRes)) {
        $genderLabels[] = $row['gender'];
        $genderVerified[] = (int)$row['verified'];
        $genderApplicant[] = (int)$row['applicant'];
    }
}

// Fetch university distribution (or use demo data)
$universityLabels = $universityVerified = $universityApplicant = [];
if ($DEMO_MODE) {
    $universityLabels = [
      'Lyceum of the Philippines University - Cavite',
      'Cavite State University',
      'De La Salle University - Dasmariñas',
      'Far Eastern University',
      'Polytechnic University of the Philippines'
    ];
    $universityVerified = [95, 132, 78, 54, 60];
    $universityApplicant = [42, 66, 40, 22, 30];
} else {
    $universityRes = pg_query($connection, "
      SELECT u.name AS university,
             SUM(CASE WHEN st.status='active' THEN 1 ELSE 0 END) AS verified,
             SUM(CASE WHEN st.status='applicant' THEN 1 ELSE 0 END) AS applicant
      FROM students st
      JOIN universities u ON st.university_id = u.university_id
      GROUP BY u.name
      HAVING COUNT(*) > 0
      ORDER BY u.name");
    while ($row = pg_fetch_assoc($universityRes)) {
        $universityLabels[] = $row['university'];
        $universityVerified[] = (int)$row['verified'];
        $universityApplicant[] = (int)$row['applicant'];
    }
}

// Fetch year level distribution (or use demo data)
$yearLevelLabels = $yearLevelVerified = $yearLevelApplicant = [];
if ($DEMO_MODE) {
    $yearLevelLabels = ['1st Year','2nd Year','3rd Year','4th Year'];
    $yearLevelVerified = [140, 160, 100, 84];
    $yearLevelApplicant = [90, 72, 56, 40];
} else {
    $yearLevelRes = pg_query($connection, "
      SELECT yl.name AS year_level,
             SUM(CASE WHEN st.status='active' THEN 1 ELSE 0 END) AS verified,
             SUM(CASE WHEN st.status='applicant' THEN 1 ELSE 0 END) AS applicant
      FROM students st
      JOIN year_levels yl ON st.year_level_id = yl.year_level_id
      GROUP BY yl.name, yl.sort_order
      HAVING COUNT(*) > 0
      ORDER BY yl.sort_order");
    while ($row = pg_fetch_assoc($yearLevelRes)) {
        $yearLevelLabels[] = $row['year_level'];
        $yearLevelVerified[] = (int)$row['verified'];
        $yearLevelApplicant[] = (int)$row['applicant'];
    }
}

// Fetch past distributions (or use demo data)
$pastDistributions = [];
if ($DEMO_MODE) {
    $pastDistributions = [
      [
        'snapshot_id' => 1,
        'distribution_date' => date('Y-m-d', strtotime('-20 days')),
        'location' => 'Town Plaza',
        'total_students_count' => 520,
        'academic_year' => '2025-2026',
        'semester' => '1st Sem',
        'finalized_at' => date('Y-m-d H:i:s', strtotime('-19 days 15:00')),
        'notes' => 'Smooth distribution; minor queue delays.',
        'finalized_by_name' => 'Admin User'
      ],
      [
        'snapshot_id' => 2,
        'distribution_date' => date('Y-m-d', strtotime('-90 days')),
        'location' => 'Municipal Gym',
        'total_students_count' => 480,
        'academic_year' => '2024-2025',
        'semester' => '2nd Sem',
        'finalized_at' => date('Y-m-d H:i:s', strtotime('-89 days 14:30')),
        'notes' => '',
        'finalized_by_name' => 'Jane Doe'
      ],
      [
        'snapshot_id' => 3,
        'distribution_date' => date('Y-m-d', strtotime('-200 days')),
        'location' => 'Barangay Hall',
        'total_students_count' => 450,
        'academic_year' => '2024-2025',
        'semester' => '1st Sem',
        'finalized_at' => date('Y-m-d H:i:s', strtotime('-199 days 10:15')),
        'notes' => 'Special priority lanes tested successfully.',
        'finalized_by_name' => 'John Smith'
      ]
    ];
} else {
    $pastDistributionsRes = pg_query($connection, "
      SELECT 
        ds.snapshot_id,
        ds.distribution_date,
        ds.location,
        ds.total_students_count,
        ds.academic_year,
        ds.semester,
        ds.finalized_at,
        ds.notes,
        CONCAT(a.first_name, ' ', a.last_name) as finalized_by_name
      FROM distribution_snapshots ds
      LEFT JOIN admins a ON ds.finalized_by = a.admin_id
      ORDER BY ds.finalized_at DESC
      LIMIT 5
    ");
    if ($pastDistributionsRes) {
        while ($row = pg_fetch_assoc($pastDistributionsRes)) {
            $pastDistributions[] = $row;
        }
    }
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
  <?php include '../../includes/admin/admin_topbar.php'; ?>
  
  <div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">

      <div class="container-fluid py-4 px-4">
        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
          <h1 class="fw-bold mb-1 mb-2 mb-sm-0">Welcome, <?php echo htmlspecialchars($_SESSION['admin_username']); ?></h1>
          <a class="btn btn-sm <?php echo $DEMO_MODE ? 'btn-warning' : 'btn-outline-warning'; ?> ms-sm-2" href="?toggle_demo=1" title="Toggle demo mode">
            <i class="bi bi-camera-video me-1"></i><?php echo $DEMO_MODE ? 'Exit Demo' : 'Enter Demo'; ?>
          </a>
          <?php if ($DEMO_MODE): ?>
            <span class="badge bg-warning text-dark ms-2"><i class="bi bi-lightning-charge me-1"></i>Demo Mode</span>
          <?php endif; ?>
        </div>
        <p class="text-muted mb-0">Here you can manage student registrations, verify applicants, and more.</p>

        <!-- Automatic Archiving Notification (Super Admin Only) -->
        <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
        <div id="archivingNotification" class="alert alert-warning alert-dismissible fade show mt-3" style="display: none;">
          <div class="d-flex align-items-start">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-4"></i>
            <div class="flex-grow-1">
              <h6 class="alert-heading mb-1">Automatic Archiving Recommended</h6>
              <p id="archivingMessage" class="mb-2"></p>
              <a href="run_automatic_archiving_admin.php" class="btn btn-warning btn-sm">
                <i class="bi bi-archive"></i> Review & Run Archiving
              </a>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Dashboard Tiles -->
        <div class="dashboard-tile-row">
          <div class="dashboard-tile tile-blue">
            <div class="tile-icon"><i class="bi bi-people-fill"></i></div>
            <div class="tile-number">
              <?php
                if ($DEMO_MODE) {
                  $totalStudents = array_sum($genderVerified ?? []) + array_sum($genderApplicant ?? []);
                } else {
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status IN ('applicant', 'active')");
                  $row = pg_fetch_assoc($result);
                  $totalStudents = (int)$row['total'];
                }
                echo $maxCapacity !== null ? ($totalStudents . '/' . $maxCapacity) : $totalStudents;
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
                if ($DEMO_MODE) {
                  echo 37; // sample
                } else {
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'under_registration'");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                }
              ?>
            </div>
            <div class="tile-label">Still on Registration</div>
          </div>

          <div class="dashboard-tile tile-yellow">
            <div class="tile-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="tile-number">
              <?php
                if ($DEMO_MODE) {
                  echo array_sum($genderApplicant ?? []);
                } else {
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'applicant'");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                }
              ?>
            </div>
            <div class="tile-label">Pending Applications</div>
          </div>

          <div class="dashboard-tile tile-green">
            <div class="tile-icon"><i class="bi bi-check-circle"></i></div>
            <div class="tile-number">
              <?php
                if ($DEMO_MODE) {
                  echo array_sum($genderVerified ?? []);
                } else {
                  $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'active'");
                  $row = pg_fetch_assoc($result);
                  echo $row['total'];
                }
              ?>
            </div>
            <div class="tile-label">Verified Students</div>
          </div>
        </div>

        <!-- Website Content Management (Super Admin Only) -->
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin'): ?>
        <div class="row g-4 mt-4">
          <div class="col-12">
            <div class="custom-card">
              <div class="custom-card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-pencil-square me-2"></i>Website Content Management</h5>
                <span class="badge bg-white text-info">Super Admin Access</span>
              </div>
              <div class="custom-card-body">
                <p class="text-muted mb-3">
                  <i class="bi bi-info-circle me-1"></i>
                  Edit static content on public website pages. Click "Edit Page" to modify text, colors, and layout.
                </p>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead class="table-light">
                      <tr>
                        <th style="width: 30%;"><i class="bi bi-file-earmark-text me-1"></i>Page</th>
                        <th style="width: 15%;" class="text-center"><i class="bi bi-puzzle me-1"></i>Blocks</th>
                        <th style="width: 20%;" class="text-center"><i class="bi bi-clock-history me-1"></i>Last Updated</th>
                        <th style="width: 20%;" class="text-center"><i class="bi bi-eye me-1"></i>View</th>
                        <th style="width: 15%;" class="text-center"><i class="bi bi-pencil me-1"></i>Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>
                          <i class="bi bi-house-door text-primary me-2"></i>
                          <strong>Landing Page</strong>
                          <br><small class="text-muted">Homepage with hero, features, FAQs</small>
                        </td>
                        <td class="text-center"><span class="badge bg-primary">50+ blocks</span></td>
                        <td class="text-center text-muted small">
                          <?php
                            if (!$DEMO_MODE) {
                              $lpUpdate = pg_query($connection, "SELECT MAX(updated_at) as last_update FROM landing_content_blocks WHERE municipality_id=1");
                              if ($lpUpdate && $row = pg_fetch_assoc($lpUpdate)) {
                                echo $row['last_update'] ? date('M d, Y H:i', strtotime($row['last_update'])) : 'Never';
                              } else { echo 'Never'; }
                            } else { echo 'Demo Mode'; }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/landingpage.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/landingpage.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <i class="bi bi-info-circle text-success me-2"></i>
                          <strong>About Page</strong>
                          <br><small class="text-muted">Program information & history</small>
                        </td>
                        <td class="text-center"><span class="badge bg-success">20+ blocks</span></td>
                        <td class="text-center text-muted small">
                          <?php
                            if (!$DEMO_MODE) {
                              $aboutUpdate = pg_query($connection, "SELECT MAX(updated_at) as last_update FROM about_content_blocks WHERE municipality_id=1");
                              if ($aboutUpdate && $row = pg_fetch_assoc($aboutUpdate)) {
                                echo $row['last_update'] ? date('M d, Y H:i', strtotime($row['last_update'])) : 'Never';
                              } else { echo 'Never'; }
                            } else { echo 'Demo Mode'; }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/about.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/about.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <i class="bi bi-diagram-3 text-info me-2"></i>
                          <strong>How It Works</strong>
                          <br><small class="text-muted">Application process & steps</small>
                        </td>
                        <td class="text-center"><span class="badge bg-info">13 blocks</span></td>
                        <td class="text-center text-muted small">
                          <?php
                            if (!$DEMO_MODE) {
                              $hiwUpdate = pg_query($connection, "SELECT MAX(updated_at) as last_update FROM how_it_works_content_blocks WHERE municipality_id=1");
                              if ($hiwUpdate && $row = pg_fetch_assoc($hiwUpdate)) {
                                echo $row['last_update'] ? date('M d, Y H:i', strtotime($row['last_update'])) : 'Never';
                              } else { echo 'Never'; }
                            } else { echo 'Demo Mode'; }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/how-it-works.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/how-it-works.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <i class="bi bi-card-checklist text-warning me-2"></i>
                          <strong>Requirements</strong>
                          <br><small class="text-muted">Document requirements & checklist</small>
                        </td>
                        <td class="text-center"><span class="badge bg-warning text-dark">52 blocks</span></td>
                        <td class="text-center text-muted small">
                          <?php
                            if (!$DEMO_MODE) {
                              $reqUpdate = pg_query($connection, "SELECT MAX(updated_at) as last_update FROM requirements_content_blocks WHERE municipality_id=1");
                              if ($reqUpdate && $row = pg_fetch_assoc($reqUpdate)) {
                                echo $row['last_update'] ? date('M d, Y H:i', strtotime($row['last_update'])) : 'Never';
                              } else { echo 'Never'; }
                            } else { echo 'Demo Mode'; }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/requirements.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/requirements.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <i class="bi bi-megaphone text-danger me-2"></i>
                          <strong>Announcements</strong>
                          <br><small class="text-muted">Page headers & descriptions</small>
                        </td>
                        <td class="text-center"><span class="badge bg-danger">7 blocks</span></td>
                        <td class="text-center text-muted small">
                          <?php
                            if (!$DEMO_MODE) {
                              $annUpdate = pg_query($connection, "SELECT MAX(updated_at) as last_update FROM announcements_content_blocks WHERE municipality_id=1");
                              if ($annUpdate && $row = pg_fetch_assoc($annUpdate)) {
                                echo $row['last_update'] ? date('M d, Y H:i', strtotime($row['last_update'])) : 'Never';
                              } else { echo 'Never'; }
                            } else { echo 'Demo Mode'; }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/announcements.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/announcements.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <div class="d-flex align-items-center">
                            <div class="page-icon me-2" style="background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);">
                              <i class="bi bi-envelope-fill"></i>
                            </div>
                            <div>
                              <div class="fw-semibold">Contact</div>
                              <small class="text-muted">Contact information & inquiry form</small>
                            </div>
                          </div>
                        </td>
                        <td class="text-center">
                          <?php
                          try {
                            $stmt = $connection->query("SELECT COUNT(*) as count FROM contact_content_blocks WHERE municipality_id = 1");
                            $count = $stmt->fetch(PDO::FETCH_ASSOC);
                            echo '<span class="badge bg-secondary">' . ($count['count'] ?? 0) . ' blocks</span>';
                          } catch (Exception $e) {
                            echo '<span class="badge bg-danger">Error</span>';
                          }
                          ?>
                        </td>
                        <td class="text-center">
                          <?php
                          try {
                            $stmt = $connection->query("SELECT MAX(updated_at) as last_updated FROM contact_content_blocks WHERE municipality_id = 1");
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($result['last_updated']) {
                              $date = new DateTime($result['last_updated']);
                              echo '<small class="text-muted">' . $date->format('M d, Y g:i A') . '</small>';
                            } else {
                              echo '<small class="text-muted">Never edited</small>';
                            }
                          } catch (Exception $e) {
                            echo '<small class="text-muted">N/A</small>';
                          }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/contact.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/contact.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div class="alert alert-info d-flex align-items-center mt-3 mb-0">
                  <i class="bi bi-lightbulb me-2 flex-shrink-0"></i>
                  <div>
                    <strong>Tip:</strong> Changes are saved per page and can be rolled back via the history feature in each page editor.
                    The "Blocks" count represents editable content sections on each page.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

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

        <!-- Past Distributions Section -->
        <?php if (!empty($pastDistributions)): ?>
        <div class="row g-4 mt-4">
          <div class="col-12">
            <div class="custom-card">
              <div class="custom-card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-clock-history me-2"></i>Recent Distribution History</h5>
                <a href="manage_distributions.php" class="btn btn-light btn-sm">
                  <i class="bi bi-arrow-right me-1"></i>View All
                </a>
              </div>
              <div class="custom-card-body">
                <div class="row g-3">
                  <?php foreach ($pastDistributions as $distribution): ?>
                  <div class="col-12 col-md-6 col-xl-4">
                    <div class="border rounded-3 p-3 h-100 shadow-sm">
                      <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-primary">
                          <i class="bi bi-calendar me-1"></i><?php echo date('M d, Y', strtotime($distribution['distribution_date'])); ?>
                        </span>
                        <span class="badge bg-success">
                          <i class="bi bi-people me-1"></i><?php echo number_format($distribution['total_students_count']); ?> students
                        </span>
                      </div>
                      <div class="mb-2 text-truncate" title="<?php echo htmlspecialchars($distribution['location']); ?>">
                        <i class="bi bi-geo-alt text-muted me-1"></i>
                        <strong><?php echo htmlspecialchars($distribution['location']); ?></strong>
                      </div>
                      <div class="d-flex flex-wrap small text-muted mb-2">
                        <?php if ($distribution['academic_year'] && $distribution['semester']): ?>
                          <span class="me-2"><i class="bi bi-mortarboard me-1"></i><?php echo htmlspecialchars($distribution['academic_year'] . ' - ' . $distribution['semester']); ?></span>
                        <?php endif; ?>
                      </div>
                      <div class="d-flex justify-content-between align-items-center small text-muted">
                        <span><i class="bi bi-person-check me-1"></i><?php echo htmlspecialchars($distribution['finalized_by_name'] ?: 'Unknown'); ?></span>
                        <span><i class="bi bi-clock me-1"></i><?php echo date('M d, Y H:i', strtotime($distribution['finalized_at'])); ?></span>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
                
                <?php if (!empty($pastDistributions[0]['notes'])): ?>
                <div class="mt-3">
                  <h6 class="text-muted mb-2">Latest Distribution Notes:</h6>
                  <div class="alert alert-light border">
                    <i class="bi bi-sticky me-2"></i>
                    <?php echo nl2br(htmlspecialchars($pastDistributions[0]['notes'])); ?>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
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

      // Check for automatic archiving notification (Super Admin only)
      <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
      fetch('check_automatic_archiving.php')
        .then(response => response.json())
        .then(data => {
          if (data.should_archive) {
            const notification = document.getElementById('archivingNotification');
            const message = document.getElementById('archivingMessage');
            if (notification && message) {
              message.innerHTML = data.message;
              notification.style.display = 'block';
            }
          }
        })
        .catch(error => {
          console.error('Error checking archiving status:', error);
        });
      <?php endif; ?>
    });
  </script>
</body>
</html>

<?php if (!$DEMO_MODE && isset($connection)) { pg_close($connection); } ?>
