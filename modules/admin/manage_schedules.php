<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
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
</head>
<body>
<div class="container-fluid">
  <div class="row">
    <!-- Sidebar -->
    <?php include '../../includes/admin/admin_sidebar.php'; ?>

    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

    <!-- Main -->
    <main class="col-md-10 ms-sm-auto px-4 py-4">
    <h2 class="mb-4">Manage Student Schedules</h2>

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
            <button type="submit" class="btn btn-success">Save Schedule</button>
        </form>
    </div>
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
            let counter = 1;
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
</body>
</html>
<?php
if (isset($connection) && $connection instanceof \PgSql\Connection) {
    pg_close($connection);
}
?>
