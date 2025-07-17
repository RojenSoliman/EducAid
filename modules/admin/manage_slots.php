<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

$municipality_id = 1;

// Handle new slot submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['slot_count'])) {
    $newSlotCount = intval($_POST['slot_count']);
    $semester = $_POST['semester'];
    $academic_year = $_POST['academic_year'];

    // Validate the academic year format (****-****)
    if (!preg_match('/^\d{4}-\d{4}$/', $academic_year)) {
        echo "<script>alert('Invalid school year format. Please use the format ****-****.'); history.back();</script>";
        exit;
    }

    pg_query_params($connection, "UPDATE signup_slots SET is_active = FALSE WHERE is_active = TRUE AND municipality_id = $1", [$municipality_id]);
    pg_query_params($connection, "INSERT INTO signup_slots (municipality_id, slot_count, is_active, semester, academic_year) VALUES ($1, $2, TRUE, $3, $4)", [$municipality_id, $newSlotCount, $semester, $academic_year]);

    // Redirect to prevent resubmission on refresh
    header("Location: manage_slots.php");
    exit;
}

// Get active slot
$activeSlot = pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
$slotInfo = pg_fetch_assoc($activeSlot);

// Count current applicants since slot activation
$slotsUsed = 0;
$slotsLeft = 0;
$applicantList = [];

if ($slotInfo) {
    $createdAt = $slotInfo['created_at'];

    // Count total applicants since the current slot activation
    $countQuery = "
        SELECT COUNT(*) AS total FROM students 
        WHERE (status = 'applicant' OR status = 'active') 
        AND municipality_id = $1 AND application_date >= $2
    ";
    $countResult = pg_query_params($connection, $countQuery, [$municipality_id, $createdAt]);
    $countRow = pg_fetch_assoc($countResult);
    $slotsUsed = intval($countRow['total']);
    $slotsLeft = intval($slotInfo['slot_count']) - $slotsUsed;

    // Fetch list of applicants under the current active slot
    $applicants = pg_query_params($connection, "
        SELECT s.first_name, s.middle_name, s.last_name, s.application_date, a.semester, a.academic_year 
        FROM students s
        LEFT JOIN applications a ON s.student_id = a.student_id
        WHERE s.status = 'applicant' AND s.municipality_id = $1 AND s.application_date >= $2
        ORDER BY s.application_date DESC
    ", [$municipality_id, $createdAt]);

    while ($row = pg_fetch_assoc($applicants)) {
        $applicantList[] = $row;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Signup Slots</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="col-md-2 d-flex flex-column bg-light sidebar">
        <div class="sidebar-sticky">
            <h4 class="text-center mt-3">Admin Dashboard</h4>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link active" href="homepage.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="verify_students.php">Verify Students</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="">Manage Applicants</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_announcements.php">Manage Announcements</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_slots.php">Manage Signup Slots</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-danger" href="logout.php" onclick="return confirm('Are you sure you want to logout?');">Logout</a>
                </li>
            </ul>
        </div>
    </nav>
    <div class="container py-4">
        <h2 class="mb-4">Manage Signup Slots</h2>

        <form method="POST" class="card p-4 mb-5 shadow-sm">
            <div class="mb-3">
                <label class="form-label">Enter number of new applicant slots</label>
                <input type="number" name="slot_count" class="form-control" min="1" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Select Semester</label>
                <select name="semester" class="form-select" required>
                    <option value="1st Semester">1st Semester</option>
                    <option value="2nd Semester">2nd Semester</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Enter Academic Year (Format: 2025-2026)</label>
                <input type="text" name="academic_year" class="form-control" pattern="^\d{4}-\d{4}$" required placeholder="2025-2026">
            </div>

            <button type="submit" class="btn btn-primary">Release New Slots</button>
        </form>

        <?php if ($slotInfo): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">Current Active Slot</div>
                <div class="card-body">
                    <p><strong>Activated:</strong> <?= htmlspecialchars($slotInfo['created_at']) ?></p>
                    <p><strong>Slots Released:</strong> <?= htmlspecialchars($slotInfo['slot_count']) ?></p>
                    <p><strong>Slots Used:</strong> <?= $slotsUsed ?></p>
                    <p><strong>Slots Remaining:</strong> <?= max(0, $slotsLeft) ?></p>
                    <p><strong>Semester:</strong> <?= htmlspecialchars($slotInfo['semester']) ?></p>
                    <p><strong>Academic Year:</strong> <?= htmlspecialchars($slotInfo['academic_year']) ?></p>
                </div>
            </div>

            <?php if (!empty($applicantList)): ?>
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">Applicants Registered Under This Slot</div>
                    <div class="card-body">
                        <ul class="list-group">
                            <?php foreach ($applicantList as $student): ?>
                                <li class="list-group-item">
                                    <?= htmlspecialchars($student['last_name']) ?>, 
                                    <?= htmlspecialchars($student['first_name']) ?> 
                                    <?= htmlspecialchars($student['middle_name']) ?> —
                                    <small class="text-muted"><?= $student['application_date'] ?></small>
                                    <?php if ($student['semester'] && $student['academic_year']): ?>
                                        <p class="mt-1">
                                            <strong>Semester:</strong> <?= htmlspecialchars($student['semester']) ?>, 
                                            <strong>Academic Year:</strong> <?= htmlspecialchars($student['academic_year']) ?>
                                        </p>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info">No applicants yet under this slot.</div>
            <?php endif; ?>

        <?php else: ?>
            <div class="alert alert-warning">No active slot found. Add one above.</div>
        <?php endif; ?>
    </div>
</body>
</html>