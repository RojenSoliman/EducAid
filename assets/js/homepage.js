document.addEventListener("click", function (e) {
  if (
    document.getElementById("wrapper").classList.contains("toggled") &&
    !e.target.closest("#sidebar-wrapper") &&
    !e.target.closest("#menu-toggle")
  ) {
    document.getElementById("wrapper").classList.remove("toggled");
  }
});
