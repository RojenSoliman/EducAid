<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

$municipality_id = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['slot_count'])) {
        $newSlotCount = intval($_POST['slot_count']);
        $semester = $_POST['semester'];
        $academic_year = $_POST['academic_year'];
        $admin_password = $_POST['admin_password'];

        if (!preg_match('/^\d{4}-\d{4}$/', $academic_year)) {
            header("Location: manage_slots.php?error=invalid_year");
            exit;
        }

        $admin_username = $_SESSION['admin_username'];
        $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE username = $1", [$admin_username]);
        $adminRow = pg_fetch_assoc($adminQuery);
        if (!$adminRow || !password_verify($admin_password, $adminRow['password'])) {
            header("Location: manage_slots.php?error=invalid_password");
            exit;
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
        pg_query_params($connection, "INSERT INTO signup_slots (municipality_id, slot_count, is_active, semester, academic_year) VALUES ($1, $2, TRUE, $3, $4)", [$municipality_id, $newSlotCount, $semester, $academic_year]);

        header("Location: manage_slots.php?status=success");
        exit;
    } elseif (isset($_POST['delete_slot_id'])) {
        $delete_slot_id = intval($_POST['delete_slot_id']);
        pg_query_params($connection, "DELETE FROM signup_slots WHERE slot_id = $1 AND municipality_id = $2", [$delete_slot_id, $municipality_id]);
        header("Location: manage_slots.php?status=deleted");
        exit;
    } elseif (isset($_POST['export_csv']) && $_POST['export_csv'] === '1') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="applicants.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['First Name', 'Middle Name', 'Last Name', 'Application Date', 'Semester', 'Academic Year']);
        $exportQuery = pg_query_params($connection, "
            SELECT s.first_name, s.middle_name, s.last_name, s.application_date, a.semester, a.academic_year
            FROM students s
            LEFT JOIN applications a ON s.student_id = a.student_id
            WHERE s.status = 'applicant' AND s.municipality_id = $1
            ORDER BY s.application_date DESC
        ", [$municipality_id]);
        while ($row = pg_fetch_assoc($exportQuery)) {
            fputcsv($output, [
                $row['first_name'], $row['middle_name'], $row['last_name'],
                $row['application_date'], $row['semester'], $row['academic_year']
            ]);
        }
        fclose($output);
        exit;
    }
}

$slotInfo = pg_fetch_assoc(pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]));
$slotsUsed = 0;
$slotsLeft = 0;
$applicantList = [];

if ($slotInfo) {
    $createdAt = $slotInfo['created_at'];
    $res = pg_query_params($connection, "SELECT COUNT(*) AS total FROM students WHERE (status = 'applicant' OR status = 'active') AND municipality_id = $1 AND application_date >= $2", [$municipality_id, $createdAt]);
    $slotsUsed = intval(pg_fetch_result($res, 0, 'total'));
    $slotsLeft = $slotInfo['slot_count'] - $slotsUsed;

    $applicantRes = pg_query_params($connection, "
        SELECT s.first_name, s.middle_name, s.last_name, s.application_date, a.semester, a.academic_year
        FROM students s
        LEFT JOIN applications a ON s.student_id = a.student_id
        WHERE s.status = 'applicant' AND s.municipality_id = $1 AND s.application_date >= $2
        ORDER BY s.application_date DESC
    ", [$municipality_id, $createdAt]);

    while ($row = pg_fetch_assoc($applicantRes)) {
        $applicantList[] = $row;
    }
}

$historyRes = pg_query_params($connection, "SELECT * FROM signup_slots WHERE municipality_id = $1 AND is_active = FALSE ORDER BY created_at DESC", [$municipality_id]);
$pastReleases = [];
while ($row = pg_fetch_assoc($historyRes)) {
    $pastReleases[] = $row;
}

foreach ($pastReleases as $i => $slot) {
    $nextSlotRes = pg_query_params($connection, "SELECT created_at FROM signup_slots WHERE municipality_id = $1 AND created_at > $2 ORDER BY created_at ASC LIMIT 1", [$municipality_id, $slot['created_at']]);
    $nextCreated = pg_fetch_result($nextSlotRes, 0, 'created_at') ?? date('Y-m-d H:i:s');
    $countRes = pg_query_params($connection, "
        SELECT COUNT(*) AS total FROM students 
        WHERE (status = 'applicant' OR status = 'active') AND municipality_id = $1 
        AND application_date >= $2 AND application_date < $3
    ", [$municipality_id, $slot['created_at'], $nextCreated]);
    $pastReleases[$i]['slots_used'] = intval(pg_fetch_result($countRes, 0, 'total'));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Signup Slots</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
  <link rel="stylesheet" href="../../assets/css/admin/manage_slots.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
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

      <div class="container-fluid p-4">
        <h2 class="fw-bold mb-4">Manage Signup Slots</h2>

        <!-- Slot release form -->
        <form id="releaseSlotsForm" method="POST" class="card p-4 shadow-sm mb-4">
          <div class="row g-3">
            <div class="col-md-4">
              <label>Slot Count</label>
              <input type="number" name="slot_count" class="form-control" required min="1">
            </div>
            <div class="col-md-4">
              <label>Semester</label>
              <select name="semester" class="form-select" required>
                <option value="1st Semester">1st Semester</option>
                <option value="2nd Semester">2nd Semester</option>
              </select>
            </div>
            <div class="col-md-4">
              <label>Academic Year</label>
              <input type="text" name="academic_year" class="form-control" pattern="^\d{4}-\d{4}$" required>
            </div>
          </div>
          <button type="button" id="showPasswordModalBtn" class="btn btn-primary mt-3">Release</button>
        </form>

        <!-- Current slot summary -->
        <?php if ($slotInfo): ?>
          <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">Current Slot</div>
            <div class="card-body">
              <p><strong>Released:</strong> <?= $slotInfo['slot_count'] ?> (<?= $slotInfo['created_at'] ?>)</p>
              <p><strong>Used:</strong> <?= $slotsUsed ?> / <?= $slotInfo['slot_count'] ?></p>
              <?php
                $percentage = ($slotsUsed / max(1, $slotInfo['slot_count'])) * 100;
                $barClass = 'bg-success';
                if ($percentage >= 80) $barClass = 'bg-danger';
                elseif ($percentage >= 50) $barClass = 'bg-warning';
              ?>
              <div class="progress mb-3 slot-progress">
                <div class="progress-bar <?= $barClass ?>" style="width: <?= $percentage ?>%">
                  <?= round($percentage) ?>%
                </div>
              </div>
              <p><strong>Remaining:</strong> <?= max(0, $slotsLeft) ?></p>
            </div>
          </div>
        <?php endif; ?>

        <!-- Export & applicant list -->
        <?php if (!empty($applicantList)): ?>
          <form method="POST" class="mb-3">
            <input type="hidden" name="export_csv" value="1">
            <button class="btn btn-success mb-2"><i class="bi bi-download"></i> Export Applicants to CSV</button>
          </form>

          <div class="card mb-4 shadow-sm">
            <div class="card-header bg-secondary text-white">Applicants</div>
            <div class="card-body">
              <ul class="list-group">
                <?php foreach ($applicantList as $a): ?>
                  <li class="list-group-item">
                    <?= htmlspecialchars("{$a['last_name']}, {$a['first_name']} {$a['middle_name']}") ?>
                    <span class="text-muted small"> — <?= $a['application_date'] ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        <?php endif; ?>

        <!-- Past Releases -->
        <h4 class="mt-4">Past Releases</h4>
        <?php if (!empty($pastReleases)): ?>
          <div class="accordion" id="pastSlotsAccordion">
            <?php foreach ($pastReleases as $i => $h): ?>
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading<?= $i ?>">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                          data-bs-target="#collapse<?= $i ?>" aria-expanded="false" aria-controls="collapse<?= $i ?>">
                    <?= $h['created_at'] ?> — <?= $h['slot_count'] ?> slots
                  </button>
                </h2>
                <div id="collapse<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#pastSlotsAccordion">
                  <div class="accordion-body">
                    <p><strong>Semester:</strong> <?= $h['semester'] ?> | <strong>AY:</strong> <?= $h['academic_year'] ?></p>
                    <p><strong>Used:</strong> <?= $h['slots_used'] ?> / <?= $h['slot_count'] ?></p>
                    <form method="POST">
                      <input type="hidden" name="delete_slot_id" value="<?= $h['slot_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Delete</button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="alert alert-info">No past releases found.</div>
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/sidebar.js"></script>
  <script>
    document.getElementById('showPasswordModalBtn').addEventListener('click', () => {
      new bootstrap.Modal(document.getElementById('passwordModal')).show();
    });

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
  </script>
</body>
</html>
