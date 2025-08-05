<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
include '../../config/database.php';

// Load settings for publish state
$settingsPath = __DIR__ . '/../../data/municipal_settings.json';
$settings = file_exists($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : [];
$location = $settings['schedule_meta']['location'] ?? '';

// Handle publish schedule action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_schedule'])) {
    $count = pg_query($connection, "SELECT COUNT(*) AS cnt FROM schedules");
    $cntRow = pg_fetch_assoc($count);
    if (isset($settings['schedule_meta']) && intval($cntRow['cnt']) === 0) {
        $meta = $settings['schedule_meta'];
        $startDate = $meta['start_date'];
        $endDate = $meta['end_date'];
        $startTimes = $meta['start_times'];
        $endTimes = $meta['end_times'];
        $batch1Cap = intval($meta['batch1_capacity']);
        $batch2Cap = intval($meta['batch2_capacity']);
        $location = $meta['location'];
        $locLit = pg_escape_literal($connection, $location);

        $counter = 1;
        $curDate = $startDate;
        while ($curDate <= $endDate) {
            // Batch 1
            for ($i = 0; $i < $batch1Cap; $i++) {
                $pno = $counter;
                $sidRes = pg_query($connection, "SELECT student_id FROM students WHERE payroll_no = $pno");
                $sid = pg_fetch_assoc($sidRes);
                pg_free_result($sidRes);
                $studentId = $sid ? intval($sid['student_id']) : null;
                $timeSlot = pg_escape_literal($connection, "{$startTimes[0]} - {$endTimes[0]}");
                pg_query($connection, "INSERT INTO schedules (student_id, payroll_no, batch_no, distribution_date, time_slot, location) VALUES (" .
                    ($studentId !== null ? $studentId : 'NULL') . ", $pno, 1, '$curDate', $timeSlot, $locLit)");
                $counter++;
            }
            // Batch 2
            for ($i = 0; $i < $batch2Cap; $i++) {
                $pno = $counter;
                $sidRes = pg_query($connection, "SELECT student_id FROM students WHERE payroll_no = $pno");
                $sid = pg_fetch_assoc($sidRes);
                pg_free_result($sidRes);
                $studentId = $sid ? intval($sid['student_id']) : null;
                $timeSlot = pg_escape_literal($connection, "{$startTimes[1]} - {$endTimes[1]}");
                pg_query($connection, "INSERT INTO schedules (student_id, payroll_no, batch_no, distribution_date, time_slot, location) VALUES (" .
                    ($studentId !== null ? $studentId : 'NULL') . ", $pno, 2, '$curDate', $timeSlot, $locLit)");
                $counter++;
            }
            $curDate = date('Y-m-d', strtotime("$curDate +1 day"));
        }
    }
    $settings['schedule_published'] = true;
    file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    
    // Add admin notification for schedule publishing
    $notification_msg = "Distribution schedule published from " . $startDate . " to " . $endDate . " at " . $location;
    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
// Handle unpublish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unpublish_schedule'])) {
    $settings['schedule_published'] = false;
    file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    
    // Add admin notification for schedule unpublishing
    $notification_msg = "Distribution schedule unpublished and reset";
    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
$schedulePublished = !empty($settings['schedule_published']);

// Get payroll info
if (function_exists('pg_query')) {
    $result = pg_query($connection, "SELECT MAX(payroll_no) AS max_no FROM students");
    $row = pg_fetch_assoc($result);
    $maxPayroll = isset($row['max_no']) ? intval($row['max_no']) : 0;
    pg_free_result($result);

    $countRes = pg_query($connection, "SELECT COUNT(payroll_no) AS count_no FROM students WHERE payroll_no IS NOT NULL");
    $rowCount = pg_fetch_assoc($countRes);
    $countStudents = isset($rowCount['count_no']) ? intval($rowCount['count_no']) : 0;
    pg_free_result($countRes);
} else {
    $maxPayroll = 0;
    $countStudents = 0;
}

// Step control
$showSaved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_edit'])) {
    $delStart = $_POST['start_date'];
    $delEnd = $_POST['end_date'];
    if (function_exists('pg_query')) {
        pg_query($connection, "DELETE FROM schedules WHERE distribution_date BETWEEN '$delStart' AND '$delEnd'");
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_save'])) {
    $showSaved = true;
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $startTimes = $_POST['start_time'];
    $endTimes = $_POST['end_time'];
    $batch1Cap = intval($_POST['batch1_capacity']);
    $batch2Cap = intval($_POST['batch2_capacity']);
    $location = trim($_POST['location']);
    $locLit = pg_escape_literal($connection, $location);

    if (function_exists('pg_query')) {
        $counter = 1;
        $curDate = $startDate;
        while ($curDate <= $endDate) {
            // Batch 1
            for ($i = 0; $i < $batch1Cap; $i++) {
                $pno = $counter;
                $sidRes = pg_query($connection, "SELECT student_id FROM students WHERE payroll_no = $pno");
                $sidRow = pg_fetch_assoc($sidRes);
                $studentId = $sidRow ? intval($sidRow['student_id']) : null;
                pg_free_result($sidRes);
                $timeSlot = pg_escape_literal($connection, "{$startTimes[0]} - {$endTimes[0]}");
                pg_query($connection, "INSERT INTO schedules (student_id, payroll_no, batch_no, distribution_date, time_slot, location) VALUES (" .
                    ($studentId !== null ? $studentId : 'NULL') . ", $pno, 1, '$curDate', $timeSlot, $locLit)");
                $counter++;
            }
            // Batch 2
            for ($i = 0; $i < $batch2Cap; $i++) {
                $pno = $counter;
                $sidRes = pg_query($connection, "SELECT student_id FROM students WHERE payroll_no = $pno");
                $sidRow = pg_fetch_assoc($sidRes);
                $studentId = $sidRow ? intval($sidRow['student_id']) : null;
                pg_free_result($sidRes);
                $timeSlot = pg_escape_literal($connection, "{$startTimes[1]} - {$endTimes[1]}");
                pg_query($connection, "INSERT INTO schedules (student_id, payroll_no, batch_no, distribution_date, time_slot, location) VALUES (" .
                    ($studentId !== null ? $studentId : 'NULL') . ", $pno, 2, '$curDate', $timeSlot, $locLit)");
                $counter++;
            }
            $curDate = date('Y-m-d', strtotime($curDate . ' +1 day'));
        }
        $settings['schedule_meta'] = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_times' => $startTimes,
            'end_times' => $endTimes,
            'batch1_capacity' => $batch1Cap,
            'batch2_capacity' => $batch2Cap,
            'location' => $location
        ];
        file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    }
}
// Load saved schedule on GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $res = pg_query($connection, "SELECT MIN(distribution_date) AS start_date, MAX(distribution_date) AS end_date FROM schedules");
    $row = pg_fetch_assoc($res);
    if ($row && $row['start_date']) {
        $showSaved = true;
        $startDate = $row['start_date'];
        $endDate = $row['end_date'];
        $intervalRes = pg_query($connection, "SELECT batch_no, time_slot FROM schedules WHERE distribution_date = '$startDate' GROUP BY batch_no, time_slot ORDER BY batch_no");
        $startTimes = [];
        $endTimes = [];
        while ($interval = pg_fetch_assoc($intervalRes)) {
            list($st, $et) = explode(' - ', $interval['time_slot']);
            if ($interval['batch_no'] == 1) {
                $startTimes[0] = $st;
                $endTimes[0] = $et;
            } else {
                $startTimes[1] = $st;
                $endTimes[1] = $et;
            }
        }
        pg_free_result($intervalRes);
        $capRes1 = pg_query($connection, "SELECT COUNT(*) AS cap FROM schedules WHERE distribution_date = '$startDate' AND batch_no = 1");
        $capRow1 = pg_fetch_assoc($capRes1);
        $batch1Cap = intval($capRow1['cap']);
        pg_free_result($capRes1);
        $capRes2 = pg_query($connection, "SELECT COUNT(*) AS cap FROM schedules WHERE distribution_date = '$startDate' AND batch_no = 2");
        $capRow2 = pg_fetch_assoc($capRes2);
        $batch2Cap = intval($capRow2['cap']);
        pg_free_result($capRes2);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Schedules</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>
    <link rel="stylesheet" href="../../assets/css/admin/homepage.css"/>
    <link rel="stylesheet" href="../../assets/css/admin/sidebar.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet"/>
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
            <div class="section-header mb-3">
                <h2 class="fw-bold text-primary">
                    <i class="bi bi-calendar2-range"></i>
                    Manage Student Schedules
                </h2>
            </div>
            <?php if ($showSaved): ?>
                <h4>Current Schedule</h4>
                <p><strong>Location:</strong> <?= htmlspecialchars($location) ?></p>
                <form id="edit-form" method="POST" class="d-none">
                    <input type="hidden" name="confirm_edit" value="1">
                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                </form>
                <button type="button" class="btn btn-secondary mb-3" data-bs-toggle="modal" data-bs-target="#confirm-edit-modal">Edit Schedule</button>
                <?php if (!$schedulePublished): ?>
                    <form method="POST" class="d-inline mb-3">
                        <button type="submit" name="publish_schedule" class="btn btn-success">Send Schedule to Students</button>
                    </form>
                <?php else: ?>
                    <span class="badge bg-success mb-3">Schedule Sent</span>
                    <form id="unpublish-form" method="POST" class="d-inline mb-3">
                        <input type="hidden" name="unpublish_schedule" value="1">
                        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#confirm-unpublish-modal">Undo Send</button>
                    </form>
                <?php endif; ?>
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Batch 1 (<?= htmlspecialchars($startTimes[0] . ' - ' . $endTimes[0]) ?>)</th>
                        <th>Students</th>
                        <th>Batch 2 (<?= htmlspecialchars($startTimes[1] . ' - ' . $endTimes[1]) ?>)</th>
                        <th>Students</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $cnt = 1;
                    $current = $startDate;
                    while ($current <= $endDate) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($current) . '</td>';
                        echo '<td>' . htmlspecialchars($startTimes[0] . ' - ' . $endTimes[0]) . '</td>';
                        echo '<td>' . $cnt . ' - ' . ($cnt + $batch1Cap - 1) . '</td>';
                        $cnt += $batch1Cap;
                        echo '<td>' . htmlspecialchars($startTimes[1] . ' - ' . $endTimes[1]) . '</td>';
                        echo '<td>' . $cnt . ' - ' . ($cnt + $batch2Cap - 1) . '</td>';
                        $cnt += $batch2Cap;
                        echo '</tr>';
                        $current = date('Y-m-d', strtotime($current . ' +1 day'));
                    }
                    ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="lead">Current maximum payroll number: <?= htmlspecialchars($maxPayroll) ?></p>
                <!-- Step 1: Input Dates -->
                <div id="step-1">
                    <h4>Step 1: Select Dates</h4>
                    <form id="dates-form" method="POST" class="mb-4">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="start_date" required>
                            </div>
                            <div class="col-md-6">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="location" class="form-label">Location</label>
                                <input type="text" class="form-control" name="location" required placeholder="Enter location">
                            </div>
                        </div>
                        <button type="button" id="next-to-step-2" class="btn btn-primary">Next</button>
                    </form>
                </div>
                <div id="step-2" class="d-none">
                    <h4>Step 2: Define Time Intervals</h4>
                    <form id="time-intervals-form" method="POST" class="mb-4">
                        <div id="time-intervals-container">
                            <div class="row mb-3 time-interval">
                                <div class="col-md-5">
                                    <label class="form-label">Batch 1 Start Time</label>
                                    <input type="time" class="form-control" name="start_time[]" min="06:00" max="17:00" step="300" required>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Batch 1 End Time</label>
                                    <input type="time" class="form-control" name="end_time[]" min="06:00" max="17:00" step="300" required>
                                </div>
                            </div>
                            <div class="row mb-3 time-interval">
                                <div class="col-md-5">
                                    <label class="form-label">Batch 2 Start Time</label>
                                    <input type="time" class="form-control" name="start_time[]" min="06:00" max="17:00" step="300" required>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Batch 2 End Time</label>
                                    <input type="time" class="form-control" name="end_time[]" min="06:00" max="17:00" step="300" required>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="confirm-intervals" class="btn btn-primary">Confirm</button>
                    </form>
                </div>
                <div id="payroll-popup" class="d-none">
                    <h4>Allocate Payroll Numbers</h4>
                    <form id="payroll-allocation-form" method="POST">
                        <div id="payroll-allocation-container"></div>
                        <button type="button" id="save-schedule-btn" class="btn btn-success d-none" data-bs-toggle="modal" data-bs-target="#confirm-save-modal">Save Schedule</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Modals -->
<div class="modal fade" id="confirm-save-modal" tabindex="-1" aria-labelledby="confirmSaveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmSaveModalLabel">Confirm Save Schedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">Are you sure you want to save this schedule? This action cannot be undone.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirm-save-btn" class="btn btn-primary">Confirm</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="confirm-edit-modal" tabindex="-1" aria-labelledby="confirmEditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmEditModalLabel">Confirm Edit Schedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">Editing the schedule will restart the process. Are you sure?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirm-edit-btn" class="btn btn-primary">Confirm</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="confirm-unpublish-modal" tabindex="-1" aria-labelledby="confirmUnpublishModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmUnpublishModalLabel">Confirm Undo Send</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">Are you sure you want to undo sending the schedule to students? This will hide it from their homepage.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirm-unpublish-btn" class="btn btn-warning">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script src="../../assets/js/admin/manage_schedules.js"></script>
</body>
</html>
<?php
if (isset($connection) && $connection instanceof \PgSql\Connection) {
    pg_close($connection);
}
?>
