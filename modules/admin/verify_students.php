<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

// Handle form submissions
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

// Filters
$sort = $_GET['sort'] ?? 'asc';
$barangayFilter = $_GET['barangay'] ?? '';

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
  <link rel="stylesheet" href="../../assets/css/admin_homepage.css">
</head>
<body>
<div class="container-fluid">
  <div class="row">
    <!-- Sidebar -->
    <nav class="col-md-2 d-flex flex-column bg-light sidebar">
      <div class="sidebar-sticky">
        <h4 class="text-center mt-3">Admin Dashboard</h4>
        <ul class="nav flex-column">
          <li class="nav-item"><a class="nav-link" href="homepage.php">Home</a></li>
          <li class="nav-item"><a class="nav-link active" href="verify_students.php">Verify Students</a></li>
          <li class="nav-item"><a class="nav-link" href="">Manage Applicants</a></li>
          <li class="nav-item">
            <a class="nav-link text-danger" href="logout.php" onclick="return confirm('Are you sure you want to logout?');">
              Logout
            </a>
          </li>
        </ul>
      </div>
    </nav>

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
                    $email = htmlspecialchars($row['email']);
                    $mobile = htmlspecialchars($row['mobile']);
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
                  echo "<tr><td colspan='5'>No applicants found.</td></tr>";
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
            <button type="submit" name="deactivate" class="btn btn-danger mt-2">Revert to Applicant</button>
          </div>
        </div>
      </form>
    </main>
  </div>
</div>

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
<?php pg_close($connection); ?>