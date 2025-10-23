<!-- Priority Notification Modal -->
<!-- This modal shows urgent notifications (like document rejections) once per login session -->
<!-- It auto-displays on page load if there are unviewed priority notifications -->

<?php
if (!isset($connection) || !isset($_SESSION['student_id'])) {
    return; // Skip if not in student context
}

// Check for priority notifications that haven't been viewed yet
$student_id = $_SESSION['student_id'];
$priority_notif_query = pg_query_params($connection,
    "SELECT notification_id, message, created_at 
     FROM notifications 
     WHERE student_id = $1 
     AND is_priority = TRUE 
     AND (viewed_at IS NULL OR viewed_at > NOW() - INTERVAL '1 minute')
     ORDER BY created_at DESC 
     LIMIT 1",
    [$student_id]
);

$has_priority_notif = $priority_notif_query && pg_num_rows($priority_notif_query) > 0;
$priority_notif = $has_priority_notif ? pg_fetch_assoc($priority_notif_query) : null;
?>

<?php if ($has_priority_notif): ?>
<div class="modal fade" id="priorityNotificationModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill me-2" style="font-size: 1.5rem;"></i>
                    <span>Urgent Notification</span>
                </h5>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-warning border-0 mb-3" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-bell-fill text-warning me-3" style="font-size: 2rem;"></i>
                        <div>
                            <h6 class="fw-bold mb-2">Important Message</h6>
                            <p class="mb-0"><?= nl2br(htmlspecialchars($priority_notif['message'])) ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="text-muted small">
                    <i class="bi bi-clock"></i> 
                    <?= date('F d, Y \a\t g:i A', strtotime($priority_notif['created_at'])) ?>
                </div>
                
                <div class="mt-4 p-3 bg-light rounded">
                    <p class="mb-2"><strong>What you need to do:</strong></p>
                    <ol class="mb-0 ps-3">
                        <li>Review the message above carefully</li>
                        <li>Go to "Upload Documents" page if documents were rejected</li>
                        <li>Take action as soon as possible</li>
                    </ol>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-danger" onclick="dismissPriorityNotification(<?= $priority_notif['notification_id'] ?>)">
                    <i class="bi bi-check-circle me-1"></i> I Understand
                </button>
                <a href="upload_document.php" class="btn btn-primary">
                    <i class="bi bi-cloud-upload me-1"></i> Go to Upload Documents
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-show priority notification modal on page load
document.addEventListener('DOMContentLoaded', function() {
    const priorityModal = document.getElementById('priorityNotificationModal');
    if (priorityModal) {
        const modal = new bootstrap.Modal(priorityModal);
        modal.show();
    }
});

function dismissPriorityNotification(notificationId) {
    // Mark notification as viewed
    fetch('../../api/mark_notification_viewed.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            notification_id: notificationId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('priorityNotificationModal'));
            if (modal) {
                modal.hide();
            }
        }
    })
    .catch(error => {
        console.error('Error dismissing notification:', error);
        // Still close the modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('priorityNotificationModal'));
        if (modal) {
            modal.hide();
        }
    });
}
</script>

<style>
#priorityNotificationModal .modal-content {
    border-radius: 16px;
    overflow: hidden;
}

#priorityNotificationModal .modal-header {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
}

#priorityNotificationModal .alert-warning {
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
}

#priorityNotificationModal .modal-body {
    max-height: 70vh;
    overflow-y: auto;
}
</style>
<?php endif; ?>
