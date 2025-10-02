<?php
// Landing page with optional super admin inline edit mode
// Start session
session_start();

$IS_EDIT_SUPER_ADMIN = false;
// Detect super admin attempting to edit (bypass captcha gate)
if (isset($_GET['edit']) && $_GET['edit'] == '1' && isset($_SESSION['admin_id'])) {
  @include_once __DIR__ . '/../config/database.php';
  @include_once __DIR__ . '/../includes/permissions.php';
  if (function_exists('getCurrentAdminRole') && isset($connection)) {
    $role = @getCurrentAdminRole($connection);
    if ($role === 'super_admin') {
      $IS_EDIT_SUPER_ADMIN = true;
    }
  }
}

if (!$IS_EDIT_SUPER_ADMIN) {
  // Standard public captcha verification flow
  if (!isset($_SESSION['captcha_verified']) || $_SESSION['captcha_verified'] !== true) {
      header('Location: security_verification.php');
      exit;
  }
  $verificationTime = $_SESSION['captcha_verified_time'] ?? 0;
  $expirationTime = 24 * 60 * 60; // 24 hours
  if (time() - $verificationTime > $expirationTime) {
      unset($_SESSION['captcha_verified'], $_SESSION['captcha_verified_time']);
      header('Location: security_verification.php');
      exit;
  }
} else {
  // Ensure DB available for downstream usage
  if (!isset($connection)) {
    @include_once __DIR__ . '/../config/database.php';
  }
}

// Include reCAPTCHA v2 configuration
require_once '../config/recaptcha_v2_config.php';
// Bring in database for dynamic announcements preview
require_once '../config/database.php';

// Fetch latest 3 announcements for landing page preview
$landing_announcements = [];
$ann_res = @pg_query($connection, "SELECT announcement_id, title, remarks, posted_at, event_date, event_time, location, image_path, is_active FROM announcements ORDER BY posted_at DESC LIMIT 3");
if ($ann_res) {
  while($row = pg_fetch_assoc($ann_res)) { $landing_announcements[] = $row; }
  pg_free_result($ann_res);
}
function lp_truncate($text, $limit = 140){ $text = trim($text); return mb_strlen($text) > $limit ? mb_substr($text,0,$limit).'…' : $text; }
function lp_event_line($row){
  $parts = [];
  if (!empty($row['event_date'])) { $d = DateTime::createFromFormat('Y-m-d',$row['event_date']); if($d) $parts[] = $d->format('M d, Y'); }
  if (!empty($row['event_time'])) { $t = DateTime::createFromFormat('H:i:s',$row['event_time']); if($t) $parts[] = $t->format('g:i A'); }
  return implode(' • ',$parts);
}
function lp_esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Simple sanitizer to strip script tags & dangerous inline event handlers from stored HTML
function lp_sanitize_html($html){
  $html = preg_replace('#<script[^>]*>.*?</script>#is','',$html); // remove scripts
  // remove on* attributes and javascript: urls
  $html = preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i','',$html);
  $html = preg_replace("/on[a-zA-Z]+\s*=\s*'[^']*'/i",'', $html);
  $html = preg_replace('/javascript:/i','',$html);
  return $html;
}

// Direct load of landing content blocks (no caching layer)
$LP_SAVED_BLOCKS = [];
$resBlocksSSR = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM landing_content_blocks WHERE municipality_id=1");
if ($resBlocksSSR) {
  while($r = pg_fetch_assoc($resBlocksSSR)) { $LP_SAVED_BLOCKS[$r['block_key']] = $r; }
  pg_free_result($resBlocksSSR);
}
function lp_block($key, $defaultHtml){
  global $LP_SAVED_BLOCKS; if(isset($LP_SAVED_BLOCKS[$key])){ $h=$LP_SAVED_BLOCKS[$key]['html']; $h = lp_sanitize_html($h); return $h!==''? $h : $defaultHtml; } return $defaultHtml; }
function lp_block_style($key){
  global $LP_SAVED_BLOCKS; if(!isset($LP_SAVED_BLOCKS[$key])) return '';
  $r = $LP_SAVED_BLOCKS[$key]; $s=[]; if(!empty($r['text_color'])) $s[]='color:'.$r['text_color']; if(!empty($r['bg_color'])) $s[]='background-color:'.$r['bg_color']; return $s? ' style="'.implode(';',$s).'"':''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo strip_tags(lp_block('page_title','EducAid – City of General Trias')); ?></title>
  <meta name="description" content="Educational Assistance Management System for the City of General Trias" />

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="../assets/css/website/landing_page.css" rel="stylesheet" />
  <link href="../assets/css/website/recaptcha_v2.css" rel="stylesheet" />
  <?php // Dynamic theme variables (colors, hero gradient, etc.)
    @include_once __DIR__ . '/../includes/website/landing_theme_loader.php';
  ?>
  <style>
    /* Skeleton loader styles for announcements */
    .ann-skeleton { position:relative; overflow:hidden; background:#fff; border:1px solid #e5e7eb; border-radius:1rem; }
    .ann-skel-img { background:#e2e8f0; aspect-ratio:16/9; border-top-left-radius:1rem; border-top-right-radius:1rem; }
    .ann-skel-body { padding:.9rem .95rem 1.1rem; }
    .skel-line { height:10px; background:#e2e8f0; border-radius:4px; margin-bottom:8px; }
    .skel-line.short { width:55%; }
    .skel-line.medium { width:75%; }
    .skel-line.long { width:95%; }
    .skeleton-shimmer:before { content:""; position:absolute; inset:0; background:linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,.55) 50%, rgba(255,255,255,0) 100%); transform:translateX(-100%); animation:shimmer 1.4s infinite; }
    @keyframes shimmer { 100% { transform:translateX(100%); } }
    .ann-card.fade-in { opacity:0; transition:opacity .4s ease; }
    .ann-card.visible { opacity:1; }
    /* Compact announcement preview styles */
    .ann-compact-grid > [class*='col'] { display:flex; }
    .ann-compact-card { position:relative; background:#fff; border:1px solid #e2e8f0; border-radius:.85rem; overflow:hidden; display:flex; flex-direction:column; width:100%; box-shadow:0 4px 14px -6px rgba(0,0,0,.06); transition:box-shadow .25s, transform .25s, border-color .25s; }
    .ann-compact-card:hover { box-shadow:0 8px 24px -8px rgba(0,0,0,.12); transform:translateY(-3px); border-color:#bfdbfe; }
    .ann-compact-thumb { width:100%; aspect-ratio:16/9; object-fit:cover; background:#f1f5f9; max-height:135px; }
    .ann-compact-body { padding:.65rem .75rem .7rem; display:flex; flex-direction:column; gap:.35rem; flex:1; }
    .ann-compact-meta { font-size:.58rem; font-weight:600; letter-spacing:.5px; text-transform:uppercase; color:#2563eb; display:flex; flex-wrap:wrap; gap:.4rem; }
  .ann-compact-title { font-size:.82rem; font-weight:600; line-height:1.18; margin:0; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; line-clamp:2; }
    .ann-compact-location { font-size:.6rem; color:#64748b; display:flex; align-items:center; gap:.25rem; }
  .ann-compact-remarks { font-size:.63rem; color:#475569; line-height:1.25; margin:0; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; line-clamp:2; }
    .ann-compact-link { margin-top:auto; font-size:.6rem; font-weight:600; text-decoration:none; color:#2563eb; display:inline-flex; align-items:center; gap:.15rem; }
    .ann-compact-link:hover { text-decoration:underline; }
    .ann-compact-badge { position:absolute; top:.45rem; left:.45rem; font-size:.55rem; }
    @media (min-width: 992px){ .ann-compact-thumb { max-height:125px; } }
    @media (max-width: 576px){ .ann-compact-thumb { aspect-ratio:16/10; } }
  </style>
  
  <!-- Google reCAPTCHA v2 -->
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>
  <?php
  // Determine base path for links
  $base_path = '../';
  
  // Custom navigation for landing page
  $custom_nav_links = [
    ['href' => 'landingpage.php#home', 'label' => 'Home', 'active' => true],
    ['href' => 'about.php', 'label' => 'About', 'active' => false],
    ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => false],
    ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => false],
    ['href' => 'announcements.php', 'label' => 'Announcements', 'active' => false],
    ['href' => 'contact.php', 'label' => 'Contact', 'active' => false]
  ];
  
  include '../includes/website/topbar.php';
  include '../includes/website/navbar.php';
  
  // Determine if super admin edit mode is enabled (?edit=1)
  $IS_EDIT_MODE = false;
  $is_super_admin = false;
  if (isset($_SESSION['admin_id'])) {
    // Re-use permissions helper if available
    @include_once __DIR__ . '/../includes/permissions.php';
    if (function_exists('getCurrentAdminRole')) {
      $role = @getCurrentAdminRole($connection);
      if ($role === 'super_admin') {
        $is_super_admin = true;
      }
    }
  }
  if ($is_super_admin && isset($_GET['edit']) && $_GET['edit'] == '1') {
    $IS_EDIT_MODE = true;
  }
  ?>
  <?php if ($IS_EDIT_MODE): ?>
  <!-- Inline Landing Page Editor (Super Admin Only) -->
  <div id="lp-edit-toolbar" class="lp-edit-toolbar shadow-sm">
    <div class="lp-edit-toolbar-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <strong class="small">Landing Page Editor</strong>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="../modules/admin/homepage.php" class="btn btn-sm btn-outline-primary" title="Return to Admin Dashboard">
          <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <button id="lp-save-btn" class="btn btn-sm btn-success" disabled><i class="bi bi-save me-1"></i>Save</button>
  <button id="lp-save-all-btn" class="btn btn-sm btn-outline-success" title="Save all editable content"><i class="bi bi-cloud-arrow-up me-1"></i>Save All</button>
  <button id="lp-history-btn" class="btn btn-sm btn-outline-secondary" type="button" title="View edit history"><i class="bi bi-clock-history me-1"></i>History</button>
        <a href="landingpage.php" id="lp-exit-btn" class="btn btn-sm btn-outline-secondary" title="Exit Edit Mode"><i class="bi bi-x-lg"></i></a>
      </div>
    </div>
    <div class="lp-edit-toolbar-body small mt-2">
      <div class="mb-2">
        <label class="form-label small mb-1">Selected Element</label>
        <div id="lp-current-target" class="form-control form-control-sm bg-body-tertiary" style="height:auto; min-height:32px; font-size:.65rem; overflow:auto"></div>
      </div>
      <div class="mb-2">
        <label class="form-label small mb-1">Text Content</label>
        <textarea id="lp-edit-text" class="form-control form-control-sm" rows="3" placeholder="Click an editable element on the page"></textarea>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6">
          <label class="form-label small mb-1">Text Color</label>
          <input type="color" id="lp-text-color" class="form-control form-control-color form-control-sm" value="#000000" title="Change text color" />
        </div>
        <div class="col-6">
          <label class="form-label small mb-1">BG Color</label>
          <input type="color" id="lp-bg-color" class="form-control form-control-color form-control-sm" value="#ffffff" title="Change background color" />
        </div>
      </div>
      <div class="d-flex flex-column gap-2">
        <div class="d-flex gap-2">
          <button id="lp-reset-btn" class="btn btn-sm btn-outline-warning w-100" type="button" disabled><i class="bi bi-arrow-counterclockwise me-1"></i>Reset Block</button>
          <button id="lp-highlight-toggle" class="btn btn-sm btn-outline-primary w-100" type="button" data-active="1"><i class="bi bi-bounding-box-circles me-1"></i>Hide Boxes</button>
        </div>
        <button id="lp-reset-all" class="btn btn-sm btn-outline-danger w-100" type="button"><i class="bi bi-trash3 me-1"></i>Reset All Blocks</button>
      </div>
      <div class="mt-2 text-end">
        <small class="text-muted" id="lp-status">Idle</small>
      </div>
    </div>
  </div>
  <style>
    .lp-edit-toolbar { position:fixed; top:70px; right:12px; width:300px; background:#fff; border:1px solid #d1d9e0; border-radius:12px; z-index:4000; padding:.75rem .85rem; font-family: system-ui, sans-serif; }
    .lp-edit-highlight { outline:2px dashed #2563eb; outline-offset:2px; cursor:text; position:relative; }
    .lp-edit-highlight:hover { outline-color:#1d4ed8; }
    .lp-edit-highlight[data-lp-dirty="1"]::after { content:'●'; position:absolute; top:-6px; right:-6px; background:#dc2626; color:#fff; width:14px; height:14px; font-size:.55rem; display:flex; align-items:center; justify-content:center; border-radius:50%; font-weight:700; box-shadow:0 0 0 2px #fff; }
    .lp-edit-toolbar textarea { font-size:.7rem; }
    .lp-edit-toolbar .form-label { font-size:.6rem; letter-spacing:.5px; text-transform:uppercase; }
    .lp-edit-toolbar-header { border-bottom:1px solid #e2e8f0; padding-bottom:.25rem; }
    body.lp-editing { scroll-padding-top:90px; }
    .lp-edit-badge { position:fixed; left:12px; top:70px; background:#1d4ed8; color:#fff; padding:4px 10px; font-size:.65rem; font-weight:600; letter-spacing:.5px; border-radius:30px; z-index:4000; display:flex; align-items:center; gap:4px; box-shadow:0 2px 4px rgba(0,0,0,.2);}    
    .lp-edit-badge .dot { width:6px; height:6px; background:#22c55e; border-radius:50%; box-shadow:0 0 0 2px rgba(255,255,255,.4); }
  </style>
  <div class="lp-edit-badge"><span class="dot"></span> EDIT MODE</div>
  <?php endif; ?>

  <!-- Hero -->
  <header id="home" class="hero">
    <div class="container">
      <div class="row align-items-center justify-content-center">
        <div class="col-12 col-lg-9">
          <div class="hero-card text-center text-lg-start">
            <div class="d-flex flex-column flex-lg-row align-items-center gap-4">
              <div class="flex-grow-1">
                <?php echo '<span class="badge text-bg-primary-subtle text-primary rounded-pill mb-2" data-lp-key="hero_badge"'.lp_block_style('hero_badge').'>'.lp_block('hero_badge','<i class="bi bi-stars me-2"></i>General Trias Scholarship & Aid').'</span>'; ?>
                <?php echo '<h1 class="display-5 mb-2" data-lp-key="hero_title"'.lp_block_style('hero_title').'>'.lp_block('hero_title','Educational Assistance, Simplified.').'</h1>'; ?>
                <?php echo '<p class="mb-4" data-lp-key="hero_sublead"'.lp_block_style('hero_sublead').'>'.lp_block('hero_sublead','Apply, upload requirements, track status, and claim assistance with QR — all in one city-run portal designed for students and families in General Trias.').'</p>'; ?>
                <div class="d-flex gap-2 justify-content-center justify-content-lg-start">
                  <a href="<?php echo $base_path; ?>register.php" class="btn cta-btn btn-primary-custom" data-lp-key="hero_cta_apply"><i class="bi bi-journal-text me-2"></i>Apply Now</a>
                  <a href="<?php echo $base_path; ?>unified_login.php" class="btn cta-btn btn-outline-custom" data-lp-key="hero_cta_signin"><i class="bi bi-box-arrow-in-right me-2"></i>Sign In</a>
                </div>
              </div>
              <div class="text-center">
                <img src="https://images.unsplash.com/photo-1587825140708-dfaf72ae4b04?q=80&w=1000&auto=format&fit=crop" alt="Students" class="img-fluid rounded-2xl shadow-soft" style="max-width:360px" />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- Quick links -->
  <div class="quick-links" data-lp-key="quick_links_wrapper"<?php echo lp_block_style('quick_links_wrapper'); ?>>
    <div class="container">
      <div class="row g-3 g-md-4">
        <div class="col-6 col-lg">
          <a class="ql-card" href="announcements.php" data-lp-key="quick_link_announcements"<?php echo lp_block_style('quick_link_announcements'); ?>><?php echo lp_block('quick_link_announcements','<span class="ql-icon"><i class="bi bi-megaphone"></i></span><span>Latest Announcements</span>'); ?></a>
        </div>
        <div class="col-6 col-lg">
          <a class="ql-card" href="requirements.php" data-lp-key="quick_link_requirements"<?php echo lp_block_style('quick_link_requirements'); ?>><?php echo lp_block('quick_link_requirements','<span class="ql-icon"><i class="bi bi-list-check"></i></span><span>Requirements</span>'); ?></a>
        </div>
        <div class="col-6 col-lg">
          <a class="ql-card" href="how-it-works.php" data-lp-key="quick_link_how"<?php echo lp_block_style('quick_link_how'); ?>><?php echo lp_block('quick_link_how','<span class="ql-icon"><i class="bi bi-gear-wide-connected"></i></span><span>How It Works</span>'); ?></a>
        </div>
        <div class="col-6 col-lg">
          <a class="ql-card" href="#faq" data-lp-key="quick_link_faq"<?php echo lp_block_style('quick_link_faq'); ?>><?php echo lp_block('quick_link_faq','<span class="ql-icon"><i class="bi bi-question-circle"></i></span><span>FAQs</span>'); ?></a>
        </div>
        <div class="col-12 col-lg">
          <a class="ql-card" href="#contact" data-lp-key="quick_link_contact"<?php echo lp_block_style('quick_link_contact'); ?>><?php echo lp_block('quick_link_contact','<span class="ql-icon"><i class="bi bi-telephone"></i></span><span>Contact & Helpdesk</span>'); ?></a>
        </div>
      </div>
    </div>
  </div>

  <!-- Mayor's Message -->
  <section class="mayor-section bg-body-tertiary">
    <div class="container">
      <div class="row g-4 align-items-center">
        <div class="col-md-2 text-center text-md-start">
          <img class="mayor-photo" src="https://www.generaltrias.gov.ph/storage/image_upload/mayor.PNG" alt="Mayor Jon-Jon Ferrer" />
        </div>
        <div class="col-md-10">
          <?php echo '<h2 class="section-title mb-2" data-lp-key="mayor_title"'.lp_block_style('mayor_title').'>'.lp_block('mayor_title','Message from the Mayor').'</h2>'; ?>
          <?php echo '<p class="mb-2" data-lp-key="mayor_paragraph_1"'.lp_block_style('mayor_paragraph_1').'>'.lp_block('mayor_paragraph_1',"Welcome to the City Government of General Trias' online platform — built to enhance connectivity, accessibility, and transparency for our thriving community. Our vision is a modern and sustainable city where every citizen can prosper.").'</p>'; ?>
          <?php echo '<p class="mb-2" data-lp-key="mayor_paragraph_2"'.lp_block_style('mayor_paragraph_2').'>'.lp_block('mayor_paragraph_2','Through this portal, we aim to empower students and families with timely information and accessible services, upholding transparency and accountability in governance.').'</p>'; ?>
          <div class="d-flex align-items-center gap-3 mb-2">
            <img class="mayor-sign" src="https://www.generaltrias.gov.ph/storage/image_upload/mayorpng.png" alt="Mayor signature" />
            <div class="small">
              <strong>Hon. Luis "Jon‑Jon" Ferrer IV</strong><br/>
              City Mayor, General Trias
            </div>
          </div>
          <div class="mt-2">
            <a class="link-primary" href="https://generaltrias.gov.ph/" target="_blank" rel="noopener">Read full message on the official website</a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- About -->
  <section id="about">
    <div class="container">
      <div class="row align-items-center g-4">
        <div class="col-lg-6">
          <?php echo '<h2 class="section-title mb-3" data-lp-key="about_title"'.lp_block_style('about_title').'>'.lp_block('about_title','What is <span class="text-primary">EducAid</span>?').'</h2>'; ?>
          <?php echo '<p class="section-lead" data-lp-key="about_lead"'.lp_block_style('about_lead').'>'.lp_block('about_lead',"EducAid is the City of General Trias' official Educational Assistance Management System. Built with transparency and accessibility in mind, it streamlines application, evaluation, release, and reporting of aid for qualified students.").'</p>'; ?>
          <div class="row g-3 mt-2">
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex align-items-center gap-2 mb-1" data-lp-key="about_feature_secure_title"<?php echo lp_block_style('about_feature_secure_title'); ?>><?php echo lp_block('about_feature_secure_title','<i class="bi bi-lock-fill text-success"></i><strong>Secure & Private</strong>'); ?></div>
                <p class="mb-0 small text-body-secondary" data-lp-key="about_feature_secure_desc"<?php echo lp_block_style('about_feature_secure_desc'); ?>><?php echo lp_block('about_feature_secure_desc','Data protected under RA 10173 and city policies.'); ?></p>
              </div>
            </div>
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex align-items-center gap-2 mb-1" data-lp-key="about_feature_qr_title"<?php echo lp_block_style('about_feature_qr_title'); ?>><?php echo lp_block('about_feature_qr_title','<i class="bi bi-qr-code text-success"></i><strong>QR-based Claiming</strong>'); ?></div>
                <p class="mb-0 small text-body-secondary" data-lp-key="about_feature_qr_desc"<?php echo lp_block_style('about_feature_qr_desc'); ?>><?php echo lp_block('about_feature_qr_desc','Fast verification on distribution day via secure QR codes.'); ?></p>
              </div>
            </div>
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex align-items-center gap-2 mb-1" data-lp-key="about_feature_updates_title"<?php echo lp_block_style('about_feature_updates_title'); ?>><?php echo lp_block('about_feature_updates_title','<i class="bi bi-bell-fill text-success"></i><strong>Real-time Updates</strong>'); ?></div>
                <p class="mb-0 small text-body-secondary" data-lp-key="about_feature_updates_desc"<?php echo lp_block_style('about_feature_updates_desc'); ?>><?php echo lp_block('about_feature_updates_desc','Get notified on slots, schedules, and requirements.'); ?></p>
              </div>
            </div>
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex align-items-center gap-2 mb-1" data-lp-key="about_feature_lgu_title"<?php echo lp_block_style('about_feature_lgu_title'); ?>><?php echo lp_block('about_feature_lgu_title','<i class="bi bi-people-fill text-success"></i><strong>LGU-Managed</strong>'); ?></div>
                <p class="mb-0 small text-body-secondary" data-lp-key="about_feature_lgu_desc"<?php echo lp_block_style('about_feature_lgu_desc'); ?>><?php echo lp_block('about_feature_lgu_desc','Powered by the Office of the Mayor and partner departments.'); ?></p>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <h5 class="fw-bold">At a glance</h5>
            <div class="row text-center g-3 mt-1">
              <div class="col-6 col-md-3"><div class="p-3 rounded-2xl bg-body-tertiary"><div class="h4 mb-0">50+</div><small class="text-body-secondary">Barangays</small></div></div>
              <div class="col-6 col-md-3"><div class="p-3 rounded-2xl bg-body-tertiary"><div class="h4 mb-0">5k+</div><small class="text-body-secondary">Beneficiaries</small></div></div>
              <div class="col-6 col-md-3"><div class="p-3 rounded-2xl bg-body-tertiary"><div class="h4 mb-0">100%</div><small class="text-body-secondary">Transparency</small></div></div>
              <div class="col-6 col-md-3"><div class="p-3 rounded-2xl bg-body-tertiary"><div class="h4 mb-0">24/7</div><small class="text-body-secondary">Access</small></div></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- How it works -->
  <section id="how" class="fade-in">
    <div class="container">
      <div class="row mb-4">
        <div class="col-lg-8 fade-in-left">
          <?php echo '<h2 class="section-title" data-lp-key="how_title"'.lp_block_style('how_title').'>'.lp_block('how_title','How it works').'</h2>'; ?>
          <?php echo '<p class="section-lead" data-lp-key="how_lead"'.lp_block_style('how_lead').'>'.lp_block('how_lead','A simple four-step process from online application to aid claiming.').'</p>'; ?>
        </div>
      </div>
      <div class="row g-3 g-lg-4 fade-in-stagger">
        <div class="col-md-6 col-lg-3 fade-in">
          <div class="soft-card p-3 h-100">
            <div class="step mb-2" data-lp-key="how_step1_title"<?php echo lp_block_style('how_step1_title'); ?>><?php echo lp_block('how_step1_title','<div class="step-badge">1</div><h6 class="mb-0">Create & Verify</h6>'); ?></div>
            <p class="small text-body-secondary mb-0" data-lp-key="how_step1_desc"<?php echo lp_block_style('how_step1_desc'); ?>><?php echo lp_block('how_step1_desc','Register using your email and mobile. Verify via OTP to secure your account.'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3 fade-in">
          <div class="soft-card p-3 h-100">
            <div class="step mb-2" data-lp-key="how_step2_title"<?php echo lp_block_style('how_step2_title'); ?>><?php echo lp_block('how_step2_title','<div class="step-badge">2</div><h6 class="mb-0">Apply Online</h6>'); ?></div>
            <p class="small text-body-secondary mb-0" data-lp-key="how_step2_desc"<?php echo lp_block_style('how_step2_desc'); ?>><?php echo lp_block('how_step2_desc','Complete your profile, select your barangay, and upload required documents.'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3 fade-in">
          <div class="soft-card p-3 h-100">
            <div class="step mb-2" data-lp-key="how_step3_title"<?php echo lp_block_style('how_step3_title'); ?>><?php echo lp_block('how_step3_title','<div class="step-badge">3</div><h6 class="mb-0">Get Evaluated</h6>'); ?></div>
            <p class="small text-body-secondary mb-0" data-lp-key="how_step3_desc"<?php echo lp_block_style('how_step3_desc'); ?>><?php echo lp_block('how_step3_desc','Admins validate eligibility and post status updates with reminders.'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3 fade-in">
          <div class="soft-card p-3 h-100">
            <div class="step mb-2" data-lp-key="how_step4_title"<?php echo lp_block_style('how_step4_title'); ?>><?php echo lp_block('how_step4_title','<div class="step-badge">4</div><h6 class="mb-0">Claim with QR</h6>'); ?></div>
            <p class="small text-body-secondary mb-0" data-lp-key="how_step4_desc"<?php echo lp_block_style('how_step4_desc'); ?>><?php echo lp_block('how_step4_desc','Receive your QR code and bring it on distribution day for quick claiming.'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Announcements -->
  <section id="announcements" class="bg-body-tertiary fade-in">
    <div class="container">
      <div class="d-flex flex-wrap align-items-end justify-content-between mb-2 gap-2">
        <div class="fade-in-left">
          <h2 class="section-title mb-1" style="font-size:1.45rem;">Latest Announcements</h2>
          <p class="section-lead mb-0 small text-body-secondary">Recent official updates & schedules</p>
        </div>
        <div>
          <a href="announcements.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-view-list me-1"></i>All</a>
        </div>
      </div>
      <div class="row g-2 g-lg-3 ann-compact-grid fade-in-stagger" id="annPreviewRow">
        <?php
          $preview_rows = [];
          $resPrev = @pg_query($connection, "SELECT announcement_id, title, remarks, posted_at, event_date, event_time, location, image_path, is_active FROM announcements ORDER BY is_active DESC, posted_at DESC LIMIT 3");
          if($resPrev){ while($r = pg_fetch_assoc($resPrev)){ $preview_rows[] = $r; } pg_free_result($resPrev); }
          if(empty($preview_rows)){
            echo '<div class="col-12"><div class="soft-card p-4 text-center"><h6 class="fw-bold mb-1">No announcements yet</h6><p class="small text-body-secondary mb-2">Official updates will appear here once posted.</p><a href="announcements.php" class="small link-primary">See full page</a></div></div>';
          } else {
            foreach($preview_rows as $a){
              $aid = (int)$a['announcement_id'];
              $title = lp_esc($a['title']);
              $posted = date('M d', strtotime($a['posted_at']));
              $remarks = lp_truncate($a['remarks'] ?? '', 110);
              $img = !empty($a['image_path']) ? '../'.lp_esc($a['image_path']) : 'https://images.unsplash.com/photo-1543269865-cbf427effbad?q=80&w=800&auto=format&fit=crop';
              $eventLine = lp_event_line($a);
              if(strlen($eventLine) > 26){ $eventLine = substr($eventLine,0,24).'…'; }
              $locLine = !empty($a['location']) ? lp_esc($a['location']) : '';
              echo '<div class="col-12 col-md-4 fade-in">';
              echo '<div class="ann-compact-card">';
              if($a['is_active'] === 't' || $a['is_active'] === true){ echo '<span class="badge bg-success ann-compact-badge">Active</span>'; }
              echo '<img src="'.$img.'" alt="Announcement image" class="ann-compact-thumb" />';
              echo '<div class="ann-compact-body">';
              echo '<div class="ann-compact-meta">'.$posted.($eventLine? ' • '.lp_esc($eventLine):'').'</div>';
              echo '<h6 class="ann-compact-title">'.$title.'</h6>';
              if($locLine){ echo '<div class="ann-compact-location"><i class="bi bi-geo-alt"></i><span>'.$locLine.'</span></div>'; }
              echo '<p class="ann-compact-remarks">'.lp_esc($remarks).'</p>';
              echo '<a class="ann-compact-link" href="announcements.php?id='.$aid.'">Full details <i class="bi bi-arrow-right-short"></i></a>';
              echo '</div></div></div>';
            }
          }
        ?>
      </div>
    </div>
  </section>

  <!-- Requirements -->
  <section id="requirements" class="fade-in-scale">
    <div class="container">
      <div class="row mb-4">
        <div class="col-lg-8">
          <?php echo '<h2 class="section-title" data-lp-key="requirements_title"'.lp_block_style('requirements_title').'>'.lp_block('requirements_title','Basic Requirements').'</h2>'; ?>
          <?php echo '<p class="section-lead" data-lp-key="requirements_lead"'.lp_block_style('requirements_lead').'>'.lp_block('requirements_lead','Prepare clear photos or PDFs of the following. Additional documents may be requested for verification.').'</p>'; ?>
        </div>
      </div>
      <div class="row g-4">
        <div class="col-md-6">
          <div class="soft-card p-4 h-100">
            <h6 class="fw-bold mb-3" data-lp-key="requirements_identity_title"<?php echo lp_block_style('requirements_identity_title'); ?>><?php echo lp_block('requirements_identity_title','<i class="bi bi-person-vcard me-2 text-success"></i>Identity & Enrollment'); ?></h6>
            <ul class="list-unstyled m-0 d-grid gap-2">
              <li data-lp-key="req_identity_school_id"<?php echo lp_block_style('req_identity_school_id'); ?>><?php echo lp_block('req_identity_school_id','<i class="bi bi-check2 check me-2"></i>Valid School ID'); ?></li>
              <li data-lp-key="req_identity_eaf"<?php echo lp_block_style('req_identity_eaf'); ?>><?php echo lp_block('req_identity_eaf','<i class="bi bi-check2 check me-2"></i>Enrollment Assessment Form'); ?></li>
              <li data-lp-key="req_identity_coi"<?php echo lp_block_style('req_identity_coi'); ?>><?php echo lp_block('req_identity_coi','<i class="bi bi-check2 check me-2"></i>Certificate of Indigency (after approval)'); ?></li>
              <li data-lp-key="req_identity_letter_mayor"<?php echo lp_block_style('req_identity_letter_mayor'); ?>><?php echo lp_block('req_identity_letter_mayor','<i class="bi bi-check2 check me-2"></i>Letter to the Mayor (PDF)'); ?></li>
            </ul>
          </div>
        </div>
        <div class="col-md-6">
          <div class="soft-card p-4 h-100">
            <h6 class="fw-bold mb-3" data-lp-key="requirements_account_title"<?php echo lp_block_style('requirements_account_title'); ?>><?php echo lp_block('requirements_account_title','<i class="bi bi-shield-lock me-2 text-success"></i>Account & Contact'); ?></h6>
            <ul class="list-unstyled m-0 d-grid gap-2">
              <li data-lp-key="req_account_email"<?php echo lp_block_style('req_account_email'); ?>><?php echo lp_block('req_account_email','<i class="bi bi-check2 check me-2"></i>Active email (OTP verification)'); ?></li>
              <li data-lp-key="req_account_mobile"<?php echo lp_block_style('req_account_mobile'); ?>><?php echo lp_block('req_account_mobile','<i class="bi bi-check2 check me-2"></i>Mobile number for SMS updates'); ?></li>
              <li data-lp-key="req_account_barangay"<?php echo lp_block_style('req_account_barangay'); ?>><?php echo lp_block('req_account_barangay','<i class="bi bi-check2 check me-2"></i>Barangay information'); ?></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- FAQ -->
  <section id="faq" class="bg-body-tertiary">
    <div class="container">
      <div class="row mb-4">
        <div class="col-lg-8">
          <?php echo '<h2 class="section-title" data-lp-key="faq_title"'.lp_block_style('faq_title').'>'.lp_block('faq_title','Frequently Asked Questions').'</h2>'; ?>
          <?php echo '<p class="section-lead" data-lp-key="faq_lead"'.lp_block_style('faq_lead').'>'.lp_block('faq_lead','Quick answers to common concerns about eligibility, slots, and claiming.').'</p>'; ?>
        </div>
      </div>
      <div class="accordion soft-card" id="faqAcc">
        <div class="accordion-item">
          <h2 class="accordion-header" id="q1">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#a1" data-lp-key="faq_q1"<?php echo lp_block_style('faq_q1'); ?>><?php echo lp_block('faq_q1','Who can apply?'); ?></button>
          </h2>
          <div id="a1" class="accordion-collapse collapse show" data-bs-parent="#faqAcc">
            <div class="accordion-body" data-lp-key="faq_a1"<?php echo lp_block_style('faq_a1'); ?>><?php echo lp_block('faq_a1','Students residing in General Trias who meet program criteria set by the LGU and partner agencies.'); ?></div>
          </div>
        </div>
        <div class="accordion-item">
          <h2 class="accordion-header" id="q2">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2" data-lp-key="faq_q2"<?php echo lp_block_style('faq_q2'); ?>><?php echo lp_block('faq_q2','How are slots allocated?'); ?></button>
          </h2>
          <div id="a2" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
            <div class="accordion-body" data-lp-key="faq_a2"<?php echo lp_block_style('faq_a2'); ?>><?php echo lp_block('faq_a2','Slots are released per batch and barangay. Availability appears during registration and closes automatically when filled.'); ?></div>
          </div>
        </div>
        <div class="accordion-item">
          <h2 class="accordion-header" id="q3">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a3" data-lp-key="faq_q3"<?php echo lp_block_style('faq_q3'); ?>><?php echo lp_block('faq_q3','What if I lose my QR code?'); ?></button>
          </h2>
          <div id="a3" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
            <div class="accordion-body" data-lp-key="faq_a3"<?php echo lp_block_style('faq_a3'); ?>><?php echo lp_block('faq_a3','You can re-download it from your dashboard. Bring a valid ID on distribution day for identity verification.'); ?></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Contact -->
  <section id="contact">
    <div class="container">
      <div class="row g-4 align-items-stretch">
        <div class="col-lg-6">
          <div class="soft-card p-4 h-100">
            <h5 class="fw-bold mb-3" data-lp-key="contact_title"<?php echo lp_block_style('contact_title'); ?>><?php echo lp_block('contact_title','Contact & Helpdesk'); ?></h5>
            <p class="text-body-secondary" data-lp-key="contact_intro"<?php echo lp_block_style('contact_intro'); ?>><?php echo lp_block('contact_intro','For inquiries about requirements, schedules, or account issues, reach us here:'); ?></p>
            <ul class="list-unstyled d-grid gap-2 m-0">
              <li data-lp-key="contact_email"<?php echo lp_block_style('contact_email'); ?>><?php echo lp_block('contact_email','<i class="bi bi-envelope me-2 text-primary"></i>educaid@generaltrias.gov.ph'); ?></li>
              <li data-lp-key="contact_phone"<?php echo lp_block_style('contact_phone'); ?>><?php echo lp_block('contact_phone','<i class="bi bi-telephone me-2 text-primary"></i>(046) 886-4454'); ?></li>
              <li data-lp-key="contact_address"<?php echo lp_block_style('contact_address'); ?>><?php echo lp_block('contact_address','<i class="bi bi-geo-alt me-2 text-primary"></i>City Government of General Trias, Cavite'); ?></li>
            </ul>
            <div class="d-flex gap-2 mt-3">
              <a href="<?php echo $base_path; ?>register.php" class="btn btn-green cta-btn" data-lp-key="contact_cta_apply"<?php echo lp_block_style('contact_cta_apply'); ?>><?php echo lp_block('contact_cta_apply','<i class="bi bi-journal-text me-2"></i>Start Application'); ?></a>
              <a href="announcements.php" class="btn btn-outline-custom cta-btn" data-lp-key="contact_cta_announcements"<?php echo lp_block_style('contact_cta_announcements'); ?>><?php echo lp_block('contact_cta_announcements','See Announcements'); ?></a>
              <a href="contact.php" class="btn btn-primary cta-btn" data-lp-key="contact_cta_full"<?php echo lp_block_style('contact_cta_full'); ?>><?php echo lp_block('contact_cta_full','<i class="bi bi-chat-dots me-1"></i>Full Contact Page'); ?></a>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card h-100 overflow-hidden">
            <iframe title="General Trias City Hall Location" width="100%" height="100%" style="min-height:300px;border:0" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade" src="https://www.google.com/maps?q=9VPJ+F9Q,+General+Trias,+Cavite&output=embed&z=17"></iframe>
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
              <div class="footer-logo" data-lp-key="footer_logo_text"<?php echo lp_block_style('footer_logo_text'); ?>><?php echo lp_block('footer_logo_text','EducAid • General Trias'); ?></div>
              <small data-lp-key="footer_tagline"<?php echo lp_block_style('footer_tagline'); ?>><?php echo lp_block('footer_tagline','Let\'s join forces for a more progressive GenTrias.'); ?></small>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="row">
            <div class="col-6 col-md-4"><h6 data-lp-key="footer_col1_title"<?php echo lp_block_style('footer_col1_title'); ?>><?php echo lp_block('footer_col1_title','Explore'); ?></h6><ul class="list-unstyled small" data-lp-key="footer_col1_links"<?php echo lp_block_style('footer_col1_links'); ?>><?php echo lp_block('footer_col1_links','<li><a href="#about">About</a></li><li><a href="how-it-works.php">Process</a></li><li><a href="#announcements">Announcements</a></li>'); ?></ul></div>
            <div class="col-6 col-md-4"><h6 data-lp-key="footer_col2_title"<?php echo lp_block_style('footer_col2_title'); ?>><?php echo lp_block('footer_col2_title','Links'); ?></h6><ul class="list-unstyled small" data-lp-key="footer_col2_links"<?php echo lp_block_style('footer_col2_links'); ?>><?php echo lp_block('footer_col2_links','<li><a href="#faq">FAQs</a></li><li><a href="requirements.php">Requirements</a></li><li><a href="#contact">Contact</a></li>'); ?></ul></div>
            <div class="col-12 col-md-4 mt-3 mt-md-0">
              <h6 data-lp-key="footer_newsletter_title"<?php echo lp_block_style('footer_newsletter_title'); ?>><?php echo lp_block('footer_newsletter_title','Stay Updated'); ?></h6>
              <form id="newsletterForm" class="d-flex gap-2">
                <input type="email" id="emailInput" class="form-control" placeholder="Email address" required />
                <button class="btn btn-light" type="submit" id="subscribeBtn">Subscribe</button>
              </form>
              <div id="newsletterMessage" class="small text-center mt-2" style="display: none;"></div>
            </div>
          </div>
        </div>
      </div>
      <hr class="border-light opacity-25 my-4" />
      <div class="d-flex justify-content-between flex-wrap gap-2 small">
  <span data-lp-key="footer_copyright"<?php echo lp_block_style('footer_copyright'); ?>><?php echo lp_block('footer_copyright','© <span id="year"></span> City Government of General Trias • EducAid'); ?></span>
  <span data-lp-key="footer_powered"<?php echo lp_block_style('footer_powered'); ?>><?php echo lp_block('footer_powered','Powered by the Office of the Mayor • IT'); ?></span>
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
          👋 Hi! I'm your EducAid Assistant. I can help you with:
          <br><br>
          • <strong>Eligibility requirements</strong>
          <br>• <strong>Required documents</strong>
          <br>• <strong>Application process</strong>
          <br>• <strong>Deadlines & schedules</strong>
          <br>• <strong>Contact information</strong>
          <br><br>
          What would you like to know about the EducAid scholarship program?
        </div>
      </div>
      <div class="ea-typing" id="eaTyping">EducAid Assistant is typing...</div>
    </div>
    <div class="ea-chat__footer">
      <input class="ea-chat__input" id="eaInput" placeholder="Type your message…" />
      <button class="ea-chat__send" id="eaSend">Send</button>
    </div>
  </div>
</div>

  <!-- Keep only these scripts before closing </body> -->

<script>
  // Smooth anchor highlighting
  const links = document.querySelectorAll('.nav-link');
  const sections = [...links].map(a => document.querySelector(a.getAttribute('href'))).filter(Boolean);
  const obs = new IntersectionObserver((entries)=>{
    entries.forEach(e=>{
      const id = '#'+e.target.id;
      const link = document.querySelector(`.nav-link[href="${id}"]`);
      if(link){ link.classList.toggle('active', e.isIntersecting && e.intersectionRatio > .5); }
    });
  }, {threshold:[.6]});
  sections.forEach(s=>obs.observe(s));

  // Current year
  document.getElementById('year').textContent = new Date().getFullYear();

  // Newsletter form handler (no CAPTCHA)
  const newsletterForm = document.getElementById('newsletterForm');
  const newsletterMessage = document.getElementById('newsletterMessage');
  const subscribeBtn = document.getElementById('subscribeBtn');
  
  if (newsletterForm) {
    newsletterForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      
      const email = document.getElementById('emailInput').value;
      
      // Reset message
      newsletterMessage.style.display = 'none';
      newsletterMessage.className = 'small text-center mt-2';
      
      // Validate email
      if (!email || !email.includes('@')) {
        showNewsletterMessage('Please enter a valid email address', 'error');
        return;
      }
      
      // Disable button and show loading
      subscribeBtn.disabled = true;
      subscribeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Subscribing...';
      
      try {
        const formData = new FormData();
        formData.append('email', email);
        
        const response = await fetch('newsletter_subscribe.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          showNewsletterMessage(result.message, 'success');
          newsletterForm.reset();
        } else {
          showNewsletterMessage(result.message, 'error');
        }
        
      } catch (error) {
        console.error('Newsletter subscription error:', error);
        showNewsletterMessage('Network error. Please try again later.', 'error');
      } finally {
        // Re-enable button
        subscribeBtn.disabled = false;
        subscribeBtn.innerHTML = 'Subscribe';
      }
    });
  }
  
  function showNewsletterMessage(message, type) {
    newsletterMessage.textContent = message;
    newsletterMessage.className = `small text-center ${type === 'success' ? 'text-success' : 'text-danger'}`;
    newsletterMessage.style.display = 'block';
    
    // Auto-hide success messages after 5 seconds
    if (type === 'success') {
      setTimeout(() => {
        newsletterMessage.style.display = 'none';
      }, 5000);
    }
  }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Chatbot script -->
<script>
// Enhanced EducAid Chatbot
document.addEventListener('DOMContentLoaded', function() {
  const apiUrl = '../chatbot/gemini_chat.php'; // Fixed path - go up one directory
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

  // Send message function (no CAPTCHA)
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

// Enhanced formatting function for improved Gemini responses
function formatChatbotResponse(text) {
  return text
    // Clean up single asterisks first (remove them)
    .replace(/(?<!\*)\*(?!\*)/g, '')
    
    // Convert emoji headers with bold text
    .replace(/📋\s*\*\*(.*?)\*\*/g, '<div class="req-header-emoji">📋 <strong>$1</strong></div>')
    
    // Convert numbered sections (1., 2., etc.)
    .replace(/(\d+)\.\s*\*\*(.*?)\*\*/g, '<div class="req-header-numbered"><strong>$1. $2</strong></div>')
    
    // Convert bold headers with colons - add spacing class
    .replace(/\*\*([^:]+):\*\*/g, '<div class="req-header-spaced"><strong>$1:</strong></div>')
    
    // Convert general bold text
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    
    // Convert bullet points/dashes to styled list items
    .replace(/^[-•]\s*(.+)$/gm, '<div class="req-item">• $1</div>')
    
    // Handle line breaks - keep double breaks as section separators
    .replace(/\n\n+/g, '<div class="req-spacer"></div>')
    .replace(/\n/g, '<br>')
    
    // Clean up any remaining asterisks
    .replace(/\*/g, '');
}
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Mobile Navbar JS -->
<script src="assets/js/website/mobile-navbar.js"></script>

<!-- Enhanced scroll animations - KEEP ONLY THIS ONE -->
<script>
class ScrollAnimations {
  constructor() {
    this.observerOptions = {
      threshold: 0.1,
      rootMargin: '0px 0px -10% 0px'
    };
    
    this.init();
  }
  
  init() {
    this.createObserver();
    this.observeElements();
  }
  
  createObserver() {
    this.observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          this.animateElement(entry.target);
          this.observer.unobserve(entry.target);
        }
      });
    }, this.observerOptions);
  }
  
  observeElements() {
    const elements = document.querySelectorAll('.fade-in, .fade-in-left, .fade-in-right, .fade-in-scale');
    elements.forEach(el => this.observer.observe(el));
  }
  
  animateElement(element) {
    element.classList.add('visible');
    
    // Add stagger effect for child elements
    if (element.classList.contains('fade-in-stagger')) {
      const children = element.querySelectorAll('.fade-in');
      children.forEach((child, index) => {
        setTimeout(() => {
          child.classList.add('visible');
        }, index * 100);
      });
    }
  }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  new ScrollAnimations();
});
</script>


</body>
<?php if ($IS_EDIT_MODE): ?>
<script>
(function(){
  // Any element with data-lp-key is considered editable
  function getEditableElements(){
    return Array.from(document.querySelectorAll('[data-lp-key]'));
  }
  const state = { target:null, originalContent:new Map(), dirtyKeys:new Set(), content:{}, saving:false };
  const toolbar = document.getElementById('lp-edit-toolbar');
  if(!toolbar){ return; }
  document.body.classList.add('lp-editing');
  const txtArea = document.getElementById('lp-edit-text');
  const targetLabel = document.getElementById('lp-current-target');
  const textColor = document.getElementById('lp-text-color');
  const bgColor = document.getElementById('lp-bg-color');
  const saveBtn = document.getElementById('lp-save-btn');
  const saveAllBtn = document.getElementById('lp-save-all-btn');
  const resetBtn = document.getElementById('lp-reset-btn');
  const highlightToggle = document.getElementById('lp-highlight-toggle');
  const statusEl = document.getElementById('lp-status');

  function setStatus(msg,type='muted'){ statusEl.textContent = msg; statusEl.className = 'text-' + (type==='error'?'danger': type==='success'?'success':'muted'); }

  function keyFor(el){
    if(el.dataset && el.dataset.lpKey){ return el.dataset.lpKey; }
    if(el.id) return el.tagName.toLowerCase()+'#'+el.id;
    const idx = Array.from(el.parentNode.children).indexOf(el);
    return el.tagName.toLowerCase()+'.'+(el.className||'').replace(/\s+/g,'-')+':'+idx;
  }

  function markDirty(el){
    el.dataset.lpDirty = '1';
    saveBtn.disabled = false;
  }

  function populateControls(el){
    state.target = el;
    targetLabel.textContent = el.tagName + (el.className?'.'+el.className.trim().replace(/\s+/g,' .'):'');
    txtArea.value = el.innerText.trim();
    const cs = getComputedStyle(el);
    textColor.value = rgbToHex(cs.color) || '#000000';
    bgColor.value = rgbToHex(cs.backgroundColor) || '#ffffff';
  }

  function rgbToHex(rgb){
    if(!rgb) return null;
    const m = rgb.match(/rgb[a]?\((\d+),\s*(\d+),\s*(\d+)/i); if(!m) return null;
    return '#'+[m[1],m[2],m[3]].map(v=>('0'+parseInt(v).toString(16)).slice(-2)).join('');
  }

  function attach(){
    getEditableElements().forEach(el=>{
      el.classList.add('lp-edit-highlight');
      const k = keyFor(el);
      if(!state.originalContent.has(k)) state.originalContent.set(k, el.innerHTML);
      el.addEventListener('click', e=>{
        if(!toolbar.contains(e.target)){
          e.preventDefault(); e.stopPropagation(); populateControls(el);
        }
      });
    });
  }

  txtArea.addEventListener('input', ()=>{
    if(!state.target) return; state.target.innerText = txtArea.value; markDirty(state.target);
  });
  textColor.addEventListener('input', ()=>{ if(state.target){ state.target.style.color = textColor.value; markDirty(state.target);} });
  bgColor.addEventListener('input', ()=>{ if(state.target){ state.target.style.backgroundColor = bgColor.value; markDirty(state.target);} });
  resetBtn.addEventListener('click', ()=>{ if(!state.target) return; const k=keyFor(state.target); const orig=state.originalContent.get(k); if(orig){ state.target.innerHTML=orig; } state.target.style.color=''; state.target.style.backgroundColor=''; state.target.removeAttribute('data-lp-dirty'); saveBtn.disabled = !document.querySelector('[data-lp-dirty="1"]'); setStatus('Block reset'); });
  const resetAllBtn = document.getElementById('lp-reset-all');
  const exitBtn = document.getElementById('lp-exit-btn');
  if(resetAllBtn){
    resetAllBtn.addEventListener('click', async ()=>{
      if(!confirm('Reset ALL edited blocks to original content? This cannot be undone.')) return;
      setStatus('Resetting all...');
      try {
        const res = await fetch('ajax_reset_landing_content.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'reset_all' }) });
        const data = await res.json();
        if(data.success){
          document.querySelectorAll('[data-lp-key]').forEach(el=>{ const orig = state.originalContent.get(keyFor(el)); if(orig){ el.innerHTML = orig; } el.style.color=''; el.style.backgroundColor=''; el.removeAttribute('data-lp-dirty'); });
          saveBtn.disabled = true; setStatus('All blocks reset','success');
        } else { setStatus(data.message||'Reset failed','error'); }
      } catch(e){ console.error(e); setStatus('Error resetting','error'); }
    });
  }
  highlightToggle.addEventListener('click', ()=>{
    const active = highlightToggle.getAttribute('data-active')==='1';
    document.querySelectorAll('.lp-edit-highlight').forEach(el=>{ el.style.outline = active?'none':''; });
    highlightToggle.setAttribute('data-active', active?'0':'1');
    highlightToggle.innerHTML = active?'<i class="bi bi-bounding-box"></i> Show Boxes':'<i class="bi bi-bounding-box-circles"></i> Hide Boxes';
  });

  async function save(){
    if(state.saving) return; const dirtyEls = Array.from(document.querySelectorAll('.lp-edit-highlight[data-lp-dirty="1"]'));
    if(!dirtyEls.length){ setStatus('Nothing to save'); return; }
    const payload = dirtyEls.map(el=>({ key:keyFor(el), html:el.innerHTML, styles:{ color:el.style.color||'', backgroundColor:el.style.backgroundColor||'' } }));
    state.saving = true; setStatus('Saving...'); saveBtn.disabled = true;
    try {
      const res = await fetch('ajax_save_landing_content.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ blocks: payload }) });
      if(!res.ok) throw new Error('HTTP '+res.status);
      const data = await res.json();
      if(data.success){
        dirtyEls.forEach(el=>el.removeAttribute('data-lp-dirty'));
        setStatus('Saved', 'success');
      } else {
        setStatus(data.message||'Save failed', 'error'); saveBtn.disabled=false;
      }
    } catch(err){ console.error(err); setStatus('Error: '+err.message,'error'); saveBtn.disabled=false; }
    finally { state.saving=false; }
  }
  saveBtn.addEventListener('click', save);
  async function saveAll(){
    if(state.saving) return;
    const allEls = Array.from(document.querySelectorAll('.lp-edit-highlight'));
    if(!allEls.length){ setStatus('No editable elements','error'); return; }
    const payload = allEls.map(el=>({ key:keyFor(el), html:el.innerHTML, styles:{ color:el.style.color||'', backgroundColor:el.style.backgroundColor||'' } }));
    state.saving = true; setStatus('Saving full snapshot...'); saveAllBtn.disabled = true; saveBtn.disabled = true;
    try {
      const res = await fetch('ajax_save_landing_content.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ blocks: payload }) });
      if(!res.ok) throw new Error('HTTP '+res.status);
      const data = await res.json();
      if(data.success){
        document.querySelectorAll('.lp-edit-highlight[data-lp-dirty="1"]').forEach(el=>el.removeAttribute('data-lp-dirty'));
        setStatus('Snapshot saved','success');
      } else { setStatus(data.message||'Snapshot failed','error'); }
    } catch(err){ console.error(err); setStatus('Error: '+err.message,'error'); }
    finally { state.saving=false; saveAllBtn.disabled=false; saveBtn.disabled = !document.querySelector('.lp-edit-highlight[data-lp-dirty="1"]'); }
  }
  if(saveAllBtn){ saveAllBtn.addEventListener('click', saveAll); }
  const historyBtn = document.getElementById('lp-history-btn');
  if(historyBtn){ historyBtn.addEventListener('click', ()=>{ LPHistoryModal.open(); }); }
  // Unsaved changes warnings
  window.addEventListener('beforeunload', function(e){
    if(document.querySelector('.lp-edit-highlight[data-lp-dirty="1"]')){ e.preventDefault(); e.returnValue=''; return ''; }
  });
  function guardNav(el){ if(!el) return; el.addEventListener('click', function(e){ if(document.querySelector('.lp-edit-highlight[data-lp-dirty="1"]')){ if(!confirm('You have unsaved changes. Leave without saving?')){ e.preventDefault(); } } }); }
  guardNav(exitBtn);
  document.querySelectorAll('a[href*="homepage.php"]').forEach(a=>guardNav(a));
  attach();
})();
// History preview modal with live temporary preview (no save until user explicitly saves)
const LPHistoryModal = (function(){
  let modalEl, listEl, filterInput, blockSelect, closeBtn, loadBtn, limitSelect, previewEl, previewNotice, previewApplyBtn, previewCancelBtn;
  let livePreview = null; // { key, el, originalHtml, originalTextColor, originalBgColor }
  function ensure(){
    if(modalEl) return;
    modalEl = document.createElement('div');
    modalEl.className = 'lp-history-modal';
    modalEl.innerHTML = `
<div class="lp-hist-backdrop"></div>
<div class="lp-hist-dialog">
  <div class="lp-hist-header d-flex justify-content-between align-items-center">
    <strong class="small mb-0">Edit History</strong>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-primary" data-load title="Reload"><i class="bi bi-arrow-repeat"></i></button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-close><i class="bi bi-x"></i></button>
    </div>
  </div>
  <div class="lp-hist-body">
    <div class="row g-2 mb-2">
      <div class="col-5"><input type="text" class="form-control form-control-sm" placeholder="Filter by key" data-filter /></div>
      <div class="col-4"><select class="form-select form-select-sm" data-limit>
        <option value="25">Last 25</option>
        <option value="50" selected>Last 50</option>
        <option value="100">Last 100</option>
      </select></div>
      <div class="col-3"><select class="form-select form-select-sm" data-block><option value="">All Blocks</option></select></div>
    </div>
    <div class="lp-hist-list" data-list style="max-height:300px;overflow:auto;border:1px solid #e2e8f0;border-radius:6px;padding:.45rem;background:#fff;font-size:.7rem"></div>
    <div class="small text-muted mt-2">Select an entry: you can preview here or inject it temporarily into the page.</div>
    <div class="lp-hist-preview mt-2" data-preview style="border:1px solid #cbd5e1;border-radius:6px;padding:.5rem;min-height:110px;background:#f8fafc;font-size:.75rem">(No selection)</div>
    <div class="d-flex gap-2 mt-2">
      <button type="button" class="btn btn-sm btn-outline-primary w-100" data-preview-apply disabled><i class="bi bi-eye"></i> Preview On Page</button>
      <button type="button" class="btn btn-sm btn-outline-warning w-100" data-preview-cancel disabled><i class="bi bi-arrow-counterclockwise"></i> Cancel Preview</button>
    </div>
    <div class="small mt-2 text-warning-emphasis" data-preview-notice style="display:none;">Temporary preview active. Use Cancel to revert. Not saved yet.</div>
  </div>
  <div class="lp-hist-footer small text-end text-muted">Changes are NOT saved until you click Save / Save All in the main editor.</div>
</div>`;
    document.body.appendChild(modalEl);
    listEl = modalEl.querySelector('[data-list]');
    filterInput = modalEl.querySelector('[data-filter]');
    blockSelect = modalEl.querySelector('[data-block]');
    closeBtn = modalEl.querySelector('[data-close]');
    loadBtn = modalEl.querySelector('[data-load]');
    limitSelect = modalEl.querySelector('[data-limit]');
    previewEl = modalEl.querySelector('[data-preview]');
    previewApplyBtn = modalEl.querySelector('[data-preview-apply]');
    previewCancelBtn = modalEl.querySelector('[data-preview-cancel]');
    previewNotice = modalEl.querySelector('[data-preview-notice]');
    closeBtn.addEventListener('click', hide);
    modalEl.querySelector('.lp-hist-backdrop').addEventListener('click', hide);
    loadBtn.addEventListener('click', load);
    filterInput.addEventListener('input', applyFilter);
    listEl.addEventListener('click', e=>{
      const item = e.target.closest('.lp-hist-item');
      if(!item) return; Array.from(listEl.querySelectorAll('.lp-hist-item')).forEach(x=>x.classList.remove('active'));
      item.classList.add('active');
      previewEl.innerHTML = item._html || '(empty)';
      previewEl.style.color = item._textColor || '';
      previewEl.style.backgroundColor = item._bgColor || '#f8fafc';
      previewApplyBtn.disabled = false;
      previewApplyBtn._selectedItem = item;
    });
    function revertPreview(){
      if(!livePreview) return;
      const { el, originalHtml, originalTextColor, originalBgColor } = livePreview;
      el.innerHTML = originalHtml;
      el.style.color = originalTextColor;
      el.style.backgroundColor = originalBgColor;
      livePreview = null;
      previewNotice.style.display = 'none';
      previewCancelBtn.disabled = true;
    }
    previewApplyBtn.addEventListener('click', ()=>{
      const item = previewApplyBtn._selectedItem; if(!item) return;
      const key = item.getAttribute('data-key');
      const target = document.querySelector('[data-lp-key="'+CSS.escape(key)+'"]');
      if(!target){ alert('Block not found on page.'); return; }
      if(livePreview && livePreview.key !== key) { revertPreview(); }
      if(!livePreview){
        livePreview = { key, el: target, originalHtml: target.innerHTML, originalTextColor: target.style.color, originalBgColor: target.style.backgroundColor };
      }
      target.innerHTML = item._html || '';
      target.style.color = item._textColor || '';
      target.style.backgroundColor = item._bgColor || '';
      previewNotice.style.display = 'block';
      previewCancelBtn.disabled = false;
    });
    previewCancelBtn.addEventListener('click', ()=>{ revertPreview(); });
  }
  function applyFilter(){
    const term = filterInput.value.trim().toLowerCase();
    Array.from(listEl.querySelectorAll('.lp-hist-item')).forEach(it=>{
      const key = it.getAttribute('data-key').toLowerCase();
      it.style.display = term && !key.includes(term) ? 'none':'block';
    });
  }
  async function load(){
    listEl.innerHTML = '<div class="text-muted small">Loading…</div>';
    const block = blockSelect.value.trim();
    const limit = limitSelect.value;
    try {
      const res = await fetch('ajax_get_landing_history.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ block, limit }) });
      const data = await res.json();
      if(!data.success){ listEl.innerHTML = '<div class="text-danger small">Failed to load history</div>'; return; }
      const recs = data.records || [];
      if(blockSelect.options.length === 1){
        const keys = Array.from(new Set(recs.map(r=>r.block_key))).sort();
        keys.forEach(k=>{ const opt=document.createElement('option'); opt.value=k; opt.textContent=k; blockSelect.appendChild(opt); });
      }
      if(!recs.length){ listEl.innerHTML = '<div class="text-muted small">No history entries</div>'; return; }
      listEl.innerHTML = '';
      recs.forEach(r=>{
        const div = document.createElement('div');
        div.className = 'lp-hist-item';
        div.setAttribute('data-key', r.block_key);
        div.innerHTML = `<div class=\"d-flex justify-content-between\"><span class=\"text-primary\">${escapeHtml(r.block_key)}</span><span class=\"text-muted\">#${r.audit_id}</span></div><div class=\"text-muted\">${escapeHtml(r.action_type)} • ${escapeHtml(r.created_at)}</div>`;
        div._html = r.html || '';
        div._textColor = r.text_color; div._bgColor = r.bg_color;
        listEl.appendChild(div);
      });
      applyFilter();
    } catch(err){ console.error(err); listEl.innerHTML = '<div class="text-danger small">Error loading</div>'; }
  }
  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); }
  function show(){ ensure(); modalEl.classList.add('show'); load(); }
  function hide(){ if(modalEl){ modalEl.classList.remove('show'); } }
  return { open: show, close: hide };
})();
</script>
<?php if($IS_EDIT_MODE): ?>
<style>
.lp-history-modal { position:fixed; inset:0; z-index:5000; display:none; }
.lp-history-modal.show { display:block; }
.lp-history-modal .lp-hist-backdrop { position:absolute; inset:0; background:rgba(0,0,0,.45); backdrop-filter:blur(2px); }
.lp-history-modal .lp-hist-dialog { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:760px; max-width:95%; background:#fff; border-radius:14px; box-shadow:0 10px 40px -10px rgba(0,0,0,.35); display:flex; flex-direction:column; max-height:85vh; }
.lp-history-modal .lp-hist-header { padding:.55rem .8rem; border-bottom:1px solid #e2e8f0; }
.lp-history-modal .lp-hist-body { padding:.7rem .85rem .9rem; overflow:auto; }
.lp-history-modal .lp-hist-footer { padding:.45rem .85rem; border-top:1px solid #e2e8f0; background:#f8fafc; border-bottom-left-radius:14px; border-bottom-right-radius:14px; }
.lp-hist-item { border:1px solid #e2e8f0; border-radius:6px; padding:.38rem .45rem; margin-bottom:.4rem; cursor:pointer; background:#fff; transition:background .15s,border-color .15s; }
.lp-hist-item:hover { background:#f1f5f9; }
.lp-hist-item.active { border-color:#2563eb; background:#eff6ff; }
@media (max-width:620px){ .lp-history-modal .lp-hist-dialog { width:95%; } }
</style>
<?php endif; ?>
<?php endif; ?>
<?php
// Always attempt to load saved blocks (no headers_sent guard to avoid early echo issues)
@include_once __DIR__ . '/../config/database.php';
if (isset($connection)) {
  $resBlocks = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM landing_content_blocks WHERE municipality_id=1");
  $blocks = [];
  if ($resBlocks) { while($r = pg_fetch_assoc($resBlocks)) { $blocks[$r['block_key']] = $r; } }
  if ($blocks) {
    $json = json_encode($blocks, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS);
    echo '<script>window.__LP_SAVED_BLOCKS=' . $json . ';(function(){var d=window.__LP_SAVED_BLOCKS;for(var k in d){if(!Object.prototype.hasOwnProperty.call(d,k)) continue;var b=d[k];var sel="[data-lp-key=\\""+k.replace(/"/g,"\\\"")+"\\"]";var el=document.querySelector(sel);if(!el) continue;try{el.innerHTML=b.html;}catch(e){} if(b.text_color) el.style.color=b.text_color; if(b.bg_color) el.style.backgroundColor=b.bg_color;}})();</script>';
  }
}
?>
</html>
