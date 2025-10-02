<?php
// filepath: c:\xampp\htdocs\EducAid\includes\footer.php
@include_once __DIR__ . '/website/landing_content_helper.php';
?>
<!-- Editable Footer -->
<footer data-lp-key="footer_container"<?php echo lp_block_style('footer_container'); ?> style="background:#2c3e50;color:white;padding:12px 0;margin-top:20px;border-top:1px solid #28a745;position:relative;width:100%;z-index:1000;">
    <div class="container-fluid">
        <div class="d-flex justify-content-center align-items-center flex-wrap" style="gap:1rem;font-size:0.85rem;">
            <!-- Brand with Custom Logo -->
            <div class="d-flex align-items-center" data-lp-key="footer_brand"<?php echo lp_block_style('footer_brand'); ?>>
                <span class="lp-no-text-edit">
                  <img src="<?php echo $logo_path ?? 'assets/images/educaid-logo.png'; ?>" alt="EducAid Logo" style="height:28px;width:28px;object-fit:contain;margin-right:8px;filter:brightness(1.1);" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                  <i class="bi bi-mortarboard-fill" style="color:#28a745;margin-right:8px;display:none;"></i>
                </span>
                <strong data-lp-key="footer_brand_name"<?php echo lp_block_style('footer_brand_name'); ?>><?php echo lp_block('footer_brand_name','EducAid'); ?></strong>
                <small data-lp-key="footer_brand_city"<?php echo lp_block_style('footer_brand_city'); ?> class="d-none d-sm-inline" style="color:#ccc;">&nbsp;<?php echo lp_block('footer_brand_city','General Trias'); ?></small>
            </div>

            <!-- Links -->
            <div class="d-flex" style="gap:1rem;" data-lp-key="footer_links"<?php echo lp_block_style('footer_links'); ?>>
                <?php echo lp_block('footer_links', '<a href="modules/student/student_register.php" style="color:white;text-decoration:none;" title="Register"><i class="bi bi-person-plus"></i></a> <a href="#" style="color:white;text-decoration:none;" title="Help"><i class="bi bi-question-circle"></i></a> <a href="#" style="color:white;text-decoration:none;" title="Contact"><i class="bi bi-telephone"></i></a>'); ?>
            </div>

            <!-- Copyright -->
            <small class="d-none d-md-inline" data-lp-key="footer_copyright"<?php echo lp_block_style('footer_copyright'); ?>><?php echo lp_block('footer_copyright','© '.date('Y').' EducAid'); ?></small>
        </div>
    </div>
</footer>

<script>
// Simple hover enhancement remains; editing should not overwrite link icons
document.addEventListener('DOMContentLoaded', function() {
  const footerLinks = document.querySelectorAll('footer a');
  footerLinks.forEach(link => {
    link.addEventListener('mouseenter', ()=>{ link.style.color = '#28a745'; });
    link.addEventListener('mouseleave', ()=>{ link.style.color = 'white'; });
  });
});
</script>
