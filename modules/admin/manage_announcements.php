<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
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
    pg_query_params($connection, $query, [$title, $remarks]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?posted=1');
    exit;
}
// Handle toggle (unpost/repost) actions for announcements
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    $aid = (int) $_POST['announcement_id'];
    if ($_POST['toggle_active'] == '1') {
        // Repost: deactivate all then activate this
        pg_query($connection, "UPDATE announcements SET is_active = FALSE");
        pg_query_params($connection, "UPDATE announcements SET is_active = TRUE WHERE announcement_id = $1", [$aid]);
    } else {
        // Unpost: deactivate this only
        pg_query_params($connection, "UPDATE announcements SET is_active = FALSE WHERE announcement_id = $1", [$aid]);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
// Check for success flag
$posted = isset($_GET['posted']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Announcements</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
    <link rel="stylesheet" href="../../assets/css/admin_homepage.css">
    <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
</head>
<body class="bg-light">



<div class="container-fluid">
  <div class="row">
    <!-- Sidebar -->
    <?php include '../../includes/admin/admin_sidebar.php'; ?>

    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

    <!-- Main -->
    <main class="col-md-10 ms-sm-auto px-4 py-4">
        <div class="container py-4">
            <!-- New Announcement Form -->
            <h2>Post New Announcement</h2>
            <form id="announcementForm" method="POST" class="mb-4">
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-control" required></textarea>
                </div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#confirmPostModal">Post Announcement</button>
                <!-- Confirmation Modal -->
                <div class="modal fade" id="confirmPostModal" tabindex="-1" aria-labelledby="confirmPostModalLabel" aria-hidden="true">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title" id="confirmPostModalLabel">Confirm Post</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">Are you sure you want to post this announcement?</div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="post_announcement" class="btn btn-primary">Yes, Post</button>
                      </div>
                    </div>
                  </div>
                </div>
             </form>

            <!-- Success Message -->
            <?php if (isset($_GET['posted']) && $_GET['posted'] == 1): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    Announcements posted successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Existing Announcements Listing -->
            <h2>Existing Announcements</h2>
            <?php
            // Fetch all announcements for pagination (include active status)
            $annRes = pg_query($connection, "SELECT announcement_id, title, remarks, posted_at, is_active FROM announcements ORDER BY posted_at DESC");
            $announcements = [];
            while ($a = pg_fetch_assoc($annRes)) {
                $announcements[] = $a;
            }
            pg_free_result($annRes);
            ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <button id="prev-btn" class="btn btn-outline-secondary">&laquo;</button>
                <span>Showing <span id="page-info"></span></span>
                <button id="next-btn" class="btn btn-outline-secondary">&raquo;</button>
            </div>
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
            <script>
            // Load announcements including active flag
            const announcements = <?php echo json_encode($announcements); ?>;
            let currentPage = 0;
            const pageSize = 5;
            const totalPages = Math.ceil(announcements.length / pageSize);
            const latestId = announcements.length ? announcements[0].announcement_id : null;
            function renderPage() {
              const start = currentPage * pageSize;
              const slice = announcements.slice(start, start + pageSize);
              const tbody = document.getElementById('ann-body');
              tbody.innerHTML = '';
              slice.forEach(a => {
                const isLatest = a.announcement_id === latestId;
                const badge = isLatest
                  ? '<span class="badge bg-success">Active</span>'
                  : '<span class="badge bg-danger">Inactive</span>';
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
              document.getElementById('page-info').textContent = `${start + 1}-${Math.min(start + pageSize, announcements.length)} of ${announcements.length}`;
              document.getElementById('prev-btn').disabled = currentPage === 0;
              document.getElementById('next-btn').disabled = currentPage >= totalPages - 1;
            }
            document.getElementById('prev-btn').addEventListener('click', () => { if (currentPage > 0) { currentPage--; renderPage(); } });
            document.getElementById('next-btn').addEventListener('click', () => { if (currentPage < totalPages - 1) { currentPage++; renderPage(); } });
            renderPage();
            </script>
        </div>
    </main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>


</body>
</html>
