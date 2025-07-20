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
    $admin_password = $_POST['admin_password'];

    // Validate the academic year format (****-****)
    if (!preg_match('/^\d{4}-\d{4}$/', $academic_year)) {
        echo "<script>alert('Invalid school year format. Please use the format ****-****.'); history.back();</script>";
        exit;
    }

    // Validate admin password
    $admin_username = $_SESSION['admin_username'];
    $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE username = $1", array($admin_username));
    $adminRow = pg_fetch_assoc($adminQuery);
    if (!$adminRow || !password_verify($admin_password, $adminRow['password'])) {
        echo "<script>alert('Incorrect password. Please try again.'); history.back();</script>";
        exit;
    }

    pg_query_params($connection, "UPDATE signup_slots SET is_active = FALSE WHERE is_active = TRUE AND municipality_id = $1", [$municipality_id]);
    pg_query_params($connection, "INSERT INTO signup_slots (municipality_id, slot_count, is_active, semester, academic_year) VALUES ($1, $2, TRUE, $3, $4)", [$municipality_id, $newSlotCount, $semester, $academic_year]);
    echo "<script>alert('Slot has been created successfully!'); window.location.href = 'manage_slots.php';</script>";
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
    <link rel="stylesheet" href="../../assets/css/admin_homepage.css">
    <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
</head>
<body class="bg-light">
<div class="container-fluid">
  <div class="row">
    <!-- Sidebar -->
    <?php include '../../includes/admin/admin_sidebar.php'; ?>

    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

    <!-- Main -->
    <main class="col-md-10 ms-sm-auto px-4 py-4">
        <div class="container py-4">
                <h2 class="mb-4">Manage Signup Slots</h2>

                <form id="releaseSlotsForm" method="POST" class="card p-4 mb-5 shadow-sm">
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

                    <button type="button" id="showPasswordModalBtn" class="btn btn-primary">Release New Slots</button>
                </form>

                <!-- Password Confirmation Modal -->
                <div class="modal fade" id="passwordModal" tabindex="-1" aria-labelledby="passwordModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="passwordModalLabel">Confirm Your Password</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                        <label class="form-label">Enter your password to confirm release</label>
                        <input type="password" name="admin_password" id="modal_admin_password" class="form-control" required placeholder="Enter your password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" id="confirmReleaseBtn" class="btn btn-primary">Confirm Release</button>
                    </div>
                    </div>
                </div>
                </div>

                <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
                <script>
                // Show modal on button click
                document.getElementById('showPasswordModalBtn').addEventListener('click', function() {
                    var passwordModal = new bootstrap.Modal(document.getElementById('passwordModal'));
                    passwordModal.show();
                });

                // On confirm, copy password to form and submit
                document.getElementById('confirmReleaseBtn').addEventListener('click', function() {
                    var modalPassword = document.getElementById('modal_admin_password').value;
                    if (!modalPassword) {
                        alert('Please enter your password.');
                        return;
                    }
                    var form = document.getElementById('releaseSlotsForm');
                    var hiddenInput = form.querySelector('input[name="admin_password"]');
                    if (!hiddenInput) {
                        hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'admin_password';
                        form.appendChild(hiddenInput);
                    }
                    hiddenInput.value = modalPassword;
                    form.submit();
                });
                </script>

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
                <?php
                // Past slot releases history
                $historyRes = pg_query_params($connection, "SELECT * FROM signup_slots WHERE municipality_id = $1 AND is_active = FALSE ORDER BY created_at DESC", array($municipality_id));
                $pastReleases = [];
                while ($row = pg_fetch_assoc($historyRes)) {
                    $pastReleases[] = $row;
                }
                // Calculate slots used per release
                foreach ($pastReleases as $idx => $h) {
                    $nextRes = pg_query_params($connection,
                        "SELECT created_at FROM signup_slots WHERE municipality_id = $1 AND created_at > $2 ORDER BY created_at ASC LIMIT 1",
                        array($municipality_id, $h['created_at'])
                    );
                    $nextRow = pg_fetch_assoc($nextRes);
                    $endDate = $nextRow['created_at'] ?? date('Y-m-d H:i:s');
                    $countRes = pg_query_params($connection,
                        "SELECT COUNT(*) AS total FROM students WHERE (status = 'applicant' OR status = 'active') AND municipality_id = $1 AND application_date >= $2 AND application_date < $3",
                        array($municipality_id, $h['created_at'], $endDate)
                    );
                    $countRow = pg_fetch_assoc($countRes);
                    $pastReleases[$idx]['slots_used'] = intval($countRow['total']);
                }
                ?>
                <h3 class="mt-5">Past Slot Releases</h3>
                <?php if (!empty($pastReleases)): ?>
                <div class="accordion" id="pastSlotsAccordion">
                    <?php foreach ($pastReleases as $i => $h): ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingPast<?= $i ?>">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePast<?= $i ?>" aria-expanded="false" aria-controls="collapsePast<?= $i ?>">
                                <?= htmlspecialchars($h['created_at']) ?> — Released <?= htmlspecialchars($h['slot_count']) ?> slots
                            </button>
                        </h2>
                        <div id="collapsePast<?= $i ?>" class="accordion-collapse collapse" aria-labelledby="headingPast<?= $i ?>" data-bs-parent="#pastSlotsAccordion">
                            <div class="accordion-body">
                                <p><strong>Semester:</strong> <?= htmlspecialchars($h['semester']) ?></p>
                                <p><strong>Academic Year:</strong> <?= htmlspecialchars($h['academic_year']) ?></p>
                                <p><strong>Slots Used:</strong> <?= $h['slots_used'] ?></p>
                                <p><strong>Slots Remaining:</strong> <?= max(0, $h['slot_count'] - $h['slots_used']) ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div class="alert alert-info">No past slot releases.</div>
                <?php endif; ?>
        </div>
    </main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>