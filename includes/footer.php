<footer style="
    background:#2c3e50;
    color:#fff;
    padding:12px 0;
    /* margin-top:20px;  <-- removed to avoid the 'white strip' */
    /* border-top:1px solid #28a745; <-- optional: removed */
    box-shadow: 0 -1px 0 rgba(0,0,0,.06); /* subtle separator */
    position:relative;
    width:100%;
    z-index:1000;
">
  <div class="container"> <!-- changed from container-fluid -->
    <div style="display:flex;justify-content:center;align-items:center;flex-wrap:wrap;gap:1rem;font-size:.85rem;">
      <!-- Brand -->
      <div style="display:flex;align-items:center;">
        <i class="bi bi-mortarboard-fill" style="color:#28a745;margin-right:8px;"></i>
        <strong style="color:white;">EducAid</strong>
        <small style="color:#ccc;margin-left:8px;">General Trias</small>
      </div>
      <!-- Links -->
      <div style="display:flex;gap:1rem;">
        <a href="modules/student/student_register.php" style="color:white;text-decoration:none;" title="Register"><i class="bi bi-person-plus"></i></a>
        <a href="#" style="color:white;text-decoration:none;" title="Help"><i class="bi bi-question-circle"></i></a>
        <a href="#" style="color:white;text-decoration:none;" title="Contact"><i class="bi bi-telephone"></i></a>
      </div>
      <!-- Copyright -->
      <small style="color:#ccc;">© <?php echo date('Y'); ?> EducAid</small>
    </div>
  </div>
</footer>
