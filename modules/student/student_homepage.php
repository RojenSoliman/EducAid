<?php
session_start();
if (!isset($_SESSION['student_username'])) {
  header("Location: student_login.html");
  exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>EducAid – Student Dashboard</title>

  <!-- Bootstrap + Icons -->
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />

  <!-- Custom CSS -->
  <link rel="stylesheet" href="../../assets/css/homepage.css" />
</head>
<body>
  <div id="wrapper">
    <!-- Sidebar -->
    <div class="sidebar close" id="sidebar">
      <div class="logo-details">
        <i class="bi bi-mortarboard-fill icon"></i>
        <span class="logo_name">EducAid</span>
      </div>
      <ul class="nav-list">
        <li class="nav-item active">
          <a href="#">
            <i class="bi bi-house-door icon"></i>
            <span class="links_name">Dashboard</span>
          </a>
        </li>
        <li class="nav-item">
          <a href="#">
            <i class="bi bi-upload icon"></i>
            <span class="links_name">Upload Documents</span>
          </a>
        </li>
        <li class="nav-item">
          <a href="#">
            <i class="bi bi-qr-code-scan icon"></i>
            <span class="links_name">My QR Code</span>
          </a>
        </li>
        <li class="nav-item">
          <a href="notificaton.html">
            <i class="bi bi-bell icon"></i>
            <span class="links_name">Notifications</span>
          </a>
        </li>
        <li class="nav-item">
          <a href="#">
            <i class="bi bi-person icon"></i>
            <span class="links_name">Profile</span>
          </a>
        </li>
        <li class="nav-item logout">
            <a href="#" onclick="confirmLogout(event)">
                <i class="bi bi-box-arrow-right icon"></i>
                <span class="links_name">Logout</span>
            </a>
        </li>
      </ul>
    </div>
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

    <!-- Page Content -->
    <section class="home-section" id="page-content-wrapper">
      <nav>
        <div class="sidebar-toggle px-4 py-3">
          <i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i>
        </div>
      </nav>

      <div class="container-fluid py-4 px-4">
        <!-- Header -->
        <div class="d-flex align-items-center mb-4">
          <img src="../../assets/images/default/profile.png" class="rounded-circle me-3" width="60" height="60" alt="Student Profile">
          <div>
            <h2 class="fw-bold mb-1">Welcome, <?php echo htmlspecialchars($_SESSION['student_username']); ?>!</h2>
            <small class="text-muted">Last login: July 10, 2025 – 9:14 AM</small>
          </div>
        </div>
       <!-- Educational Event -->
        <?php
          include '../../config/database.php';
          $announcement = pg_query($connection, "SELECT * FROM announcements WHERE municipality_id = 1 AND is_active = TRUE ORDER BY created_at DESC LIMIT 1");
          if ($announcement && pg_num_rows($announcement) > 0) {
              $row = pg_fetch_assoc($announcement);
              echo "<div class='custom-card mb-4 shadow-sm'>
                      <div class='custom-card-header bg-warning text-dark'>
                        <h5 class='mb-0'><i class='bi bi-calendar-event me-2'></i>" . htmlspecialchars($row['title']) . "</h5>
                      </div>
                      <div class='custom-card-body'>
                        <ul class='mb-0'>
                          <li><strong>Location:</strong> " . htmlspecialchars($row['location']) . "</li>
                          <li><strong>Date:</strong> " . htmlspecialchars($row['announcement_date']) . "</li>
                          <li><strong>Time:</strong> " . htmlspecialchars($row['time']) . "</li>
                          <li><strong>Reminder:</strong> " . htmlspecialchars($row['reminder']) . "</li>
                        </ul>
                      </div>
                    </div>";
          } else {
              echo "<div class='custom-card mb-4 shadow-sm>No current announcements available.
                      <div class='custom-card-header bg-warning text-dark'>
                        <h5 class='mb-0'><i class='bi bi-calendar-event me-2'></i>No current announcements.</h5>
                      </div>
                    </div>";
          }
          ?>
        <!-- Dashboard Cards -->
        <div class="row g-4 mb-4">
          <div class="col-md-4">
            <div class="custom-card shadow-sm">
              <div class="custom-card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-award me-2"></i>Scholarship Program</h5>
              </div>
              <div class="custom-card-body">
                Tertiary Education Assistance
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <?php
            // Example: fetch status from database or session
            // For demonstration, let's assume $status is set
            // $status = 'active'; // Possible values: 'active', 'applicant', 'disabled'

            // Replace this with your actual logic to get the status
            $status = 'active'; // Example, replace with real value
            $result = pg_query_params($connection, "SELECT status FROM students WHERE student_id = $1", [$_SESSION['student_id']]);
            $status = null;
            if ($result && pg_num_rows($result) > 0) {
              $row = pg_fetch_assoc($result);
              $status = $row['status'];
            }
            if ($status === 'active') {
              $cardHeaderClass = 'bg-success text-white';
              $icon = 'bi-check2-circle';
              $statusText = 'Verified';
              $bodyClass = 'text-success fw-semibold';
            } elseif ($status === 'applicant') {
              $cardHeaderClass = 'bg-warning text-dark';
              $icon = 'bi-hourglass-split';
              $statusText = 'Applicant';
              $bodyClass = 'text-warning fw-semibold';
            } elseif ($status === 'disabled') {
              $cardHeaderClass = 'bg-danger text-white';
              $icon = 'bi-x-circle';
              $statusText = 'Disabled';
              $bodyClass = 'text-danger fw-semibold';
            } else {
              $cardHeaderClass = 'bg-secondary text-white';
              $icon = 'bi-question-circle';
              $statusText = 'Unknown';
              $bodyClass = 'text-secondary fw-semibold';
            }
            ?>
            <div class="custom-card shadow-sm">
              <div class="custom-card-header <?php echo $cardHeaderClass; ?>">
              <h5 class="mb-0"><i class="bi <?php echo $icon; ?> me-2"></i>Application Status</h5>
              </div>
              <div class="custom-card-body <?php echo $bodyClass; ?>">
              <?php echo $statusText; ?>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="custom-card shadow-sm">
              <div class="custom-card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Last Updated</h5>
              </div>
              <div class="custom-card-body">
                July 8, 2025
              </div>
            </div>
          </div>
        </div>

        <div id="deadline-section" class="custom-card border border-2 border-danger-subtle mb-4 shadow-sm">
          <div class="p-3 d-flex justify-content-between align-items-center bg-danger-subtle border-bottom" data-bs-toggle="collapse" data-bs-target="#deadline-body" style="cursor: pointer;">
            <h5 class="mb-0 text-danger fw-bold">
              <i class="bi bi-hourglass-top me-2"></i>Application Submission Deadlines
            </h5>
            <span class="badge bg-light text-danger border border-danger" id="deadline-badge">Loading...</span>
          </div>
        
          <div id="deadline-body" class="collapse show">
            <div class="custom-card-body">
              <div class="row gy-3">
                <div class="col-md-6">
                  <p><strong>Applicant Deadline:</strong><br>August 20, 2025
                    <span class="badge bg-success ms-2"><i class="bi bi-check-circle me-1"></i>On Time</span>
                  </p>
                </div>
                <div class="col-md-6">
                  <p><strong>Reapplicant Deadline:</strong><br>August 25, 2025
                    <span class="badge bg-success ms-2"><i class="bi bi-check-circle me-1"></i>On Time</span>
                  </p>
                </div>
              </div>
              <p class="text-success fw-semibold mt-3">
                <i class="bi bi-info-circle me-1"></i>You are still within the submission period.
              </p>
              <div class="text-end mt-3">
                <a href="apply_now.html" class="btn btn-primary px-4">
                  <i class="bi bi-pencil-square me-1"></i>Apply Now
                </a>
              </div>
            </div>
          </div>
        </div>
        

        <!-- Reminders -->
        <div class="custom-card mb-4 shadow-sm">
          <div class="custom-card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-bell-fill me-2"></i>Reminders</h5>
          </div>
          <div class="custom-card-body">
            <ul class="mb-0">
              <li>Upload your updated grades by <strong>August 15</strong>.</li>
              <li>Check notifications regularly for city updates.</li>
            </ul>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/homepage.js"></script>
  <script src="../../assets/js/deadline.js"></script>

    <script>
        function confirmLogout(event) {
            event.preventDefault(); // Stop default <a> action

            if (confirm("Are you sure you want to logout?")) {
            window.location.href = 'logout.php'; // Now redirect manually
            }
        }
    </script>

</body>
</html>
