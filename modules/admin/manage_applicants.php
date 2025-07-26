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

// Pagination & filters
$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;
$sort = $_GET['sort'] ?? 'asc';
$search = trim($_GET['search_surname'] ?? '');

$query = "SELECT * FROM students WHERE status = 'applicant'";
$params = [];
if ($search) {
    $query .= " AND last_name ILIKE $1";
    $params[] = "%$search%";
}
$query .= " ORDER BY last_name " . ($sort === 'desc' ? 'DESC' : 'ASC');
$query .= " LIMIT $limit OFFSET $offset";
$applicants = $params ? pg_query_params($connection, $query, $params) : pg_query($connection, $query);

// Count for pagination
$countQuery = "SELECT COUNT(*) FROM students WHERE status = 'applicant'" . ($search ? " AND last_name ILIKE $1" : "");
$totalApplicants = $params ? pg_fetch_result(pg_query_params($connection, $countQuery, $params), 0, 0) : pg_fetch_result(pg_query($connection, $countQuery), 0, 0);
$totalPages = ceil($totalApplicants / $limit);

// AJAX response for rows + modals
if (isset($_GET['ajax'])) {
    if (pg_num_rows($applicants) === 0) {
        echo "<tr><td colspan='5' class='text-center text-muted'>No applicants found.</td></tr>";
    } else {
        while ($applicant = pg_fetch_assoc($applicants)) {
            $student_id = $applicant['student_id'];
            $isComplete = check_documents($connection, $student_id); ?>
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
            <?php
            // Render modal HTML after the row
            echo "<div class='modal fade' id='modal{$student_id}' tabindex='-1'>
                <div class='modal-dialog modal-lg'>
                  <div class='modal-content'>
                    <div class='modal-header'>
                      <h5 class='modal-title'>Documents for " . htmlspecialchars($applicant['first_name']) . " " . htmlspecialchars($applicant['last_name']) . "</h5>
                      <button class='btn-close' data-bs-dismiss='modal'></button>
                    </div>
                    <div class='modal-body'>";
                      $docs = pg_query_params($connection, "SELECT * FROM documents WHERE student_id = $1", [$student_id]);
                      if (pg_num_rows($docs)) {
                          while ($doc = pg_fetch_assoc($docs)) {
                              $label = ucfirst(str_replace('_', ' ', $doc['type']));
                              echo "<p><strong>$label:</strong> <a href='" . htmlspecialchars($doc['file_path']) . "' target='_blank'>View</a></p>";
                          }
                      } else {
                          echo "<p class='text-muted'>No documents uploaded.</p>";
                      }
            echo "    </div>
                    <div class='modal-footer'>";
                      if ($isComplete) {
                          echo "<form method='POST' class='d-inline' onsubmit='return confirm(\"Verify this student?\");'>
                                  <input type='hidden' name='student_id' value='{$student_id}'>
                                  <input type='hidden' name='mark_verified' value='1'>
                                  <button class='btn btn-success btn-sm'><i class='bi bi-check-circle me-1'></i> Verify</button>
                                </form>
                                <form method='POST' class='d-inline ms-2' onsubmit='return confirm(\"Reject and reset uploads?\");'>
                                  <input type='hidden' name='student_id' value='{$student_id}'>
                                  <input type='hidden' name='reject_applicant' value='1'>
                                  <button class='btn btn-danger btn-sm'><i class='bi bi-x-circle me-1'></i> Reject</button>
                                </form>";
                      } else {
                          echo "<span class='text-muted'>Incomplete documents</span>";
                      }
            echo "    </div>
                  </div>
                </div>
              </div>";
        }
    }

    // Pagination for AJAX
    echo '<tr><td colspan="5" class="text-center">';
    echo '<nav><ul class="pagination pagination-sm justify-content-center">';
    if ($page > 1) {
        echo '<li class="page-item"><a href="#" class="page-link" data-page="' . ($page - 1) . '">&laquo; Prev</a></li>';
    }
    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i == $page ? 'active' : '';
        echo "<li class='page-item $active'><a href='#' class='page-link' data-page='$i'>$i</a></li>";
    }
    if ($page < $totalPages) {
        echo '<li class="page-item"><a href="#" class="page-link" data-page="' . ($page + 1) . '">Next &raquo;</a></li>';
    }
    echo '</ul></nav>';
    echo '</td></tr>';
    exit;
}
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
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css"/>
  <link rel="stylesheet" href="../../assets/css/admin/manage_applicants.css"/>
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
      <h2 class="fw-bold text-primary mb-4"><i class="bi bi-people-fill me-2"></i> Manage Applicants</h2>

      <!-- Filter Block -->
      <div class="card shadow-sm mb-4 filter-card">
        <div class="card-header fw-semibold"><i class="bi bi-funnel me-1"></i> Filters</div>
        <div class="card-body">
          <form class="row g-3" id="filterForm" onsubmit="return false;">
            <div class="col-sm-4">
              <label class="form-label">Sort by Surname</label>
              <select name="sort" id="sortSelect" class="form-select">
                <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>A to Z</option>
                <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label">Search by Surname</label>
              <input type="text" name="search_surname" id="searchInput" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Enter surname...">
            </div>
            <div class="col-sm-4 d-flex align-items-end gap-2">
              <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-search me-1"></i> Apply Filters</button>
              <a href="manage_applicants.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Clear</a>
            </div>
          </form>
        </div>
      </div>

      <!-- Applicants Table -->
      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle table-striped">
          <thead>
            <tr>
              <th>Name</th>
              <th>Contact</th>
              <th>Email</th>
              <th>Documents</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="applicantsTableBody">
            <!-- AJAX rows injected here -->
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script src="../../assets/js/admin/manage_applicants.js"></script>
</body>
</html>

<?php pg_close($connection); ?>
