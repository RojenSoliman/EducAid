<?php
session_start();
if (!isset($_SESSION['student_username'])) {
  header("Location: student_login.html");
  exit;
}
// Include database connection
include __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['schedule_modal_shown'])) {
    $_SESSION['schedule_modal_shown'] = true;
    $showScheduleModal = true;
} else {
    $showScheduleModal = false;
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
  <link rel="stylesheet" href="../../assets/css/student/homepage.css" />
  <style>
    body:not(.js-ready) .sidebar { visibility: hidden; transition: none !important; }
  </style>
</head>
<body>
  <div id="wrapper">
    <!-- Sidebar -->
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
   <!-- Page Content -->
    <!-- Page Content -->
    <section class="home-section" id="page-content-wrapper">
      <nav>
        <div class="sidebar-toggle px-4 py-3">
          <i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i>
        </div>
      </nav>

      <div class="container-fluid py-4 px-4">
        <!-- Header -->
        <div class="d-flex align-items-center mb-4 section-spacing">
          <img src="../../assets/images/default/profile.png" class="rounded-circle me-3" width="60" height="60" alt="Student Profile">
          <div>
            <h2 class="fw-bold mb-1">Welcome, <?php echo htmlspecialchars($_SESSION['student_username']); ?>!</h2>
            <small class="text-muted">Last login: July 10, 2025 – 9:14 AM</small>
          </div>
        </div>

        <!-- Dashboard Cards -->
        <div class="row g-4 section-spacing">
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


        <!-- Schedule Section -->
        <?php
        // Check if schedule has been published
        $settings = file_exists(__DIR__ . '/../../data/municipal_settings.json')
            ? json_decode(file_get_contents(__DIR__ . '/../../data/municipal_settings.json'), true)
            : [];
        // Load scheduled location
        $location = isset($settings['schedule_meta']['location']) ? $settings['schedule_meta']['location'] : '';
        if (!empty($settings['schedule_published'])) {
            // Fetch this student's schedule
            $studentId = $_SESSION['student_id'];
            // Fetch this student's schedules by payroll number (student_id in schedules may be null)
            // Fetch this student's schedules by student_id or payroll_no
            $schedRes = pg_query_params($connection,
                'SELECT distribution_date, batch_no, time_slot
                 FROM schedules
                 WHERE student_id = $1
                    OR payroll_no = (
                        SELECT payroll_no FROM students WHERE student_id = $1
                    )
                 ORDER BY distribution_date, batch_no',
                [$studentId]
            );
            if ($schedRes && pg_num_rows($schedRes) > 0) {
                $rows = pg_fetch_all($schedRes);
                // Show modal on first login instead of alert
                if ($showScheduleModal) {
                    echo '<div class="modal fade" id="scheduleModal" tabindex="-1" aria-labelledby="scheduleModalLabel" aria-hidden="true">'
                       . '<div class="modal-dialog modal-dialog-centered">'
                       . '<div class="modal-content">'
                       . '<div class="modal-header">'
                       . '<h5 class="modal-title" id="scheduleModalLabel">Your Distribution Schedule</h5>'
                       . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
                       . '</div>'
                       . '<div class="modal-body">';
                    // Display location
                    if ($location !== '') {
                        echo '<p><strong>Location:</strong> ' . htmlspecialchars($location) . '</p>';
                    }
                    foreach ($rows as $r) {
                        echo htmlspecialchars($r['distribution_date']) . ' (Batch ' . htmlspecialchars($r['batch_no']) . '): ' . htmlspecialchars($r['time_slot']) . '<br>';
                    }
                    echo '</div><div class="modal-footer">'
                       . '<button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>'
                       . '</div></div></div></div>';
                    // JS trigger for modal
                    echo '<script>document.addEventListener("DOMContentLoaded", function() {'
                        . 'var modal = new bootstrap.Modal(document.getElementById("scheduleModal"));'
                        . 'modal.show();'
                        . '});</script>';
                }
                // Render schedule card
                echo "<div class='custom-card mb-4'><div class='custom-card-header bg-info text-white'><h5 class='mb-0'><i class='bi bi-calendar3 me-2'></i>Your Schedule</h5></div><div class='custom-card-body'>";
                // Show location in card
                if ($location !== '') {
                    echo '<p><strong>Location:</strong> ' . htmlspecialchars($location) . '</p>';
                }
                echo "<table class='table'><thead><tr><th>Date</th><th>Batch</th><th>Time Slot</th></tr></thead><tbody>";
                foreach ($rows as $s) {
                    echo '<tr>'
                         . '<td>' . htmlspecialchars($s['distribution_date']) . '</td>'
                         . '<td>' . htmlspecialchars($s['batch_no']) . '</td>'
                         . '<td>' . htmlspecialchars($s['time_slot']) . '</td>'
                         . '</tr>';
                }
                echo "</tbody></table></div></div>";
            } else {
                echo "<div class='custom-card mb-4'><div class='custom-card-body'>Your schedule will appear here once published.</div></div>";
            }
        }
        ?>

        <!-- Announcements -->
        <?php
          // Fetch latest active announcement
          $annRes = pg_query($connection, 
            "SELECT title, remarks, posted_at FROM announcements WHERE is_active = TRUE ORDER BY posted_at DESC LIMIT 1"
          );
          if ($annRes && pg_num_rows($annRes) > 0) {
              $ann = pg_fetch_assoc($annRes);
              echo "<div class='custom-card mb-4 shadow-sm section-spacing'>";
              echo "<div class='custom-card-header bg-warning text-dark'><h5 class='mb-0'><i class='bi bi-megaphone me-2'></i>" . htmlspecialchars(
                       $ann['title']) . "</h5></div>";
              echo "<div class='custom-card-body'><p class='mb-1'>" . nl2br(htmlspecialchars($ann['remarks'])) . "</p>";
              echo "<small class='text-muted'>Posted on: " . date('F j, Y, g:i A', strtotime($ann['posted_at'])) . "</small></div></div>";
          } else {
              echo "<div class='custom-card mb-4 shadow-sm section-spacing'>";
              echo "<div class='custom-card-header bg-warning text-dark'><h5 class='mb-0'><i class='bi bi-megaphone me-2'></i>No current announcements.</h5></div></div>";
          }
        ?>

        <!-- Deadline Section -->
        <?php
        // Load deadlines from JSON file
        $deadlinesPath = __DIR__ . '/../../data/deadlines.json';
        $deadlines = file_exists($deadlinesPath) ? json_decode(file_get_contents($deadlinesPath), true) : [];
        $today = date('Y-m-d');
        echo '<div id="deadline-section" class="custom-card border border-2 border-danger-subtle shadow-sm section-spacing">';
        echo '<div class="p-3 d-flex justify-content-between align-items-center bg-danger-subtle border-bottom" data-bs-toggle="collapse" data-bs-target="#deadline-body" style="cursor: pointer;">';
        echo '<h5 class="mb-0 text-danger fw-bold"><i class="bi bi-hourglass-top me-2"></i>Submission Deadlines</h5>';
        echo '<span class="badge bg-light text-danger border border-danger">' . count(array_filter($deadlines, fn($d) => $d['active'])) . ' item(s)</span>';
        echo '</div><div id="deadline-body" class="collapse show"><div class="custom-card-body"><ul class="list-unstyled mb-0">';
        foreach ($deadlines as $d) {
            if (empty($d['active'])) continue;
            $due = $d['deadline_date'];
            $onTime = ($today <= $due);
            $badgeClass = $onTime ? 'bg-success' : 'bg-danger';
            $badgeIcon = $onTime ? 'bi-check-circle' : 'bi-exclamation-triangle';
            echo '<li class="mb-3">';
            echo '<strong>' . htmlspecialchars($d['label']) . ':</strong><br>' . date('F j, Y', strtotime($due));
            echo ' <span class="badge ' . $badgeClass . ' ms-2"><i class="' . $badgeIcon . ' me-1"></i>' . ($onTime ? 'On Time' : 'Overdue') . '</span>';
            if (!empty($d['link'])) {
                echo ' <a href="' . htmlspecialchars($d['link']) . '" class="btn btn-primary btn-sm float-end">Go</a>';
            }
            echo '</li>';
        }
        echo '</ul></div></div></div>';
        ?>

        <!-- Reminders -->
        <?php
        // Load reminder date from deadlines JSON
        $deadlineData = file_exists(__DIR__ . '/../../data/deadlines.json') ? json_decode(file_get_contents(__DIR__ . '/../../data/deadlines.json'), true) : [];
        $reminderDate = '';
        foreach ($deadlineData as $d) {
            if (isset($d['key']) && $d['key'] === 'grades_submission' && !empty($d['active'])) {
                $reminderDate = date('F j', strtotime($d['deadline_date']));
                break;
            }
        }
        ?>

        <!-- Reminders -->
        <div class="custom-card shadow-sm section-spacing">
          <div class="custom-card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-bell-fill me-2"></i>Reminders</h5>
          </div>
          <div class="custom-card-body">
            <ul class="mb-0">
              <li>Upload your updated grades by <strong><?php echo htmlspecialchars($reminderDate ?: 'TBD'); ?></strong>.</li>
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
      event.preventDefault();
      if (confirm("Are you sure you want to logout?")) {
        window.location.href = 'logout.php';
      }
    }
  </script>
</body>
</html>