<?php
/** @phpstan-ignore-file */
/** @phpstan-ignore-file */
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: index.php");
  exit;
}
// Pagination setup
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)
    $_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
// Base notifications union
$baseSql = "
  SELECT 'Announcement: ' || title AS message, posted_at AS created_at
    FROM announcements
  UNION ALL
  SELECT 'Slot released: ' || slot_count || ' slots for ' || semester || ' ' || academic_year AS message, created_at
    FROM signup_slots
  UNION ALL
  SELECT 'Schedule created for student ' || student_id || ' on ' || distribution_date::text AS message, created_at
    FROM schedules
  UNION ALL
  SELECT 'Admin Event: ' || message AS message, created_at FROM admin_notifications
";
// Count total notifications
$countSql = "SELECT COUNT(*) AS total FROM (" . $baseSql . ") AS sub";
/** @phpstan-ignore-next-line */
$countRes = pg_query($connection, $countSql);
$total = $countRes ? (int)pg_fetch_assoc($countRes)['total'] : 0;
$lastPage = (int)ceil($total / $limit);
// Paginated query
$adminNotifSql = $baseSql . " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
// Suppress static analysis for pg_query with connection arg
/** @phpstan-ignore-next-line */
@$adminNotifRes = pg_query($connection, $adminNotifSql);
$adminNotifs = $adminNotifRes ? pg_fetch_all($adminNotifRes) : [];
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
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
</head>
<body>
  <div id="wrapper">
    <!-- Sidebar (comes first for layout logic to work) -->
    <?php include '../../includes/admin/admin_sidebar.php'; ?>

    <!-- Backdrop for mobile sidebar -->
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

    <!-- Main Section -->
    <section class="home-section p-4" id="mainContent">
      <h2>Notifications</h2>
      <div class="card mt-3">
        <div class="card-body">
          <?php if (empty($adminNotifs)): ?>
            <p class="text-center mb-0">No notifications at this time.</p>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($adminNotifs as $note): ?>
                <li class="list-group-item">
                  <?php echo htmlspecialchars($note['message']); ?><br>
                  <small class="text-muted"><?php echo date("F j, Y, g:i a", strtotime($note['created_at'])); ?></small>
                </li>
              <?php endforeach; ?>
            </ul>
            <!-- New Pagination controls -->
            <nav class="mt-3 d-flex justify-content-center">
              <ul class="pagination mb-0">
                <?php if ($page > 1): ?>
                  <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>"><i class="bi bi-chevron-left"></i></a>
                  </li>
                <?php endif; ?>
                <?php if ($page < $lastPage): ?>
                  <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>"><i class="bi bi-chevron-right"></i></a>
                  </li>
                <?php endif; ?>
              </ul>
            </nav>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php pg_close($connection); ?>
