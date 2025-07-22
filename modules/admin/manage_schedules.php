<?php
include __DIR__ . '/../../config/database.php';
session_start();
// Load settings for publish state
$settingsPath = __DIR__ . '/../../data/municipal_settings.json';
$settings = file_exists($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : [];
// Load location from previous settings or default
$location = $settings['schedule_meta']['location'] ?? '';

// Handle publish schedule action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_schedule'])) {
    // If schedules table empty but we have saved parameters, recreate schedule
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
        // Load location for republish
        $location = $meta['location'];
        $locLit = pg_escape_literal($connection, $location);
        // Persist schedule records
        $counter = 1; // start assignment at first payroll number
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
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
// Handle unpublish schedule action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unpublish_schedule'])) {
    $settings['schedule_published'] = false;
    file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Determine if schedule is sent
$schedulePublished = !empty($settings['schedule_published']);

if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

// Fetch maximum payroll number if PostgreSQL functions are available
if (function_exists('pg_query')) {
    // Get max payroll number
    $result = pg_query($connection, "SELECT MAX(payroll_no) AS max_no FROM students");
    $row = pg_fetch_assoc($result);
    $maxPayroll = isset($row['max_no']) ? intval($row['max_no']) : 0;
    pg_free_result($result);
    // Get count of students with payroll numbers
    $countRes = pg_query($connection, "SELECT COUNT(payroll_no) AS count_no FROM students WHERE payroll_no IS NOT NULL");
    $rowCount = pg_fetch_assoc($countRes);
    $countStudents = isset($rowCount['count_no']) ? intval($rowCount['count_no']) : 0;
    pg_free_result($countRes);
} else {
    // Fallback if pgsql extension not available
    $maxPayroll = 0;
    $countStudents = 0;
}

// After fetching $maxPayroll and $countStudents
$showSaved = false;
// If editing schedule, delete previous entries for the date range
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_edit'])) {
    $delStart = $_POST['start_date'];
    $delEnd = $_POST['end_date'];
    if (function_exists('pg_query')) {
        pg_query($connection, "DELETE FROM schedules WHERE distribution_date BETWEEN '$delStart' AND '$delEnd'");
    }
}
// If saving schedule, persist and show saved view
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_save'])) {
    $showSaved = true;
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $startTimes = $_POST['start_time'];
    $endTimes = $_POST['end_time'];
    $batch1Cap = intval($_POST['batch1_capacity']);
    $batch2Cap = intval($_POST['batch2_capacity']);
    // Capture location
    $location = trim($_POST['location']);
    $locLit = pg_escape_literal($connection, $location);
    // Persist schedule records to DB
    if (function_exists('pg_query')) {
        $counter = 1; // start assignment at first payroll number
        $curDate = $startDate;
        while ($curDate <= $endDate) {
            // Batch 1
            for ($i = 0; $i < $batch1Cap; $i++) {
                $pno = $counter;
                // find student_id by payroll_no
                $sidRes = pg_query($connection, "SELECT student_id FROM students WHERE payroll_no = $pno");
                $sidRow = pg_fetch_assoc($sidRes);
                $studentId = $sidRow ? intval($sidRow['student_id']) : null;
                pg_free_result($sidRes);
                // insert schedule
                $timeSlot = pg_escape_literal($connection, "{$startTimes[0]} - {$endTimes[0]}");
                pg_query($connection, "INSERT INTO schedules (student_id, payroll_no, batch_no, distribution_date, time_slot, location) VALUES (".
                    ($studentId !== null ? $studentId : 'NULL').", $pno, 1, '$curDate', $timeSlot, $locLit)");
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
                pg_query($connection, "INSERT INTO schedules (student_id, payroll_no, batch_no, distribution_date, time_slot, location) VALUES (".
                    ($studentId !== null ? $studentId : 'NULL').", $pno, 2, '$curDate', $timeSlot, $locLit)");
                $counter++;
            }
            // next date
            $curDate = date('Y-m-d', strtotime($curDate . ' +1 day'));
        }
        // Save schedule parameters for reuse on publish
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
// Check for existing schedules on page load (GET)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Determine existing schedule date range
    $res = pg_query($connection, "SELECT MIN(distribution_date) AS start_date, MAX(distribution_date) AS end_date FROM schedules");
    $row = pg_fetch_assoc($res);
    if ($row && $row['start_date']) {
        $showSaved = true;
        $startDate = $row['start_date'];
        $endDate = $row['end_date'];
        // Fetch time intervals for batches
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
        // Fetch capacities for the start date
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
  <title>Set a Schedule</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <link rel="stylesheet" href="../../assets/css/admin_homepage.css">
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
</head>
<body>
<div class="container-fluid">
  <div class="row">
    <!-- Sidebar -->
    <?php include '../../includes/admin/admin_sidebar.php'; ?>

    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

    <!-- Main -->
    <main class="col-md-10 ms-sm-auto px-4 py-4">
    <?php if ($showSaved): ?>
        <h4>Current Schedule</h4>
        <p><strong>Location:</strong> <?php echo htmlspecialchars($location); ?></p>
        <!-- Hidden edit form -->
        <form id="edit-form" method="POST" class="d-none">
            <input type="hidden" name="confirm_edit" value="1">
            <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
            <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
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
              <th>Batch 1 (<?php echo htmlspecialchars($startTimes[0] . ' - ' . $endTimes[0]); ?>)</th>
              <th>Students</th>
              <th>Batch 2 (<?php echo htmlspecialchars($startTimes[1] . ' - ' . $endTimes[1]); ?>)</th>
              <th>Students</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // Display numbering starts from 1 for UI
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
    <h2 class="mb-4">Manage Student Schedules</h2>
    <!-- Display current max payroll number -->
    <p class="lead">Current maximum payroll number: <?php echo htmlspecialchars($maxPayroll); ?></p>

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

    <!-- Step 2: Input Time Intervals -->
    <div id="step-2" class="d-none">
        <h4>Step 2: Define Time Intervals</h4>
        <form id="time-intervals-form" method="POST" class="mb-4">
            <div id="time-intervals-container">
                <!-- Batch 1 -->
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
                <!-- Batch 2 -->
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

    <!-- Popup for Payroll Allocation -->
    <div id="payroll-popup" class="d-none">
        <h4>Allocate Payroll Numbers</h4>
        <form id="payroll-allocation-form" method="POST">
            <div id="payroll-allocation-container">
                <!-- Dynamic content will be added here via JavaScript -->
            </div>
            <button type="button" id="save-schedule-btn" class="btn btn-success d-none" data-bs-toggle="modal" data-bs-target="#confirm-save-modal">Save Schedule</button>
        </form>
    </div>
    <?php endif; ?>
</main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Define bounds for time intervals
        const minTime = '06:00', maxTime = '17:00';

        const startDateInput = document.querySelector('input[name="start_date"]');
        const endDateInput = document.querySelector('input[name="end_date"]');
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const minDate = tomorrow.toISOString().split('T')[0];

        // Set the minimum date for the start date input to tomorrow
        startDateInput.setAttribute('min', minDate);
        // Set the minimum date for the end date input to tomorrow
        endDateInput.setAttribute('min', minDate);

        // Update end date min when start date changes
        startDateInput.addEventListener('change', function() {
            endDateInput.setAttribute('min', this.value);
            if (endDateInput.value < this.value) {
                endDateInput.value = this.value;
            }
        });

        // Apply time bounds to any existing time inputs
        document.querySelectorAll('input[name="start_time[]"], input[name="end_time[]"]').forEach(input => {
            input.setAttribute('min', minTime);
            input.setAttribute('max', maxTime);
            input.setAttribute('step', '300');
            input.addEventListener('change', function() {
                if (!this.value) return;
                if (this.value < minTime) {
                    alert('Time cannot be earlier than ' + minTime + '.');
                    this.value = minTime;
                } else if (this.value > maxTime) {
                    alert('Time cannot be later than ' + maxTime + '.');
                    this.value = maxTime;
                }
                updateConfirmButtonState();
                updateAddIntervalState();
            });
        });
        // Initial state for add-interval button
        updateAddIntervalState();
    });

    // Function to enable/disable Add Interval based on max bound
    function updateAddIntervalState() {
        const addBtn = document.getElementById('add-interval');
        const lastEndInput = document.querySelector('.time-interval:last-child input[name="end_time[]"]');
        if (lastEndInput) {
            if (lastEndInput.value >= maxTime) addBtn.disabled = true;
            else addBtn.disabled = false;
        }
    }

    document.getElementById('next-to-step-2').addEventListener('click', function() {
        const startDate = document.querySelector('input[name="start_date"]').value;
        const endDate = document.querySelector('input[name="end_date"]').value;

        if (!startDate || !endDate) {
            alert('Please select both start and end dates.');
            return;
        }

        if (new Date(endDate) < new Date(startDate)) {
            alert('End date cannot be earlier than start date.');
            return;
        }

        document.getElementById('step-1').classList.add('d-none');
        document.getElementById('step-2').classList.remove('d-none');
    });

    // Add functionality to return to Step 1
    const returnToDatesButton = document.createElement('button');
    returnToDatesButton.textContent = 'Return to Dates';
    returnToDatesButton.classList.add('btn', 'btn-secondary', 'mb-3');
    returnToDatesButton.addEventListener('click', function() {
        document.getElementById('step-2').classList.add('d-none');
        document.getElementById('step-1').classList.remove('d-none');
    });

    document.getElementById('step-2').prepend(returnToDatesButton);

    // Remove dynamic interval manipulation—two batch inputs fixed

    // Function to enable/disable confirm based on filled batch intervals
    function updateConfirmButtonState() {
        const intervals = document.querySelectorAll('.time-interval');
        const confirmBtn = document.getElementById('confirm-intervals');
        for (const interval of intervals) {
            const start = interval.querySelector('input[name="start_time[]"]').value;
            const end = interval.querySelector('input[name="end_time[]"]').value;
            if (!start || !end) {
                confirmBtn.disabled = true;
                return;
            }
        }
        confirmBtn.disabled = false;
    }

    // Attach change listeners to existing inputs
    document.querySelectorAll('input[name="start_time[]"], input[name="end_time[]"]').forEach(input => {
        input.addEventListener('change', updateConfirmButtonState);
    });

    // Validate time intervals for no overlaps on confirm
    document.getElementById('confirm-intervals').addEventListener('click', function() {
        const intervals = document.querySelectorAll('.time-interval');
        let prevEnd = null;
        for (const interval of intervals) {
            const start = interval.querySelector('input[name="start_time[]"]').value;
            const end = interval.querySelector('input[name="end_time[]"]').value;

            if (!start || !end) {
                alert('Please complete all time intervals before confirming.');
                return;
            }

            const startTime = new Date(`1970-01-01T${start}`);
            const endTime = new Date(`1970-01-01T${end}`);

            if (endTime <= startTime) {
                alert('End time must be later than start time.');
                return;
            }

            if (prevEnd && startTime < prevEnd) {
                alert('Time intervals cannot overlap. Please adjust the intervals.');
                return;
            }

            prevEnd = endTime;
        }

        // Proceed to display the payroll allocation popup
        document.getElementById('step-2').classList.add('d-none');
        document.getElementById('payroll-popup').classList.remove('d-none');

        const payrollContainer = document.getElementById('payroll-allocation-container');
        payrollContainer.innerHTML = `
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Batch 1 Capacity</label>
                    <input type="number" id="batch1-capacity" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Batch 2 Capacity</label>
                    <input type="number" id="batch2-capacity" class="form-control" required>
                </div>
            </div>
            <button type="button" id="generate-schedule" class="btn btn-secondary mb-3">Generate Schedule</button>
            <div id="schedule-preview"></div>
        `;
        // Capture schedule parameters
        const dateStart = document.querySelector('input[name="start_date"]').value;
        const dateEnd = document.querySelector('input[name="end_date"]').value;
        const intervalsData = Array.from(document.querySelectorAll('.time-interval')).map(iv => ({
            start: iv.querySelector('input[name="start_time[]"]').value,
            end: iv.querySelector('input[name="end_time[]"]').value
        }));
        // Generate schedule on button click
        document.getElementById('generate-schedule').addEventListener('click', function() {
            const b1 = parseInt(document.getElementById('batch1-capacity').value, 10);
            const b2 = parseInt(document.getElementById('batch2-capacity').value, 10);
            const preview = document.getElementById('schedule-preview');
            // Build table header with batch times
            preview.innerHTML = `
                <table class="table table-bordered">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Batch 1 (${intervalsData[0].start} - ${intervalsData[0].end})</th>
                      <th>Students</th>
                      <th>Batch 2 (${intervalsData[1].start} - ${intervalsData[1].end})</th>
                      <th>Students</th>
                    </tr>
                  </thead>
                  <tbody></tbody>
                </table>
            `;
            const tbody = preview.querySelector('tbody');
            let counter = 1; // start preview numbering from 1 independent of existing payroll numbers
            let currentDate = new Date(dateStart);
            const endDateObj = new Date(dateEnd);
            while (currentDate <= endDateObj) {
                const dateLabel = currentDate.toISOString().split('T')[0];
                // Batch 1
                const startNum1 = counter;
                const endNum1 = counter + b1 - 1;
                counter += b1;
                // Batch 2
                const startNum2 = counter;
                const endNum2 = counter + b2 - 1;
                counter += b2;
                // Create row
                const tr = document.createElement('tr');
                tr.innerHTML = `
                  <td>${dateLabel}</td>
                  <td>${intervalsData[0].start} - ${intervalsData[0].end}</td>
                  <td>${startNum1} - ${endNum1}</td>
                  <td>${intervalsData[1].start} - ${intervalsData[1].end}</td>
                  <td>${startNum2} - ${endNum2}</td>
                `;
                tbody.appendChild(tr);
                currentDate.setDate(currentDate.getDate() + 1);
            }
            // Insert hidden inputs into form for final submission
            const form = document.getElementById('payroll-allocation-form');
            // Capture location from Step 1
            const locationVal = document.querySelector('input[name="location"]').value;
form.insertAdjacentHTML('beforeend', `
    <input type="hidden" name="start_date" value="${dateStart}">
    <input type="hidden" name="end_date" value="${dateEnd}">
    <input type="hidden" name="start_time[]" value="${intervalsData[0].start}">
    <input type="hidden" name="end_time[]" value="${intervalsData[0].end}">
    <input type="hidden" name="start_time[]" value="${intervalsData[1].start}">
    <input type="hidden" name="end_time[]" value="${intervalsData[1].end}">
    <input type="hidden" name="batch1_capacity" value="${b1}">
    <input type="hidden" name="batch2_capacity" value="${b2}">
    <input type="hidden" name="location" value="${locationVal}">
    <input type="hidden" name="confirm_save" value="1">
`);
            // Show Save Schedule button now that preview exists
            document.getElementById('save-schedule-btn').classList.remove('d-none');
        });
    });

    // Add functionality to return to Time Scheduling in Step 3
    const returnToSchedulingButton = document.createElement('button');
    returnToSchedulingButton.textContent = 'Return to Time Scheduling';
    returnToSchedulingButton.classList.add('btn', 'btn-secondary', 'mb-3');
    returnToSchedulingButton.addEventListener('click', function() {
        document.getElementById('payroll-popup').classList.add('d-none');
        document.getElementById('step-2').classList.remove('d-none');
    });

    document.getElementById('payroll-popup').prepend(returnToSchedulingButton);
</script>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirm-save-modal" tabindex="-1" aria-labelledby="confirmSaveModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmSaveModalLabel">Confirm Save Schedule</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to save this schedule? This action cannot be undone.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" id="confirm-save-btn" class="btn btn-primary">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- Edit Confirmation Modal -->
<div class="modal fade" id="confirm-edit-modal" tabindex="-1" aria-labelledby="confirmEditModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmEditModalLabel">Confirm Edit Schedule</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Editing the schedule will restart the process. Are you sure?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" id="confirm-edit-btn" class="btn btn-primary">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- Unpublish Confirmation Modal -->
<div class="modal fade" id="confirm-unpublish-modal" tabindex="-1" aria-labelledby="confirmUnpublishModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmUnpublishModalLabel">Confirm Undo Send</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to undo sending the schedule to students? This will hide it from their homepage.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" id="confirm-unpublish-btn" class="btn btn-warning">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('confirm-save-btn').addEventListener('click', function() {
            document.getElementById('payroll-allocation-form').submit();
        });
        document.getElementById('confirm-edit-btn').addEventListener('click', function() {
            // submit hidden edit form to clear schedules and restart
            document.getElementById('edit-form').submit();
        });
        var btn = document.getElementById('confirm-unpublish-btn');
        if (btn) {
          btn.addEventListener('click', function() {
            document.getElementById('unpublish-form').submit();
          });
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
