document.addEventListener("DOMContentLoaded", function () {
  const wrapper = document.getElementById("wrapper");
  const toggleBtn = document.getElementById("menu-toggle");
  const backdrop = document.getElementById("sidebar-backdrop");

  toggleBtn.addEventListener("click", function () {
    wrapper.classList.toggle("toggled");
    backdrop.classList.toggle("d-none");
  });

  backdrop.addEventListener("click", function () {
    wrapper.classList.remove("toggled");
    backdrop.classList.add("d-none");
  });
});
