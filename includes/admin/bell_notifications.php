<?php
// Bell Notification Component for Admin Header

// Get unread notifications count
function getUnreadNotificationCount($connection, $admin_id) {
    $query = "SELECT COUNT(*) as unread_count FROM admin_notifications 
              WHERE admin_id = $1 AND is_read = FALSE 
              AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)";
    $result = pg_query_params($connection, $query, [$admin_id]);
    
    if ($result) {
        $row = pg_fetch_assoc($result);
        return (int)$row['unread_count'];
    }
    
    return 0;
}

// Get recent notifications
function getRecentNotifications($connection, $admin_id, $limit = 5) {
    $query = "SELECT notification_id, title, message, type, priority, created_at, action_url, is_read
              FROM admin_notifications 
              WHERE admin_id = $1 AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
              ORDER BY created_at DESC 
              LIMIT $2";
    $result = pg_query_params($connection, $query, [$admin_id, $limit]);
    
    $notifications = [];
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $notifications[] = $row;
        }
    }
    
    return $notifications;
}

// Get notification count and recent notifications
$admin_id = $_SESSION['admin_id'] ?? 0;
$unread_count = getUnreadNotificationCount($connection, $admin_id);
$recent_notifications = getRecentNotifications($connection, $admin_id);
?>

<div class="dropdown">
    <button class="btn btn-link text-white position-relative" 
            type="button" 
            id="notificationDropdown" 
            data-bs-toggle="dropdown" 
            aria-expanded="false">
        <i class="bi bi-bell fs-5"></i>
        <?php if ($unread_count > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                <?= $unread_count > 99 ? '99+' : $unread_count ?>
                <span class="visually-hidden">unread notifications</span>
            </span>
        <?php endif; ?>
    </button>
    
    <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationDropdown">
        <div class="dropdown-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Notifications</h6>
            <?php if ($unread_count > 0): ?>
                <button class="btn btn-sm btn-outline-primary" onclick="markAllAsRead()">
                    Mark all read
                </button>
            <?php endif; ?>
        </div>
        
        <div class="dropdown-divider"></div>
        
        <?php if (empty($recent_notifications)): ?>
            <div class="dropdown-item text-center text-muted py-3">
                <i class="bi bi-bell-slash fs-4 d-block mb-2"></i>
                No notifications
            </div>
        <?php else: ?>
            <?php foreach ($recent_notifications as $notification): ?>
                <a class="dropdown-item notification-item <?= !$notification['is_read'] ? 'unread' : '' ?>" 
                   href="<?= htmlspecialchars($notification['action_url'] ?? '#') ?>"
                   onclick="markAsRead(<?= $notification['notification_id'] ?>)">
                    <div class="d-flex">
                        <div class="flex-shrink-0 me-2">
                            <i class="bi bi-<?= getNotificationIcon($notification['type']) ?> 
                               text-<?= getNotificationColor($notification['priority']) ?>"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1 fs-6"><?= htmlspecialchars($notification['title']) ?></h6>
                            <p class="mb-1 small text-muted">
                                <?= htmlspecialchars(substr($notification['message'], 0, 100)) ?>
                                <?= strlen($notification['message']) > 100 ? '...' : '' ?>
                            </p>
                            <small class="text-muted">
                                <?= timeAgo($notification['created_at']) ?>
                            </small>
                        </div>
                    </div>
                </a>
                <div class="dropdown-divider"></div>
            <?php endforeach; ?>
            
            <div class="dropdown-item text-center">
                <a href="notifications.php" class="btn btn-sm btn-outline-secondary">
                    View all notifications
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.notification-dropdown {
    width: 350px;
    max-height: 400px;
    overflow-y: auto;
}

.notification-item {
    white-space: normal;
    padding: 0.75rem 1rem;
}

.notification-item.unread {
    background-color: #f8f9fa;
    border-left: 3px solid #0d6efd;
}

.notification-item:hover {
    background-color: #e9ecef;
}

.dropdown-header {
    padding: 0.75rem 1rem;
    background-color: #f8f9fa;
}
</style>

<script>
// Mark single notification as read
function markAsRead(notificationId) {
    fetch('mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    }).then(response => response.json())
      .then(data => {
          if (data.success) {
              updateNotificationBadge();
          }
      });
}

// Mark all notifications as read
function markAllAsRead() {
    fetch('mark_all_notifications_read.php', {
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
function updateNotificationBadge() {
    fetch('get_notification_count.php')
        .then(response => response.json())
        .then(data => {
            const badge = document.querySelector('.badge');
            if (data.count > 0) {
                if (badge) {
                    badge.textContent = data.count > 99 ? '99+' : data.count;
                } else {
                    // Create badge if it doesn't exist
                    const bell = document.querySelector('#notificationDropdown i');
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
function getNotificationIcon($type) {
    $icons = [
        'visual_change' => 'palette',
        'visual_change_alert' => 'exclamation-triangle',
        'system' => 'gear',
        'security' => 'shield-lock',
        'info' => 'info-circle',
        'warning' => 'exclamation-triangle',
        'error' => 'x-circle',
        'success' => 'check-circle'
    ];
    
    return $icons[$type] ?? 'info-circle';
}

function getNotificationColor($priority) {
    $colors = [
        'high' => 'danger',
        'medium' => 'warning',
        'low' => 'info'
    ];
    
    return $colors[$priority] ?? 'secondary';
}

function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . 'm ago';
    if ($time < 86400) return floor($time/3600) . 'h ago';
    if ($time < 2592000) return floor($time/86400) . 'd ago';
    
    return date('M j, Y', strtotime($datetime));
}
?>