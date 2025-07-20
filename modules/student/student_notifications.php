<?php
include '../../config/database.php';
// Check if student is logged in
session_start();
if (!isset($_SESSION['student_username'])) {
    header("Location: student_login.php");
    exit;
}
$student_id = $_SESSION['student_id'];
// Fetch student notifications
$notifRes = pg_query_params($connection,
    "SELECT message, created_at FROM notifications WHERE student_id = $1 ORDER BY created_at DESC",
    [$student_id]
);
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
</head>
<body>
  <div class="container-fluid">
    <div class="row">
      <!-- Include Sidebar -->
      <?php include '../../includes/student/student_sidebar.php' ?>
      <!-- Main Content Area -->
      <section class="home-section" id="page-content-wrapper">
        <nav>
            <div class="sidebar-toggle px-4 py-3">
            <i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i>
            </div>
        </nav>
        <div class="container py-5">
            <!-- Student Notifications -->
            <div class="row mb-4">
              <div class="col">
                <div class="card">
                  <div class="card-header">
                    <h5 class="mb-0">Notifications</h5>
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
  </div>
    <script src="../../assets/js/homepage.js"></script>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>