<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

// Handle form submissions for activation and deactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['activate']) && isset($_POST['selected_applicants'])) {
        foreach ($_POST['selected_applicants'] as $student_id) {
            pg_query_params($connection, "UPDATE students SET status = 'active' WHERE student_id = $1", [$student_id]);
        }
    }

    if (isset($_POST['deactivate']) && isset($_POST['selected_actives'])) {
        foreach ($_POST['selected_actives'] as $student_id) {
            pg_query_params($connection, "UPDATE students SET status = 'applicant' WHERE student_id = $1", [$student_id]);
        }
    }
}

// Sorting/filtering logic
$sort = $_GET['sort'] ?? 'asc';
$barangayFilter = $_GET['barangay'] ?? '';

// Get barangay options
$barangayOptions = [];
$barangayResult = pg_query($connection, "SELECT barangay_id, name FROM barangays ORDER BY name ASC");
while ($row = pg_fetch_assoc($barangayResult)) {
    $barangayOptions[] = $row;
}

// Query helper function
function fetch_students($connection, $status, $sort, $barangayFilter) {
    $base = "
        SELECT s.student_id, s.first_name, s.middle_name, s.last_name, s.mobile, s.email, b.name AS barangay 
        FROM students s 
        JOIN barangays b ON s.barangay_id = b.barangay_id 
        WHERE s.status = $1";
    
    $params = [$status];

    if (!empty($barangayFilter)) {
        $base .= " AND b.barangay_id = $2";
        $params[] = $barangayFilter;
    }

    $base .= " ORDER BY s.last_name " . ($sort === 'desc' ? 'DESC' : 'ASC');
    return pg_query_params($connection, $base, $params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Students</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
  <h2 class="mb-4">Manage Student Status</h2>

  <!-- Filter Options -->
  <form method="GET" class="row mb-4 g-3">
    <div class="col-md-4">
      <label for="sort" class="form-label">Sort by Name</label>
      <select name="sort" id="sort" class="form-select">
        <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>A to Z</option>
        <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
      </select>
    </div>
    <div class="col-md-4">
      <label for="barangay" class="form-label">Filter by Barangay</label>
      <select name="barangay" id="barangay" class="form-select">
        <option value="">All Barangays</option>
        <?php foreach ($barangayOptions as $b): ?>
          <option value="<?= $b['barangay_id'] ?>" <?= $barangayFilter == $b['barangay_id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 d-flex align-items-end">
      <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
    </div>
  </form>

  <!-- Applicants Table -->
  <form method="POST">
    <div class="card mb-4">
      <div class="card-header bg-primary text-white">Applicants</div>
      <div class="card-body">
        <table class="table table-hover table-bordered">
          <thead>
            <tr>
              <th><input type="checkbox" id="selectAllApplicants"></th>
              <th>Full Name</th>
              <th>Email</th>
              <th>Mobile Number</th>
              <th>Barangay</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $applicants = fetch_students($connection, 'applicant', $sort, $barangayFilter);
            if (pg_num_rows($applicants) > 0):
              while ($row = pg_fetch_assoc($applicants)):
                $id = $row['student_id'];
                $name = htmlspecialchars($row['last_name'] . ', ' . $row['first_name'] . ' ' . $row['middle_name']);
                $mobile = htmlspecialchars($row['mobile']);
                $email = htmlspecialchars($row['email']);
                $barangay = htmlspecialchars($row['barangay']);
                echo "<tr>
                        <td><input type='checkbox' name='selected_applicants[]' value='$id'></td>
                        <td>$name</td>
                        <td>$email</td>
                        <td>$mobile</td>
                        <td>$barangay</td>
                      </tr>";
              endwhile;
            else:
              echo "<tr><td colspan='4'>No applicants found.</td></tr>";
            endif;
            ?>
          </tbody>
        </table>
        <button type="submit" name="activate" class="btn btn-success mt-2">Set Selected as Active</button>
      </div>
    </div>
  </form>

  <!-- Active Students Table -->
  <form method="POST">
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
                $mobile = htmlspecialchars($row['mobile']);
                $email = htmlspecialchars($row['email']);
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
              echo "<tr><td colspan='4'>No active students found.</td></tr>";
            endif;
            ?>
          </tbody>
        </table>
        <button type="submit" name="deactivate" class="btn btn-danger mt-2">Revert to Applicant</button>
      </div>
    </div>
  </form>
</div>

<a href="logout.php" onclick="confirmLogout(event)">
     <span class="links_name">Logout</span>
</a>

<script>
  document.getElementById('selectAllApplicants')?.addEventListener('change', function () {
    document.querySelectorAll("input[name='selected_applicants[]']").forEach(cb => cb.checked = this.checked);
  });

  document.getElementById('selectAllActive')?.addEventListener('change', function () {
    document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => cb.checked = this.checked);
  });
</script>
</body>
</html>