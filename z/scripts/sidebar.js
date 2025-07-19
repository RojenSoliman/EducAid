function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');

  if (!sidebar || sidebar.classList.contains('transitioning')) return;

  // Add lock to avoid rapid clicks
  sidebar.classList.add('transitioning');

  setTimeout(() => {
    sidebar.classList.remove('transitioning');
  }, 350); // slightly longer than CSS transition time

  if (window.innerWidth <= 768) {
    sidebar.classList.toggle('mobile-show');
    console.log("📱 Mobile sidebar:", sidebar.classList.contains('mobile-show') ? "Open" : "Closed");
  } else {
    sidebar.classList.toggle('collapsed');
    const state = sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded';
    localStorage.setItem('sidebarState', state);
    console.log("🖥️ Saved sidebar state:", state);
  }
}

window.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.getElementById('sidebar');
  const state = localStorage.getItem('sidebarState');

  if (state === 'collapsed' && window.innerWidth > 768) {
    sidebar.classList.add('collapsed');
  } else {
    sidebar.classList.remove('collapsed');
  }

  document.body.classList.add('ready');
});
