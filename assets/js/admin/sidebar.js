// assets/js/admin/sidebar_toggle.js

document.addEventListener("DOMContentLoaded", function () {
  const sidebar = document.getElementById("sidebar");
  const toggleBtn = document.getElementById("menu-toggle");
  const backdrop = document.getElementById("sidebar-backdrop");
  const homeSection = document.querySelector(".home-section") || document.getElementById("mainContent");

  function isMobile() {
    return window.innerWidth <= 768;
  }

  const header = document.querySelector('.admin-main-header');
  const adjustLayout = () => {
    if (!header) return;
  const closed = sidebar.classList.contains('close');
  const left = closed ? 70 : 250; // match .sidebar.close { width: 70px; }
    header.style.left = isMobile() ? '0px' : left + 'px';
    if (homeSection) {
      // Remove any inline margin-left so CSS width calc stays accurate
      homeSection.style.marginLeft = isMobile() ? '0px' : '';
      if (isMobile()) homeSection.style.width = '100%'; else homeSection.style.width = '';
    }
  };

  function updateSidebarState() {
    // On mobile, sidebar state is always "open" only when toggled, never saved
    if (isMobile()) {
      sidebar.classList.remove("close", "open");
      backdrop.classList.add("d-none");
      document.body.style.overflow = "";
      if (homeSection) homeSection.classList.remove("expanded");
      adjustLayout();
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
      adjustLayout();
    }
  }

  // On page load, set sidebar state
  updateSidebarState();

  // === JS Animation (no CSS transitions) for desktop collapse/expand ===
  let sidebarAnimFrame = null;
  let sidebarAnimating = false;

  function animateSidebar(expand) {
    if (sidebarAnimating) {
      cancelAnimationFrame(sidebarAnimFrame);
      sidebarAnimating = false;
    }

    if (isMobile()) {
      // Mobile: still instant overlay behavior
      if (expand) {
        sidebar.classList.add("open");
        sidebar.classList.remove("close");
        backdrop.classList.remove("d-none");
        document.body.style.overflow = "hidden";
      } else {
        sidebar.classList.remove("open");
        sidebar.classList.add("close");
        backdrop.classList.add("d-none");
        document.body.style.overflow = "";
      }
      adjustLayout();
      return;
    }

    const startWidth = sidebar.offsetWidth;
    const targetWidth = expand ? 250 : 70; // sync with CSS values
    const startTime = performance.now();
    const duration = 220; // ms

    // Pre-state: add/remove class only AFTER animation so content text appears/disappears at end
    // For collapsing we keep it open (no .close) until the end so labels don't instantly vanish.
    if (!expand) {
      sidebar.classList.remove("close");
    }

    sidebarAnimating = true;

    function easeOutQuad(t) { return 1 - (1 - t) * (1 - t); }

    function step(now) {
      const elapsed = now - startTime;
      const progress = Math.min(1, elapsed / duration);
      const eased = easeOutQuad(progress);
      const current = Math.round(startWidth + (targetWidth - startWidth) * eased);
      sidebar.style.width = current + 'px';

      // Animate header and content shift inline (will be cleaned up after)
      if (header && !isMobile()) header.style.left = current + 'px';
      if (homeSection && !isMobile()) {
        homeSection.style.marginLeft = current + 'px';
        homeSection.style.width = `calc(100% - ${current}px)`;
      }

      if (progress < 1) {
        sidebarAnimFrame = requestAnimationFrame(step);
      } else {
        // Finish state
        sidebarAnimating = false;
        sidebar.style.width = '';
        if (expand) {
          sidebar.classList.remove("close");
          localStorage.setItem("adminSidebarState", "open");
          if (homeSection) homeSection.classList.add("expanded");
        } else {
          sidebar.classList.add("close");
          localStorage.setItem("adminSidebarState", "closed");
          if (homeSection) homeSection.classList.remove("expanded");
        }
        // Clean up inline shifts - let adjustLayout() set final canonical values
        if (homeSection) {
          homeSection.style.marginLeft = '';
          homeSection.style.width = '';
        }
        adjustLayout();
      }
    }

    requestAnimationFrame(step);
  }

  toggleBtn.addEventListener("click", function (e) {
    e.stopPropagation();
    const expanding = sidebar.classList.contains("close");
    animateSidebar(expanding);
  });

  backdrop.addEventListener("click", function () {
    sidebar.classList.remove("open");
    sidebar.classList.add("close");
    backdrop.classList.add("d-none");
    document.body.style.overflow = "";
    adjustLayout();
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
        adjustLayout();
      }
    }
  });

  // Always update sidebar state on resize to keep in sync
  window.addEventListener("resize", () => { updateSidebarState(); });

  // (Optional) Force apply state when navigating via SPA or AJAX:
  // window.addEventListener("pageshow", updateSidebarState);

  // For debugging: log localStorage state
  // console.log("Sidebar state:", localStorage.getItem("adminSidebarState"));
});
// This code handles the sidebar toggle functionality for the admin panel.