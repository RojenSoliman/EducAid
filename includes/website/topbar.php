<?php
// Determine if we're in a subfolder and calculate relative path to root
$base_path = '';
if (strpos($_SERVER['PHP_SELF'], '/website/') !== false) {
  $base_path = '../';
} elseif (strpos($_SERVER['PHP_SELF'], '/modules/student/') !== false) {
  $base_path = '../../';
} elseif (strpos($_SERVER['PHP_SELF'], '/modules/admin/') !== false) {
  $base_path = '../../';
}

// Shared landing content block loader (idempotent)
@include_once __DIR__ . '/landing_content_helper.php';
?>
<!-- Top information bar -->
<div class="topbar py-2" data-lp-key="topbar_container"<?php echo lp_block_style('topbar_container'); ?>>
  <div class="container d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-3" data-lp-key="topbar_contact_group"<?php echo lp_block_style('topbar_contact_group'); ?>>
      <span data-lp-key="topbar_email_icon" class="lp-no-text-edit"><i class="bi bi-envelope-paper"></i></span>
      <span data-lp-key="topbar_email"<?php echo lp_block_style('topbar_email'); ?>><?php echo lp_block('topbar_email', '<a href="mailto:educaid@generaltrias.gov.ph">educaid@generaltrias.gov.ph</a>'); ?></span>
      <span class="vr mx-2 d-none d-md-inline lp-no-text-edit" data-lp-key="topbar_divider"></span>
      <span data-lp-key="topbar_phone_icon" class="lp-no-text-edit"><i class="bi bi-telephone"></i></span>
      <span data-lp-key="topbar_phone"<?php echo lp_block_style('topbar_phone'); ?>><?php echo lp_block('topbar_phone', '(046) 886-4454'); ?></span>
    </div>
    <form class="d-flex" role="search" onsubmit="event.preventDefault(); const f=document.getElementById('faq'); if(f) f.scrollIntoView({behavior:'smooth'});" data-lp-key="topbar_search_form">
      <input class="form-control" type="search" placeholder="Search FAQs, requirements…" aria-label="Search" />
    </form>
  </div>
</div>