<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}
include '../../config/database.php';

// Check if finalized
$isFinalized = false;
$res = pg_query($connection, "SELECT value FROM config WHERE key = 'student_list_finalized'");
if ($res && $row = pg_fetch_assoc($res)) $isFinalized = $row['value'] === '1';

// Filters
$sort = $_GET['sort'] ?? 'asc';
$barangayFilter = $_GET['barangay'] ?? '';
$search = trim($_GET['search_surname'] ?? '');

// Query students
$query = "
    SELECT s.student_id, s.first_name, s.middle_name, s.last_name, s.mobile, s.email, s.payroll_no, b.name AS barangay
    FROM students s
    JOIN barangays b ON s.barangay_id = b.barangay_id
    WHERE s.status = 'active'";
$params = [];

if (!empty($search)) {
    $query .= " AND s.last_name ILIKE $1";
    $params[] = "%$search%";
}
if (!empty($barangayFilter)) {
    $query .= empty($params) ? " AND b.barangay_id = $1" : " AND b.barangay_id = $2";
    $params[] = $barangayFilter;
}
$query .= " ORDER BY s.last_name " . ($sort === 'desc' ? 'DESC' : 'ASC');
$students = count($params) ? pg_query_params($connection, $query, $params) : pg_query($connection, $query);

// Barangays
$barangayOptions = [];
$res = pg_query($connection, "SELECT barangay_id, name FROM barangays ORDER BY name ASC");
while ($row = pg_fetch_assoc($res)) $barangayOptions[] = $row;

// Check payroll
$payrollCheck = pg_query($connection, "SELECT COUNT(*) AS total, SUM(CASE WHEN payroll_no > 0 THEN 1 ELSE 0 END) AS with_payroll FROM students WHERE status = 'active'");
$row = pg_fetch_assoc($payrollCheck);
$allHavePayroll = $row && $row['total'] == $row['with_payroll'];

// Export CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_csv'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="active_students.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Payroll No', 'Last Name', 'First Name', 'Middle Name', 'Mobile']);
    $res = pg_query($connection, "SELECT student_id, payroll_no, last_name, first_name, middle_name, mobile FROM students WHERE status = 'active' ORDER BY last_name");
    while ($r = pg_fetch_assoc($res)) fputcsv($out, $r);
    fclose($out);
    exit;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['deactivate']) && isset($_POST['selected'])) {
        foreach ($_POST['selected'] as $sid) {
            pg_query_params($connection, "UPDATE students SET status = 'applicant' WHERE student_id = $1", [$sid]);
        }
        pg_query($connection, "UPDATE config SET value = '0' WHERE key = 'student_list_finalized'");
        header("Location: verify_students.php");
        exit;
    }
    if (isset($_POST['finalize'])) {
        pg_query($connection, "UPDATE config SET value = '1' WHERE key = 'student_list_finalized'");
        header("Location: verify_students.php");
        exit;
    }
    if (isset($_POST['revert'])) {
        pg_query($connection, "UPDATE config SET value = '0' WHERE key = 'student_list_finalized'");
        pg_query($connection, "UPDATE students SET payroll_no = 0 WHERE status = 'active'");
        header("Location: verify_students.php");
        exit;
    }
    if (isset($_POST['generate_payroll'])) {
        $res = pg_query($connection, "SELECT student_id FROM students WHERE status = 'active' ORDER BY last_name, first_name");
        $num = 1;
        while ($r = pg_fetch_assoc($res)) {
            pg_query_params($connection, "UPDATE students SET payroll_no = $1 WHERE student_id = $2", [$num++, $r['student_id']]);
        }
        echo "<script>alert('Payroll numbers generated successfully.'); location.href='verify_students.php';</script>";
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Verify Students</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css">
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css">
  <link rel="stylesheet" href="../../assets/css/admin/verify_students.css">
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

    <div class="container-fluid px-4 py-4">
      <h2 class="fw-bold">Manage Verified Students</h2>
      <p class="text-muted mb-4">View and manage students who have been verified for payroll assignment.</p>

      <!-- Filter Card -->
      <div class="card filter-card shadow-sm mb-4">
        <div class="card-body">
          <form class="row g-3" method="GET">
            <div class="col-12 col-md-3">
              <label class="form-label">Sort by Surname</label>
              <select name="sort" class="form-select">
                <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>A to Z</option>
                <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
              </select>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Search by Surname</label>
              <input type="text" name="search_surname" class="form-control" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Filter by Barangay</label>
              <select name="barangay" class="form-select">
                <option value="">All</option>
                <?php foreach ($barangayOptions as $b): ?>
                  <option value="<?= $b['barangay_id'] ?>" <?= $barangayFilter == $b['barangay_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($b['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-3 d-flex align-items-end">
              <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter"></i> Apply</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Students Table -->
      <form method="POST">
        <div class="card shadow-sm">
          <div class="card-header section-title-bar">
            <i class="bi bi-person-badge me-2"></i> Active Students
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-bordered table-striped mb-0">
                <thead>
                  <tr>
                    <th><input type="checkbox" id="selectAll" <?= $isFinalized ? 'disabled' : '' ?>></th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Mobile</th>
                    <th>Barangay</th>
                    <th>Payroll #</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (pg_num_rows($students) > 0): ?>
                    <?php while ($s = pg_fetch_assoc($students)): ?>
                      <tr>
                        <td data-label="Select"><input type="checkbox" name="selected[]" value="<?= $s['student_id'] ?>" <?= $isFinalized ? 'disabled' : '' ?>></td>
                        <td data-label="Name"><?= htmlspecialchars("{$s['last_name']}, {$s['first_name']} {$s['middle_name']}") ?></td>
                        <td data-label="Email"><?= htmlspecialchars($s['email']) ?></td>
                        <td data-label="Mobile"><?= htmlspecialchars($s['mobile']) ?></td>
                        <td data-label="Barangay"><?= htmlspecialchars($s['barangay']) ?></td>
                        <td data-label="Payroll #"><?= $s['payroll_no'] > 0 ? $s['payroll_no'] : '-' ?></td>
                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="6" class="text-center text-muted py-4">
                        <i class="bi bi-info-circle me-2"></i>No students found for the current filters.
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
          <button type="submit" name="deactivate" class="btn btn-danger" <?= $isFinalized ? 'disabled' : '' ?>>
            <i class="bi bi-arrow-counterclockwise"></i> Revert to Applicant
          </button>
          <?php if ($isFinalized): ?>
            <button type="submit" name="revert" class="btn btn-warning">
              <i class="bi bi-x-circle"></i> Revert Finalization
            </button>
            <button type="submit" name="generate_payroll" class="btn btn-primary" <?= $allHavePayroll ? 'disabled' : '' ?>>
              <i class="bi bi-gear-fill"></i> Generate Payroll Numbers
            </button>
            <button type="submit" name="export_csv" class="btn btn-success">
              <i class="bi bi-download"></i> Export to CSV
            </button>
          <?php else: ?>
            <button type="submit" name="finalize" class="btn btn-success">
              <i class="bi bi-check-circle"></i> Finalize List
            </button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script>
  document.getElementById('selectAll')?.addEventListener('change', function () {
    document.querySelectorAll("input[name='selected[]']").forEach(cb => {
      if (!cb.disabled) cb.checked = this.checked;
    });
  });
</script>
</body>
</html>
<?php pg_close($connection); ?>
