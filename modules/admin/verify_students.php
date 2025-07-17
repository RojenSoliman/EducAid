<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        SELECT s.student_id, s.first_name, s.middle_name, s.last_name, s.mobile, s.email, b.name AS barangay 
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
                  <th><input type="checkbox" id="selectAllActive"></th>
                  <th>Full Name</th>
                  <th>Email</th>
                  <th>Mobile Number</th>
                  <th>Barangay</th>
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
                    echo "<tr>
                            <td><input type='checkbox' name='selected_actives[]' value='$id'></td>
                            <td>$name</td>
                            <td>$email</td>
                            <td>$mobile</td>
                            <td>$barangay</td>
                          </tr>";
                  endwhile;
                else:
                  echo "<tr><td colspan='5'>No active students found.</td></tr>";
                endif;
                ?>
              </tbody>
            </table>
            <button type="submit" name="deactivate" class="btn btn-danger mt-2" id="revertBtn">Revert to Applicant</button>

            <!-- Finalize/Revert Button -->
            <button type="button" class="btn btn-success mt-2" id="finalizeTriggerBtn">Finalize List</button>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
  // Finalize modal confirm button disables checkboxes and revert button, then toggles button to Revert
document.getElementById('finalizeConfirmBtnModal')?.addEventListener('click', function () {
  // Disable the checkboxes
  document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => cb.disabled = true);
  
  // Disable the 'Revert to Applicant' button
  document.getElementById('revertBtn').disabled = true;
  
  // Change Finalize button to Revert
  var finalizeBtn = document.getElementById('finalizeTriggerBtn');
  finalizeBtn.textContent = 'Revert List';
  finalizeBtn.classList.remove('btn-success');
  finalizeBtn.classList.add('btn-warning');
  finalizeBtn.id = 'revertTriggerBtn';

  // Remove event listener for finalize and add event listener for revert
  finalizeBtn.removeEventListener('click', finalizeHandler);
  finalizeBtn.addEventListener('click', revertHandler);

  // Hide the modal after finalizing
  var finalizeModal = bootstrap.Modal.getInstance(document.getElementById('finalizeModal'));
  finalizeModal.hide();
});

// Finalize handler to show the modal
function finalizeHandler() {
  var finalizeModal = new bootstrap.Modal(document.getElementById('finalizeModal'));
  finalizeModal.show();
}

// Revert handler to re-enable checkboxes and revert button
function revertHandler() {
  // Re-enable the checkboxes
  document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => cb.disabled = false);
  
  // Re-enable the 'Revert to Applicant' button
  document.getElementById('revertBtn').disabled = false;
  
  // Change the button back to Finalize
  var revertBtn = document.getElementById('revertTriggerBtn');
  revertBtn.textContent = 'Finalize List';
  revertBtn.classList.remove('btn-warning');
  revertBtn.classList.add('btn-success');
  revertBtn.id = 'finalizeTriggerBtn';

  // Remove event listener for revert and add event listener for finalize
  revertBtn.removeEventListener('click', revertHandler);
  revertBtn.addEventListener('click', finalizeHandler);
}

// Attach finalizeHandler initially to the Finalize button
document.getElementById('finalizeTriggerBtn')?.addEventListener('click', finalizeHandler);

</script>

</body>
</html>

<?php pg_close($connection); ?>
