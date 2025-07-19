<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

// Fetch active students sorted by payroll number
$query = "SELECT student_id, first_name, last_name, payroll_no FROM students WHERE status = 'active' ORDER BY payroll_no ASC";
$students = pg_query($connection, $query);

// Handle form submissions for scheduling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['schedule'])) {
        $date = $_POST['date'];
        $time_slot = $_POST['time_slot'];
        $payroll_range_start = $_POST['payroll_range_start'];
        $payroll_range_end = $_POST['payroll_range_end'];

        // Insert schedule into the schedule table
        $query = "INSERT INTO schedules (date, time_slot, payroll_range_start, payroll_range_end) 
                  VALUES ($1, $2, $3, $4)";
        pg_query_params($connection, $query, [$date, $time_slot, $payroll_range_start, $payroll_range_end]);

        echo "<script>alert('Schedule created successfully!'); window.location.href = 'manage_schedules.php';</script>";
    }
}

// Fetch total number of students with payroll number
$total_students_query = "SELECT COUNT(*) FROM students WHERE status = 'given'";
$total_students_result = pg_query($connection, $total_students_query);
$total_students_row = pg_fetch_assoc($total_students_result);
$total_students = $total_students_row['count'];

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

    <!-- Display total students -->
    <div class="alert alert-info">Total Students: <?= $total_students ?></div>

    <!-- Schedule Form -->
    <form method="POST" class="mb-4">
        <div class="row mb-3">
        <div class="col-md-4">
            <label for="date" class="form-label">Date</label>
            <input type="date" class="form-control" name="date" required>
        </div>
        <div class="col-md-4">
            <label for="time_slot" class="form-label">Time Slot</label>
            <select name="time_slot" class="form-select" required>
            <option value="7-9am">7:00 AM - 9:00 AM</option>
            <option value="9:01-10am">9:01 AM - 10:00 AM</option>
            <option value="10:01-11am">10:01 AM - 11:00 AM</option>
            <!-- Add more time slots as needed -->
            </select>
        </div>
        <div class="col-md-4">
            <label for="payroll_range_start" class="form-label">Payroll Range Start</label>
            <input type="number" class="form-control" name="payroll_range_start" required>
        </div>
        </div>

        <div class="row mb-3">
        <div class="col-md-4">
            <label for="payroll_range_end" class="form-label">Payroll Range End</label>
            <input type="number" class="form-control" name="payroll_range_end" required>
        </div>
        <div class="col-md-8 d-flex align-items-end">
            <button type="submit" name="schedule" class="btn btn-success w-100">Create Schedule</button>
        </div>
        </div>
    </form>

    <!-- Students List -->
    <div class="card">
        <div class="card-header bg-primary text-white">Assigned Students</div>
        <div class="card-body">
        <table class="table table-bordered">
            <thead>
            <tr>
                <th>Full Name</th>
                <th>Payroll Number</th>
            </tr>
            </thead>
            <tbody>
            <?php
            // Display active students
            while ($student = pg_fetch_assoc($students)):
                $name = htmlspecialchars($student['last_name'] . ', ' . $student['first_name']);
                $payroll_no = htmlspecialchars($student['payroll_no']);
            ?>
                <tr>
                <td><?= $name ?></td>
                <td><?= $payroll_no ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        </div>
    </div>
    </main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
pg_close($connection);
?>
