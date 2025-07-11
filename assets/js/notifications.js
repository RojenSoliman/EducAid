function toggleNotification(headerEl) {
  const card = headerEl.closest('.notification-card');
  const body = card.querySelector('.notification-body');
  card.classList.toggle('expanded');
  body.classList.toggle('d-none');
}

function updateUnreadCount() {
  const unread = document.querySelectorAll('.notification-card.unread');
  document.getElementById('unread-count').textContent = unread.length;

  const visible = Array.from(document.querySelectorAll(".notification-card")).filter(c => c.style.display !== "none");
  document.getElementById("empty-state").classList.toggle("d-none", visible.length > 0);
}

document.querySelectorAll(".mark-btn").forEach(btn => {
  btn.addEventListener("click", () => {
    const card = btn.closest(".notification-card");
    const icon = btn.querySelector("i");

    if (card.classList.contains("unread")) {
      card.classList.replace("unread", "read");
      card.dataset.status = "read";
      icon.classList.replace("bi-envelope-open", "bi-envelope");
      icon.classList.add("text-success");
    } else {
      card.classList.replace("read", "unread");
      card.dataset.status = "unread";
      icon.classList.replace("bi-envelope", "bi-envelope-open");
      icon.classList.remove("text-success");
    }
    updateUnreadCount();
  });
});

document.querySelectorAll(".delete-btn").forEach(btn => {
  btn.addEventListener("click", () => {
    const card = btn.closest(".notification-card");
    card.remove();
    updateUnreadCount();
  });
});

document.getElementById("mark-all-read").addEventListener("click", () => {
  document.querySelectorAll(".notification-card.unread").forEach(card => {
    const icon = card.querySelector(".mark-btn i");
    card.classList.replace("unread", "read");
    card.dataset.status = "read";
    icon.classList.replace("bi-envelope-open", "bi-envelope");
    icon.classList.add("text-success");
  });
  updateUnreadCount();
});

document.getElementById("filter-unread").addEventListener("click", () => {
  document.getElementById("filter-unread").classList.add("active");
  document.getElementById("filter-read").classList.remove("active");
  document.querySelectorAll(".notification-card").forEach(card => {
    card.style.display = card.dataset.status === "unread" ? "block" : "none";
  });
  updateUnreadCount();
});

document.getElementById("filter-read").addEventListener("click", () => {
  document.getElementById("filter-read").classList.add("active");
  document.getElementById("filter-unread").classList.remove("active");
  document.querySelectorAll(".notification-card").forEach(card => {
    card.style.display = card.dataset.status === "read" ? "block" : "none";
  });
  updateUnreadCount();
});

updateUnreadCount();
