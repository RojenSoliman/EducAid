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

<<<<<<< HEAD
// Pagination & filters
$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;
$sort = $_GET['sort'] ?? 'asc';
$search = trim($_GET['search_surname'] ?? '');

$query = "SELECT * FROM students WHERE status = 'applicant'";
=======
// Pagination & Filtering logic
$page = max(1, intval($_GET['page'] ?? $_POST['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$sort = $_GET['sort'] ?? $_POST['sort'] ?? 'asc';
$search = trim($_GET['search_surname'] ?? $_POST['search_surname'] ?? '');

$where = "status = 'applicant'";
>>>>>>> 09807c52616f708245bbb6ea55f992ea78af2157
$params = [];
if ($search) {
    $where .= " AND last_name ILIKE $1";
    $params[] = "%$search%";
}
<<<<<<< HEAD
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
=======
$countQuery = "SELECT COUNT(*) FROM students WHERE $where";
$totalApplicants = pg_fetch_assoc(pg_query_params($connection, $countQuery, $params))['count'];
$totalPages = max(1, ceil($totalApplicants / $perPage));

$query = "SELECT * FROM students WHERE $where ORDER BY last_name " . ($sort === 'desc' ? 'DESC' : 'ASC') . " LIMIT $perPage OFFSET $offset";
$applicants = $params ? pg_query_params($connection, $query, $params) : pg_query($connection, $query);
>>>>>>> 09807c52616f708245bbb6ea55f992ea78af2157

// Table rendering function with live preview
function render_table($applicants, $connection) {
    ob_start();
    ?>
    <table class="table table-bordered align-middle">
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
        <?php if (pg_num_rows($applicants) === 0): ?>
            <tr><td colspan="5" class="text-center no-applicants">No applicants found.</td></tr>
        <?php else: ?>
            <?php while ($applicant = pg_fetch_assoc($applicants)) {
                $student_id = $applicant['student_id'];
                $isComplete = check_documents($connection, $student_id);
                ?>
                <tr>
                    <td data-label="Name">
                        <?= htmlspecialchars("{$applicant['last_name']}, {$applicant['first_name']} {$applicant['middle_name']}") ?>
                    </td>
                    <td data-label="Contact">
                        <?= htmlspecialchars($applicant['mobile']) ?>
                    </td>
                    <td data-label="Email">
                        <?= htmlspecialchars($applicant['email']) ?>
                    </td>
                    <td data-label="Documents">
                        <span class="badge <?= $isComplete ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $isComplete ? 'Complete' : 'Incomplete' ?>
                        </span>
                    </td>
                    <td data-label="Action">
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
                                        $filePath = htmlspecialchars($doc['file_path']);
                                        if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $filePath)) {
                                            // Show image preview
                                            echo "<div class='doc-preview mb-3'>
                                                    <strong>$label:</strong><br>
                                                    <img src='$filePath' alt='$label' class='img-fluid rounded border' style='max-height: 200px; max-width: 100%;'>
                                                  </div>";
                                        } elseif (preg_match('/\.pdf$/i', $filePath)) {
                                            // Show embedded PDF
                                            echo "<div class='doc-preview mb-3'>
                                                    <strong>$label:</strong><br>
                                                    <iframe src='$filePath' width='100%' height='400' style='border: 1px solid #ccc;'></iframe>
                                                  </div>";
                                        } else {
                                            // Fallback link
                                            echo "<p><strong>$label:</strong> <a href='$filePath' target='_blank'>View</a></p>";
                                        }
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
    <?php
    return ob_get_clean();
}

// Pagination rendering function
function render_pagination($page, $totalPages) {
    if ($totalPages <= 1) return '';
    ?>
    <nav aria-label="Table pagination" class="mt-3">
        <ul class="pagination justify-content-end">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page-1 ?>">&lt;</a>
            </li>
            <li class="page-item">
                <span class="page-link">
                    Page <input type="number" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" id="manualPage" style="width:55px; text-align:center;" /> of <?= $totalPages ?>
                </span>
            </li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page+1 ?>">&gt;</a>
            </li>
        </ul>
    </nav>
    <?php
}

// --------- AJAX handler ---------
if ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' === 'XMLHttpRequest') {
    echo render_table($applicants, $connection);
    render_pagination($page, $totalPages);
    exit;
}

// Normal page output below...
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
<<<<<<< HEAD
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
=======
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet"/>
>>>>>>> 09807c52616f708245bbb6ea55f992ea78af2157
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
<<<<<<< HEAD
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
=======
      <div class="section-header mb-3">
        <h2 class="fw-bold text-primary">
          <i class="bi bi-person-vcard" style="font-size: 2.1rem;vertical-align: -0.3em"></i>
          Manage Applicants
        </h2>
      </div>
      <!-- Filter Container -->
      <div class="filter-container card shadow-sm mb-4 p-3">
        <form class="row g-3" id="filterForm" method="GET">
          <div class="col-sm-4">
            <label class="form-label fw-bold" style="color:#1182FF;">Sort by Surname</label>
            <select name="sort" class="form-select">
              <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>A to Z</option>
              <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-bold" style="color:#1182FF;">Search by Surname</label>
            <input type="text" name="search_surname" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Enter surname...">
          </div>
          <div class="col-sm-4 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Apply Filters</button>
            <button type="button" class="btn btn-secondary w-100" id="clearFiltersBtn">Clear</button>
          </div>
        </form>
>>>>>>> 09807c52616f708245bbb6ea55f992ea78af2157
      </div>
      <!-- Applicants Table -->
<<<<<<< HEAD
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
=======
      <div class="table-responsive" id="tableWrapper">
        <?= render_table($applicants, $connection) ?>
      </div>
      <div id="pagination">
        <?php render_pagination($page, $totalPages); ?>
>>>>>>> 09807c52616f708245bbb6ea55f992ea78af2157
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
