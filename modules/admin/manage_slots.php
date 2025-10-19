<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Check workflow permissions - must have active distribution
require_once __DIR__ . '/../../includes/workflow_control.php';
$workflow_status = getWorkflowStatus($connection);
if (!$workflow_status['can_manage_slots']) {
    $_SESSION['error_message'] = "Please start a distribution first before managing slots. Go to Distribution Control to begin.";
    header("Location: distribution_control.php");
    exit;
}

$municipality_id = 1;

function formatName($last, $first, $middle) {
    return ucwords(strtolower(trim("$last, $first $middle")));
}

$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Get academic period from distribution control FIRST
$distribution_academic_year = '';
$distribution_semester = '';
$distribution_status = 'inactive';
$distribution_query = "SELECT key, value FROM config WHERE key IN ('current_academic_year', 'current_semester', 'distribution_status')";
$distribution_result = pg_query($connection, $distribution_query);
if ($distribution_result) {
    while ($row = pg_fetch_assoc($distribution_result)) {
        if ($row['key'] === 'current_academic_year') {
            $distribution_academic_year = $row['value'];
        } elseif ($row['key'] === 'current_semester') {
            $distribution_semester = $row['value'];
        } elseif ($row['key'] === 'distribution_status') {
            $distribution_status = $row['value'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['slot_count'])) {
        $newSlotCount = intval($_POST['slot_count']);
        $admin_password = $_POST['admin_password'];
        
        // Get academic period from distribution config
        $semester = $distribution_semester;
        $academic_year = $distribution_academic_year;
        
        // Validate that distribution is active
        if (!in_array($distribution_status, ['preparing', 'active'])) {
            header("Location: manage_slots.php?error=distribution_inactive");
            exit;
        }
        
        // Validate academic period is set
        if (empty($academic_year) || empty($semester)) {
            header("Location: manage_slots.php?error=no_academic_period");
            exit;
        }

        if (!preg_match('/^\d{4}-\d{4}$/', $academic_year)) {
            header("Location: manage_slots.php?error=invalid_year");
            exit;
        }

        // Get admin password using admin_id from session
        if (isset($_SESSION['admin_id'])) {
            // New unified login system
            $admin_id = $_SESSION['admin_id'];
            $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE admin_id = $1", [$admin_id]);
        } elseif (isset($_SESSION['admin_username'])) {
            // Legacy login system fallback
            $admin_username = $_SESSION['admin_username'];
            $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE username = $1", [$admin_username]);
        } else {
            header("Location: manage_slots.php?error=session_invalid");
            exit;
        }
        
        $adminRow = pg_fetch_assoc($adminQuery);
        if (!$adminRow || !password_verify($admin_password, $adminRow['password'])) {
            header("Location: manage_slots.php?error=invalid_password");
            exit;
        }

        // Check if there are unfinalized distributions (students with 'given' status)
        $unfinalizedDistributionsQuery = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE status = 'given'");
        $unfinalizedCount = pg_fetch_assoc($unfinalizedDistributionsQuery)['count'];
        
        if ($unfinalizedCount > 0) {
            header("Location: manage_slots.php?error=unfinalized_distributions&count=" . $unfinalizedCount);
            exit;
        }

        // Additional check: If distribution status is 'finalized', don't allow new slots until a new distribution is started
        if ($distribution_status === 'finalized') {
            header("Location: manage_slots.php?error=distribution_finalized");
            exit;
        }

        // Additional validation: Check if academic year/semester is valid
        // Get the most recent slot to compare against
        $latestSlotQuery = pg_query_params($connection, "
            SELECT academic_year, semester FROM signup_slots 
            WHERE municipality_id = $1 
            ORDER BY created_at DESC LIMIT 1
        ", [$municipality_id]);
        
        if (pg_num_rows($latestSlotQuery) > 0) {
            $latestSlot = pg_fetch_assoc($latestSlotQuery);
            $latestAcademicYear = $latestSlot['academic_year'];
            $latestSemester = $latestSlot['semester'];
            
            // Extract years for comparison
            $latestYearParts = explode('-', $latestAcademicYear);
            $newYearParts = explode('-', $academic_year);
            
            if (count($latestYearParts) === 2 && count($newYearParts) === 2) {
                $latestStartYear = intval($latestYearParts[0]);
                $newStartYear = intval($newYearParts[0]);
                
                // Check if new academic year is before the latest one
                if ($newStartYear < $latestStartYear) {
                    header("Location: manage_slots.php?error=past_academic_year");
                    exit;
                }
                
                // If same academic year, check semester progression
                if ($newStartYear === $latestStartYear) {
                    // Convert semesters to numeric for comparison
                    $semesterOrder = [
                        '1st Semester' => 1,
                        '2nd Semester' => 2
                    ];
                    
                    $latestSemesterNum = $semesterOrder[$latestSemester] ?? 0;
                    $newSemesterNum = $semesterOrder[$semester] ?? 0;
                    
                    // Don't allow going backwards in the same academic year
                    if ($newSemesterNum <= $latestSemesterNum) {
                        header("Location: manage_slots.php?error=past_semester");
                        exit;
                    }
                }
            }
        }

        $existingCheck = pg_query_params($connection, "
            SELECT 1 FROM signup_slots 
            WHERE municipality_id = $1 AND semester = $2 AND academic_year = $3
        ", [$municipality_id, $semester, $academic_year]);

        if (pg_num_rows($existingCheck) > 0) {
            header("Location: manage_slots.php?error=duplicate_slot");
            exit;
        }

        pg_query_params($connection, "UPDATE signup_slots SET is_active = FALSE WHERE is_active = TRUE AND municipality_id = $1", [$municipality_id]);
        $insertResult = pg_query_params($connection, "INSERT INTO signup_slots (municipality_id, slot_count, is_active, semester, academic_year) VALUES ($1, $2, TRUE, $3, $4) RETURNING slot_id", [$municipality_id, $newSlotCount, $semester, $academic_year]);
        
        // Get the newly created slot ID
        $newSlot = pg_fetch_assoc($insertResult);
        $newSlotId = $newSlot['slot_id'];

        // Add admin notification for slot creation
        $notification_msg = "New slot configuration created: " . $newSlotCount . " slots for " . $semester . " " . $academic_year;
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        
        // Log slot creation in audit trail
        require_once __DIR__ . '/../../services/AuditLogger.php';
        $auditLogger = new AuditLogger($connection);
        $auditLogger->logSlotOpened(
            $_SESSION['admin_id'],
            $_SESSION['admin_username'],
            $newSlotId,
            [
                'slot_count' => $newSlotCount,
                'semester' => $semester,
                'academic_year' => $academic_year,
                'max_applicants' => $newSlotCount
            ]
        );

        header("Location: manage_slots.php?status=success");
        exit;
    } elseif (isset($_POST['delete_slot_id'])) {
        $delete_slot_id = intval($_POST['delete_slot_id']);
        
        // Get slot details before deletion for notification
        $slotQuery = pg_query_params($connection, "SELECT slot_count, semester, academic_year FROM signup_slots WHERE slot_id = $1", [$delete_slot_id]);
        $slotData = pg_fetch_assoc($slotQuery);
        
        pg_query_params($connection, "DELETE FROM signup_slots WHERE slot_id = $1 AND municipality_id = $2", [$delete_slot_id, $municipality_id]);
        
        // Add admin notification for slot deletion
        if ($slotData) {
            $notification_msg = "Slot configuration deleted: " . $slotData['slot_count'] . " slots for " . $slotData['semester'] . " " . $slotData['academic_year'];
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        }
        
        header("Location: manage_slots.php?status=deleted");
        exit;
    } elseif (isset($_POST['finish_current_slot'])) {
        $admin_password = $_POST['admin_password'];

        // Get admin password using admin_id from session
        if (isset($_SESSION['admin_id'])) {
            // New unified login system
            $admin_id = $_SESSION['admin_id'];
            $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE admin_id = $1", [$admin_id]);
        } elseif (isset($_SESSION['admin_username'])) {
            // Legacy login system fallback
            $admin_username = $_SESSION['admin_username'];
            $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE username = $1", [$admin_username]);
        } else {
            header("Location: manage_slots.php?error=session_invalid");
            exit;
        }
        
        $adminRow = pg_fetch_assoc($adminQuery);
        if (!$adminRow || !password_verify($admin_password, $adminRow['password'])) {
            header("Location: manage_slots.php?error=invalid_password");
            exit;
        }

        // Get current active slot details for notification
        $currentSlotQuery = pg_query_params($connection, "
            SELECT slot_count, semester, academic_year FROM signup_slots 
            WHERE is_active = TRUE AND municipality_id = $1 
            ORDER BY created_at DESC LIMIT 1
        ", [$municipality_id]);
        $currentSlotData = pg_fetch_assoc($currentSlotQuery);

        // Mark current active slot as finished
        pg_query_params($connection, "
            UPDATE signup_slots 
            SET is_active = FALSE 
            WHERE is_active = TRUE AND municipality_id = $1
        ", [$municipality_id]);

        // Add admin notification for slot finish
        if ($currentSlotData) {
            $notification_msg = "Slot manually finished: " . $currentSlotData['slot_count'] . " slots for " . $currentSlotData['semester'] . " " . $currentSlotData['academic_year'];
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            
            // Log slot closure in audit trail
            require_once __DIR__ . '/../../services/AuditLogger.php';
            $auditLogger = new AuditLogger($connection);
            
            // Get total applicants for this slot
            $applicantCount = pg_query_params($connection, "SELECT COUNT(*) as total FROM students WHERE slot_id IN (SELECT slot_id FROM signup_slots WHERE semester = $1 AND academic_year = $2 AND municipality_id = $3)", [$currentSlotData['semester'], $currentSlotData['academic_year'], $municipality_id]);
            $applicantData = pg_fetch_assoc($applicantCount);
            
            $auditLogger->logSlotClosed(
                $_SESSION['admin_id'],
                $_SESSION['admin_username'],
                null, // slot_id (we don't have it from the select, but could add it)
                [
                    'slot_count' => $currentSlotData['slot_count'],
                    'semester' => $currentSlotData['semester'],
                    'academic_year' => $currentSlotData['academic_year'],
                    'total_applicants' => $applicantData['total'] ?? 0
                ]
            );
        }

        header("Location: manage_slots.php?status=slot_finished");
        exit;
    } elseif (isset($_POST['export_csv']) && $_POST['export_csv'] === '1') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="registrations.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Name', 'Application Date', 'Status', 'Semester', 'Academic Year']);
        $exportQuery = pg_query_params($connection, "
            SELECT s.first_name, s.middle_name, s.last_name, s.application_date, s.status, a.semester, a.academic_year
            FROM students s
            LEFT JOIN applications a ON s.student_id = a.student_id
            WHERE (s.status = 'under_registration' OR s.status = 'applicant') AND s.municipality_id = $1
            ORDER BY s.status DESC, s.application_date DESC
        ", [$municipality_id]);
        while ($row = pg_fetch_assoc($exportQuery)) {
            $statusLabel = $row['status'] === 'under_registration' ? 'Pending Approval' : 'Approved';
            fputcsv($output, [
                formatName($row['last_name'], $row['first_name'], $row['middle_name']),
                date('M d, Y — h:i A', strtotime($row['application_date'])),
                $statusLabel,
                $row['semester'],
                $row['academic_year']
            ]);
        }
        fclose($output);
        exit;
    }
}

// Fetch current active slot
$slotInfo = pg_fetch_assoc(pg_query_params($connection, "
    SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1
", [$municipality_id]));



// Fetch latest slot for validation (used in form hints and JavaScript validation)
$latestSlotForValidation = null;
$latestSlotValidationQuery = pg_query_params($connection, "
    SELECT academic_year, semester FROM signup_slots 
    WHERE municipality_id = $1 
    ORDER BY created_at DESC LIMIT 1
", [$municipality_id]);
if (pg_num_rows($latestSlotValidationQuery) > 0) {
    $latestSlotForValidation = pg_fetch_assoc($latestSlotValidationQuery);
}

// Fetch municipality max capacity
$capacityResult = pg_query_params($connection, "SELECT max_capacity FROM municipalities WHERE municipality_id = $1", [$municipality_id]);
$maxCapacity = null;
if ($capacityResult && pg_num_rows($capacityResult) > 0) {
    $capacityRow = pg_fetch_assoc($capacityResult);
    $maxCapacity = $capacityRow['max_capacity'];
}

// Get current total students count for capacity management
$currentTotalStudentsQuery = pg_query_params($connection, "
    SELECT COUNT(*) as total FROM students 
    WHERE municipality_id = $1 AND status IN ('under_registration', 'applicant', 'verified', 'active', 'given')
", [$municipality_id]);
$currentTotalStudents = 0;
if ($currentTotalStudentsQuery) {
    $currentTotalRow = pg_fetch_assoc($currentTotalStudentsQuery);
    $currentTotalStudents = intval($currentTotalRow['total']);
}

$slotsUsed = 0;
$slotsLeft = 0;
$pendingCount = 0;
$approvedCount = 0;
$applicantList = [];
$totalApplicants = 0;

if ($slotInfo) {
    $slot_id = $slotInfo['slot_id'];

    // Count total registrations for this slot (all non-rejected students)
    $countResult = pg_query_params($connection, "
        SELECT COUNT(*) FROM students 
        WHERE slot_id = $1 AND municipality_id = $2
    ", [$slot_id, $municipality_id]);
    $totalApplicants = intval(pg_fetch_result($countResult, 0, 0));

    // Count pending registrations for this slot (under_registration status)
    $pendingCountResult = pg_query_params($connection, "
        SELECT COUNT(*) FROM students 
        WHERE slot_id = $1 AND status = 'under_registration' AND municipality_id = $2
    ", [$slot_id, $municipality_id]);
    $pendingCount = intval(pg_fetch_result($pendingCountResult, 0, 0));

    // Count approved registrations for this slot (applicant, verified, given, active)
    $approvedCountResult = pg_query_params($connection, "
        SELECT COUNT(*) FROM students 
        WHERE slot_id = $1 AND status IN ('applicant', 'verified', 'active', 'given') AND municipality_id = $2
    ", [$slot_id, $municipality_id]);
    $approvedCount = intval(pg_fetch_result($approvedCountResult, 0, 0));

    // Get applicants list - include all relevant statuses
    $res = pg_query_params($connection, "
        SELECT s.first_name, s.middle_name, s.last_name, s.application_date, s.status, a.semester, a.academic_year
        FROM students s
        LEFT JOIN applications a ON s.student_id = a.student_id
        WHERE s.slot_id = $1 
        AND s.status IN ('under_registration', 'applicant', 'verified', 'active')
        AND s.municipality_id = $2
        ORDER BY 
            CASE 
                WHEN s.status = 'under_registration' THEN 1
                WHEN s.status = 'applicant' THEN 2
                WHEN s.status = 'verified' THEN 3
                WHEN s.status = 'active' THEN 4
                ELSE 5
            END,
            s.application_date DESC
        LIMIT $3 OFFSET $4
    ", [$slot_id, $municipality_id, $limit, $offset]);

    while ($row = pg_fetch_assoc($res)) {
        $applicantList[] = $row;
    }

    $slotsUsed = $totalApplicants;
    $slotsLeft = $slotInfo['slot_count'] - $slotsUsed;
}

// Fetch past slots
$pastReleases = [];
$res = pg_query_params($connection, "SELECT * FROM signup_slots WHERE municipality_id = $1 AND is_active = FALSE ORDER BY created_at DESC", [$municipality_id]);

if ($res) {
    while ($row = pg_fetch_assoc($res)) {
        // Set default values for backward compatibility
        $row['manually_finished'] = false;
        $row['finished_at'] = null;
        
        $nextSlotRes = pg_query_params($connection, "SELECT created_at FROM signup_slots WHERE municipality_id = $1 AND created_at > $2 ORDER BY created_at ASC LIMIT 1", [$municipality_id, $row['created_at']]);
        
        // Safely get the next created timestamp
        if ($nextSlotRes && pg_num_rows($nextSlotRes) > 0) {
            $nextCreated = pg_fetch_result($nextSlotRes, 0, 0);
        } else {
            $nextCreated = date('Y-m-d H:i:s');
        }
        
        $countRes = pg_query_params($connection, "
            SELECT COUNT(*) FROM students 
            WHERE slot_id = $1 AND municipality_id = $2
        ", [$row['slot_id'], $municipality_id]);
        
        if ($countRes) {
            $row['slots_used'] = intval(pg_fetch_result($countRes, 0, 0));
        } else {
            $row['slots_used'] = 0;
        }
        $pastReleases[] = $row;
    }
}
?>
<?php $page_title='Manage Signup Slots'; $extra_css=['../../assets/css/admin/manage_slots.css']; include '../../includes/admin/admin_head.php'; ?>
</head>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
  <?php include '../../includes/admin/admin_sidebar.php'; ?>
  <?php include '../../includes/admin/admin_header.php'; ?>
  <section class="home-section" id="mainContent">
    <div class="container-fluid p-4">
      <h2 class="fw-bold mb-4 text-primary"><i class="bi bi-calendar-week"></i> Manage Signup Slots</h2>

      <?php
      // Display status/error messages
      if (isset($_GET['status'])) {
          if ($_GET['status'] === 'success') {
              echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                      <i class="bi bi-check-circle-fill"></i> Slot released successfully!
                      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>';
          } elseif ($_GET['status'] === 'deleted') {
              echo '<div class="alert alert-info alert-dismissible fade show" role="alert">
                      <i class="bi bi-trash-fill"></i> Slot deleted successfully!
                      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>';
          } elseif ($_GET['status'] === 'slot_finished') {
              echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                      <i class="bi bi-check-square-fill"></i> Current slot finished successfully!
                      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>';
          }
      }
      
      if (isset($_GET['error'])) {
          $errorMsg = '';
          switch ($_GET['error']) {
              case 'invalid_year':
                  $errorMsg = 'Invalid academic year format. Use format: 2025-2026';
                  break;
              case 'invalid_password':
                  $errorMsg = 'Incorrect password. Please try again.';
                  break;
              case 'duplicate_slot':
                  $errorMsg = 'A slot for this semester and academic year already exists.';
                  break;
              case 'past_academic_year':
                  $errorMsg = 'Cannot create a slot for a past academic year. Please select a current or future academic year.';
                  break;
              case 'past_semester':
                  $errorMsg = 'Cannot create a slot for a past semester in the same academic year. Please select the next semester or a future academic year.';
                  break;
              case 'unfinalized_distributions':
                  $unfinalizedCount = isset($_GET['count']) ? intval($_GET['count']) : 0;
                  $errorMsg = "Cannot create new slots while there are unfinalized distributions! There are currently {$unfinalizedCount} students with 'given' status. Please finalize the current distribution in <a href='manage_distributions.php' class='alert-link'>Manage Distributions</a> before creating new slots.";
                  break;
              case 'session_invalid':
                  $errorMsg = 'Session error. Please log out and log in again.';
                  break;
              case 'distribution_inactive':
                  $errorMsg = 'Cannot create slots when distribution is inactive. Please start a distribution in <a href="distribution_control.php" class="alert-link">Distribution Control</a> first.';
                  break;
              case 'no_academic_period':
                  $errorMsg = 'No academic period set. Please set the academic period in <a href="distribution_control.php" class="alert-link">Distribution Control</a> first.';
                  break;
              case 'distribution_finalized':
                  $errorMsg = 'Cannot create slots after distribution is finalized. Please start a new distribution cycle in <a href="distribution_control.php" class="alert-link">Distribution Control</a> first.';
                  break;
              default:
                  $errorMsg = 'An error occurred. Please try again.';
          }
          echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <i class="bi bi-exclamation-triangle-fill"></i> ' . $errorMsg . '
                  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>';
      }
      ?>

      <!-- Program Capacity Overview -->
      <div class="card border-info mb-4">
        <div class="card-header bg-info text-white">
          <h5 class="mb-0"><i class="bi bi-speedometer2 me-2"></i>Program Capacity Overview</h5>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-3">
              <div class="text-center">
                <h6 class="text-muted mb-1">Current Students</h6>
                <h4 class="text-primary mb-0" id="currentStudentsCount"><?= number_format($currentTotalStudents) ?></h4>
              </div>
            </div>
            <div class="col-md-3">
              <div class="text-center">
                <h6 class="text-muted mb-1">Maximum Capacity</h6>
                <h4 class="text-<?= $maxCapacity !== null ? 'success' : 'warning' ?> mb-0">
                  <?= $maxCapacity !== null ? number_format($maxCapacity) : 'Not Set' ?>
                </h4>
              </div>
            </div>
            <div class="col-md-3">
              <div class="text-center">
                <h6 class="text-muted mb-1">Remaining Slots</h6>
                <h4 class="text-<?= $maxCapacity !== null && ($maxCapacity - $currentTotalStudents) > 0 ? 'info' : 'danger' ?> mb-0" id="remainingCapacity">
                  <?php 
                    if ($maxCapacity !== null) {
                        $remaining = $maxCapacity - $currentTotalStudents;
                        echo $remaining > 0 ? number_format($remaining) : '0';
                    } else {
                        echo 'N/A';
                    }
                  ?>
                </h4>
              </div>
            </div>
            <div class="col-md-3">
              <div class="text-center">
                <h6 class="text-muted mb-1">Utilization</h6>
                <h4 class="mb-0" id="utilizationPercentage">
                  <?php 
                    if ($maxCapacity !== null && $maxCapacity > 0) {
                        $utilization = ($currentTotalStudents / $maxCapacity) * 100;
                        $colorClass = $utilization >= 90 ? 'text-danger' : ($utilization >= 75 ? 'text-warning' : 'text-success');
                        echo '<span class="' . $colorClass . '">' . round($utilization, 1) . '%</span>';
                    } else {
                        echo '<span class="text-muted">N/A</span>';
                    }
                  ?>
                </h4>
              </div>
            </div>
          </div>
          
          <?php if ($maxCapacity !== null): ?>
          <div class="mt-3">
            <div class="progress" style="height: 15px;">
              <?php 
              $percentage = ($currentTotalStudents / max(1, $maxCapacity)) * 100;
              $barClass = 'bg-success';
              if ($percentage >= 90) $barClass = 'bg-danger';
              elseif ($percentage >= 75) $barClass = 'bg-warning';
              ?>
              <div class="progress-bar <?= $barClass ?>" style="width: <?= min(100, $percentage) ?>%">
                <?= round($percentage, 1) ?>%
              </div>
            </div>
          </div>
          <?php endif; ?>
          
          <?php if ($maxCapacity === null): ?>
          <div class="alert alert-warning mt-3 mb-0">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Notice:</strong> Maximum capacity not set. Please configure it in 
            <a href="settings.php" class="alert-link">Settings</a> to get slot recommendations.
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Release New Slot -->
      <?php
      // Check for unfinalized distributions before showing form
      $unfinalizedCheck = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE status = 'given'");
      $hasUnfinalizedDistributions = pg_fetch_assoc($unfinalizedCheck)['count'] > 0;
      ?>
      
      <?php if ($hasUnfinalizedDistributions): ?>
      <div class="alert alert-warning">
        <h5><i class="bi bi-exclamation-triangle-fill me-2"></i>Distribution In Progress</h5>
        <p class="mb-2">
          <strong>Cannot create new slots:</strong> There are students with distributed aid that need to be finalized first.
        </p>
        <p class="mb-0">
          Please complete the current distribution cycle in 
          <a href="manage_distributions.php" class="alert-link">
            <i class="bi bi-box-seam me-1"></i>Manage Distributions
          </a>
          before creating new slots.
        </p>
      </div>
      <?php endif; ?>
      
      <?php if (!in_array($distribution_status, ['preparing', 'active'])): ?>
      <div class="alert alert-info">
        <h5><i class="bi bi-info-circle-fill me-2"></i>Distribution Not Active</h5>
        <p class="mb-2">
          <strong>Distribution Status:</strong> <?= ucfirst($distribution_status) ?>
        </p>
        <p class="mb-0">
          Please start and activate a distribution cycle in 
          <a href="distribution_control.php" class="alert-link">
            <i class="bi bi-gear-fill me-1"></i>Distribution Control Center
          </a>
          to enable slot management.
        </p>
      </div>
      <?php endif; ?>
      
      <?php if (empty($distribution_academic_year) || empty($distribution_semester)): ?>
      <div class="alert alert-warning">
        <h5><i class="bi bi-exclamation-triangle-fill me-2"></i>Academic Period Not Set</h5>
        <p class="mb-0">
          Academic period has not been configured. Please set it in 
          <a href="distribution_control.php" class="alert-link fw-bold">Distribution Control Center</a> first.
        </p>
      </div>
      <?php endif; ?>
      
      <?php if ($distribution_status === 'finalized'): ?>
      <div class="alert alert-info">
        <h5><i class="bi bi-check-circle-fill me-2"></i>Distribution Cycle Complete</h5>
        <p class="mb-2">
          <strong>Status:</strong> Current distribution has been finalized
        </p>
        <p class="mb-0">
          To create new slots, please start a new distribution cycle in 
          <a href="distribution_control.php" class="alert-link">
            <i class="bi bi-gear-fill me-1"></i>Distribution Control Center
          </a>
        </p>
      </div>
      <?php endif; ?>
      
      <?php 
      $canCreateSlots = !$hasUnfinalizedDistributions && 
                        in_array($distribution_status, ['preparing', 'active']) && 
                        !empty($distribution_academic_year) && 
                        !empty($distribution_semester) &&
                        $distribution_status !== 'finalized';
      ?>
      
      <form id="releaseSlotsForm" method="POST" class="card p-4 shadow-sm mb-4" <?php echo !$canCreateSlots ? 'style="opacity: 0.6; pointer-events: none;"' : ''; ?>>
        <!-- Hidden fields for academic period -->
        <input type="hidden" name="semester" value="<?= htmlspecialchars($distribution_semester) ?>">
        <input type="hidden" name="academic_year" value="<?= htmlspecialchars($distribution_academic_year) ?>">
        
        <h5 class="fw-semibold mb-3 text-secondary">
          <i class="bi bi-plus-circle"></i> Release New Slot
          <?php if (!$canCreateSlots): ?>
            <span class="badge bg-warning ms-2">Blocked</span>
          <?php endif; ?>
        </h5>
        
        <!-- Smart Recommendations -->
        <?php if ($maxCapacity !== null): ?>
        <div class="alert alert-info" id="slotRecommendation">
          <i class="bi bi-lightbulb me-2"></i>
          <strong>Smart Suggestion:</strong> 
          <span id="recommendationText">
            <?php 
              $remainingCapacity = $maxCapacity - $currentTotalStudents;
              if ($remainingCapacity > 0) {
                  $suggestedSlots = min($remainingCapacity, 50); // Cap at 50 for practical reasons
                  echo "Based on remaining capacity ({$remainingCapacity} students), we suggest {$suggestedSlots} slots.";
              } else {
                  echo "Program is at maximum capacity. Consider increasing capacity in Settings before releasing new slots.";
              }
            ?>
          </span>
        </div>
        <?php endif; ?>
        
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Slot Count</label>
            <div class="input-group">
              <input type="number" name="slot_count" id="slotCountInput" class="form-control" required min="1" 
                     <?php if ($maxCapacity !== null && ($maxCapacity - $currentTotalStudents) > 0): ?>
                       placeholder="<?= min($maxCapacity - $currentTotalStudents, 50) ?>"
                     <?php endif; ?>>
              <?php if ($maxCapacity !== null && ($maxCapacity - $currentTotalStudents) > 0): ?>
              <button type="button" class="btn btn-outline-info" id="useSuggestionBtn" 
                      data-suggestion="<?= min($maxCapacity - $currentTotalStudents, 50) ?>">
                <i class="bi bi-magic"></i> Use Suggestion
              </button>
              <?php endif; ?>
            </div>
            <small class="text-muted" id="slotValidationText">
              <?php if ($maxCapacity !== null): ?>
                Max remaining: <?= max(0, $maxCapacity - $currentTotalStudents) ?> students
              <?php endif; ?>
            </small>
          </div>
          <div class="col-md-4">
            <label class="form-label">Semester</label>
            <input type="text" class="form-control" 
                   value="<?= htmlspecialchars($distribution_semester) ?>" 
                   readonly style="background-color: #f8f9fa;">
            <small class="text-muted">
              <i class="bi bi-info-circle me-1"></i>
              Set in Distribution Control Center
            </small>
          </div>
          <div class="col-md-4">
            <label class="form-label">Academic Year</label>
            <input type="text" class="form-control" 
                   value="<?= htmlspecialchars($distribution_academic_year) ?>" 
                   readonly style="background-color: #f8f9fa;">
            <small class="text-muted">
              <i class="bi bi-info-circle me-1"></i>
              Managed by Distribution Control
            </small>
          </div>
        </div>
        <div class="mt-2">
          <small class="text-info">
            <i class="bi bi-info-circle"></i> 
            All fields are required. Academic year must be progressive (cannot go backwards from latest slot).
          </small>
        </div>
        <button type="button" id="showPasswordModalBtn" class="btn btn-primary mt-3">
          <i class="bi bi-upload"></i> Release
        </button>
      </form>

      <!-- Current Slot -->
      <?php if ($slotInfo): ?>
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-clock-history"></i> Current Slot</span>
            <span class="badge badge-pill badge-blue"><?= $slotInfo['semester'] ?> | AY <?= $slotInfo['academic_year'] ?></span>
          </div>
          <div id="currentSlotBody" class="collapse show card-body">
            <p><strong>Released:</strong> <?= date('F j, Y — h:i A', strtotime($slotInfo['created_at'])) ?></p>
            <p><strong>Slot Usage:</strong> <span id="slotUsageDisplay"><?= $slotsUsed ?> / <?= $slotInfo['slot_count'] ?></span></p>
            <?php if ($maxCapacity !== null): ?>
            <p><strong>Program Capacity:</strong> 
              <span id="capacityDisplay" class="badge <?= $currentTotalStudents >= $maxCapacity ? 'bg-danger' : 'bg-info' ?>">
                <?= $currentTotalStudents ?> / <?= number_format($maxCapacity) ?>
              </span>
              <span id="capacityWarning" <?php if ($currentTotalStudents < $maxCapacity): ?>style="display: none;"<?php endif; ?>>
                <small class="text-danger">⚠️ At maximum capacity</small>
              </span>
            </p>
            <?php endif; ?>
            <div class="row mb-3">
              <div class="col-md-6">
                <p><strong>Pending Approval:</strong> <span id="pendingCount" class="badge bg-warning"><?= $pendingCount ?></span></p>
              </div>
              <div class="col-md-6">
                <p><strong>Approved:</strong> <span id="approvedCount" class="badge bg-success"><?= $approvedCount ?></span></p>
              </div>
            </div>
            <?php
              $percentage = ($slotsUsed / max(1, $slotInfo['slot_count'])) * 100;
              $barClass = 'bg-success';
              if ($percentage >= 80) $barClass = 'bg-danger';
              elseif ($percentage >= 50) $barClass = 'bg-warning';

              $expired = (strtotime('now') - strtotime($slotInfo['created_at'])) >= (14 * 24 * 60 * 60);
            ?>
            <div class="progress mb-3">
              <div id="progressBar" class="progress-bar <?= $barClass ?>" style="width: <?= $percentage ?>%">
                <span id="progressText"><?= round($percentage) ?>%</span>
              </div>
            </div>
            <?php if ($expired): ?>
              <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill"></i> This slot is more than 14 days old.</div>
            <?php endif; ?>
            <p><strong>Remaining:</strong> <span id="remainingSlots" class="badge badge-pill <?= $slotsLeft > 0 ? 'badge-green' : 'badge-red' ?>"><?= max(0, $slotsLeft) ?> slots left</span></p>

            <!-- Finish Current Slot Button -->
            <div class="d-flex gap-2 mb-3">
              <button type="button" id="finishSlotBtn" class="btn btn-warning">
                <i class="bi bi-stop-circle"></i> Finish Current Slot
              </button>
            </div>

            <?php if (!empty($applicantList)): ?>
              <form method="POST" class="mb-3">
                <input type="hidden" name="export_csv" value="1">
                <button class="btn btn-success btn-sm"><i class="bi bi-download"></i> Export All Registrations</button>
              </form>

              <h6 class="fw-semibold mt-4"><i class="bi bi-people"></i> All Registrations (Pending + Approved)</h6>
              <div class="table-responsive">
                <table class="table table-bordered table-sm table-striped align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>Name</th>
                      <th>Application Date</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($applicantList as $a): ?>
                      <tr>
                        <td><?= htmlspecialchars(formatName($a['last_name'], $a['first_name'], $a['middle_name'])) ?></td>
                        <td><?= date('M d, Y — h:i A', strtotime($a['application_date'])) ?></td>
                        <td>
                          <?php if ($a['status'] === 'under_registration'): ?>
                            <span class="badge bg-warning">Pending Approval</span>
                          <?php elseif ($a['status'] === 'applicant'): ?>
                            <span class="badge bg-success">Approved</span>
                          <?php elseif ($a['status'] === 'verified'): ?>
                            <span class="badge bg-info">Verified</span>
                          <?php elseif ($a['status'] === 'active'): ?>
                            <span class="badge bg-primary">Active</span>
                          <?php else: ?>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($a['status'])); ?></span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php
                $totalPages = ceil($totalApplicants / $limit);
                if ($totalPages > 1): ?>
                  <nav>
                    <ul class="pagination pagination-sm mt-3">
                      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                          <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                        </li>
                      <?php endfor; ?>
                    </ul>
                  </nav>
              <?php endif; ?>
            <?php else: ?>
              <p class="text-center text-muted border rounded py-3">No applicants for this slot.</p>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Past Releases -->
      <h4 class="mt-4 text-primary"><i class="bi bi-archive"></i> Past Releases</h4>
      <?php if (!empty($pastReleases)): ?>
        <div class="accordion" id="pastSlotsAccordion">
          <?php foreach ($pastReleases as $i => $h): ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="heading<?= $i ?>">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                        data-bs-target="#collapse<?= $i ?>" aria-expanded="false" aria-controls="collapse<?= $i ?>">
                  <div class="d-flex justify-content-between align-items-center w-100 me-3">
                    <span>
                      <i class="bi bi-calendar-event"></i>
                      <?= date('F j, Y — h:i A', strtotime($h['created_at'])) ?> — <?= $h['slot_count'] ?> slots
                    </span>
                    <span class="badge bg-secondary">
                      Past Release
                    </span>
                  </div>
                </button>
              </h2>
              <div id="collapse<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#pastSlotsAccordion">
                <div class="accordion-body">
                  <p><strong>Semester:</strong> <?= $h['semester'] ?> | <strong>AY:</strong> <?= $h['academic_year'] ?></p>
                  <p><strong>Used:</strong> <?= $h['slots_used'] ?> / <?= $h['slot_count'] ?></p>
                  <form method="POST" onsubmit="return confirm('Are you sure you want to delete this slot?')">
                    <input type="hidden" name="delete_slot_id" value="<?= $h['slot_id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Delete</button>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="alert alert-info"><i class="bi bi-info-circle-fill"></i> No past releases found.</div>
      <?php endif; ?>
    </div>
  </section>
</div>

<!-- Password Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Confirm Password</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="password" id="modal_admin_password" class="form-control" placeholder="Enter password" required>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="confirmReleaseBtn">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- Finish Slot Password Modal -->
<div class="modal fade" id="finishSlotModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Finish Current Slot</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <strong>Warning:</strong> This will permanently finish the current slot and move it to past releases. 
          Students will no longer be able to register using this slot configuration.
        </div>
        <input type="password" id="finish_modal_admin_password" class="form-control" placeholder="Enter your password to confirm" required>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-warning" id="confirmFinishBtn">
          <i class="bi bi-stop-circle"></i> Finish Slot
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script>
  document.getElementById('showPasswordModalBtn').addEventListener('click', () => {
    // Comprehensive validation before showing password modal
    if (!validateAllFields()) {
      return; // Don't show modal if validation fails
    }
    new bootstrap.Modal(document.getElementById('passwordModal')).show();
  });

  // Comprehensive field validation function
  function validateAllFields() {
    const slotCountInput = document.querySelector('input[name="slot_count"]');
    const semesterInput = document.querySelector('input[name="semester"]');
    const academicYearInput = document.querySelector('input[name="academic_year"]');
    
    // Check if all fields are filled
    if (!slotCountInput.value || slotCountInput.value <= 0) {
      alert('Please enter a valid slot count (must be greater than 0).');
      slotCountInput.focus();
      return false;
    }
    
    // Check capacity constraints if max capacity is set
    if (maxCapacity && parseInt(slotCountInput.value) > remainingCapacity) {
      const exceed = parseInt(slotCountInput.value) - remainingCapacity;
      if (!confirm(`This slot count exceeds program capacity by ${exceed} students. Do you want to proceed anyway? Consider increasing capacity in Settings first.`)) {
        slotCountInput.focus();
        return false;
      }
    }
    
    if (!semesterInput.value) {
      alert('No semester configured. Please set academic period in Distribution Control first.');
      return false;
    }
    
    if (!academicYearInput.value) {
      alert('No academic year configured. Please set academic period in Distribution Control first.');
      return false;
    }
    
    // Validate academic year format
    const academicYearPattern = /^\d{4}-\d{4}$/;
    if (!academicYearPattern.test(academicYearInput.value)) {
      alert('Invalid academic year format from Distribution Control. Please check the configuration.');
      return false;
    }
    
    // Validate academic year logic (start year should be exactly 1 year before end year)
    const yearParts = academicYearInput.value.split('-');
    const startYear = parseInt(yearParts[0]);
    const endYear = parseInt(yearParts[1]);
    
    if (endYear !== startYear + 1) {
      alert('Invalid academic year from Distribution Control. Please check the configuration.');
      return false;
    }
    
    // Check slot progression constraints
    if (!validateSlotProgression()) {
      return false;
    }
    
    return true;
  }
  
  // Slot progression validation (moved from existing code)
  function validateSlotProgression() {
    const academicYearInput = document.querySelector('input[name="academic_year"]');
    const semesterInput = document.querySelector('input[name="semester"]');
    
    const currentAcademicYear = academicYearInput.value;
    const currentSemester = semesterInput.value;
    
    // Get latest slot info for validation (already fetched in PHP)
    const latestSlotInfo = {
      academicYear: <?= json_encode($latestSlotForValidation['academic_year'] ?? '') ?>,
      semester: <?= json_encode($latestSlotForValidation['semester'] ?? '') ?>
    };
    
    if (!latestSlotInfo.academicYear) return true; // No previous slots, allow any
    
    // Parse years
    const currentYearParts = currentAcademicYear.split('-');
    const latestYearParts = latestSlotInfo.academicYear.split('-');
    
    if (currentYearParts.length !== 2 || latestYearParts.length !== 2) return true;
    
    const currentStartYear = parseInt(currentYearParts[0]);
    const latestStartYear = parseInt(latestYearParts[0]);
    
    // Check if trying to go backwards in academic year
    if (currentStartYear < latestStartYear) {
      alert(`Cannot create slot for ${currentAcademicYear}. Latest slot is for ${latestSlotInfo.academicYear}. Please use ${latestStartYear}-${latestStartYear + 1} or later.`);
      academicYearInput.focus();
      return false;
    }
    
    // Check if same year but trying to go backwards in semester
    if (currentStartYear === latestStartYear) {
      const semesterOrder = {'1st Semester': 1, '2nd Semester': 2};
      const currentSemesterNum = semesterOrder[currentSemester] || 0;
      const latestSemesterNum = semesterOrder[latestSlotInfo.semester] || 0;
      
      if (currentSemesterNum <= latestSemesterNum) {
        alert(`Cannot create slot for ${currentAcademicYear} ${currentSemester}. Latest slot is for ${latestSlotInfo.academicYear} ${latestSlotInfo.semester}. Please configure a new academic period in Distribution Control.`);
        return false;
      }
    }
    
    return true;
  }

  document.getElementById('confirmReleaseBtn').addEventListener('click', () => {
    const pass = document.getElementById('modal_admin_password').value;
    if (!pass) return alert('Please enter your password.');
    const form = document.getElementById('releaseSlotsForm');
    let input = form.querySelector('input[name="admin_password"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'admin_password';
      form.appendChild(input);
    }
    input.value = pass;
    form.submit();
  });

  // Finish Slot functionality
  <?php if ($slotInfo): ?>
  document.getElementById('finishSlotBtn').addEventListener('click', () => {
    new bootstrap.Modal(document.getElementById('finishSlotModal')).show();
  });

  document.getElementById('confirmFinishBtn').addEventListener('click', () => {
    const pass = document.getElementById('finish_modal_admin_password').value;
    if (!pass) return alert('Please enter your password.');
    
    // Create and submit form for finishing slot
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    const finishInput = document.createElement('input');
    finishInput.type = 'hidden';
    finishInput.name = 'finish_current_slot';
    finishInput.value = '1';
    form.appendChild(finishInput);
    
    const passwordInput = document.createElement('input');
    passwordInput.type = 'hidden';
    passwordInput.name = 'admin_password';
    passwordInput.value = pass;
    form.appendChild(passwordInput);
    
    document.body.appendChild(form);
    form.submit();
  });
  <?php endif; ?>

  // Smart slot recommendations and real-time validation
  const maxCapacity = <?= $maxCapacity ?? 'null' ?>;
  const currentStudents = <?= $currentTotalStudents ?>;
  const remainingCapacity = maxCapacity ? (maxCapacity - currentStudents) : null;

  // Use suggestion button
  const useSuggestionBtn = document.getElementById('useSuggestionBtn');
  if (useSuggestionBtn) {
    useSuggestionBtn.addEventListener('click', () => {
      const suggestion = parseInt(useSuggestionBtn.dataset.suggestion);
      document.getElementById('slotCountInput').value = suggestion;
      updateSlotValidation(suggestion);
    });
  }

  // Real-time slot count validation and feedback
  const slotCountInput = document.getElementById('slotCountInput');
  if (slotCountInput) {
    slotCountInput.addEventListener('input', (e) => {
      const value = parseInt(e.target.value) || 0;
      updateSlotValidation(value);
    });
  }

  function updateSlotValidation(slotCount) {
    const validationText = document.getElementById('slotValidationText');
    const recommendationText = document.getElementById('recommendationText');
    
    if (!maxCapacity || !validationText) return;

    let message = '';
    let alertClass = 'text-muted';
    let recommendationMessage = '';

    if (slotCount <= 0) {
      message = `Max remaining: ${remainingCapacity} students`;
      alertClass = 'text-muted';
    } else if (slotCount > remainingCapacity) {
      message = `⚠️ Exceeds capacity! Max remaining: ${remainingCapacity} students`;
      alertClass = 'text-danger';
      recommendationMessage = `This exceeds program capacity by ${slotCount - remainingCapacity} students. Consider reducing to ${remainingCapacity} or increasing capacity in Settings.`;
    } else if (slotCount === remainingCapacity) {
      message = `✓ Perfect! Uses all remaining capacity (${remainingCapacity} students)`;
      alertClass = 'text-success';
      recommendationMessage = `Excellent! This will fully utilize the remaining program capacity.`;
    } else {
      const remaining = remainingCapacity - slotCount;
      message = `✓ Good choice! ${remaining} students will remain available`;
      alertClass = 'text-success';
      recommendationMessage = `Good allocation! After this slot, ${remaining} students can still be accommodated.`;
    }

    validationText.innerHTML = message;
    validationText.className = `form-text ${alertClass}`;
    
    if (recommendationText) {
      recommendationText.innerHTML = recommendationMessage;
    }
  }

  // Update capacity display periodically (optional - for future real-time updates)
  function updateCapacityDisplay() {
    // This could be enhanced to fetch updated counts via AJAX
    // For now, it's static but the structure is ready for real-time updates
  }

  // Real-time slot updates
  let updateInterval;
  let isUpdating = false;

  function updateSlotStats() {
    if (isUpdating) return; // Prevent concurrent requests
    isUpdating = true;
    
    console.log('Fetching updated slot stats...');

    fetch('get_slot_stats.php', {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
      }
    })
    .then(response => response.json())
    .then(data => {
      console.log('Received slot stats data:', data);
      
      if (data.success) {
        // Update slot usage display
        const slotUsageDisplay = document.getElementById('slotUsageDisplay');
        if (slotUsageDisplay) {
          slotUsageDisplay.textContent = `${data.slotsUsed} / ${data.slotCount}`;
        }

        // Update pending and approved counts
        const pendingCount = document.getElementById('pendingCount');
        if (pendingCount) {
          pendingCount.textContent = data.pendingCount;
        }

        const approvedCount = document.getElementById('approvedCount');
        if (approvedCount) {
          approvedCount.textContent = data.approvedCount;
        }

        // Update remaining slots
        const remainingSlots = document.getElementById('remainingSlots');
        if (remainingSlots) {
          remainingSlots.textContent = `${data.slotsLeft} slots left`;
          remainingSlots.className = `badge badge-pill ${data.slotsLeft > 0 ? 'badge-green' : 'badge-red'}`;
        }

        // Update progress bar
        const progressBar = document.getElementById('progressBar');
        const progressText = document.getElementById('progressText');
        if (progressBar && progressText) {
          progressBar.style.width = `${data.percentage}%`;
          progressBar.className = `progress-bar ${data.barClass}`;
          progressText.textContent = `${Math.round(data.percentage)}%`;
        }

        // Update program capacity overview section
        const currentStudentsCount = document.getElementById('currentStudentsCount');
        if (currentStudentsCount && data.currentTotalStudents !== undefined) {
          currentStudentsCount.textContent = data.currentTotalStudents.toLocaleString();
        }

        const remainingCapacity = document.getElementById('remainingCapacity');
        if (remainingCapacity && data.maxCapacity) {
          const remaining = data.maxCapacity - data.currentTotalStudents;
          remainingCapacity.textContent = remaining.toLocaleString();
          remainingCapacity.className = `text-${remaining > 0 ? 'info' : 'danger'} mb-0`;
        }

        const utilizationPercentage = document.getElementById('utilizationPercentage');
        if (utilizationPercentage && data.maxCapacity) {
          const utilization = (data.currentTotalStudents / data.maxCapacity) * 100;
          utilizationPercentage.textContent = `${Math.round(utilization)}%`;
        }

        // Update capacity display
        const capacityDisplay = document.getElementById('capacityDisplay');
        const capacityWarning = document.getElementById('capacityWarning');
        if (capacityDisplay && data.maxCapacity) {
          capacityDisplay.textContent = `${data.currentTotalStudents} / ${data.maxCapacity.toLocaleString()}`;
          capacityDisplay.className = `badge ${data.atCapacity ? 'bg-danger' : 'bg-info'}`;
          
          if (capacityWarning) {
            capacityWarning.style.display = data.atCapacity ? 'inline' : 'none';
          }
        }

        console.log('Slot stats updated silently');
      } else {
        console.warn('Failed to update slot stats:', data.error || 'Unknown error');
      }
    })
    .catch(error => {
      console.error('Error fetching slot stats:', error);
    })
    .finally(() => {
      isUpdating = false;
    });
  }

  function startRealTimeUpdates() {
    // Update immediately
    updateSlotStats();
    
    // Then update every 100ms for real-time updates
    updateInterval = setInterval(updateSlotStats, 100);
    
    console.log('Real-time slot updates started (every 100ms)');
  }

  function stopRealTimeUpdates() {
    if (updateInterval) {
      clearInterval(updateInterval);
      updateInterval = null;
    }
    
    console.log('Real-time slot updates stopped');
  }

  // Start real-time updates when page loads
  document.addEventListener('DOMContentLoaded', function() {
    // Only start if there's an active slot
    if (document.getElementById('slotUsageDisplay')) {
      startRealTimeUpdates();
    }

    // Stop updates when page is about to unload
    window.addEventListener('beforeunload', stopRealTimeUpdates);
    
    // Pause updates when tab is not visible (optional performance optimization)
    document.addEventListener('visibilitychange', function() {
      if (document.hidden) {
        stopRealTimeUpdates();
      } else if (document.getElementById('slotUsageDisplay')) {
        startRealTimeUpdates();
      }
    });
  });
</script>
</body>
</html>
