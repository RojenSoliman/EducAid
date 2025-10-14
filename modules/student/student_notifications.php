<?php
include '../../config/database.php';
session_start();
if (!isset($_SESSION['student_username'])) { header("Location: ../../unified_login.php"); exit; }
$student_id = $_SESSION['student_id'];

// Detect if is_read column exists
$hasReadColumn = false;
$colCheck = @pg_query($connection, "SELECT 1 FROM information_schema.columns WHERE table_name='notifications' AND column_name='is_read' LIMIT 1");
if ($colCheck && pg_num_rows($colCheck) > 0) { $hasReadColumn = true; }

// Filters & pagination similar to admin UI
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$limit = 15; // slightly fewer for more open spacing
$offset = ($page - 1) * $limit;
$filterType = isset($_GET['filter']) ? $_GET['filter'] : 'all';

$whereFilter = 'WHERE student_id = $1';
if ($hasReadColumn) {
  if ($filterType === 'unread') { $whereFilter .= " AND (is_read = FALSE OR is_read IS NULL)"; }
  elseif ($filterType === 'read') { $whereFilter .= " AND is_read = TRUE"; }
}

// Count total for current filter
$countSql = "SELECT COUNT(*) AS total FROM notifications $whereFilter";
$countRes = @pg_query_params($connection, $countSql, [$student_id]);
$total = $countRes ? (int)pg_fetch_assoc($countRes)['total'] : 0;
$lastPage = $total > 0 ? (int)ceil($total / $limit) : 1;

// Main notification query
$selectCols = $hasReadColumn ? 'notification_id, message, created_at, COALESCE(is_read,FALSE) as is_read' : 'notification_id, message, created_at';
$notifSql = "SELECT $selectCols FROM notifications $whereFilter ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$notifRes = @pg_query_params($connection, $notifSql, [$student_id]);
$notifications = $notifRes ? pg_fetch_all($notifRes) : [];

// Unread count (independent of filter) if column present
$unreadCount = 0;
if ($hasReadColumn) {
  $unreadRes = @pg_query_params($connection, "SELECT COUNT(*) AS c FROM notifications WHERE student_id = $1 AND (is_read = FALSE OR is_read IS NULL)", [$student_id]);
  if ($unreadRes) { $unreadCount = (int)pg_fetch_assoc($unreadRes)['c']; }
}

function getNotificationIcon($message) {
  // rudimentary keyword-based icon selection
  $m = strtolower($message);
  if (str_contains($m,'schedule')) return 'bi-clock-fill text-info';
  if (str_contains($m,'slot')) return 'bi-calendar-plus-fill text-success';
  if (str_contains($m,'announcement')) return 'bi-megaphone-fill text-primary';
  if (str_contains($m,'system') || str_contains($m,'update')) return 'bi-gear-fill text-warning';
  return 'bi-info-circle-fill text-secondary';
}
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
  <!-- Reuse admin notification stylesheet for 1:1 UI parity -->
  <link rel="stylesheet" href="../../assets/css/admin/notification.css" />
  <style>body:not(.js-ready) .sidebar { visibility:hidden; }</style>
</head>
<body>
  <!-- Student Topbar -->
  <?php include __DIR__ . '/../../includes/student/student_topbar.php'; ?>

  <div id="wrapper" style="padding-top: var(--topbar-h);">
    <!-- Sidebar -->
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
    
    <!-- Student Header -->
    <?php include __DIR__ . '/../../includes/student/student_header.php'; ?>
    
    <!-- Page Content -->
    <section class="home-section" id="page-content-wrapper">
      <div class="container-fluid py-4 px-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h3 class="fw-bold mb-0"><i class="bi bi-bell-fill me-2 text-warning"></i>Notifications
            <?php if ($hasReadColumn): ?><span class="badge bg-danger" id="unread-count"><?php echo $unreadCount; ?></span><?php endif; ?>
          </h3>
        </div>

        <!-- Filter Controls (Desktop) -->
        <div class="notification-actions-desktop d-none d-md-flex justify-content-between align-items-center mb-4">
          <div class="btn-group" role="group">
            <a href="?filter=unread&page=1" class="btn btn-outline-primary <?php echo $filterType==='unread'?'active':''; ?><?php if(!$hasReadColumn) echo ' disabled'; ?>">Unread<?php if($hasReadColumn): ?> (<?php echo $unreadCount; ?>)<?php endif; ?></a>
            <a href="?filter=read&page=1" class="btn btn-outline-secondary <?php echo $filterType==='read'?'active':''; ?><?php if(!$hasReadColumn) echo ' disabled'; ?>">Read</a>
            <a href="?filter=all&page=1" class="btn btn-outline-info <?php echo $filterType==='all'?'active':''; ?>">All</a>
          </div>
          <?php if ($hasReadColumn): ?>
          <button id="mark-all-read" class="btn btn-outline-success">
            <i class="bi bi-envelope-open"></i> Mark All as Read
          </button>
          <?php endif; ?>
        </div>

        <!-- Filter Controls (Mobile) -->
        <div class="notification-actions-mobile d-flex d-md-none mb-3">
          <a href="?filter=unread&page=1" class="btn btn-outline-primary flex-fill me-1 <?php echo $filterType==='unread'?'active':''; ?><?php if(!$hasReadColumn) echo ' disabled'; ?>">Unread</a>
          <a href="?filter=read&page=1" class="btn btn-outline-secondary flex-fill mx-1 <?php echo $filterType==='read'?'active':''; ?><?php if(!$hasReadColumn) echo ' disabled'; ?>">Read</a>
          <a href="?filter=all&page=1" class="btn btn-outline-info flex-fill me-1 <?php echo $filterType==='all'?'active':''; ?>">All</a>
          <?php if ($hasReadColumn): ?>
          <button class="btn btn-outline-success flex-fill ms-1" id="mark-all-read">
            <i class="bi bi-envelope-open"></i>
          </button>
          <?php endif; ?>
        </div>

        <!-- Notifications List -->
        <div class="notifications-list">
          <?php if (empty($notifications)): ?>
            <div id="empty-state">No notifications available.</div>
          <?php else: ?>
            <?php foreach ($notifications as $note): ?>
              <?php
                $is_read = $hasReadColumn ? ($note['is_read'] === 't' || $note['is_read'] === true) : true;
                // Derive a pseudo type for consistent icon mapping
                $msgLower = strtolower($note['message']);
                if (str_contains($msgLower,'announcement')) $type='Announcement';
                elseif (str_contains($msgLower,'slot')) $type='Slot';
                elseif (str_contains($msgLower,'schedule')) $type='Schedule';
                else $type='System';
                // Reuse admin icon logic pattern
                switch ($type) {
                  case 'Announcement': $iconClass='bi-megaphone-fill text-primary'; break;
                  case 'Slot': $iconClass='bi-calendar-plus-fill text-success'; break;
                  case 'Schedule': $iconClass='bi-clock-fill text-info'; break;
                  case 'System': default: $iconClass='bi-gear-fill text-warning'; break;
                }
              ?>
              <div class="notification-card <?php echo $is_read ? 'read' : 'unread'; ?>" 
                   data-notification-id="<?php echo $note['notification_id']; ?>" 
                   data-notification-type="<?php echo htmlspecialchars($type); ?>">
                <div class="d-flex justify-content-between align-items-center notification-header">
                  <div>
                    <span class="icon-box text-primary bg-light me-3">
                      <i class="<?php echo $iconClass; ?>"></i>
                    </span>
                    <?php echo htmlspecialchars($note['message']); ?>
                  </div>
                  <div class="action-buttons">
                    <?php if ($hasReadColumn && !$is_read): ?>
                      <i class="bi bi-envelope mark-read-btn" role="button" title="Mark as Read" 
                         data-notification-id="<?php echo $note['notification_id']; ?>"></i>
                    <?php else: ?>
                      <?php if ($hasReadColumn): ?><i class="bi bi-envelope-open text-success" title="Already Read"></i><?php endif; ?>
                    <?php endif; ?>
                    <i class="bi bi-trash text-danger delete-btn" role="button" title="Delete"></i>
                  </div>
                </div>
                <div class="text-muted small ms-5 mt-1">Posted: <?php echo date("F j, Y, g:i a", strtotime($note['created_at'])); ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Pagination -->
        <nav class="mt-4 d-flex justify-content-center">
          <ul class="pagination mb-0">
            <?php if ($page > 1): ?>
              <li class="page-item">
                <a class="page-link" href="?filter=<?php echo $filterType; ?>&page=<?php echo $page - 1; ?>">
                  <i class="bi bi-chevron-left"></i>
                </a>
              </li>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($lastPage, $page + 2); $i++): ?>
              <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                <a class="page-link" href="?filter=<?php echo $filterType; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
              </li>
            <?php endfor; ?>
            <?php if ($page < $lastPage): ?>
              <li class="page-item">
                <a class="page-link" href="?filter=<?php echo $filterType; ?>&page=<?php echo $page + 1; ?>">
                  <i class="bi bi-chevron-right"></i>
                </a>
              </li>
            <?php endif; ?>
          </ul>
        </nav>
      </div>
    </section>
  </div>
  <script src="../../assets/js/student/sidebar.js"></script>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <!-- Reuse admin notifications behavior script -->
  <script src="../../assets/js/admin/notifications.js"></script>
  <div id="undo-snackbar" class="undo-snackbar"></div>
</body>
</html>
