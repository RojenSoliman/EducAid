<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
include '../../config/database.php';
include '../../includes/workflow_control.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

// Load documents submission deadline from config (if any)
$documents_deadline = null;
$cfgRes = pg_query_params($connection, "SELECT value FROM config WHERE key = $1", ['documents_deadline']);
if ($cfgRes && pg_num_rows($cfgRes) > 0) {
    $documents_deadline = pg_fetch_result($cfgRes, 0, 'value');
}

// Check workflow status - prevent access if payroll/QR not ready
$workflow_status = getWorkflowStatus($connection);
if (!$workflow_status['can_schedule']) {
    echo "<script>alert('Access denied: Please generate payroll numbers and QR codes first.'); window.location.href = 'verify_students.php';</script>";
    exit;
}

// Get admin ID for tracking
$admin_id = $_SESSION['admin_id'] ?? 1;

// Load settings for publish state
$settingsPath = __DIR__ . '/../../data/municipal_settings.json';
$settings = file_exists($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : [];

// Handle AJAX requests for date validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'check_date_availability') {
        $date = $_POST['date'];
        $result = pg_query_params($connection, 
            "SELECT COUNT(*) as count FROM schedules WHERE distribution_date = $1", 
            [$date]
        );
        $row = pg_fetch_assoc($result);
        echo json_encode(['available' => $row['count'] == 0]);
        exit;
    }
    
    if ($_POST['action'] === 'validate_time_slot') {
        $date = $_POST['date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        
        // Check for time overlaps on the same date
        $result = pg_query_params($connection,
            "SELECT COUNT(*) as count FROM schedules 
             WHERE distribution_date = $1 
             AND ((time_slot LIKE $2) OR (time_slot LIKE $3))",
            [$date, "%{$start_time}%", "%{$end_time}%"]
        );
        $row = pg_fetch_assoc($result);
        echo json_encode(['valid' => $row['count'] == 0]);
        exit;
    }
}

// Handle schedule creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_schedule'])) {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('manage_schedules', $token)) {
        $error_message = "Security validation failed. Please refresh the page and try again.";
        error_log("CSRF validation failed for create_schedule");
    } else {
    // Debug: Log the received data with more detail
    error_log("Received POST data: " . print_r($_POST, true));
    error_log("Raw schedule_data: " . ($_POST['schedule_data'] ?? 'NOT SET'));
    error_log("Schedule_data length: " . strlen($_POST['schedule_data'] ?? ''));
    
    if (!isset($_POST['schedule_data'])) {
        $error_message = "No schedule data received in POST request.";
        error_log("ERROR: No schedule_data in POST");
    } else {
        $schedule_data = json_decode($_POST['schedule_data'], true);
        
        // Debug: Log the decoded data
        error_log("Decoded schedule data: " . print_r($schedule_data, true));
        error_log("JSON decode error: " . json_last_error_msg());
        
        if ($schedule_data === null) {
            $error_message = "Invalid JSON data received: " . json_last_error_msg();
            error_log("JSON decode failed: " . json_last_error_msg() . " for data: " . $_POST['schedule_data']);
        } elseif (!isset($schedule_data['start_date']) || !isset($schedule_data['end_date']) || !isset($schedule_data['location']) || !isset($schedule_data['batches'])) {
            $error_message = "Invalid schedule data received. Please ensure all fields are filled correctly.";
            error_log("Invalid schedule data: " . $_POST['schedule_data']);
        } else {
            $start_date = $schedule_data['start_date'];
            $end_date = $schedule_data['end_date'];
            $location = $schedule_data['location'];
            $batches = $schedule_data['batches'];
            
            // Enforce start date should not be before the configured documents submission deadline
            if (!empty($documents_deadline) && $start_date < $documents_deadline) {
                $error_message = "Schedule start date cannot be before the documents submission deadline (" . htmlspecialchars($documents_deadline) . ").";
            }
            // Additional validation
            elseif (empty($batches) || !is_array($batches)) {
                $error_message = "No batches were configured. Please select number of batches and configure them.";
            } elseif (strtotime($end_date) < strtotime($start_date)) {
                $error_message = "End date cannot be before start date.";
            } else {
                // Check if any dates in the range are already used
                $conflicting_dates = [];
                $current_date = $start_date;
                while (strtotime($current_date) <= strtotime($end_date)) {
                    $date_check = pg_query_params($connection, 
                        "SELECT COUNT(*) as count FROM schedules WHERE distribution_date = $1", 
                        [$current_date]
                    );
                    $date_row = pg_fetch_assoc($date_check);
                    if ($date_row && $date_row['count'] > 0) {
                        $conflicting_dates[] = $current_date;
                    }
                    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                }
                
                if (!empty($conflicting_dates)) {
                    $error_message = "The following dates are already used: " . implode(', ', $conflicting_dates) . ". Please choose different dates.";
                } else {
                    // Begin transaction
                    pg_query($connection, "BEGIN");
                    
                    try {
                        // First, get all students with payroll numbers to know actual capacity
                        $studentCountResult = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE payroll_no IS NOT NULL");
                        $studentCountRow = pg_fetch_assoc($studentCountResult);
                        $actualStudentCount = intval($studentCountRow['count']);
                        
                        if ($actualStudentCount == 0) {
                            throw new Exception("No students with payroll numbers found in the database. Please ensure students have been assigned payroll numbers.");
                        }
                        
                        $counter = 1;
                        $total_students = 0;
                        $schedule_dates = [];
                        
                        // Create schedules for each date in the range
                        $current_date = $start_date;
                        while (strtotime($current_date) <= strtotime($end_date)) {
                            $schedule_dates[] = $current_date;
                            
                            if (is_array($batches) && count($batches) > 0) {
                                foreach ($batches as $batch_num => $batch) {
                                    $students_in_batch = intval($batch['capacity'] ?? 0);
                                    $start_time = $batch['start_time'] ?? '';
                                    $end_time = $batch['end_time'] ?? '';
                                    $time_slot = $start_time . ' - ' . $end_time;
                                    
                                    // Only schedule as many students as we have capacity for and exist in DB
                                    $students_to_schedule = min($students_in_batch, $actualStudentCount - ($counter - 1));
                                    
                                    for ($i = 0; $i < $students_to_schedule; $i++) {
                                        $pno = $counter;
                                        
                                        // Check if student exists before creating schedule
                                        if ($pno > $actualStudentCount) {
                                            error_log("Skipping payroll_no $pno - exceeds actual student count of $actualStudentCount");
                                            break;
                                        }
                                        
                                        $sidRes = pg_query_params($connection, 
                                            "SELECT student_id FROM students WHERE payroll_no = $1", 
                                            [$pno]
                                        );
                                        
                                        if (!$sidRes) {
                                            error_log("Failed to query student with payroll_no: $pno - " . pg_last_error($connection));
                                            continue;
                                        }
                                        
                                        $sid = pg_fetch_assoc($sidRes);
                                        if (!$sid) {
                                            error_log("No student found with payroll_no: $pno");
                                            continue;
                                        }
                                        
                                        $studentId = intval($sid['student_id']);
                                        
                                        // Debug the data being inserted
                                        error_log("Inserting schedule: student_id=$studentId, payroll_no=$pno, batch_no=" . ($batch_num + 1) . ", date=$current_date, time_slot=$time_slot, location=$location");
                                        
                                        $insertResult = pg_query_params($connection,
                                            "INSERT INTO schedules (student_id, payroll_no, batch_no, distribution_date, time_slot, location) 
                                             VALUES ($1, $2, $3, $4, $5, $6)",
                                            [$studentId, $pno, $batch_num + 1, $current_date, $time_slot, $location]
                                        );
                                        
                                        if (!$insertResult) {
                                            $error = pg_last_error($connection);
                                            error_log("Failed to insert schedule for payroll_no $pno: $error");
                                            throw new Exception("Database insertion failed: $error");
                                        }
                                        
                                        $counter++;
                                        $total_students++;
                                    }
                                    
                                    // If we've scheduled all available students, break out of batch loop
                                    if ($counter > $actualStudentCount) {
                                        break;
                                    }
                                }
                            }
                            
                            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                        }
                        
                        // Save schedule metadata
                        $settings['schedule_meta'] = [
                            'start_date' => $start_date,
                            'end_date' => $end_date,
                            'location' => $location,
                            'batches' => $batches,
                            'total_students' => $total_students,
                            'schedule_dates' => $schedule_dates
                        ];
                        file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
                        
                        pg_query($connection, "COMMIT");
                        $date_range = ($start_date === $end_date) ? $start_date : "{$start_date} to {$end_date}";
                        $success_message = "Schedule created successfully for {$date_range} with {$total_students} students across " . count($batches) . " batches per day.";
                        
                        // Add info if actual students is less than requested capacity
                        $total_requested_capacity = array_sum(array_column($batches, 'capacity'));
                        if ($total_students < $total_requested_capacity) {
                            $success_message .= " Note: Only {$actualStudentCount} students are available in the database (requested capacity: {$total_requested_capacity}).";
                        }
                
                } catch (Exception $e) {
                    pg_query($connection, "ROLLBACK");
                    $error_message = "Failed to create schedule: " . $e->getMessage();
                }
            }
            }
        }
    }
    } // Close CSRF validation else block
}

// Handle publish schedule action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_schedule'])) {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('manage_schedules', $token)) {
        echo "<script>alert('Security validation failed. Please refresh and try again.'); window.location.href='manage_schedules.php';</script>";
        exit;
    }
    
    $settings['schedule_published'] = true;
    file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    
    // Add admin notification
    $notification_msg = "Distribution schedule published";
    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle unpublish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unpublish_schedule'])) {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('manage_schedules', $token)) {
        echo "<script>alert('Security validation failed. Please refresh and try again.'); window.location.href='manage_schedules.php';</script>";
        exit;
    }
    
    $settings['schedule_published'] = false;
    file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    
    // NOTE: We no longer delete schedules - just toggle visibility
    // This preserves all schedule data while hiding it from students
    
    // Add admin notification
    $notification_msg = "Distribution schedule unpublished (schedules preserved)";
    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle clear schedule data (permanent deletion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_schedule_data'])) {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('manage_schedules', $token)) {
        echo "<script>alert('Security validation failed. Please refresh and try again.'); window.location.href='manage_schedules.php';</script>";
        exit;
    }
    
    // Delete all schedules from database
    pg_query($connection, "DELETE FROM schedules");
    
    // Reset published state and clear schedule metadata
    $settings['schedule_published'] = false;
    if (isset($settings['schedule_meta'])) {
        unset($settings['schedule_meta']);
    }
    file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    
    // Add admin notification
    $notification_msg = "All schedule data permanently deleted";
    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$schedulePublished = !empty($settings['schedule_published']);

// Generate CSRF token for all forms on this page
$csrfToken = CSRFProtection::generateToken('manage_schedules');

// Get payroll info
$result = pg_query($connection, "SELECT MAX(payroll_no) AS max_no FROM students");
$row = pg_fetch_assoc($result);
$maxPayroll = isset($row['max_no']) ? intval($row['max_no']) : 0;

$countRes = pg_query($connection, "SELECT COUNT(payroll_no) AS count_no FROM students WHERE payroll_no IS NOT NULL");
$rowCount = pg_fetch_assoc($countRes);
$countStudents = isset($rowCount['count_no']) ? intval($rowCount['count_no']) : 0;

// Check if schedule exists
$scheduleExists = false;
$currentSchedule = [];
$result = pg_query($connection, "SELECT COUNT(*) as count FROM schedules");
$row = pg_fetch_assoc($result);
if ($row && $row['count'] > 0) {
    $scheduleExists = true;
    // Get current schedule details
    $scheduleResult = pg_query($connection, 
        "SELECT distribution_date, location, batch_no, time_slot, COUNT(*) as student_count 
         FROM schedules 
         GROUP BY distribution_date, location, batch_no, time_slot 
         ORDER BY batch_no"
    );
    
    if ($scheduleResult) {
        while ($scheduleRow = pg_fetch_assoc($scheduleResult)) {
            $currentSchedule[] = $scheduleRow;
        }
    }
}

// Get used dates to prevent duplicates
$usedDatesResult = pg_query($connection, "SELECT DISTINCT distribution_date FROM schedules ORDER BY distribution_date");
$usedDates = [];
if ($usedDatesResult) {
    while ($dateRow = pg_fetch_assoc($usedDatesResult)) {
        $usedDates[] = $dateRow['distribution_date'];
    }
}
?>
<?php $page_title='Manage Schedules'; include '../../includes/admin/admin_head.php'; ?>
    <style>
        .batch-card {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .batch-card:hover {
            border-color: #007bff;
            box-shadow: 0 4px 8px rgba(0,123,255,0.1);
        }
        .time-input {
            border-radius: 6px;
        }
        .batch-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border-radius: 6px 6px 0 0;
        }
        .used-date {
            background-color: #f8d7da;
            color: #721c24;
            pointer-events: none;
        }
        .validation-message {
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        .floating-total {
            position: sticky;
            top: 20px;
            z-index: 1000;
        }
    </style>
    </head>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>
    <section class="home-section" id="mainContent">
        <div class="container-fluid py-4 px-4">
            <div class="section-header mb-4">
                <h2 class="fw-bold text-primary">
                    <i class="bi bi-calendar2-range"></i>
                    Manage Student Schedules
                </h2>
                <p class="text-muted">Create flexible scheduling with custom batches and time slots</p>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Info about publish/unpublish functionality -->
            <?php if ($scheduleExists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle-fill"></i> 
                    <strong>Schedule Control:</strong> 
                    Use "Hide from Students" to make schedules invisible without deleting data. 
                    Use "Clear All Schedule Data" only if you want to permanently delete everything.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Card -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card border-primary">
                        <div class="card-body text-center">
                            <h5 class="card-title text-primary">Total Students</h5>
                            <h3 class="text-primary"><?= number_format($countStudents) ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h5 class="card-title text-success">Max Payroll Number</h5>
                            <h3 class="text-success"><?= number_format($maxPayroll) ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <h5 class="card-title text-info">Used Dates</h5>
                            <h3 class="text-info"><?= count($usedDates) ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($scheduleExists && !empty($currentSchedule)): ?>
                <!-- Current Schedule Display -->
                <div class="card border-<?= $schedulePublished ? 'success' : 'warning' ?> mb-4">
                    <div class="card-header bg-<?= $schedulePublished ? 'success' : 'warning' ?> text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-calendar-check"></i> Current Schedule
                                <?php if ($schedulePublished): ?>
                                    <span class="badge bg-light text-success ms-2">
                                        <i class="bi bi-eye"></i> Visible to Students
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-light text-warning ms-2">
                                        <i class="bi bi-eye-slash"></i> Hidden from Students
                                    </span>
                                <?php endif; ?>
                            </h5>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Date:</strong> <?= htmlspecialchars($currentSchedule[0]['distribution_date'] ?? 'N/A') ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Location:</strong> <?= htmlspecialchars($currentSchedule[0]['location'] ?? 'N/A') ?>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Batch #</th>
                                        <th>Time Slot</th>
                                        <th>Students</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($currentSchedule as $batch): ?>
                                        <tr>
                                            <td><span class="badge bg-primary">Batch <?= htmlspecialchars($batch['batch_no'] ?? '') ?></span></td>
                                            <td><?= htmlspecialchars($batch['time_slot'] ?? 'N/A') ?></td>
                                            <td><?= number_format($batch['student_count'] ?? 0) ?> students</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-3">
                            <?php if (!$schedulePublished): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <button type="submit" name="publish_schedule" class="btn btn-success">
                                        <i class="bi bi-send"></i> Publish Schedule to Students
                                    </button>
                                </form>
                                <small class="text-muted ms-2">Schedule will be visible to students</small>
                            <?php else: ?>
                                <span class="badge bg-success fs-6 me-2">
                                    <i class="bi bi-check-circle"></i> Schedule Published & Visible to Students
                                </span>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <button type="submit" name="unpublish_schedule" class="btn btn-outline-warning" 
                                            onclick="return confirm('This will hide the schedule from students but preserve all data. Continue?')">
                                        <i class="bi bi-eye-slash"></i> Hide from Students
                                    </button>
                                </form>
                                <small class="text-muted ms-2">Data will be preserved</small>
                            <?php endif; ?>
                            
                            <div class="mt-2">
                                <button type="button" class="btn btn-danger" onclick="clearSchedule()">
                                    <i class="bi bi-trash"></i> Clear All Schedule Data
                                </button>
                                <small class="text-muted ms-2">This will permanently delete all schedules</small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Create New Schedule -->
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Create New Schedule</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($documents_deadline)): ?>
                        <div class="alert alert-info d-flex align-items-center" role="alert">
                            <i class="bi bi-info-circle-fill me-2"></i>
                            <div>
                                Documents submission deadline is <strong><?= htmlspecialchars($documents_deadline) ?></strong>. Schedules must start on or after this date.
                            </div>
                        </div>
                        <?php endif; ?>
                        <form id="scheduleForm">
                            <!-- Basic Info -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <label for="schedule_date" class="form-label">
                                        <i class="bi bi-calendar3"></i> Start Date
                                    </label>
                                    <input type="date" class="form-control" id="schedule_date" name="schedule_date" required>
                                    <div class="validation-message" id="date-validation"></div>
                                </div>
                                <div class="col-md-4">
                                    <label for="end_date" class="form-label">
                                        <i class="bi bi-calendar-check"></i> End Date
                                    </label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" required>
                                    <div class="validation-message" id="end-date-validation"></div>
                                </div>
                                <div class="col-md-4">
                                    <label for="location" class="form-label">
                                        <i class="bi bi-geo-alt"></i> Location
                                    </label>
                                    <input type="text" class="form-control" id="location" name="location" required placeholder="Enter distribution location">
                                </div>
                            </div>

                            <!-- Number of Batches -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <label for="num_batches" class="form-label">
                                        <i class="bi bi-layers"></i> Number of Batches
                                    </label>
                                    <select class="form-control" id="num_batches" name="num_batches" required>
                                        <option value="">Select number of batches</option>
                                        <option value="1">1 Batch</option>
                                        <option value="2">2 Batches</option>
                                        <option value="3">3 Batches</option>
                                        <option value="4">4 Batches</option>
                                        <option value="5">5 Batches</option>
                                        <option value="6">6 Batches</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <div class="floating-total">
                                        <div class="card bg-light">
                                            <div class="card-body py-2">
                                                <strong>Total Students: <span id="total-students">0</span> / <?= $countStudents ?></strong>
                                                <div class="progress mt-1" style="height: 6px;">
                                                    <div class="progress-bar" id="student-progress" style="width: 0%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Batches Container -->
                            <div id="batches-container">
                                <!-- Batches will be dynamically generated here -->
                            </div>

                            <!-- Submit Button -->
                            <div class="text-center mt-4" id="submit-container" style="display: none;">
                                <button type="button" class="btn btn-success btn-lg me-2" onclick="createSchedule()">
                                    <i class="bi bi-calendar-plus"></i> Create Schedule
                                </button>
                                <button type="button" class="btn btn-info btn-sm" onclick="debugBatches()">
                                    <i class="bi bi-bug"></i> Debug Info
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Used Dates Display -->
            <?php if (!empty($usedDates)): ?>
                <div class="card border-warning mt-4">
                    <div class="card-header bg-warning text-dark">
                        <h6 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Previously Used Dates</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($usedDates as $date): ?>
                                <div class="col-md-2 mb-2">
                                    <span class="badge bg-secondary"><?= htmlspecialchars($date) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>

<script>
const maxStudents = <?= $countStudents ?>;
const usedDates = <?= json_encode($usedDates) ?>;
const documentsDeadline = <?= !empty($documents_deadline) ? ('\'' . htmlspecialchars($documents_deadline, ENT_QUOTES) . '\'') : 'null' ?>;
let batches = [];

// Date validation for start date
document.getElementById('schedule_date')?.addEventListener('change', function() {
    const selectedDate = this.value;
    const endDateInput = document.getElementById('end_date');
    const validation = document.getElementById('date-validation');
    
    // Set end date to same as start date if not set
    if (!endDateInput.value) {
        endDateInput.value = selectedDate;
    }
    
    // Set minimum end date
    endDateInput.min = selectedDate;
    
    if (documentsDeadline && selectedDate < documentsDeadline) {
        validation.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Start date cannot be before documents deadline (' + documentsDeadline + ')</span>';
        this.classList.add('is-invalid');
    } else if (usedDates.includes(selectedDate)) {
        validation.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> This date has already been used for scheduling</span>';
        this.classList.add('is-invalid');
    } else {
        validation.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Date is available</span>';
        this.classList.remove('is-invalid');
        this.classList.add('is-valid');
    }
});

// Date validation for end date
document.getElementById('end_date')?.addEventListener('change', function() {
    const selectedDate = this.value;
    const startDate = document.getElementById('schedule_date').value;
    const validation = document.getElementById('end-date-validation');
    
    if (selectedDate < startDate) {
        validation.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> End date cannot be before start date</span>';
        this.classList.add('is-invalid');
    } else if (usedDates.includes(selectedDate)) {
        validation.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> This date has already been used</span>';
        this.classList.add('is-invalid');
    } else {
        validation.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Date is available</span>';
        this.classList.remove('is-invalid');
        this.classList.add('is-valid');
    }
});

// Generate batches when number is selected
document.getElementById('num_batches')?.addEventListener('change', function() {
    const numBatches = parseInt(this.value);
    generateBatches(numBatches);
});

function generateBatches(numBatches) {
    const container = document.getElementById('batches-container');
    const submitContainer = document.getElementById('submit-container');
    
    container.innerHTML = '';
    batches = [];
    
    for (let i = 0; i < numBatches; i++) {
        batches.push({
            start_time: '',
            end_time: '',
            capacity: 0
        });
        
        const batchCard = createBatchCard(i, numBatches);
        container.appendChild(batchCard);
    }
    
    submitContainer.style.display = 'block';
    updateTotalStudents();
}

function createBatchCard(batchIndex, totalBatches) {
    const card = document.createElement('div');
    card.className = 'batch-card mb-3 p-3';
    
    // Suggest default times
    const suggestedStartTime = getSuggestedTime(batchIndex, totalBatches, 'start');
    const suggestedEndTime = getSuggestedTime(batchIndex, totalBatches, 'end');
    const suggestedCapacity = Math.ceil(maxStudents / totalBatches);
    
    card.innerHTML = `
        <div class="batch-header p-2 mb-3">
            <h6 class="mb-0"><i class="bi bi-clock"></i> Batch ${batchIndex + 1}</h6>
        </div>
        <div class="row">
            <div class="col-md-3">
                <label class="form-label">Start Time</label>
                <input type="time" class="form-control time-input" 
                       id="start_time_${batchIndex}" 
                       value="${suggestedStartTime}"
                       min="06:00" max="17:00" step="900"
                       onchange="updateBatch(${batchIndex}, 'start_time', this.value)">
            </div>
            <div class="col-md-3">
                <label class="form-label">End Time</label>
                <input type="time" class="form-control time-input" 
                       id="end_time_${batchIndex}" 
                       value="${suggestedEndTime}"
                       min="06:00" max="17:00" step="900"
                       onchange="updateBatch(${batchIndex}, 'end_time', this.value)">
            </div>
            <div class="col-md-3">
                <label class="form-label">Students in Batch</label>
                <input type="number" class="form-control" 
                       id="capacity_${batchIndex}" 
                       value="${suggestedCapacity}"
                       min="1" max="${maxStudents}"
                       onchange="updateBatch(${batchIndex}, 'capacity', parseInt(this.value))">
            </div>
            <div class="col-md-3">
                <label class="form-label">Duration</label>
                <div class="form-control-static">
                    <span id="duration_${batchIndex}" class="badge bg-info">--</span>
                </div>
            </div>
        </div>
        <div class="mt-2">
            <small class="text-muted" id="time_validation_${batchIndex}"></small>
        </div>
    `;
    
    // Initialize batch data
    batches[batchIndex] = {
        start_time: suggestedStartTime,
        end_time: suggestedEndTime,
        capacity: suggestedCapacity
    };
    
    return card;
}

function getSuggestedTime(batchIndex, totalBatches, type) {
    const startHour = 8; // 8 AM start
    const endHour = 17; // 5 PM end
    const totalHours = endHour - startHour;
    const hoursPerBatch = totalHours / totalBatches;
    
    if (type === 'start') {
        const hour = Math.floor(startHour + (batchIndex * hoursPerBatch));
        return `${hour.toString().padStart(2, '0')}:00`;
    } else {
        const hour = Math.floor(startHour + ((batchIndex + 1) * hoursPerBatch));
        return `${hour.toString().padStart(2, '0')}:00`;
    }
}

function updateBatch(batchIndex, field, value) {
    // Ensure batches array exists and has the right length
    if (!batches[batchIndex]) {
        batches[batchIndex] = {};
    }
    
    batches[batchIndex][field] = value;
    
    console.log(`Updated batch ${batchIndex}, field ${field} to:`, value);
    console.log('Current batches:', batches);
    
    if (field === 'start_time' || field === 'end_time') {
        updateDuration(batchIndex);
        validateTimeSlot(batchIndex);
    }
    
    if (field === 'capacity') {
        updateTotalStudents();
    }
}

function updateDuration(batchIndex) {
    const batch = batches[batchIndex];
    if (batch.start_time && batch.end_time) {
        const start = new Date(`2000-01-01 ${batch.start_time}`);
        const end = new Date(`2000-01-01 ${batch.end_time}`);
        const diffMs = end - start;
        const diffHours = diffMs / (1000 * 60 * 60);
        
        const durationElement = document.getElementById(`duration_${batchIndex}`);
        if (diffHours > 0) {
            durationElement.textContent = `${diffHours.toFixed(1)}h`;
            durationElement.className = 'badge bg-info';
        } else {
            durationElement.textContent = 'Invalid';
            durationElement.className = 'badge bg-danger';
        }
    }
}

function validateTimeSlot(batchIndex) {
    const batch = batches[batchIndex];
    const validationElement = document.getElementById(`time_validation_${batchIndex}`);
    
    if (!batch.start_time || !batch.end_time) {
        validationElement.innerHTML = '';
        return;
    }
    
    const start = new Date(`2000-01-01 ${batch.start_time}`);
    const end = new Date(`2000-01-01 ${batch.end_time}`);
    
    if (end <= start) {
        validationElement.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> End time must be after start time</span>';
        return;
    }
    
    // Check for overlaps with other batches
    for (let i = 0; i < batches.length; i++) {
        if (i === batchIndex) continue;
        
        const otherBatch = batches[i];
        if (otherBatch.start_time && otherBatch.end_time) {
            const otherStart = new Date(`2000-01-01 ${otherBatch.start_time}`);
            const otherEnd = new Date(`2000-01-01 ${otherBatch.end_time}`);
            
            if ((start < otherEnd && end > otherStart)) {
                validationElement.innerHTML = `<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> Time overlaps with Batch ${i + 1}</span>`;
                return;
            }
        }
    }
    
    validationElement.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Time slot is valid</span>';
}

function updateTotalStudents() {
    const total = batches.reduce((sum, batch) => sum + (batch.capacity || 0), 0);
    const totalElement = document.getElementById('total-students');
    const progressElement = document.getElementById('student-progress');
    
    if (totalElement) {
        totalElement.textContent = total.toLocaleString();
        
        const percentage = (total / maxStudents) * 100;
        progressElement.style.width = `${Math.min(percentage, 100)}%`;
        
        if (total > maxStudents) {
            progressElement.className = 'progress-bar bg-danger';
            totalElement.classList.add('text-danger');
        } else if (total === maxStudents) {
            progressElement.className = 'progress-bar bg-success';
            totalElement.classList.remove('text-danger');
            totalElement.classList.add('text-success');
        } else {
            progressElement.className = 'progress-bar bg-primary';
            totalElement.classList.remove('text-danger', 'text-success');
        }
    }
}

function createSchedule() {
    const startDateInput = document.getElementById('schedule_date');
    const endDateInput = document.getElementById('end_date');
    const locationInput = document.getElementById('location');
    const numBatchesSelect = document.getElementById('num_batches');
    
    // Create error display area if it doesn't exist
    let errorDisplay = document.getElementById('error-display');
    if (!errorDisplay) {
        errorDisplay = document.createElement('div');
        errorDisplay.id = 'error-display';
        errorDisplay.className = 'alert alert-danger mt-3';
        errorDisplay.style.display = 'none';
        document.getElementById('scheduleForm').appendChild(errorDisplay);
    }
    
    function showError(message) {
        errorDisplay.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${message}`;
        errorDisplay.style.display = 'block';
        errorDisplay.scrollIntoView({ behavior: 'smooth' });
        return false;
    }
    
    console.log('Creating schedule...');
    console.log('Start Date:', startDateInput.value);
    console.log('End Date:', endDateInput.value);
    console.log('Location:', locationInput.value);
    console.log('Number of batches:', numBatchesSelect.value);
    console.log('Batches array:', batches);
    
    // Hide previous errors
    errorDisplay.style.display = 'none';
    
    if (!startDateInput.value || !endDateInput.value || !locationInput.value) {
        return showError('Please fill in start date, end date, and location');
    }
    
    if (!numBatchesSelect.value) {
        return showError('Please select the number of batches');
    }
    
    if (documentsDeadline && startDateInput.value < documentsDeadline) {
        return showError('Start date cannot be before the documents submission deadline (' + documentsDeadline + ').');
    }

    if (endDateInput.value < startDateInput.value) {
        return showError('End date cannot be before start date');
    }
    
    // Check if any date in range is already used
    const startDate = new Date(startDateInput.value);
    const endDate = new Date(endDateInput.value);
    const conflictingDates = [];
    
    for (let d = new Date(startDate); d <= endDate; d.setDate(d.getDate() + 1)) {
        const dateStr = d.toISOString().split('T')[0];
        if (usedDates.includes(dateStr)) {
            conflictingDates.push(dateStr);
        }
    }
    
    if (conflictingDates.length > 0) {
        return showError(`The following dates are already used: ${conflictingDates.join(', ')}. Please choose different dates.`);
    }
    
    // Ensure batches array is populated
    if (!batches || batches.length === 0) {
        return showError('Please configure the batches first by selecting the number of batches');
    }
    
    const totalStudents = batches.reduce((sum, batch) => sum + (batch.capacity || 0), 0);
    if (totalStudents > maxStudents) {
        return showError(`Total students (${totalStudents}) exceeds available students (${maxStudents})`);
    }
    
    if (totalStudents === 0) {
        return showError('Please set the number of students for each batch');
    }
    
    // Validate all batches
    for (let i = 0; i < batches.length; i++) {
        const batch = batches[i];
        if (!batch.start_time || !batch.end_time || !batch.capacity || batch.capacity <= 0) {
            return showError(`Please complete all fields for Batch ${i + 1}`);
        }
        
        // Validate time order
        if (batch.start_time >= batch.end_time) {
            return showError(`Batch ${i + 1}: End time must be after start time`);
        }
    }
    
    const scheduleData = {
        start_date: startDateInput.value,
        end_date: endDateInput.value,
        location: locationInput.value,
        batches: batches
    };
    
    console.log('Final schedule data:', scheduleData);
    console.log('JSON data being sent:', JSON.stringify(scheduleData));
    
    // Show confirmation before submitting
    const dateRange = (scheduleData.start_date === scheduleData.end_date) 
        ? scheduleData.start_date 
        : `${scheduleData.start_date} to ${scheduleData.end_date}`;
    
    if (!confirm(`Create schedule for ${dateRange} at ${scheduleData.location} with ${batches.length} batches and ${totalStudents} total students?`)) {
        return false;
    }
    
    // Submit form
    const form = document.createElement('form');
    form.method = 'POST';
    
    // Create hidden input for create_schedule
    const createInput = document.createElement('input');
    createInput.type = 'hidden';
    createInput.name = 'create_schedule';
    createInput.value = '1';
    form.appendChild(createInput);
    
    // Create hidden input for schedule_data
    const dataInput = document.createElement('input');
    dataInput.type = 'hidden';
    dataInput.name = 'schedule_data';
    dataInput.value = JSON.stringify(scheduleData);
    form.appendChild(dataInput);
    
    // Add CSRF token
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= htmlspecialchars($csrfToken) ?>';
    form.appendChild(csrfInput);
    
    document.body.appendChild(form);
    console.log('Submitting form with data:', dataInput.value);
    form.submit();
}

function clearSchedule() {
    if (confirm('⚠️ DANGER: This will permanently delete ALL schedule data from the database.\n\nThis action cannot be undone. Are you absolutely sure?')) {
        if (confirm('Last confirmation: This will DELETE all schedules permanently. Continue?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="clear_schedule_data" value="1"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">';
            document.body.appendChild(form);
            form.submit();
        }
    }
}

function debugBatches() {
    const startDateInput = document.getElementById('schedule_date');
    const endDateInput = document.getElementById('end_date');
    const locationInput = document.getElementById('location');
    const numBatchesSelect = document.getElementById('num_batches');
    
    console.log('=== DEBUG INFO ===');
    console.log('Start Date:', startDateInput ? startDateInput.value : 'NOT FOUND');
    console.log('End Date:', endDateInput ? endDateInput.value : 'NOT FOUND');
    console.log('Location:', locationInput ? locationInput.value : 'NOT FOUND');
    console.log('Number of batches selected:', numBatchesSelect ? numBatchesSelect.value : 'NOT FOUND');
    console.log('Batches array:', batches);
    console.log('Batches length:', batches ? batches.length : 'UNDEFINED');
    console.log('Max students:', maxStudents);
    console.log('Used dates:', usedDates);
    
    // Test JSON serialization
    if (batches && batches.length > 0) {
        const testData = {
            start_date: startDateInput ? startDateInput.value : '',
            end_date: endDateInput ? endDateInput.value : '',
            location: locationInput ? locationInput.value : '',
            batches: batches
        };
        console.log('Test JSON serialization:', JSON.stringify(testData));
    }
    
    alert(`Debug Info:\nBatches: ${batches ? batches.length : 'UNDEFINED'}\nStart Date: ${startDateInput ? startDateInput.value : 'NOT FOUND'}\nEnd Date: ${endDateInput ? endDateInput.value : 'NOT FOUND'}\nLocation: ${locationInput ? locationInput.value : 'NOT FOUND'}\n\nCheck console for detailed info.`);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set minimum date to today
    const today = new Date().toISOString().split('T')[0];
    const startDateInput = document.getElementById('schedule_date');
    const endDateInput = document.getElementById('end_date');
    
    if (startDateInput) {
        startDateInput.min = today;
    }
    
    if (endDateInput) {
        endDateInput.min = today;
    }
});
</script>

</body>
</html>
<?php
if (isset($connection) && $connection instanceof \PgSql\Connection) {
    pg_close($connection);
}
?>