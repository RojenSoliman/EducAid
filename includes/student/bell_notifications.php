<?php
// Bell Notification Component for Student Header
// Similar structure to admin bell notifications but for student-specific notifications

// Get unread notifications count
function getStudentUnreadNotificationCount($connection, $student_id) {
    $query = "SELECT COUNT(*) as unread_count FROM student_notifications 
              WHERE student_id = $1 AND is_read = FALSE 
              AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)";
    $result = pg_query_params($connection, $query, [$student_id]);
    
    if ($result) {
        $row = pg_fetch_assoc($result);
        return (int)$row['unread_count'];
    }
    
    return 0;
}

// Get recent notifications
function getStudentRecentNotifications($connection, $student_id, $limit = 5) {
    $query = "SELECT notification_id, title, message, type, priority, created_at, action_url, is_read
              FROM student_notifications 
              WHERE student_id = $1 AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
              ORDER BY created_at DESC 
              LIMIT $2";
    $result = pg_query_params($connection, $query, [$student_id, $limit]);
    
    $notifications = [];
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $notifications[] = $row;
        }
    }
    
    return $notifications;
}

// Get notification count and recent notifications
$student_id = $_SESSION['student_id'] ?? 0;
$student_unread_count = getStudentUnreadNotificationCount($connection, $student_id);
$student_recent_notifications = getStudentRecentNotifications($connection, $student_id);
?>

<button class="student-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
    <i class="bi bi-bell"></i>
    <?php if ($student_unread_count > 0): ?>
        <span class="badge rounded-pill bg-danger">
            <?= $student_unread_count > 99 ? '99+' : $student_unread_count ?>
        </span>
    <?php endif; ?>
</button>
<ul class="dropdown-menu dropdown-menu-end shadow-sm">
    <li><h6 class="dropdown-header d-flex justify-content-between align-items-center">
        <span>Notifications</span>
        <?php if ($student_unread_count > 0): ?>
            <button class="btn btn-sm btn-outline-primary" onclick="studentMarkAllAsRead()">
                Mark all read
            </button>
        <?php endif; ?>
    </h6></li>
    
    <li><hr class="dropdown-divider"/></li>
    
    <?php if (empty($student_recent_notifications)): ?>
        <li><div class="dropdown-item-text text-muted text-center py-3">
            <i class="bi bi-bell-slash fs-4 d-block mb-2"></i>
            No notifications
        </div></li>
    <?php else: ?>
        <?php foreach ($student_recent_notifications as $notification): ?>
            <li>
                <a class="dropdown-item <?= !$notification['is_read'] ? 'bg-light' : '' ?>" 
                   href="<?= htmlspecialchars($notification['action_url'] ?? 'student_notifications.php') ?>"
                   onclick="studentMarkAsRead(<?= $notification['notification_id'] ?>)">
                    <div class="d-flex">
                        <div class="flex-shrink-0 me-2">
                            <i class="bi bi-<?= getStudentNotificationIcon($notification['type']) ?> 
                               text-<?= getStudentNotificationColor($notification['priority']) ?>"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-medium"><?= htmlspecialchars($notification['title']) ?></div>
                            <small class="text-muted d-block">
                                <?= htmlspecialchars(substr($notification['message'], 0, 50)) ?>
                                <?= strlen($notification['message']) > 50 ? '...' : '' ?>
                            </small>
                            <small class="text-muted">
                                <?= studentTimeAgo($notification['created_at']) ?>
                                <?php if (!$notification['is_read']): ?>
                                    <span class="badge bg-primary ms-1">New</span>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </a>
            </li>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <li><hr class="dropdown-divider"/></li>
    <li><a class="dropdown-item text-center fw-medium" href="student_notifications.php">
        <i class="bi bi-bell me-1"></i>View all notifications
    </a></li>
</ul>

<script>
// Mark single notification as read
function studentMarkAsRead(notificationId) {
    fetch('../../api/student/mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    }).then(response => response.json())
      .then(data => {
          if (data.success) {
              studentUpdateNotificationBadge();
          }
      });
}

// Mark all notifications as read
function studentMarkAllAsRead() {
    fetch('../../api/student/mark_all_notifications_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    }).then(response => response.json())
      .then(data => {
          if (data.success) {
              location.reload(); // Refresh to update the UI
          }
      });
}

// Update notification badge count
function studentUpdateNotificationBadge() {
    fetch('../../api/student/get_notification_count.php')
        .then(response => response.json())
        .then(data => {
            const badge = document.querySelector('#studentNotificationDropdown .badge');
            if (data.count > 0) {
                if (badge) {
                    badge.textContent = data.count > 99 ? '99+' : data.count;
                } else {
                    // Create badge if it doesn't exist
                    const bell = document.querySelector('#studentNotificationDropdown i');
                    const newBadge = document.createElement('span');
                    newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                    newBadge.textContent = data.count > 99 ? '99+' : data.count;
                    bell.parentNode.appendChild(newBadge);
                }
            } else if (badge) {
                badge.remove();
            }
        });
}
</script>

<?php
// Helper functions
function getStudentNotificationIcon($type) {
    $icons = [
        'announcement' => 'megaphone',
        'document' => 'file-earmark-check',
        'schedule' => 'calendar-event',
        'system' => 'gear',
        'security' => 'shield-lock',
        'info' => 'info-circle',
        'warning' => 'exclamation-triangle',
        'error' => 'x-circle',
        'success' => 'check-circle'
    ];
    
    return $icons[$type] ?? 'info-circle';
}

function getStudentNotificationColor($priority) {
    $colors = [
        'high' => 'danger',
        'medium' => 'warning',
        'low' => 'info'
    ];
    
    return $colors[$priority] ?? 'secondary';
}

function studentTimeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . 'm ago';
    if ($time < 86400) return floor($time/3600) . 'h ago';
    if ($time < 2592000) return floor($time/86400) . 'd ago';
    
    return date('M j, Y', strtotime($datetime));
}
?>
