<?php
/** @phpstan-ignore-file */
/** @phpstan-ignore-file */
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: index.php");
  exit;
}
// Fetch admin notifications: announcements, slots, schedules
$adminNotifSql = "
  SELECT 'Announcement: ' || title AS message, posted_at AS created_at
    FROM announcements
  UNION ALL
  SELECT 'Slot released: ' || slot_count || ' slots for ' || semester || ' ' || academic_year AS message, created_at
    FROM signup_slots
  UNION ALL
  SELECT 'Schedule created for student ' || student_id || ' on ' || distribution_date::text AS message, created_at
    FROM schedules
  ORDER BY created_at DESC
";
// @phpstan-ignore-next-line
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
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php pg_close($connection); ?>
