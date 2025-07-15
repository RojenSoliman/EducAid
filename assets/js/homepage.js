document.addEventListener("DOMContentLoaded", function () {
  const sidebar = document.getElementById("sidebar");
  const toggleBtn = document.getElementById("menu-toggle");
  const backdrop = document.getElementById("sidebar-backdrop");

  function isMobile() {
    return window.innerWidth <= 768;
  }
  //pa check nga
  // ✅ Force correct sidebar state on initial load
  if (isMobile()) {
    sidebar.classList.remove("close");
  } else {
    sidebar.classList.add("close");
  }

  // Toggle sidebar
  toggleBtn.addEventListener("click", function () {
    if (isMobile()) {
      sidebar.classList.add("open");
      backdrop.classList.remove("d-none");
      document.body.classList.add("no-scroll");
    } else {
      sidebar.classList.toggle("close");
    }
  });

  // Close on backdrop click
  backdrop.addEventListener("click", function () {
    sidebar.classList.remove("open");
    backdrop.classList.add("d-none");
    document.body.classList.remove("no-scroll");
  });

  // Close when clicking outside
  document.addEventListener("click", function (e) {
    const isClickInside = sidebar.contains(e.target) || toggleBtn.contains(e.target);
    if (isMobile() && sidebar.classList.contains("open") && !isClickInside) {
      sidebar.classList.remove("open");
      backdrop.classList.add("d-none");
      document.body.classList.remove("no-scroll");
    }
  });

  // Handle window resize
  window.addEventListener("resize", () => {
    if (isMobile()) {
      sidebar.classList.remove("close");
    } else {
      sidebar.classList.remove("open");
      backdrop.classList.add("d-none");
      sidebar.classList.add("close");
      document.body.classList.remove("no-scroll");
    }
  });
});
