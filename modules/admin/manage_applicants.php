<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

include '../../config/database.php';

// Function to check if all required documents are uploaded
function check_documents($connection, $student_id) {
    $required = ['id_picture', 'letter_to_mayor', 'certificate_of_indigency'];
    $query = pg_query_params($connection, "SELECT type FROM documents WHERE student_id = $1", [$student_id]);
    $uploaded = [];
    while ($row = pg_fetch_assoc($query)) $uploaded[] = $row['type'];
    return count(array_diff($required, $uploaded)) === 0;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'] ?? null;

    if (isset($_POST['mark_verified'])) {
        pg_query_params($connection, "UPDATE students SET status = 'active' WHERE student_id = $1", [$student_id]);
        $name = pg_fetch_assoc(pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$student_id]));
        $msg = "{$name['first_name']} {$name['last_name']} ($student_id) documents verified.";
        pg_query($connection, "INSERT INTO admin_notifications (message) VALUES ('" . pg_escape_string($connection, $msg) . "')");
        echo "<script>alert('Student verified.'); location.href='manage_applicants.php';</script>";
        exit;
    }

    if (isset($_POST['reject_applicant'])) {
        $docs = pg_query_params($connection, "SELECT file_path FROM documents WHERE student_id = $1", [$student_id]);
        while ($doc = pg_fetch_assoc($docs)) if (file_exists($doc['file_path'])) @unlink($doc['file_path']);
        pg_query_params($connection, "DELETE FROM documents WHERE student_id = $1", [$student_id]);
        $msg = "Your documents were rejected on " . date("F j, Y, g:i a") . ". Please re-upload.";
        pg_query_params($connection, "INSERT INTO notifications (student_id, message) VALUES ($1, $2)", [$student_id, $msg]);
        $name = pg_fetch_assoc(pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$student_id]));
        $adminMsg = "{$name['first_name']} {$name['last_name']} ($student_id) documents rejected.";
        pg_query($connection, "INSERT INTO admin_notifications (message) VALUES ('" . pg_escape_string($connection, $adminMsg) . "')");
        echo "<script>alert('Documents reset. Applicant may re-upload.'); location.href='manage_applicants.php';</script>";
        exit;
    }
}

// Filters
$sort = $_GET['sort'] ?? 'asc';
$search = trim($_GET['search_surname'] ?? '');
$query = "SELECT * FROM students WHERE status = 'applicant'";
$params = [];

if ($search) {
    $query .= " AND last_name ILIKE $1";
    $params[] = "%$search%";
}

$query .= " ORDER BY last_name " . ($sort === 'desc' ? 'DESC' : 'ASC');
$applicants = $params ? pg_query_params($connection, $query, $params) : pg_query($connection, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Applicants</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css"/>
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css"/>]
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

    <div class="container-fluid py-4 px-4">
      <h2 class="fw-bold mb-4">Manage Applicants</h2>

      <!-- Filter Block -->
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <form class="row g-3" method="GET">
            <div class="col-sm-4">
              <label class="form-label">Sort by Surname</label>
              <select name="sort" class="form-select">
                <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>A to Z</option>
                <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label">Search by Surname</label>
              <input type="text" name="search_surname" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Enter surname...">
            </div>
            <div class="col-sm-4 d-flex align-items-end">
              <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Apply Filters</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Applicants Table -->
      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Name</th>
              <th>Contact</th>
              <th>Email</th>
              <th>Documents</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (pg_num_rows($applicants) === 0): ?>
              <tr><td colspan="5" class="text-center text-muted">No applicants found.</td></tr>
            <?php else: ?>
              <?php while ($applicant = pg_fetch_assoc($applicants)) {
                $student_id = $applicant['student_id'];
                $isComplete = check_documents($connection, $student_id);
              ?>
              <tr>
                <td><?= htmlspecialchars("{$applicant['last_name']}, {$applicant['first_name']} {$applicant['middle_name']}") ?></td>
                <td><?= htmlspecialchars($applicant['mobile']) ?></td>
                <td><?= htmlspecialchars($applicant['email']) ?></td>
                <td>
                  <span class="badge <?= $isComplete ? 'bg-success' : 'bg-secondary' ?>">
                    <?= $isComplete ? 'Complete' : 'Incomplete' ?>
                  </span>
                </td>
                <td>
                  <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#modal<?= $student_id ?>">
                    <i class="bi bi-eye"></i> View
                  </button>
                </td>
              </tr>

              <!-- Modal -->
              <div class="modal fade" id="modal<?= $student_id ?>" tabindex="-1">
                <div class="modal-dialog modal-lg">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title">Documents for <?= htmlspecialchars($applicant['first_name']) ?> <?= htmlspecialchars($applicant['last_name']) ?></h5>
                      <button class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                      <?php
                      $docs = pg_query_params($connection, "SELECT * FROM documents WHERE student_id = $1", [$student_id]);
                      if (pg_num_rows($docs)) {
                        while ($doc = pg_fetch_assoc($docs)) {
                          $label = ucfirst(str_replace('_', ' ', $doc['type']));
                          echo "<p><strong>$label:</strong> <a href='" . htmlspecialchars($doc['file_path']) . "' target='_blank'>View</a></p>";
                        }
                      } else echo "<p class='text-muted'>No documents uploaded.</p>";
                      ?>
                    </div>
                    <div class="modal-footer">
                      <?php if ($isComplete): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Verify this student?');">
                          <input type="hidden" name="student_id" value="<?= $student_id ?>">
                          <input type="hidden" name="mark_verified" value="1">
                          <button class="btn btn-success btn-sm"><i class="bi bi-check-circle me-1"></i> Verify</button>
                        </form>
                        <form method="POST" class="d-inline ms-2" onsubmit="return confirm('Reject and reset uploads?');">
                          <input type="hidden" name="student_id" value="<?= $student_id ?>">
                          <input type="hidden" name="reject_applicant" value="1">
                          <button class="btn btn-danger btn-sm"><i class="bi bi-x-circle me-1"></i> Reject</button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted">Incomplete documents</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
              <?php } ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>

<?php pg_close($connection); ?>
