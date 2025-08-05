<?php
include '../../config/database.php';
// Check if student is logged in
session_start();
if (!isset($_SESSION['student_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
// Get student ID for operations
$student_id = $_SESSION['student_id'];
// Handle clear all notifications via POST and set flash
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_notifications'])) {
    // Delete all notifications for this student
    $deleteSql = "DELETE FROM notifications WHERE student_id = " . intval($student_id);
    /** @phpstan-ignore-next-line */
    @pg_query($connection, $deleteSql);
    $_SESSION['notif_cleared'] = true;
    // Redirect back to avoid form resubmission
    header("Location: student_notifications.php");
    exit;
}
// Flash for cleared notifications
$flash_cleared = $_SESSION['notif_cleared'] ?? false;
unset($_SESSION['notif_cleared']);
$selectSql = "SELECT message, created_at FROM notifications WHERE student_id = " . intval($student_id) . " ORDER BY created_at DESC";
/** @phpstan-ignore-next-line */
@$notifRes = pg_query($connection, $selectSql);
$notifications = $notifRes ? pg_fetch_all($notifRes) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Notifications</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/student/sidebar.css" />
  <style>
    body:not(.js-ready) .sidebar { visibility: hidden; transition: none !important; }
  </style>
</head>
<body>
  <div id="wrapper">
    <!-- Sidebar -->
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
    <!-- Page Content -->
    <section class="home-section" id="page-content-wrapper">
      <nav>
        <div class="sidebar-toggle px-4 py-3">
          <i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i>
        </div>
      </nav>
      <div class="container py-5">
        <?php if (!empty($flash_cleared)): ?>
          <div class="alert alert-success text-center">
            All notifications cleared.
          </div>
        <?php endif; ?>
        <!-- Student Notifications -->
        <div class="row mb-4">
          <div class="col">
            <div class="card">
              <div class="card-header d-flex align-items-center">
                <h5 class="mb-0">Notifications</h5>
                <form method="POST" class="ms-auto" onsubmit="return confirm('Are you sure you want to clear all notifications?');">
                  <input type="hidden" name="clear_notifications" value="1" />
                  <button type="submit" class="btn btn-sm btn-danger">Clear All</button>
                </form>
              </div>
              <div class="card-body">
                <?php if (empty($notifications)): ?>
                  <p class="text-center">No notifications at this time.</p>
                <?php else: ?>
                  <ul class="list-group list-group-flush">
                    <?php foreach ($notifications as $note): ?>
                      <li class="list-group-item">
                        <?php echo htmlspecialchars($note['message']); ?><br>
                        <small class="text-muted"><?php echo date("F j, Y, g:i a", strtotime($note['created_at'])); ?></small>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
  <script src="../../assets/js/homepage.js"></script>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
