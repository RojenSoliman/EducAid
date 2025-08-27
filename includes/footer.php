<?php
// filepath: c:\xampp\htdocs\EducAid\includes\footer.php
?>
<!-- NON-CONFLICTING FOOTER WITH CUSTOM LOGO -->
<footer style="
    background: #2c3e50; 
    color: white; 
    padding: 12px 0; 
    margin-top: 20px; 
    border-top: 1px solid #28a745;
    position: relative;
    width: 100%;
    z-index: 1000;
">
    <div class="container-fluid">
        <div class="d-flex justify-content-center align-items-center flex-wrap" style="gap: 1rem; font-size: 0.85rem;">
            <!-- Brand with Custom Logo -->
            <div class="d-flex align-items-center">
                <img src="<?php echo $logo_path ?? 'assets/images/educaid-logo.png'; ?>" 
                     alt="EducAid Logo" 
                     style="height: 28px; width: 28px; object-fit: contain; margin-right: 8px; filter: brightness(1.1);"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                <i class="bi bi-mortarboard-fill" style="color: #28a745; margin-right: 8px; display: none;"></i>
                <strong style="color: white;">EducAid</strong>
                <small style="color: #ccc; margin-left: 8px;" class="d-none d-sm-inline">General Trias</small>
            </div>
            
            <!-- Links -->
            <div class="d-flex" style="gap: 1rem;">
                <a href="modules/student/student_register.php" style="color: white; text-decoration: none;" title="Register">
                    <i class="bi bi-person-plus"></i>
                </a>
                <a href="#" style="color: white; text-decoration: none;" title="Help">
                    <i class="bi bi-question-circle"></i>
                </a>
                <a href="#" style="color: white; text-decoration: none;" title="Contact">
                    <i class="bi bi-telephone"></i>
                </a>
            </div>
            
            <!-- Copyright -->
            <small style="color: #ccc;" class="d-none d-md-inline">
                © <?php echo date('Y'); ?> EducAid
            </small>
        </div>
    </div>
</footer>

<script>
// Add hover effects without CSS conflicts
document.addEventListener('DOMContentLoaded', function() {
    const footerLinks = document.querySelectorAll('footer a');
    footerLinks.forEach(link => {
        link.addEventListener('mouseenter', function() {
            this.style.color = '#28a745';
        });
        link.addEventListener('mouseleave', function() {
            this.style.color = 'white';
        });
    });
});
</script>
