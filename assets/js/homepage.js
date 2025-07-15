document.addEventListener("DOMContentLoaded", function () {
  const sidebar = document.getElementById("sidebar");
  const toggleBtn = document.getElementById("menu-toggle");
  const backdrop = document.getElementById("sidebar-backdrop");

  function isMobile() {
    return window.innerWidth <= 768;
  }

  // ✅ NEW: Consolidated sidebar state handler
  function updateSidebarState() {
    if (isMobile()) {
      // On mobile: sidebar should not be collapsed, and should start hidden
      sidebar.classList.remove("close");
      sidebar.classList.remove("open");
      backdrop.classList.add("d-none");
      document.body.classList.remove("no-scroll");
    } else {
      // On desktop: sidebar should be collapsed by default
      sidebar.classList.add("close");
      sidebar.classList.remove("open");
      backdrop.classList.add("d-none");
      document.body.classList.remove("no-scroll");
    }
  }

  // ✅ CHANGE: Delay initial state set to ensure correct rendering
  setTimeout(updateSidebarState, 10);

  // ✅ UNCHANGED: Handle menu icon toggle
  toggleBtn.addEventListener("click", function () {
    if (isMobile()) {
      // On mobile: open sidebar with backdrop and scroll lock
      sidebar.classList.add("open");
      backdrop.classList.remove("d-none");
      document.body.classList.add("no-scroll");
    } else {
      // On desktop: toggle collapse state
      sidebar.classList.toggle("close");
    }
  });

  // ✅ UNCHANGED: Close on backdrop click
  backdrop.addEventListener("click", function () {
    sidebar.classList.remove("open");
    backdrop.classList.add("d-none");
    document.body.classList.remove("no-scroll");
  });

  // ✅ UNCHANGED: Close on outside click (mobile only)
  document.addEventListener("click", function (e) {
    const isClickInside = sidebar.contains(e.target) || toggleBtn.contains(e.target);
    if (isMobile() && sidebar.classList.contains("open") && !isClickInside) {
      sidebar.classList.remove("open");
      backdrop.classList.add("d-none");
      document.body.classList.remove("no-scroll");
    }
  });

  // ✅ CHANGE: Re-check correct state when window resizes
  window.addEventListener("resize", updateSidebarState);
});
