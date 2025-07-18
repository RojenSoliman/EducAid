<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

// Check finalized state from config table
$isFinalized = false;
$configResult = pg_query($connection, "SELECT value FROM config WHERE key = 'student_list_finalized'");
if ($configResult && $row = pg_fetch_assoc($configResult)) {
    $isFinalized = ($row['value'] === '1');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Finalize list
    if (isset($_POST['finalize_list'])) {
        pg_query($connection, "UPDATE config SET value = '1' WHERE key = 'student_list_finalized'");
        $isFinalized = true;
    }
    // Revert list
    if (isset($_POST['revert_list'])) {
        pg_query($connection, "UPDATE config SET value = '0' WHERE key = 'student_list_finalized'");
        $isFinalized = false;
        // Reset all payroll numbers to 0 if requested
        if (isset($_POST['reset_payroll'])) {
            pg_query($connection, "UPDATE students SET payroll_no = 0");
        }
    }
    // Mark students as active
    if (isset($_POST['activate']) && isset($_POST['selected_applicants'])) {
        foreach ($_POST['selected_applicants'] as $student_id) {
            pg_query_params($connection, "UPDATE students SET status = 'active' WHERE student_id = $1", [$student_id]);
        }
    }

    // Revert students to applicants
    if (isset($_POST['deactivate']) && isset($_POST['selected_actives'])) {
        foreach ($_POST['selected_actives'] as $student_id) {
            pg_query_params($connection, "UPDATE students SET status = 'applicant' WHERE student_id = $1", [$student_id]);
        }
    }

}

// Get filter and sort parameters
$sort = $_GET['sort'] ?? 'asc';
$barangayFilter = $_GET['barangay'] ?? '';
$searchSurname = trim($_GET['search_surname'] ?? '');

// Base query
$query = "SELECT * FROM students WHERE status = 'active'";
$params = [];

// If searching by surname
if (!empty($searchSurname)) {
    $query .= " AND last_name ILIKE $1";
    $params[] = "%$searchSurname%";
}

// Add sorting
$query .= " ORDER BY last_name " . ($sort === 'desc' ? 'DESC' : 'ASC');

// Run the query
if (!empty($params)) {
    $actives = pg_query_params($connection, $query, $params);
} else {
    $actives = pg_query($connection, $query);
}

// Barangay list
$barangayOptions = [];
$barangayResult = pg_query($connection, "SELECT barangay_id, name FROM barangays ORDER BY name ASC");
while ($row = pg_fetch_assoc($barangayResult)) {
    $barangayOptions[] = $row;
}


function fetch_students($connection, $status, $sort, $barangayFilter) {
    $query = "
        SELECT s.student_id, s.first_name, s.middle_name, s.last_name, s.mobile, s.email, b.name AS barangay, s.payroll_no
        FROM students s
        JOIN barangays b ON s.barangay_id = b.barangay_id
        WHERE s.status = $1";
    $params = [$status];
    if (!empty($barangayFilter)) {
        $query .= " AND b.barangay_id = $2";
        $params[] = $barangayFilter;
    }
    $query .= " ORDER BY s.last_name " . ($sort === 'desc' ? 'DESC' : 'ASC');
    return pg_query_params($connection, $query, $params);
}

// Check if all active students have payroll_no > 0
$allHavePayroll = false;
if ($isFinalized) {
    $payrollCheck = pg_query($connection, "SELECT COUNT(*) AS total, SUM(CASE WHEN payroll_no > 0 THEN 1 ELSE 0 END) AS with_payroll FROM students WHERE status = 'active'");
    $row = pg_fetch_assoc($payrollCheck);
    if ($row && $row['total'] > 0 && intval($row['total']) === intval($row['with_payroll'])) {
        $allHavePayroll = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Verify Students</title>
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
      <h2 class="mb-4">Manage Student Status</h2>

      <!-- Filter -->
      <form method="GET" class="row mb-4 g-3">
        <div class="col-md-4">
          <label for="sort" class="form-label">Sort by Name</label>
          <select name="sort" id="sort" class="form-select">
            <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>A to Z</option>
            <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
          </select>
        </div>
        <div class="col-md-4">
          <label for="search_surname" class="form-label">Search by Surname</label>
          <input type="text" name="search_surname" class="form-control" value="<?= htmlspecialchars($searchSurname) ?>" />
        </div>
        <div class="col-md-4">
          <label for="barangay" class="form-label">Filter by Barangay</label>
          <select name="barangay" id="barangay" class="form-select">
            <option value="">All Barangays</option>
            <?php foreach ($barangayOptions as $b): ?>
              <option value="<?= $b['barangay_id'] ?>" <?= $barangayFilter == $b['barangay_id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
        </div>
      </form>

      <!-- Active Students Table -->
      <form method="POST" id="activeStudentsForm">
        <div class="card">
          <div class="card-header bg-success text-white">Active Students</div>
          <div class="card-body">
            <table class="table table-hover table-bordered">
              <thead>
                <tr>
                  <th><input type="checkbox" id="selectAllActive" <?= $isFinalized ? 'disabled' : '' ?>></th>
                  <th>Full Name</th>
                  <th>Email</th>
                  <th>Mobile Number</th>
                  <th>Barangay</th>
                  <th class="payroll-col<?= $isFinalized ? '' : ' d-none' ?>">Payroll Number</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $actives = fetch_students($connection, 'active', $sort, $barangayFilter);
                if (pg_num_rows($actives) > 0):
                  while ($row = pg_fetch_assoc($actives)):
                    $id = $row['student_id'];
                    $name = htmlspecialchars($row['last_name'] . ', ' . $row['first_name'] . ' ' . $row['middle_name']);
                    $email = htmlspecialchars($row['email']);
                    $mobile = htmlspecialchars($row['mobile']);
                    $barangay = htmlspecialchars($row['barangay']);
                    $payroll_no = isset($row['payroll_no']) ? $row['payroll_no'] : '';
                    echo "<tr>
                            <td><input type='checkbox' name='selected_actives[]' value='$id'" . ($isFinalized ? " disabled" : "") . "></td>
                            <td>$name</td>
                            <td>$email</td>
                            <td>$mobile</td>
                            <td>$barangay</td>
                            <td class='payroll-col'" . ($isFinalized ? "" : " style='display:none'") . ">$payroll_no</td>
                          </tr>";
                  endwhile;
                else:
                  echo "<tr><td colspan='6'>No active students found.</td></tr>";
                endif;
                ?>
              </tbody>
            </table>
            <button type="submit" name="deactivate" class="btn btn-danger mt-2" id="revertBtn"<?= $isFinalized ? ' disabled' : '' ?>>Revert to Applicant</button>
            <!-- Finalize/Revert Button -->
            <?php if ($isFinalized): ?>
                <button type="submit" name="revert_list" class="btn btn-warning mt-2" id="revertTriggerBtn">Revert List</button>
                <button type="button" class="btn btn-primary mt-2 ms-2" id="generatePayrollBtn" <?= $allHavePayroll ? 'disabled' : '' ?>>Generate Payroll Numbers</button>
            <?php else: ?>
                <button type="button" class="btn btn-success mt-2" id="finalizeTriggerBtn">Finalize List</button>
                <input type="hidden" name="finalize_list" id="finalizeListInput" value="">
                <button type="button" class="btn btn-primary mt-2 ms-2 d-none" id="generatePayrollBtn">Generate Payroll Numbers</button>
            <?php endif; ?>
          </div>
        </div>
      </form>
    </main>
  </div>
</div>

<!-- Modal for Finalize Confirmation -->
<div class="modal fade" id="finalizeModal" tabindex="-1" aria-labelledby="finalizeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="finalizeModalLabel">Finalize Student List</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to finalize the student list for payroll number generation?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="finalizeConfirmBtnModal">Finalize</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal for Generate Payroll Confirmation -->
<div class="modal fade" id="generatePayrollModal" tabindex="-1" aria-labelledby="generatePayrollModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="generatePayrollModalLabel">Generate Payroll Numbers</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to generate payroll numbers for all active students? This will overwrite any existing payroll numbers.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="generatePayrollConfirmBtnModal">Generate</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal for Revert Confirmation -->
<div class="modal fade" id="revertListModal" tabindex="-1" aria-labelledby="revertListModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="revertListModalLabel">Revert List</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to revert? All payroll numbers will reset if they have already been generated.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="revertListConfirmBtnModal">Yes, Revert</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Finalize modal confirm button disables checkboxes and revert button, then toggles button to Revert
  document.getElementById('finalizeTriggerBtn')?.addEventListener('click', function () {
    var finalizeModal = new bootstrap.Modal(document.getElementById('finalizeModal'));
    finalizeModal.show();
  });
  document.getElementById('finalizeConfirmBtnModal')?.addEventListener('click', function () {
    // Set hidden input to trigger finalize on submit
    document.getElementById('finalizeListInput').value = '1';
    document.getElementById('activeStudentsForm').submit();
  });

  // Generate Payroll Numbers confirmation
  document.getElementById('generatePayrollBtn')?.addEventListener('click', function () {
    var generatePayrollModal = new bootstrap.Modal(document.getElementById('generatePayrollModal'));
    generatePayrollModal.show();
  });
  document.getElementById('generatePayrollConfirmBtnModal')?.addEventListener('click', function () {
    // Submit hidden form to generate payroll numbers
    var form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'generate_payroll';
    input.value = '1';
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
  });

  // Revert List confirmation
  document.getElementById('revertTriggerBtn')?.addEventListener('click', function (e) {
    e.preventDefault();
    var revertListModal = new bootstrap.Modal(document.getElementById('revertListModal'));
    revertListModal.show();
  });
  document.getElementById('revertListConfirmBtnModal')?.addEventListener('click', function () {
    // Submit hidden form to revert and reset payroll numbers
    var form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'revert_list';
    input.value = '1';
    form.appendChild(input);
    var resetInput = document.createElement('input');
    resetInput.type = 'hidden';
    resetInput.name = 'reset_payroll';
    resetInput.value = '1';
    form.appendChild(resetInput);
    document.body.appendChild(form);
    form.submit();
  });

  // Revert handler to re-enable checkboxes and revert button
  function revertHandler() {
    document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => cb.disabled = false);
    document.getElementById('revertBtn').disabled = false;
    var revertBtn = document.getElementById('revertTriggerBtn');
    revertBtn.textContent = 'Finalize List';
    revertBtn.classList.remove('btn-warning');
    revertBtn.classList.add('btn-success');
    revertBtn.id = 'finalizeTriggerBtn';
    revertBtn.removeEventListener('click', revertHandler);
    revertBtn.addEventListener('click', finalizeHandler);
    // Hide Generate Payroll Numbers button
    document.getElementById('generatePayrollBtn').classList.add('d-none');
    // Hide payroll number column
    document.querySelectorAll('.payroll-col').forEach(col => col.classList.add('d-none'));
  }

  // Attach finalizeHandler initially to the Finalize button
  document.getElementById('finalizeTriggerBtn')?.addEventListener('click', finalizeHandler);

  // On page load, set up UI based on PHP $isFinalized
  var isFinalized = <?= $isFinalized ? 'true' : 'false' ?>;
  if (isFinalized) {
    document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => cb.disabled = true);
    document.getElementById('revertBtn').disabled = true;
    document.getElementById('generatePayrollBtn').classList.remove('d-none');
    document.querySelectorAll('.payroll-col').forEach(col => col.classList.remove('d-none'));
  } else {
    document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => cb.disabled = false);
    document.getElementById('revertBtn').disabled = false;
    document.getElementById('generatePayrollBtn').classList.add('d-none');
    document.querySelectorAll('.payroll-col').forEach(col => col.classList.add('d-none'));
  }
</script>

</body>
</html>

<?php
// Handle payroll number generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_payroll'])) {
    // Get all active students ordered by last name ASC
    $result = pg_query($connection, "SELECT student_id FROM students WHERE status = 'active' ORDER BY last_name ASC, first_name ASC, middle_name ASC");
    $payroll_no = 1;
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $sid = $row['student_id'];
            pg_query_params($connection, "UPDATE students SET payroll_no = $1 WHERE student_id = $2", array($payroll_no, $sid));
            $payroll_no++;
        }
    }
    echo "<script>alert('Payroll numbers generated successfully!'); window.location.href = 'verify_students.php';</script>";
    exit;
}

pg_close($connection);
?>
