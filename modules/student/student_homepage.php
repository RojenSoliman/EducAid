<?php
session_start();
if (!isset($_SESSION['student_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}
// Include database connection
include __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../includes/workflow_control.php';

// Fetch student info including last login
$studentId = $_SESSION['student_id'];
$student_info_query = "SELECT last_login, first_name, last_name FROM students WHERE student_id = $1";
$student_info_result = pg_query_params($connection, $student_info_query, [$studentId]);
$student_info = pg_fetch_assoc($student_info_result);

// Check if this is a fresh login (within last 5 minutes) and adjust display
$current_time = new DateTime('now', new DateTimeZone('Asia/Manila'));
$display_login_time = null;

if ($student_info['last_login']) {
    $last_login_time = new DateTime($student_info['last_login']);
    $last_login_time->setTimezone(new DateTimeZone('Asia/Manila'));
    $time_diff = $current_time->diff($last_login_time);
    
    // If login was very recent (within 5 minutes), it's likely the current session
    // So we should show a different message or use the session previous_login if available
    if ($time_diff->days == 0 && $time_diff->h == 0 && $time_diff->i <= 5) {
        // Recent login - use session previous_login if available
        $display_login_time = $_SESSION['previous_login'] ?? null;
        
        // If no session previous_login, this might be first time login
        if (!$display_login_time) {
            $display_login_time = "first_time";
        }
    } else {
        // Not a recent login, safe to show database value
        $display_login_time = $student_info['last_login'];
    }
} else {
    $display_login_time = "first_time";
}

// Helper function to format last login time
function formatLastLogin($last_login_string) {
    if (!$last_login_string || $last_login_string === "first_time") {
        return "First time login - Welcome!";
    }
    
    try {
        $last_login = new DateTime($last_login_string);
        $last_login->setTimezone(new DateTimeZone('Asia/Manila'));
        $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $diff = $now->diff($last_login);
        
        // If login was today, show relative time
        if ($diff->days == 0) {
            if ($diff->h == 0 && $diff->i < 30) {
                return "Last login: Just now";
            } elseif ($diff->h == 0) {
                return "Last login: " . $diff->i . " minute" . ($diff->i != 1 ? "s" : "") . " ago";
            } else {
                return "Last login: " . $diff->h . " hour" . ($diff->h != 1 ? "s" : "") . " ago";
            }
        }
        // If login was yesterday
        elseif ($diff->days == 1) {
            return "Last login: Yesterday at " . $last_login->format('g:i A');
        }
        // For older logins, show full date
        else {
            return "Last login: " . $last_login->format('F j, Y – g:i A');
        }
    } catch (Exception $e) {
        return "Last login: " . htmlspecialchars($last_login_string);
    }
}

// Fetch past distributions where this student participated
$past_participation_query = "
    SELECT DISTINCT
        ds.distribution_date,
        ds.location,
        ds.academic_year,
        ds.semester,
        ds.finalized_at,
        ds.notes,
        CONCAT(a.first_name, ' ', a.last_name) as finalized_by_name
    FROM distribution_snapshots ds
    LEFT JOIN admins a ON ds.finalized_by = a.admin_id
    WHERE ds.students_data::text LIKE '%\"student_id\":\"' || $1 || '\"%'
       OR ds.students_data::text LIKE '%\"student_id\":' || $1 || ',%'
       OR ds.students_data::text LIKE '%\"student_id\":' || $1 || '}%'
       OR ds.students_data::text LIKE '%\"student_id\": \"' || $1 || '\"%'
       OR ds.students_data::text LIKE '%\"student_id\": ' || $1 || ',%'
       OR ds.students_data::text LIKE '%\"student_id\": ' || $1 || '}%'
    ORDER BY ds.finalized_at DESC
";
$past_participation_result = pg_query_params($connection, $past_participation_query, [$studentId]);

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

  <!-- Bootstrap 5.3.3 + Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />

  <!-- Custom CSS -->
  <link rel="stylesheet" href="../../assets/css/student/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/student/sidebar.css" />
  <link rel="stylesheet" href="../../assets/css/student/distribution_notifications.css" />
  <style>
    body:not(.js-ready) .sidebar { visibility: hidden; transition: none !important; }
    
    /* Adjust main content spacing */
    .home-section {
      padding-top: 0;
    }
    
    /* Footer styles - Small chip/sticker */
    .main-footer {
      position: fixed;
      bottom: 20px;
      right: 20px;
      background: white;
      border: 1px solid #e9ecef;
      border-radius: 25px;
      padding: 0.5rem 1rem;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      font-size: 0.75rem;
      color: #6c757d;
      z-index: 1000;
      max-width: 250px;
    }
    .footer-chip {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      white-space: nowrap;
    }
    .footer-chip .bi {
      font-size: 0.875rem;
      color: #0068da;
    }
    
    /* Welcome Banner Styles - Matches info banner design */
    .welcome-banner {
      background: white;
      border: 1px solid #e9ecef;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      border-left: 4px solid #0068da;
    }
    .welcome-content {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
    }
    .profile-section {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .profile-avatar {
      position: relative;
    }
    .profile-avatar img {
      border: 2px solid #e9ecef;
    }
    .status-indicator {
      position: absolute;
      bottom: 2px;
      right: 2px;
      width: 12px;
      height: 12px;
      border-radius: 50%;
      border: 2px solid white;
    }
    .profile-info h4.welcome-title {
      font-size: 1.25rem;
      font-weight: 600;
      margin: 0 0 4px 0;
      color: #212529;
    }
    .profile-info .login-info {
      margin: 0;
      color: #6c757d;
      font-size: 0.875rem;
      display: flex;
      align-items: center;
    }
    .welcome-actions .btn {
      color: #0068da;
      border-color: #0068da;
    }
    .welcome-actions .btn:hover {
      background: #0068da;
      color: white;
    }
    
    @media (max-width: 768px) {
      .welcome-content {
        text-align: center;
      }
      .profile-section {
        flex-direction: column;
        text-align: center;
      }
      .welcome-actions {
        width: 100%;
        text-align: center;
      }
    }
  </style>
</head>
<body>
  <!-- Student Topbar -->
  <?php include __DIR__ . '/../../includes/student/student_topbar.php'; ?>

  <div id="wrapper" style="padding-top: var(--topbar-h);">
    <!-- Sidebar -->
  <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
   
    <!-- Main Header (outside home-section for proper fixed positioning) -->
    <?php include __DIR__ . '/../../includes/student/student_header.php'; ?>

    <!-- Page Content -->
    <section class="home-section" id="page-content-wrapper">

      <!-- Main Content -->
      <div class="container-fluid py-4 px-4">
        <!-- Welcome Section - Banner Style -->
        <div class="welcome-banner mb-4">
          <div class="welcome-content">
            <div class="profile-section">
              <div class="profile-avatar">
                <img src="../../assets/images/profile.jpg" class="rounded-circle" width="48" height="48" alt="Student Profile">
                <div class="status-indicator bg-success"></div>
              </div>
              <div class="profile-info">
                <h4 class="welcome-title">Welcome back, <?php echo htmlspecialchars($_SESSION['student_username']); ?>!</h4>
                <p class="login-info">
                  <i class="bi bi-clock me-1"></i>
                  <?php echo formatLastLogin($display_login_time); ?>
                </p>
              </div>
            </div>
            <div class="welcome-actions">
              <button class="btn btn-outline-primary btn-sm">
                <i class="bi bi-gear me-1"></i>
                Settings
              </button>
            </div>
          </div>
        </div>
        
        <!-- Global Documents Deadline Banner (if distribution started) -->
        <?php
          $workflow = getWorkflowStatus($connection);
          $distribution_status = $workflow['distribution_status'] ?? 'inactive';
          $doc_deadline = null;
          if ($distribution_status === 'preparing' || $distribution_status === 'active') {
              $cfg = pg_query_params($connection, "SELECT value FROM config WHERE key = $1", ['documents_deadline']);
              if ($cfg && pg_num_rows($cfg) > 0) {
                  $doc_deadline = pg_fetch_result($cfg, 0, 'value');
              }
          }
          if (!empty($doc_deadline)):
        ?>
          <div class="alert alert-warning d-flex align-items-center" role="alert">
            <i class="bi bi-hourglass-split me-2"></i>
            <div>
              Document submissions are due on <strong><?= htmlspecialchars($doc_deadline) ?></strong>. Please upload required documents before this date.
            </div>
          </div>
        <?php endif; ?>

        <!-- Three banners under welcome -->
        <?php
          $result = pg_query_params($connection, "SELECT status FROM students WHERE student_id = $1", [$_SESSION['student_id']]);
          $status = null;
          if ($result && pg_num_rows($result) > 0) {
            $row = pg_fetch_assoc($result);
            $status = $row['status'];
          }
          if ($status === 'given') {
            $badgeClass = 'bg-primary';
            $icon = 'bi-gift-fill';
            $statusText = 'Received Aid';
          } elseif ($status === 'active') {
            $badgeClass = 'bg-success';
            $icon = 'bi-check2-circle';
            $statusText = 'Verified';
          } elseif ($status === 'applicant') {
            $badgeClass = 'bg-warning text-dark';
            $icon = 'bi-hourglass-split';
            $statusText = 'Applicant';
          } elseif ($status === 'disabled') {
            $badgeClass = 'bg-danger';
            $icon = 'bi-x-circle';
            $statusText = 'Disabled';
          } else {
            $badgeClass = 'bg-secondary';
            $icon = 'bi-question-circle';
            $statusText = 'Unknown';
          }
        ?>
        <div class="row g-3 info-banners">
          <div class="col-md-4">
            <div class="info-banner banner-primary">
              <span class="icon text-primary"><i class="bi bi-award"></i></span>
              <div>
                <div class="label">Scholarship Program</div>
                <div class="value">Tertiary Education Assistance</div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="info-banner banner-status">
              <span class="icon text-success"><i class="bi <?php echo $icon; ?>"></i></span>
              <div>
                <div class="label">Application Status</div>
                <div class="value"><span class="badge <?php echo $badgeClass; ?>"><?php echo $statusText; ?></span></div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="info-banner banner-muted">
              <span class="icon text-muted"><i class="bi bi-clock-history"></i></span>
              <div>
                <div class="label">Last Updated</div>
                <div class="value text-muted"><?php echo date('M j, Y'); ?></div>
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
                // Render schedule section - Modern Design
                echo "<section class='modern-schedule-section section-spacing'>";
                echo "<div class='modern-section-header'>";
                echo "<div class='header-icon-wrapper blue'>";
                echo "<i class='bi bi-calendar3'></i>";
                echo "</div>";
                echo "<div class='header-content'>";
                echo "<h3 class='modern-section-title'>Your Schedule</h3>";
                echo "<p class='modern-section-subtitle'>Your upcoming distribution schedule</p>";
                echo "</div>";
                echo "</div>";
                
                // Show location badge if available
                if ($location !== '') {
                    echo '<div class="location-badge mb-3">';
                    echo '<i class="bi bi-geo-alt-fill"></i> ';
                    echo '<span>' . htmlspecialchars($location) . '</span>';
                    echo '</div>';
                }
                
                echo "<div class='schedule-timeline'>";
                foreach ($rows as $s) {
                    echo '<div class="schedule-item">';
                    echo '<div class="schedule-date">';
                    echo '<div class="date-icon"><i class="bi bi-calendar-event"></i></div>';
                    echo '<div class="date-info">';
                    echo '<div class="date-day">' . date('l', strtotime($s['distribution_date'])) . '</div>';
                    echo '<div class="date-full">' . date('F j, Y', strtotime($s['distribution_date'])) . '</div>';
                    echo '</div>';
                    echo '</div>';
                    echo '<div class="schedule-details">';
                    echo '<div class="detail-row">';
                    echo '<span class="detail-label"><i class="bi bi-clock"></i> Time</span>';
                    echo '<span class="detail-value">' . htmlspecialchars($s['time_slot']) . '</span>';
                    echo '</div>';
                    echo '<div class="detail-row">';
                    echo '<span class="detail-label"><i class="bi bi-people"></i> Batch</span>';
                    echo '<span class="detail-value">Batch ' . htmlspecialchars($s['batch_no']) . '</span>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                }
                echo "</div>";
                echo "</section>";
            } else {
                echo "<section class='modern-schedule-section section-spacing empty-state'>";
                echo "<div class='modern-section-header'>";
                echo "<div class='header-icon-wrapper blue'>";
                echo "<i class='bi bi-calendar3'></i>";
                echo "</div>";
                echo "<div class='header-content'>";
                echo "<h3 class='modern-section-title'>Your Schedule</h3>";
                echo "<p class='modern-section-subtitle'>Your upcoming distribution schedule</p>";
                echo "</div>";
                echo "</div>";
                echo "<div class='empty-state-content'>";
                echo "<i class='bi bi-calendar-x empty-icon'></i>";
                echo "<p class='empty-text'>Your schedule will appear here once published</p>";
                echo "</div>";
                echo "</section>";
            }
        }
        ?>

        <!-- Past Distributions Section -->
    <?php if ($past_participation_result && pg_num_rows($past_participation_result) > 0): ?>
  <section class="section-block section-history section-spacing">
      <div class="section-header">
        <h3 class="section-title mb-0"><i class="bi bi-archive me-2"></i>Your Distribution History</h3>
        <p class="section-lead m-0">Previous distributions you have participated in:</p>
      </div>
                
                <!-- Carousel Container -->
                <div class="distribution-carousel-container">
                    <div class="distribution-carousel" id="distributionCarousel">
                        <div class="carousel-track" id="carouselTrack">
                            <?php 
                            $distributions = [];
                            while ($dist = pg_fetch_assoc($past_participation_result)) {
                                $distributions[] = $dist;
                            }
                            
                            // Group distributions into sets of 3 for carousel pages
                            $itemsPerPage = 3;
                            $totalPages = ceil(count($distributions) / $itemsPerPage);
                            
                            for ($page = 0; $page < $totalPages; $page++):
                                $pageStart = $page * $itemsPerPage;
                                $pageEnd = min($pageStart + $itemsPerPage, count($distributions));
                            ?>
                                <div class="carousel-page">
                                    <div class="row g-3">
                                        <?php for ($i = $pageStart; $i < $pageEnd; $i++): 
                                            $dist = $distributions[$i];
                                        ?>
                                            <div class="col-md-4">
                                                <div class="distribution-card border rounded p-3 h-100">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <h6 class="mb-0 text-success fw-bold">
                                                            <i class="bi bi-calendar-check me-1"></i>
                                                            <?php echo date('M d, Y', strtotime($dist['distribution_date'])); ?>
                                                        </h6>
                                                        <span class="badge bg-success">Distributed</span>
                                                    </div>
                                                    
                                                    <div class="mb-2">
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-geo-alt me-1"></i>
                                                            <strong>Location:</strong> <?php echo htmlspecialchars($dist['location']); ?>
                                                        </small>
                                                    </div>
                                                    
                                                    <?php if (!empty($dist['academic_year']) || !empty($dist['semester'])): ?>
                                                    <div class="mb-2">
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-mortarboard me-1"></i>
                                                            <strong>Academic Period:</strong> 
                                                            <?php 
                                                            $period_parts = [];
                                                            if (!empty($dist['academic_year'])) $period_parts[] = 'AY ' . $dist['academic_year'];
                                                            if (!empty($dist['semester'])) $period_parts[] = $dist['semester'];
                                                            echo htmlspecialchars(implode(', ', $period_parts));
                                                            ?>
                                                        </small>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="mb-2">
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-clock me-1"></i>
                                                            <strong>Processed:</strong> <?php echo date('M d, Y g:i A', strtotime($dist['finalized_at'])); ?>
                                                        </small>
                                                    </div>
                                                    
                                                    <?php if (!empty($dist['finalized_by_name'])): ?>
                                                    <div class="mb-2">
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-person-check me-1"></i>
                                                            <strong>Processed by:</strong> <?php echo htmlspecialchars($dist['finalized_by_name']); ?>
                                                        </small>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($dist['notes'])): ?>
                                                    <div class="mt-2 pt-2 border-top">
                                                        <small class="text-muted">
                                                            <i class="bi bi-sticky me-1"></i>
                                                            <strong>Notes:</strong> <?php echo htmlspecialchars($dist['notes']); ?>
                                                        </small>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    
                    <!-- Navigation Controls -->
                    <?php if ($totalPages > 1): ?>
                    <div class="carousel-controls">
                        <button class="carousel-btn carousel-prev" id="carouselPrev">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <button class="carousel-btn carousel-next" id="carouselNext">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                    
                    <!-- Dot Indicators -->
                    <div class="carousel-indicators">
                        <?php for ($i = 0; $i < $totalPages; $i++): ?>
                        <button class="carousel-dot <?php echo $i === 0 ? 'active' : ''; ?>" data-page="<?php echo $i; ?>"></button>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Summary Info -->
                <div class="text-center mt-3">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Showing <?php echo count($distributions); ?> distribution<?php echo count($distributions) !== 1 ? 's' : ''; ?>
                        <?php if ($totalPages > 1): ?>
                            across <?php echo $totalPages; ?> page<?php echo $totalPages !== 1 ? 's' : ''; ?>
                        <?php endif; ?>
                    </small>
                </div>
    </section>
        <?php endif; ?>

        <!-- Announcements Section - Modern Design with Advanced Features -->
        <?php
          // Fetch latest active announcement with all fields
          $annRes = pg_query($connection, 
            "SELECT announcement_id, title, remarks, posted_at, is_active, event_date, event_time, location, image_path 
             FROM announcements 
             WHERE is_active = TRUE 
             ORDER BY posted_at DESC 
             LIMIT 1"
          );
          if ($annRes && pg_num_rows($annRes) > 0) {
              $ann = pg_fetch_assoc($annRes);
              
              // Format event info
              $event_parts = [];
              if (!empty($ann['event_date'])) {
                  $event_date = DateTime::createFromFormat('Y-m-d', $ann['event_date']);
                  if ($event_date) {
                      $event_parts[] = $event_date->format('M d, Y');
                  }
              }
              if (!empty($ann['event_time'])) {
                  $event_time = DateTime::createFromFormat('H:i:s', $ann['event_time']);
                  if ($event_time) {
                      $event_parts[] = $event_time->format('g:i A');
                  }
              }
              $event_line = implode(' • ', $event_parts);
              
              // Prepare image - use relative path from student module directory
              // The image_path from database is already relative to project root (e.g., "uploads/announcements/image.jpg")
              $img_path = !empty($ann['image_path']) ? '../../' . $ann['image_path'] : null;
              
              // Prepare remarks (truncate if too long)
              $full_remarks = trim($ann['remarks']);
              $short_remarks = mb_strlen($full_remarks) > 400 ? mb_substr($full_remarks, 0, 400) . '…' : $full_remarks;
              $need_toggle = $short_remarks !== $full_remarks;
              
              echo "<section class='modern-announcement-section section-spacing'>";
              echo "<div class='modern-section-header'>";
              echo "<div class='header-icon-wrapper orange'>";
              echo "<i class='bi bi-megaphone'></i>";
              echo "</div>";
              echo "<div class='header-content'>";
              echo "<h3 class='modern-section-title'>Latest Announcement</h3>";
              echo "<p class='modern-section-subtitle'>Stay updated with news and information</p>";
              echo "</div>";
              echo "</div>";
              
              // Image section (if available) - Display image without file_exists check
              // The path is relative to the student module, so ../../ goes back to project root
              if ($img_path) {
                  echo "<div class='announcement-image'>";
                  echo "<img src='" . htmlspecialchars($img_path) . "' alt='Announcement image' onerror=\"this.parentElement.style.display='none';\" />";
                  echo "</div>";
              }
              
              echo "<div class='announcement-content'>";
              
              // Meta information
              echo "<div class='announcement-meta-row'>";
              echo "<div class='meta-badges'>";
              echo "<span class='meta-badge date'>";
              echo "<i class='bi bi-calendar3'></i>";
              echo date('M d, Y', strtotime($ann['posted_at']));
              echo "</span>";
              
              if ($event_line) {
                  echo "<span class='meta-badge event'>";
                  echo "<i class='bi bi-calendar-event'></i> Event";
                  echo "</span>";
              }
              
              if ($ann['is_active'] === 't' || $ann['is_active'] === true) {
                  echo "<span class='meta-badge active'>";
                  echo "<i class='bi bi-lightning-charge-fill'></i> Active";
                  echo "</span>";
              }
              echo "</div>";
              echo "</div>";
              
              // Title
              echo "<div class='announcement-header'>";
              echo "<h4 class='announcement-title'>" . htmlspecialchars($ann['title']) . "</h4>";
              
              // Event details
              if ($event_line || !empty($ann['location'])) {
                  echo "<div class='event-details'>";
                  if ($event_line) {
                      echo "<div class='event-detail-item'>";
                      echo "<i class='bi bi-calendar2-week'></i>";
                      echo "<span>" . htmlspecialchars($event_line) . "</span>";
                      echo "</div>";
                  }
                  if (!empty($ann['location'])) {
                      echo "<div class='event-detail-item'>";
                      echo "<i class='bi bi-geo-alt-fill'></i>";
                      echo "<span>" . htmlspecialchars($ann['location']) . "</span>";
                      echo "</div>";
                  }
                  echo "</div>";
              }
              echo "</div>";
              
              // Remarks body with toggle
              echo "<div class='announcement-body' id='announcementRemarks'>";
              if ($need_toggle) {
                  echo "<div class='remarks-short'>";
                  echo "<p>" . nl2br(htmlspecialchars($short_remarks)) . "</p>";
                  echo "</div>";
                  echo "<div class='remarks-full' style='display: none;'>";
                  echo "<p>" . nl2br(htmlspecialchars($full_remarks)) . "</p>";
                  echo "</div>";
              } else {
                  echo "<p>" . nl2br(htmlspecialchars($full_remarks)) . "</p>";
              }
              echo "</div>";
              
              // Read more toggle button
              if ($need_toggle) {
                  echo "<div class='announcement-actions'>";
                  echo "<button class='btn-read-more' id='toggleAnnouncementBtn'>";
                  echo "<span class='read-more-text'><i class='bi bi-chevron-down'></i> Read full announcement</span>";
                  echo "<span class='read-less-text' style='display: none;'><i class='bi bi-chevron-up'></i> Show less</span>";
                  echo "</button>";
                  echo "</div>";
              }
              
              echo "</div>";
              echo "</section>";
          } else {
              echo "<section class='modern-announcement-section section-spacing empty-state'>";
              echo "<div class='modern-section-header'>";
              echo "<div class='header-icon-wrapper orange'>";
              echo "<i class='bi bi-megaphone'></i>";
              echo "</div>";
              echo "<div class='header-content'>";
              echo "<h3 class='modern-section-title'>Latest Announcement</h3>";
              echo "<p class='modern-section-subtitle'>Stay updated with news and information</p>";
              echo "</div>";
              echo "</div>";
              echo "<div class='empty-state-content'>";
              echo "<i class='bi bi-megaphone-fill empty-icon'></i>";
              echo "<p class='empty-text'>No current announcements</p>";
              echo "</div>";
              echo "</section>";
          }
        ?>

        <!-- Submission Deadlines & Reminders -->
        <?php
    // Load deadlines from config table (distribution control settings)
    $todayStr = date('Y-m-d');
    $todayDt = new DateTime('today');
    
    // Get documents deadline from config
    $deadlines = [];
    $workflow = getWorkflowStatus($connection);
    $distribution_status = $workflow['distribution_status'] ?? 'inactive';
    
    // Only show deadlines if distribution is active or preparing
    if ($distribution_status === 'preparing' || $distribution_status === 'active') {
        // Fetch documents deadline
        $deadline_query = "SELECT value FROM config WHERE key = 'documents_deadline'";
        $deadline_result = pg_query($connection, $deadline_query);
        
        if ($deadline_result && $deadline_row = pg_fetch_assoc($deadline_result)) {
            $doc_deadline = $deadline_row['value'];
            if (!empty($doc_deadline)) {
                $deadlines[] = [
                    'label' => 'Document Submission',
                    'deadline_date' => $doc_deadline,
                    'link' => 'upload_document.php'
                ];
            }
        }
        
        // You can add more deadline types here in the future
        // For example: registration deadline, grades deadline, etc.
    }

    // Build active list + counts
    $activeItems = [];
    $activeCount = count($deadlines);

    // Separate overdue vs upcoming (overdue first)
    $overdueItems = [];
    $upcomingItems = [];
    foreach ($deadlines as $d) {
      $dueStr = isset($d['deadline_date']) ? $d['deadline_date'] : '';
      $dueDt = null;
      try { if ($dueStr) { $dueDt = new DateTime($dueStr); } } catch (Exception $e) { $dueDt = null; }
      $isOverdue = $dueDt ? ($dueDt < $todayDt) : false;
      $isToday = $dueDt ? ($dueDt->format('Y-m-d') === $todayStr) : false;
      $daysAbs = $dueDt ? $todayDt->diff($dueDt)->days : null;
      $item = [
        'label' => $d['label'] ?? 'Untitled',
        'link' => $d['link'] ?? '',
        'dueDt' => $dueDt,
        'dueStr' => $dueStr,
        'isOverdue' => $isOverdue,
        'isToday' => $isToday,
        'daysAbs' => $daysAbs,
      ];
      if ($isOverdue) { $overdueItems[] = $item; } else { $upcomingItems[] = $item; }
    }

    // Only show section if there are deadlines
    if ($activeCount > 0) {
    $hasOverdue = count($overdueItems) > 0;
    $sectionClass = 'section-block section-deadlines section-spacing' . ($hasOverdue ? ' has-overdue' : '');
    echo '<section class="' . $sectionClass . '">';
    echo '  <div class="section-header d-flex justify-content-between align-items-center">';
    echo '    <div><h3 class="section-title mb-0"><i class="bi bi-hourglass-top me-2"></i>Submission Deadlines</h3><p class="section-lead m-0">Upcoming and active requirements.</p></div>';
    $badgeClass = $hasOverdue ? 'bg-danger-subtle text-danger border border-danger' : 'bg-success-subtle text-success border border-success';
    echo '    <span class="badge ' . $badgeClass . '">' . $activeCount . ' item(s)</span>';
    echo '  </div>';

    echo '  <div class="deadline-list">';
    // Render overdue first with strong accents
    foreach ($overdueItems as $it) {
      $title = htmlspecialchars($it['label']);
      $dateText = $it['dueDt'] ? $it['dueDt']->format('F j, Y') : htmlspecialchars($it['dueStr']);
      $statusText = ($it['daysAbs'] !== null && $it['daysAbs'] > 0)
        ? ('Overdue by ' . $it['daysAbs'] . ' day' . ($it['daysAbs'] != 1 ? 's' : ''))
        : 'Overdue';
      $link = $it['link'] ? htmlspecialchars($it['link']) : '';
      echo '    <div class="deadline-item is-overdue">';
      echo '      <div class="deadline-left">';
      echo '        <div class="deadline-title"><i class="bi bi-exclamation-octagon-fill text-danger me-2"></i>' . $title . '</div>';
      echo '        <div class="deadline-meta"><span class="due-date"><i class="bi bi-calendar-event me-1"></i>' . $dateText . '</span></div>';
      echo '      </div>';
      echo '      <div class="deadline-right">';
      echo '        <span class="chip chip-overdue"><i class="bi bi-lightning-charge-fill me-1"></i>' . $statusText . '</span>';
      if ($link) {
        echo '        <a href="' . $link . '" class="btn btn-danger btn-sm ms-2">Resolve</a>';
      }
      echo '      </div>';
      echo '    </div>';
    }

    // Then on-time/upcoming
    foreach ($upcomingItems as $it) {
      $title = htmlspecialchars($it['label']);
      $dateText = $it['dueDt'] ? $it['dueDt']->format('F j, Y') : htmlspecialchars($it['dueStr']);
      if ($it['isToday']) {
        $statusText = 'Due today';
      } else if ($it['daysAbs'] !== null) {
        $statusText = 'Due in ' . $it['daysAbs'] . ' day' . ($it['daysAbs'] != 1 ? 's' : '');
      } else {
        $statusText = 'Upcoming';
      }
      $link = $it['link'] ? htmlspecialchars($it['link']) : '';
      echo '    <div class="deadline-item is-ontime">';
      echo '      <div class="deadline-left">';
      echo '        <div class="deadline-title"><i class="bi bi-clipboard-check text-success me-2"></i>' . $title . '</div>';
      echo '        <div class="deadline-meta"><span class="due-date"><i class="bi bi-calendar-event me-1"></i>' . $dateText . '</span></div>';
      echo '      </div>';
      echo '      <div class="deadline-right">';
      echo '        <span class="chip chip-ontime"><i class="bi bi-clock me-1"></i>' . $statusText . '</span>';
      if ($link) {
        echo '        <a href="' . $link . '" class="btn btn-primary btn-sm ms-2">Go</a>';
      }
      echo '      </div>';
      echo '    </div>';
    }
    echo '  </div>';

    // Reminders section
    $reminderDate = '';
    if (!empty($deadlines[0]['deadline_date'])) {
        $reminderDate = date('F j, Y', strtotime($deadlines[0]['deadline_date']));
    }
    echo '  <div class="mt-3 pt-3 border-top">';
    echo '    <h6 class="fw-bold mb-2"><i class="bi bi-bell-fill me-2"></i>Reminders</h6>';
    echo '    <ul class="mb-0">';
    if (!empty($reminderDate)) {
        echo '      <li>Submit all required documents by <strong>' . htmlspecialchars($reminderDate) . '</strong>.</li>';
    }
    echo '      <li>Check notifications regularly for city updates.</li>';
    echo '    </ul>';
    echo '  </div>';
    echo '</section>';
    }
        ?>
        

      </div>
    </section>
  </div>

  <!-- Footer Chip -->
  <footer class="main-footer">
    <div class="footer-chip">
      <i class="bi bi-c-circle"></i>
      <span><?php echo date('Y'); ?> EducAid</span>
    </div>
  </footer>

  <!-- JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/student/sidebar.js"></script>
  <script src="../../assets/js/deadline.js"></script>
  <script src="../../assets/js/student/student_homepage.js"></script>
  
  <!-- Real-Time Distribution Monitor -->
  <script src="../../assets/js/student/distribution_monitor.js"></script>
  
  <!-- Announcement Read More Toggle -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const toggleBtn = document.getElementById('toggleAnnouncementBtn');
      if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
          const remarksContainer = document.getElementById('announcementRemarks');
          const shortRemarks = remarksContainer.querySelector('.remarks-short');
          const fullRemarks = remarksContainer.querySelector('.remarks-full');
          const readMoreText = this.querySelector('.read-more-text');
          const readLessText = this.querySelector('.read-less-text');
          
          if (shortRemarks.style.display !== 'none') {
            // Expand
            shortRemarks.style.display = 'none';
            fullRemarks.style.display = 'block';
            readMoreText.style.display = 'none';
            readLessText.style.display = 'inline-flex';
          } else {
            // Collapse
            shortRemarks.style.display = 'block';
            fullRemarks.style.display = 'none';
            readMoreText.style.display = 'inline-flex';
            readLessText.style.display = 'none';
          }
        });
      }
    });
  </script>
</body>
</html>