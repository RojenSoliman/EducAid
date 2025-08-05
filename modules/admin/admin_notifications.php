<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}

// Pagination setup
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Notification query
$baseSql = "
  SELECT 'Announcement' as type, 'Announcement: ' || title AS message, posted_at AS created_at FROM announcements
  UNION ALL
  SELECT 'Slot' as type, 'Slot released: ' || slot_count || ' slots for ' || semester || ' ' || academic_year AS message, created_at FROM signup_slots
  UNION ALL
  SELECT 'Schedule' as type, 'Schedule created for student ' || student_id || ' on ' || distribution_date::text AS message, created_at FROM schedules
  UNION ALL
  SELECT 'System' as type, 'Admin Event: ' || message AS message, created_at FROM admin_notifications
";
$countSql = "SELECT COUNT(*) AS total FROM ($baseSql) AS sub";
$countRes = pg_query($connection, $countSql);
$total = $countRes ? (int)pg_fetch_assoc($countRes)['total'] : 0;
$lastPage = (int)ceil($total / $limit);

$adminNotifSql = $baseSql . " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$adminNotifRes = @pg_query($connection, $adminNotifSql);
$adminNotifs = $adminNotifRes ? pg_fetch_all($adminNotifRes) : [];

// Function to get notification icon based on type
function getNotificationIcon($type) {
    switch ($type) {
        case 'Announcement':
            return 'bi-megaphone-fill text-primary';
        case 'Slot':
            return 'bi-calendar-plus-fill text-success';
        case 'Schedule':
            return 'bi-clock-fill text-info';
        case 'System':
            return 'bi-gear-fill text-warning';
        default:
            return 'bi-info-circle-fill text-secondary';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Notifications</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/admin/notification.css" />
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
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
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h3 class="fw-bold mb-0"><i class="bi bi-bell-fill me-2 text-warning"></i>Notifications
            <span class="badge bg-danger" id="unread-count">0</span>
          </h3>
        </div>

        <!-- Filter Controls -->
        <div class="notification-actions-desktop d-none d-md-flex justify-content-between align-items-center mb-4">
          <div class="btn-group" role="group">
            <button class="btn btn-outline-primary active">Unread</button>
            <button class="btn btn-outline-secondary">Read</button>
          </div>
          <button id="mark-all-read" class="btn btn-outline-success">
            <i class="bi bi-envelope-open"></i> Mark All as Read
          </button>
        </div>

        <div class="notification-actions-mobile d-flex d-md-none mb-3">
          <button class="btn btn-outline-primary flex-fill me-1">Unread</button>
          <button class="btn btn-outline-secondary flex-fill mx-1">Read</button>
          <button class="btn btn-outline-success flex-fill ms-1" id="mark-all-read">
            <i class="bi bi-envelope-open"></i>
          </button>
        </div>

        <!-- Notifications List -->
        <div class="notifications-list">
          <?php if (empty($adminNotifs)): ?>
            <div id="empty-state">No notifications available.</div>
          <?php else: ?>
            <?php foreach ($adminNotifs as $note): ?>
              <div class="notification-card unread">
                <div class="d-flex justify-content-between align-items-center notification-header">
                  <div>
                    <span class="icon-box text-primary bg-light me-3">
                      <i class="<?php echo getNotificationIcon($note['type']); ?>"></i>
                    </span>
                    <?php echo htmlspecialchars($note['message']); ?>
                  </div>
                  <div class="action-buttons">
                    <i class="bi bi-envelope" role="button" title="Mark as Read"></i>
                    <i class="bi bi-trash text-danger" role="button" title="Delete"></i>
                  </div>
                </div>
                <div class="text-muted small ms-5 mt-1">Posted: <?= date("F j, Y, g:i a", strtotime($note['created_at'])) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Pagination -->
        <nav class="mt-4 d-flex justify-content-center">
          <ul class="pagination mb-0">
            <?php if ($page > 1): ?>
              <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>"><i class="bi bi-chevron-left"></i></a></li>
            <?php endif; ?>
            <?php if ($page < $lastPage): ?>
              <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>"><i class="bi bi-chevron-right"></i></a></li>
            <?php endif; ?>
          </ul>
        </nav>
      </div>
    </section>
  </div>

  <!-- Snackbar -->
  <div id="undo-snackbar" class="undo-snackbar"></div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/sidebar.js"></script>
  <script src="../../assets/js/admin/notifications.js"></script>
</body>
</html>

<?php pg_close($connection); ?>
