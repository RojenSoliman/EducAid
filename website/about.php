  <?php
session_start();
// Determine super admin edit mode for About page (?edit=1)
$IS_EDIT_MODE = false; $is_super_admin = false;
@include_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
  $role = @getCurrentAdminRole($connection);
  if ($role === 'super_admin') { $is_super_admin = true; }
}
if ($is_super_admin && isset($_GET['edit']) && $_GET['edit'] == '1') { $IS_EDIT_MODE = true; }
// Load dedicated about page content helper (separate storage)
@include_once __DIR__ . '/../includes/website/about_content_helper.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo strip_tags(about_block('about_page_title','About EducAid – City of General Trias')); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars(strip_tags(about_block('about_page_meta_desc','Learn more about EducAid - Educational Assistance Management System for General Trias students'))); ?>" />
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="../assets/css/website/landing_page.css" rel="stylesheet" />
  <?php if ($IS_EDIT_MODE): ?>
  <link href="../assets/css/content_editor.css" rel="stylesheet" />
  <?php endif; ?>
</head>
<body>
  <?php if ($IS_EDIT_MODE): ?>
    <?php
      $toolbar_config = [
        'page_title' => 'About Page',
        'exit_url' => 'about.php'
      ];
      include '../includes/website/edit_toolbar.php';
    ?>
  <?php endif; ?>

<?php
// Custom nav links for this page
$custom_nav_links = [
  ['href' => 'landingpage.php', 'label' => 'Home', 'active' => false],
  ['href' => 'about.php', 'label' => 'About', 'active' => true],
  ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => false],
  ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => false],
  ['href' => 'announcements.php', 'label' => 'Announcements', 'active' => false],
  ['href' => 'contact.php', 'label' => 'Contact', 'active' => false]
];
include '../includes/website/topbar.php';
include '../includes/website/navbar.php';
include '../includes/website/cookie_consent.php';
?>

  <!-- Hero Section -->
  <section class="hero py-5" style="min-height: 50vh;">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-lg-10">
          <div class="hero-card text-center">
            <h1 class="display-4 fw-bold mb-3" data-lp-key="about_hero_title"<?php echo about_block_style('about_hero_title'); ?>><?php echo about_block('about_hero_title','About <span class="text-primary">EducAid</span>'); ?></h1>
            <p class="lead" data-lp-key="about_hero_lead"<?php echo about_block_style('about_hero_lead'); ?>><?php echo about_block('about_hero_lead','Empowering General Trias students through transparent, accessible, and efficient educational assistance management.'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Mission & Vision -->
  <section class="py-5">
    <div class="container">
      <div class="row g-5">
        <div class="col-lg-6">
          <div class="soft-card p-4 h-100">
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="bg-primary rounded-circle p-3">
                <i class="bi bi-bullseye text-white fs-4"></i>
              </div>
              <h3 class="section-title mb-0" data-lp-key="about_mission_title"<?php echo about_block_style('about_mission_title'); ?>><?php echo about_block('about_mission_title','Our Mission'); ?></h3>
            </div>
            <p class="mb-0" data-lp-key="about_mission_body"<?php echo about_block_style('about_mission_body'); ?>><?php echo about_block('about_mission_body','To provide equitable access to educational assistance for all qualified students in General Trias through a transparent, efficient, and technology-driven platform that eliminates barriers and ensures fair distribution of resources.'); ?></p>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4 h-100">
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="bg-success rounded-circle p-3">
                <i class="bi bi-eye text-white fs-4"></i>
              </div>
              <h3 class="section-title mb-0" data-lp-key="about_vision_title"<?php echo about_block_style('about_vision_title'); ?>><?php echo about_block('about_vision_title','Our Vision'); ?></h3>
            </div>
            <p class="mb-0" data-lp-key="about_vision_body"<?php echo about_block_style('about_vision_body'); ?>><?php echo about_block('about_vision_body','To be the leading model for digital educational assistance management in the Philippines, fostering an educated community where every student has the opportunity to pursue their academic dreams without financial barriers.'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Core Values -->
  <section class="py-5 bg-body-tertiary">
    <div class="container">
      <div class="text-center mb-5">
  <h2 class="section-title" data-lp-key="about_values_heading"<?php echo about_block_style('about_values_heading'); ?>><?php echo about_block('about_values_heading','Our Core Values'); ?></h2>
  <p class="section-lead" data-lp-key="about_values_lead"<?php echo about_block_style('about_values_lead'); ?>><?php echo about_block('about_values_lead','The principles that guide our commitment to educational assistance'); ?></p>
      </div>
      <div class="row g-4">
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-primary bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-shield-check text-primary fs-3"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="about_value_transparency_title"<?php echo about_block_style('about_value_transparency_title'); ?>><?php echo about_block('about_value_transparency_title','Transparency'); ?></h5>
            <p class="text-body-secondary mb-0" data-lp-key="about_value_transparency_body"<?php echo about_block_style('about_value_transparency_body'); ?>><?php echo about_block('about_value_transparency_body','Open processes, clear criteria, and accessible information for all stakeholders.'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-success bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-people text-success fs-3"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="about_value_equity_title"<?php echo about_block_style('about_value_equity_title'); ?>><?php echo about_block('about_value_equity_title','Equity'); ?></h5>
            <p class="text-body-secondary mb-0" data-lp-key="about_value_equity_body"<?php echo about_block_style('about_value_equity_body'); ?>><?php echo about_block('about_value_equity_body','Fair distribution of assistance based on need and qualification, not privilege.'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-warning bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-lightning text-warning fs-3"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="about_value_efficiency_title"<?php echo about_block_style('about_value_efficiency_title'); ?>><?php echo about_block('about_value_efficiency_title','Efficiency'); ?></h5>
            <p class="text-body-secondary mb-0" data-lp-key="about_value_efficiency_body"<?php echo about_block_style('about_value_efficiency_body'); ?>><?php echo about_block('about_value_efficiency_body','Streamlined processes that save time for students, families, and administrators.'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-info bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-heart text-info fs-3"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="about_value_compassion_title"<?php echo about_block_style('about_value_compassion_title'); ?>><?php echo about_block('about_value_compassion_title','Compassion'); ?></h5>
            <p class="text-body-secondary mb-0" data-lp-key="about_value_compassion_body"<?php echo about_block_style('about_value_compassion_body'); ?>><?php echo about_block('about_value_compassion_body','Understanding the struggles of students and families in need of assistance.'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-danger bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-gear text-danger fs-3"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="about_value_innovation_title"<?php echo about_block_style('about_value_innovation_title'); ?>><?php echo about_block('about_value_innovation_title','Innovation'); ?></h5>
            <p class="text-body-secondary mb-0" data-lp-key="about_value_innovation_body"<?php echo about_block_style('about_value_innovation_body'); ?>><?php echo about_block('about_value_innovation_body','Embracing technology to improve service delivery and user experience.'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-secondary bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-hand-thumbs-up text-secondary fs-3"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="about_value_accountability_title"<?php echo about_block_style('about_value_accountability_title'); ?>><?php echo about_block('about_value_accountability_title','Accountability'); ?></h5>
            <p class="text-body-secondary mb-0" data-lp-key="about_value_accountability_body"<?php echo about_block_style('about_value_accountability_body'); ?>><?php echo about_block('about_value_accountability_body','Responsible stewardship of public resources and student data.'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- System Features -->
  <section class="py-5">
    <div class="container">
      <div class="text-center mb-5">
  <h2 class="section-title" data-lp-key="about_features_heading"<?php echo about_block_style('about_features_heading'); ?>><?php echo about_block('about_features_heading','What Makes EducAid Special'); ?></h2>
  <p class="section-lead" data-lp-key="about_features_lead"<?php echo about_block_style('about_features_lead'); ?>><?php echo about_block('about_features_lead','Advanced features designed for transparency, security, and ease of use'); ?></p>
      </div>
      <div class="row g-4">
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <div class="d-flex gap-3">
              <div class="bg-primary rounded p-3 flex-shrink-0">
                <i class="bi bi-qr-code text-white fs-4"></i>
              </div>
              <div>
                <h5 class="fw-bold" data-lp-key="about_feat_qr_title"<?php echo about_block_style('about_feat_qr_title'); ?>><?php echo about_block('about_feat_qr_title','QR-Based Claiming System'); ?></h5>
                <p class="text-body-secondary mb-0" data-lp-key="about_feat_qr_body"<?php echo about_block_style('about_feat_qr_body'); ?>><?php echo about_block('about_feat_qr_body','Secure, fast verification on distribution day. Each student receives a unique QR code that prevents fraud and speeds up the claiming process.'); ?></p>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <div class="d-flex gap-3">
              <div class="bg-success rounded p-3 flex-shrink-0">
                <i class="bi bi-bell text-white fs-4"></i>
              </div>
              <div>
                <h5 class="fw-bold" data-lp-key="about_feat_notifications_title"<?php echo about_block_style('about_feat_notifications_title'); ?>><?php echo about_block('about_feat_notifications_title','Real-Time Notifications'); ?></h5>
                <p class="text-body-secondary mb-0" data-lp-key="about_feat_notifications_body"<?php echo about_block_style('about_feat_notifications_body'); ?>><?php echo about_block('about_feat_notifications_body','Instant updates via SMS and email about application status, requirements, schedules, and important announcements.'); ?></p>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <div class="d-flex gap-3">
              <div class="bg-warning rounded p-3 flex-shrink-0">
                <i class="bi bi-cloud-upload text-white fs-4"></i>
              </div>
              <div>
                <h5 class="fw-bold" data-lp-key="about_feat_documents_title"<?php echo about_block_style('about_feat_documents_title'); ?>><?php echo about_block('about_feat_documents_title','Digital Document Management'); ?></h5>
                <p class="text-body-secondary mb-0" data-lp-key="about_feat_documents_body"<?php echo about_block_style('about_feat_documents_body'); ?>><?php echo about_block('about_feat_documents_body','Upload and manage all required documents digitally. Automated validation and secure storage with backup systems.'); ?></p>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <div class="d-flex gap-3">
              <div class="bg-info rounded p-3 flex-shrink-0">
                <i class="bi bi-graph-up text-white fs-4"></i>
              </div>
              <div>
                <h5 class="fw-bold" data-lp-key="about_feat_analytics_title"<?php echo about_block_style('about_feat_analytics_title'); ?>><?php echo about_block('about_feat_analytics_title','Analytics & Reporting'); ?></h5>
                <p class="text-body-secondary mb-0" data-lp-key="about_feat_analytics_body"<?php echo about_block_style('about_feat_analytics_body'); ?>><?php echo about_block('about_feat_analytics_body','Comprehensive reporting for administrators and transparent statistics for the public on distribution and impact metrics.'); ?></p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Statistics -->
  <section class="py-5 bg-primary text-white">
    <div class="container">
      <div class="text-center mb-5">
  <h2 class="fw-bold" data-lp-key="about_stats_heading"<?php echo about_block_style('about_stats_heading'); ?>><?php echo about_block('about_stats_heading','EducAid by the Numbers'); ?></h2>
  <p class="opacity-75" data-lp-key="about_stats_lead"<?php echo about_block_style('about_stats_lead'); ?>><?php echo about_block('about_stats_lead','Making a measurable impact in General Trias education'); ?></p>
      </div>
      <div class="row text-center g-4">
        <div class="col-6 col-md-3">
          <div class="h1 fw-bold" data-lp-key="about_stat_barangays_num"<?php echo about_block_style('about_stat_barangays_num'); ?>><?php echo about_block('about_stat_barangays_num','50+'); ?></div>
          <div class="small opacity-75" data-lp-key="about_stat_barangays_label"<?php echo about_block_style('about_stat_barangays_label'); ?>><?php echo about_block('about_stat_barangays_label','Barangays Served'); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="h1 fw-bold" data-lp-key="about_stat_students_num"<?php echo about_block_style('about_stat_students_num'); ?>><?php echo about_block('about_stat_students_num','5,000+'); ?></div>
          <div class="small opacity-75" data-lp-key="about_stat_students_label"<?php echo about_block_style('about_stat_students_label'); ?>><?php echo about_block('about_stat_students_label','Students Assisted'); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="h1 fw-bold" data-lp-key="about_stat_assistance_num"<?php echo about_block_style('about_stat_assistance_num'); ?>><?php echo about_block('about_stat_assistance_num','₱10M+'); ?></div>
          <div class="small opacity-75" data-lp-key="about_stat_assistance_label"<?php echo about_block_style('about_stat_assistance_label'); ?>><?php echo about_block('about_stat_assistance_label','Total Assistance Distributed'); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="h1 fw-bold" data-lp-key="about_stat_uptime_num"<?php echo about_block_style('about_stat_uptime_num'); ?>><?php echo about_block('about_stat_uptime_num','99.9%'); ?></div>
          <div class="small opacity-75" data-lp-key="about_stat_uptime_label"<?php echo about_block_style('about_stat_uptime_label'); ?>><?php echo about_block('about_stat_uptime_label','System Uptime'); ?></div>
        </div>
      </div>
    </div>
  </section>

  <!-- Team & Partnership -->
  <section class="py-5">
    <div class="container">
      <div class="row g-5 align-items-center">
        <div class="col-lg-6">
          <h2 class="section-title" data-lp-key="about_partnership_heading"<?php echo about_block_style('about_partnership_heading'); ?>><?php echo about_block('about_partnership_heading','Partnerships & Collaboration'); ?></h2>
          <p class="section-lead" data-lp-key="about_partnership_lead"<?php echo about_block_style('about_partnership_lead'); ?>><?php echo about_block('about_partnership_lead','EducAid is a collaborative effort between multiple departments and agencies working together for student success.'); ?></p>
          
          <div class="d-flex flex-column gap-3">
            <div class="d-flex gap-3">
              <i class="bi bi-building text-primary fs-5 mt-1"></i>
              <div>
                <h6 class="fw-bold mb-1" data-lp-key="about_partner_mayor_title"<?php echo about_block_style('about_partner_mayor_title'); ?>><?php echo about_block('about_partner_mayor_title','Office of the Mayor'); ?></h6>
                <small class="text-body-secondary" data-lp-key="about_partner_mayor_body"<?php echo about_block_style('about_partner_mayor_body'); ?>><?php echo about_block('about_partner_mayor_body','Policy direction and executive oversight'); ?></small>
              </div>
            </div>
            <div class="d-flex gap-3">
              <i class="bi bi-mortarboard text-primary fs-5 mt-1"></i>
              <div>
                <h6 class="fw-bold mb-1" data-lp-key="about_partner_edu_title"<?php echo about_block_style('about_partner_edu_title'); ?>><?php echo about_block('about_partner_edu_title','Education Department'); ?></h6>
                <small class="text-body-secondary" data-lp-key="about_partner_edu_body"<?php echo about_block_style('about_partner_edu_body'); ?>><?php echo about_block('about_partner_edu_body','Program development and student outreach'); ?></small>
              </div>
            </div>
            <div class="d-flex gap-3">
              <i class="bi bi-laptop text-primary fs-5 mt-1"></i>
              <div>
                <h6 class="fw-bold mb-1" data-lp-key="about_partner_it_title"<?php echo about_block_style('about_partner_it_title'); ?>><?php echo about_block('about_partner_it_title','IT Department'); ?></h6>
                <small class="text-body-secondary" data-lp-key="about_partner_it_body"<?php echo about_block_style('about_partner_it_body'); ?>><?php echo about_block('about_partner_it_body','System development and technical support'); ?></small>
              </div>
            </div>
            <div class="d-flex gap-3">
              <i class="bi bi-people text-primary fs-5 mt-1"></i>
              <div>
                <h6 class="fw-bold mb-1" data-lp-key="about_partner_social_title"<?php echo about_block_style('about_partner_social_title'); ?>><?php echo about_block('about_partner_social_title','Social Services'); ?></h6>
                <small class="text-body-secondary" data-lp-key="about_partner_social_body"<?php echo about_block_style('about_partner_social_body'); ?>><?php echo about_block('about_partner_social_body','Needs assessment and verification'); ?></small>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <h5 class="fw-bold mb-3" data-lp-key="about_contact_panel_title"<?php echo about_block_style('about_contact_panel_title'); ?>><?php echo about_block('about_contact_panel_title','Contact Our Team'); ?></h5>
            <div class="d-flex flex-column gap-2">
              <div class="d-flex gap-2">
                <i class="bi bi-envelope text-primary"></i>
                <span data-lp-key="about_contact_email"<?php echo about_block_style('about_contact_email'); ?>><?php echo about_block('about_contact_email','educaid@generaltrias.gov.ph'); ?></span>
              </div>
              <div class="d-flex gap-2">
                <i class="bi bi-telephone text-primary"></i>
                <span data-lp-key="about_contact_phone"<?php echo about_block_style('about_contact_phone'); ?>><?php echo about_block('about_contact_phone','(046) 886-4454'); ?></span>
              </div>
              <div class="d-flex gap-2">
                <i class="bi bi-geo-alt text-primary"></i>
                <span data-lp-key="about_contact_address"<?php echo about_block_style('about_contact_address'); ?>><?php echo about_block('about_contact_address','City Government of General Trias, Cavite'); ?></span>
              </div>
              <div class="d-flex gap-2">
                <i class="bi bi-clock text-primary"></i>
                <span data-lp-key="about_contact_hours"<?php echo about_block_style('about_contact_hours'); ?>><?php echo about_block('about_contact_hours','Monday - Friday, 8:00 AM - 5:00 PM'); ?></span>
              </div>
            </div>
            <div class="mt-3 pt-3 border-top">
              <a href="contact.php" class="btn btn-primary me-2" data-lp-key="about_contact_support_btn"<?php echo about_block_style('about_contact_support_btn'); ?>><?php echo about_block('about_contact_support_btn','Get Support'); ?></a>
              <a href="how-it-works.php" class="btn btn-outline-primary" data-lp-key="about_contact_how_btn"<?php echo about_block_style('about_contact_how_btn'); ?>><?php echo about_block('about_contact_how_btn','Learn How It Works'); ?></a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="pt-5 pb-4">
    <div class="container">
      <div class="row g-4 align-items-center">
        <div class="col-lg-6">
          <div class="d-flex align-items-center gap-3">
            <div class="brand-badge">EA</div>
            <div>
              <div class="footer-logo" data-lp-key="about_footer_brand"<?php echo about_block_style('about_footer_brand'); ?>><?php echo about_block('about_footer_brand','EducAid • General Trias'); ?></div>
              <small data-lp-key="about_footer_tagline"<?php echo about_block_style('about_footer_tagline'); ?>><?php echo about_block('about_footer_tagline','Empowering students through accessible educational assistance'); ?></small>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="row">
            <div class="col-6 col-md-4">
              <h6 data-lp-key="about_footer_col1_title"<?php echo about_block_style('about_footer_col1_title'); ?>><?php echo about_block('about_footer_col1_title','Explore'); ?></h6>
              <ul class="list-unstyled small" data-lp-key="about_footer_col1_links"<?php echo about_block_style('about_footer_col1_links'); ?>><?php echo about_block('about_footer_col1_links','<li><a href="landingpage.php">Home</a></li><li><a href="about.php">About</a></li><li><a href="how-it-works.php">How It Works</a></li>'); ?></ul>
            </div>
            <div class="col-6 col-md-4">
              <h6 data-lp-key="about_footer_col2_title"<?php echo about_block_style('about_footer_col2_title'); ?>><?php echo about_block('about_footer_col2_title','Resources'); ?></h6>
              <ul class="list-unstyled small" data-lp-key="about_footer_col2_links"<?php echo about_block_style('about_footer_col2_links'); ?>><?php echo about_block('about_footer_col2_links','<li><a href="requirements.php">Requirements</a></li><li><a href="landingpage.php#faq">FAQs</a></li><li><a href="contact.php">Contact</a></li>'); ?></ul>
            </div>
            <div class="col-12 col-md-4 mt-3 mt-md-0">
              <h6 data-lp-key="about_footer_newsletter_title"<?php echo about_block_style('about_footer_newsletter_title'); ?>><?php echo about_block('about_footer_newsletter_title','Stay Updated'); ?></h6>
              <form class="d-flex gap-2">
                <input type="email" class="form-control" placeholder="Email address" />
                <button class="btn btn-light" type="button">Subscribe</button>
              </form>
            </div>
          </div>
        </div>
      </div>
      <hr class="border-light opacity-25 my-4" />
      <div class="d-flex justify-content-between flex-wrap gap-2 small">
  <span data-lp-key="about_footer_copyright"<?php echo about_block_style('about_footer_copyright'); ?>><?php echo about_block('about_footer_copyright','© <span id="year"></span> City Government of General Trias • EducAid'); ?></span>
  <span data-lp-key="about_footer_powered"<?php echo about_block_style('about_footer_powered'); ?>><?php echo about_block('about_footer_powered','Powered by the Office of the Mayor • IT'); ?></span>
      </div>
    </div>
  </footer>

  <!-- Chatbot Widget -->
<div class="ea-chat">
  <button class="ea-chat__toggle" id="eaToggle">
    <i class="bi bi-chat-dots-fill"></i>
    Chat with EducAid
  </button>
  <div class="ea-chat__panel" id="eaPanel">
    <div class="ea-chat__header">
      <span>🤖 EducAid Assistant</span>
      <button class="ea-chat__close" id="eaClose" aria-label="Close chat">×</button>
    </div>
    <div class="ea-chat__body" id="eaBody">
      <div class="ea-chat__msg">
        <div class="ea-chat__bubble">
          👋 Hi! I can help with EducAid requirements, schedules, and account questions.
        </div>
      </div>
      <div class="ea-typing" id="eaTyping">EducAid Assistant is typing</div>
    </div>
    <div class="ea-chat__footer">
      <input class="ea-chat__input" id="eaInput" placeholder="Type your message…" />
      <button class="ea-chat__send" id="eaSend">Send</button>
    </div>
  </div>
</div>

  <script>
    // Current year
    document.getElementById('year').textContent = new Date().getFullYear();
    
    // Smooth scroll animations
    const observerOptions = {
      threshold: 0.1,
      rootMargin: '0px 0px -10% 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
        }
      });
    }, observerOptions);
    
    // Observe all cards
    document.querySelectorAll('.soft-card').forEach(el => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(20px)';
      el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
      observer.observe(el);
    });
  </script>

  <!-- Chatbot script -->
<script>
// Enhanced EducAid Chatbot
document.addEventListener('DOMContentLoaded', function() {
  const apiUrl = '../chatbot/gemini_chat.php'; // corrected relative path
  const toggle = document.getElementById('eaToggle');
  const panel  = document.getElementById('eaPanel');
  const close  = document.getElementById('eaClose');
  const body   = document.getElementById('eaBody');
  const input  = document.getElementById('eaInput');
  const send   = document.getElementById('eaSend');
  const typing = document.getElementById('eaTyping');

  let isOpen = false;

  // Toggle chatbot panel
  function toggleChat() {
    isOpen = !isOpen;
    panel.style.display = isOpen ? 'block' : 'none';
    if (isOpen) {
      input.focus();
    }
  }

  // Event listeners
  toggle.addEventListener('click', toggleChat);
  close.addEventListener('click', toggleChat);

  // Send message function
  async function sendMsg() {
    const text = input.value.trim();
    if (!text) return;
    
    input.value = '';
    input.disabled = true;

    // Add user message
    const userMsg = document.createElement('div');
    userMsg.className = 'ea-chat__msg ea-chat__msg--user';
    userMsg.innerHTML = `<div class="ea-chat__bubble ea-chat__bubble--user"></div>`;
    userMsg.querySelector('.ea-chat__bubble').textContent = text;
    body.appendChild(userMsg);
    body.scrollTop = body.scrollHeight;

    // Show typing indicator
    typing.style.display = 'block';

    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ message: text })
      });

      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }

      const data = await res.json();
      const reply = data.reply || 'Sorry, I could not understand that.';

      // Add bot response
      const botMsg = document.createElement('div');
      botMsg.className = 'ea-chat__msg';
      botMsg.innerHTML = `<div class="ea-chat__bubble"></div>`;
      const formattedReply = formatChatbotResponse(reply);
      botMsg.querySelector('.ea-chat__bubble').innerHTML = formattedReply;
      body.appendChild(botMsg);

    } catch (error) {
      console.error('Chatbot error:', error);
      
      // Add error message
      const errMsg = document.createElement('div');
      errMsg.className = 'ea-chat__msg';
      errMsg.innerHTML = `<div class="ea-chat__bubble">Sorry, I'm having trouble connecting. Please try again later or contact support at educaid@generaltrias.gov.ph</div>`;
      body.appendChild(errMsg);
      
    } finally {
      // Hide typing indicator and re-enable input
      typing.style.display = 'none';
      input.disabled = false;
      input.focus();
      body.scrollTop = body.scrollHeight;
    }
  }

  // Event listeners for sending messages
  send.addEventListener('click', sendMsg);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMsg();
    }
  });

  // Close chat when clicking outside
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.ea-chat') && isOpen) {
      toggleChat();
    }
  });
});

// ALTERNATIVE: Simpler formatting function
function formatChatbotResponse(text) {
  return text
    // Clean up single asterisks first (remove them)
    .replace(/(?<!\*)\*(?!\*)/g, '')
    
    // Convert bold headers with colons - add spacing class
    .replace(/\*\*([^:]+):\*\*/g, '<div class="req-header-spaced"><strong>$1:</strong></div>')
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    
    // Convert bullet points/dashes to list items
    .replace(/^[-•]\s*(.+)$/gm, '<div class="req-item">$1</div>')
    
    // Handle line breaks - keep double breaks as section separators
    .replace(/\n\n+/g, '<div class="req-spacer"></div>')
    .replace(/\n/g, '<br>')
    
    // Clean up any remaining asterisks
    .replace(/\*/g, '');
}
</script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Mobile Navbar JS -->
  <script src="../assets/js/website/mobile-navbar.js"></script>
  <?php if($IS_EDIT_MODE): ?>
  <script src="../assets/js/website/content_editor.js"></script>
  <script>
  // Initialize shared ContentEditor for About page
  ContentEditor.init({
    page: 'about',
    pageTitle: 'About Page',
    saveEndpoint: 'ajax_save_about_content.php',
    resetAllEndpoint: 'ajax_reset_about_content.php',
    history: { fetchEndpoint: 'ajax_get_about_history.php', rollbackEndpoint: 'ajax_rollback_about_block.php' },
    refreshAfterSave: async (keys)=>{
      try {
        const r = await fetch('ajax_get_about_blocks.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({keys})});
        const d = await r.json(); if(!d.success) return;
        (d.blocks||[]).forEach(b=>{ const el=document.querySelector('[data-lp-key="'+CSS.escape(b.block_key)+'"]'); if(!el) return; el.innerHTML=b.html; if(b.text_color) el.style.color=b.text_color; else el.style.removeProperty('color'); if(b.bg_color) el.style.backgroundColor=b.bg_color; else el.style.removeProperty('background-color'); });
      } catch(err){ console.error('Refresh error', err); }
    }
  });
  </script>
  <?php endif; ?>
</body>
</html>