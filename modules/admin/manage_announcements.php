<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Handle form submission for general announcements
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_announcement'])) {
    $title = trim($_POST['title']);
    $remarks = trim($_POST['remarks']);

    // Deactivate any previously active announcement
    pg_query($connection, "UPDATE announcements SET is_active = FALSE");

    // Insert new announcement as active
    $query = "INSERT INTO announcements (title, remarks, is_active) VALUES ($1, $2, TRUE)";
    $result = pg_query_params($connection, $query, [$title, $remarks]);
    
    // Add admin notification for announcement creation
    if ($result) {
        $notification_msg = "New announcement posted: " . $title;
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?posted=1');
    exit;
}

// Check for success flag
$posted = isset($_GET['posted']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Announcements</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/admin/manage_announcements.css" />
  <style>
    .card:hover {
      transform: none !important;
      transition: none !important;
    }
    .card h5 {
      font-size: 1.25rem;
      font-weight: 600;
      color: #333;
    }
    .pagination-controls {
      display: flex;
      gap: 0.5rem;
      align-items: center;
      justify-content: center;
      margin-top: 1rem;
    }
    .pagination-controls input[type='number'] {
      width: 60px;
      text-align: center;
    }
  </style>
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
      <h2 class="fw-bold mb-4"><i class="bi bi-megaphone-fill text-primary me-2"></i>Manage Announcements</h2>

      <div class="card p-4 mb-4">
        <form method="POST">
          <div class="mb-3">
            <label class="form-label">Title</label>
            <input type="text" name="title" class="form-control form-control-lg" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Remarks</label>
            <textarea name="remarks" class="form-control form-control-lg" rows="4" required></textarea>
          </div>
          <button type="submit" name="post_announcement" class="btn btn-primary">
            <i class="bi bi-send me-1"></i> Post Announcement
          </button>
        </form>
        <?php if ($posted): ?>
          <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
            Announcement posted successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
      </div>

      <h2 class="fw-bold mb-4"><i class="bi bi-card-text text-primary me-2"></i>Existing Announcements</h2>
      <div class="card p-4">
        <?php
        $annRes = pg_query($connection, "SELECT announcement_id, title, remarks, posted_at, is_active FROM announcements ORDER BY posted_at DESC");
        $announcements = [];
        while ($a = pg_fetch_assoc($annRes)) {
          $announcements[] = $a;
        }
        pg_free_result($annRes);
        ?>
        <div class="table-responsive">
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Title</th>
                <th>Remarks</th>
                <th>Posted At</th>
                <th>Active</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="ann-body"></tbody>
          </table>
        </div>
        <div class="pagination-controls">
          <label>Page:</label>
          <input type="number" id="page-input" min="1" value="1">
          <button id="prev-btn" class="btn btn-outline-secondary btn-sm">&laquo;</button>
          <button id="next-btn" class="btn btn-outline-secondary btn-sm">&raquo;</button>
          <span id="page-info" class="ms-2"></span>
        </div>
      </div>
    </div>
  </section>
</div>
<script>
const announcements = <?php echo json_encode($announcements); ?>;
let currentPage = 0;
const pageSize = 5;
const totalPages = Math.ceil(announcements.length / pageSize);
const latestId = announcements.length > 0 ? announcements[0].announcement_id : null;

function renderPage() {
  const start = currentPage * pageSize;
  const slice = announcements.slice(start, start + pageSize);
  const tbody = document.getElementById('ann-body');
  tbody.innerHTML = '';
  slice.forEach(a => {
    const isLatest = a.announcement_id === latestId;
    const badge = isLatest
      ? '<span class="badge bg-success">Active</span>'
      : '<span class="badge bg-secondary">Inactive</span>';
    const btnLabel = isLatest ? 'Unpost' : 'Repost';
    const btnClass = isLatest ? 'danger' : 'success';
    const toggleValue = isLatest ? 0 : 1;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${a.title}</td>
      <td>${a.remarks}</td>
      <td>${a.posted_at}</td>
      <td>${badge}</td>
      <td>
        <form method="POST" class="d-inline">
          <input type="hidden" name="announcement_id" value="${a.announcement_id}">
          <input type="hidden" name="toggle_active" value="${toggleValue}">
          <button type="submit" class="btn btn-sm btn-outline-${btnClass}">${btnLabel}</button>
        </form>
      </td>`;
    tbody.appendChild(tr);
  });
  document.getElementById('page-info').textContent = `Page ${currentPage + 1} of ${totalPages}`;
  document.getElementById('prev-btn').disabled = currentPage === 0;
  document.getElementById('next-btn').disabled = currentPage >= totalPages - 1;
  document.getElementById('page-input').value = currentPage + 1;
}

renderPage();
document.getElementById('prev-btn').addEventListener('click', () => {
  if (currentPage > 0) {
    currentPage--;
    renderPage();
  }
});
document.getElementById('next-btn').addEventListener('click', () => {
  if (currentPage < totalPages - 1) {
    currentPage++;
    renderPage();
  }
});
document.getElementById('page-input').addEventListener('change', (e) => {
  const value = parseInt(e.target.value);
  if (!isNaN(value) && value >= 1 && value <= totalPages) {
    currentPage = value - 1;
    renderPage();
  }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>