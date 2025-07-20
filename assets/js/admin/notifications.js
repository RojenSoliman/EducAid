document.addEventListener('DOMContentLoaded', function () {
  const unreadCountSpan = document.getElementById('unread-count');
  const markAllReadBtn = document.getElementById('mark-all-read');
  const filterButtons = document.querySelectorAll('.btn-group .btn, .notification-actions-mobile .btn');
  const notificationList = document.querySelector('.notifications-list');
  let lastDeleted = null;
  let undoTimeout = null;

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

  function showUndoSnackbar() {
    const snackbar = document.getElementById('undo-snackbar');
    snackbar.innerHTML = `Notification deleted. <button id="undo-btn" class="btn btn-sm btn-light ms-2">Undo</button>`;
    snackbar.classList.add('show');

    document.getElementById('undo-btn').onclick = function () {
      if (lastDeleted) {
        lastDeleted.classList.remove('fade-out');
        notificationList.appendChild(lastDeleted);
        lastDeleted = null;
      }
      clearTimeout(undoTimeout);
      snackbar.classList.remove('show');
      updateUnreadCount();
    };

    undoTimeout = setTimeout(() => {
      if (lastDeleted) lastDeleted.remove();
      lastDeleted = null;
      snackbar.classList.remove('show');
      updateUnreadCount();
    }, 5000);
  }

  function deleteNotification(button) {
    const card = button.closest('.notification-card');
    if (card) {
      lastDeleted = card;
      card.classList.add('fade-out');
      showUndoSnackbar();
    }
  }

  // Attach delete events
  document.querySelectorAll('.bi-trash').forEach(button => {
    button.addEventListener('click', () => {
      deleteNotification(button);
    });
  });

  // Attach mark-as-read events
  document.querySelectorAll('.bi-envelope').forEach(button => {
    button.addEventListener('click', () => {
      const card = button.closest('.notification-card');
      if (card) {
        card.classList.remove('unread');
        card.classList.add('read');
        updateUnreadCount();
      }
    });
  });

  // Filter toggle
  const notificationCards = document.querySelectorAll('.notification-card');
  filterButtons.forEach(button => {
    button.addEventListener('click', () => {
      filterButtons.forEach(btn => btn.classList.remove('active'));
      button.classList.add('active');

      const label = button.textContent.trim();
      const showUnread = label === 'Unread';
      const showRead = label === 'Read';

      notificationCards.forEach(card => {
        if (showUnread && !card.classList.contains('unread')) {
          card.style.display = 'none';
        } else if (showRead && !card.classList.contains('read')) {
          card.style.display = 'none';
        } else {
          card.style.display = 'block';
        }
      });
    });
  });

  // Mark all as read
  markAllReadBtn?.addEventListener('click', markAllAsRead);

  // Initialize count
  updateUnreadCount();
});
