// assets/js/admin/sidebar_toggle.js

document.addEventListener("DOMContentLoaded", function () {
  const sidebar = document.getElementById("sidebar");
  const toggleBtn = document.getElementById("menu-toggle");
  const backdrop = document.getElementById("sidebar-backdrop");

  function isMobile() {
    return window.innerWidth <= 768;
  }

  function closeSidebar() {
    if (isMobile()) {
      sidebar.classList.remove("open");
      sidebar.classList.add("close");
      backdrop.classList.add("d-none");
      document.body.style.overflow = "";
    } else {
      sidebar.classList.toggle("close");
      document.querySelector(".home-section").classList.toggle("expanded");
    }
  }

  toggleBtn.addEventListener("click", function () {
    if (isMobile()) {
      sidebar.classList.add("open");
      sidebar.classList.remove("close");
      backdrop.classList.remove("d-none");
      document.body.style.overflow = "hidden";
    } else {
      sidebar.classList.toggle("close");
      document.querySelector(".home-section").classList.toggle("expanded");
    }
  });

  backdrop.addEventListener("click", closeSidebar);

  document.addEventListener("click", function (e) {
    if (
      isMobile() &&
      !sidebar.contains(e.target) &&
      !toggleBtn.contains(e.target)
    ) {
      closeSidebar();
    }
  });
});
