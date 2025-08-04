document.addEventListener("DOMContentLoaded", function () {
  document.body.classList.add('js-ready');
  const sidebar = document.getElementById("sidebar");
  const toggleBtn = document.getElementById("menu-toggle");
  const backdrop = document.getElementById("sidebar-backdrop");

  function isMobile() {
    return window.innerWidth <= 768;
  }

  function updateSidebarState() {
    if (isMobile()) {
      sidebar.classList.remove("close");
      sidebar.classList.remove("open");
      backdrop.classList.add("d-none");
      document.body.classList.remove("no-scroll");
    } else {
      // Read state from localStorage
      const state = localStorage.getItem("sidebarState");
      if (state === "closed") {
        sidebar.classList.add("close");
      } else {
        sidebar.classList.remove("close");
      }
      sidebar.classList.remove("open");
      backdrop.classList.add("d-none");
      document.body.classList.remove("no-scroll");
    }
  }

  // Call immediately to avoid animation jump
  updateSidebarState();

  toggleBtn.addEventListener("click", function () {
    if (isMobile()) {
      sidebar.classList.add("open");
      backdrop.classList.remove("d-none");
      document.body.classList.add("no-scroll");
    } else {
      sidebar.classList.toggle("close");
      // Save state to localStorage
      if (sidebar.classList.contains("close")) {
        localStorage.setItem("sidebarState", "closed");
      } else {
        localStorage.setItem("sidebarState", "open");
      }
    }
  });

  backdrop.addEventListener("click", function () {
    sidebar.classList.remove("open");
    backdrop.classList.add("d-none");
    document.body.classList.remove("no-scroll");
  });

  document.addEventListener("click", function (e) {
    const isClickInside = sidebar.contains(e.target) || toggleBtn.contains(e.target);
    if (isMobile() && sidebar.classList.contains("open") && !isClickInside) {
      sidebar.classList.remove("open");
      backdrop.classList.add("d-none");
      document.body.classList.remove("no-scroll");
    }
  });

  window.addEventListener("resize", updateSidebarState);
});
