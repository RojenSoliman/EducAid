document.addEventListener('DOMContentLoaded', function () {
  const unreadCountSpan = document.getElementById('unread-count');
  const markAllReadBtn = document.getElementById('mark-all-read');
  const filterButtons = document.querySelectorAll('.btn-group .btn');
  const notificationCards = document.querySelectorAll('.notification-card');

  function updateUnreadCount() {
    const unread = document.querySelectorAll('.notification-card.unread').length;
    unreadCountSpan.textContent = unread;
    unreadCountSpan.style.display = unread > 0 ? 'inline-block' : 'none';
  }

  function markAllAsRead() {
    document.querySelectorAll('.notification-card.unread').forEach(card => {
      card.classList.remove('unread');
      card.classList.add('read');
    });
    updateUnreadCount();
  }

  function deleteNotification(button) {
    const card = button.closest('.notification-card');
    if (card) {
      card.remove();
      updateUnreadCount();
    }
  }

  // Event listener: "Mark All as Read"
  markAllReadBtn.addEventListener('click', markAllAsRead);

  // Event listener: Delete buttons
  document.querySelectorAll('.bi-trash').forEach(button => {
    button.addEventListener('click', function () {
      deleteNotification(button);
    });
  });

  // Event listener: Filter toggle
  filterButtons.forEach(button => {
    button.addEventListener('click', () => {
      filterButtons.forEach(btn => btn.classList.remove('active'));
      button.classList.add('active');

      const isUnread = button.textContent.trim() === 'Unread';
      notificationCards.forEach(card => {
        if (isUnread && !card.classList.contains('unread')) {
          card.style.display = 'none';
        } else if (!isUnread && !card.classList.contains('read')) {
          card.style.display = 'none';
        } else {
          card.style.display = 'block';
        }
      });
    });
  });

  // Initial count
  updateUnreadCount();
});
