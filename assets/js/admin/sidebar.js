// assets/js/admin/sidebar_toggle.js

document.addEventListener("DOMContentLoaded", function () {
  const sidebar = document.getElementById("sidebar");
  const toggleBtn = document.getElementById("menu-toggle");
  const backdrop = document.getElementById("sidebar-backdrop");
  const homeSection = document.querySelector(".home-section") || document.getElementById("mainContent");

  function isMobile() {
    return window.innerWidth <= 768;
  }

  function updateSidebarState() {
    // On mobile, sidebar state is always "open" only when toggled, never saved
    if (isMobile()) {
      sidebar.classList.remove("close", "open");
      backdrop.classList.add("d-none");
      document.body.style.overflow = "";
      if (homeSection) homeSection.classList.remove("expanded");
    } else {
      // Desktop: remember state in localStorage
      const state = localStorage.getItem("adminSidebarState");
      if (state === "closed") {
        sidebar.classList.add("close");
        if (homeSection) homeSection.classList.remove("expanded");
      } else {
        sidebar.classList.remove("close");
        if (homeSection) homeSection.classList.add("expanded");
      }
      sidebar.classList.remove("open");
      backdrop.classList.add("d-none");
      document.body.style.overflow = "";
    }
  }

  // On page load, set sidebar state
  updateSidebarState();

  toggleBtn.addEventListener("click", function (e) {
    e.stopPropagation();
    if (isMobile()) {
      sidebar.classList.add("open");
      sidebar.classList.remove("close");
      backdrop.classList.remove("d-none");
      document.body.style.overflow = "hidden";
    } else {
      sidebar.classList.toggle("close");
      if (sidebar.classList.contains("close")) {
        localStorage.setItem("adminSidebarState", "closed");
        if (homeSection) homeSection.classList.remove("expanded");
      } else {
        localStorage.setItem("adminSidebarState", "open");
        if (homeSection) homeSection.classList.add("expanded");
      }
    }
  });

  backdrop.addEventListener("click", function () {
    sidebar.classList.remove("open");
    sidebar.classList.add("close");
    backdrop.classList.add("d-none");
    document.body.style.overflow = "";
  });

  // Hide sidebar on mobile when clicking outside of it
  document.addEventListener("click", function (e) {
    if (isMobile() && sidebar.classList.contains("open")) {
      const isClickInside = sidebar.contains(e.target) || toggleBtn.contains(e.target);
      if (!isClickInside) {
        sidebar.classList.remove("open");
        sidebar.classList.add("close");
        backdrop.classList.add("d-none");
        document.body.style.overflow = "";
      }
    }
  });

  // Always update sidebar state on resize to keep in sync
  window.addEventListener("resize", updateSidebarState);

  // (Optional) Force apply state when navigating via SPA or AJAX:
  // window.addEventListener("pageshow", updateSidebarState);

  // For debugging: log localStorage state
  // console.log("Sidebar state:", localStorage.getItem("adminSidebarState"));
});
// This code handles the sidebar toggle functionality for the admin panel.