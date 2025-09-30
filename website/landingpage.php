<?php
// filepath: c:\xampp\htdocs\EducAid\landingpage.php

// Start session and check verification
session_start();

// Check if user has completed CAPTCHA verification
if (!isset($_SESSION['captcha_verified']) || $_SESSION['captcha_verified'] !== true) {
    // Redirect to security verification page
    header('Location: security_verification.php');
    exit;
}

// Optional: Check if verification is still valid (expires after 24 hours)
$verificationTime = $_SESSION['captcha_verified_time'] ?? 0;
$expirationTime = 24 * 60 * 60; // 24 hours in seconds

if (time() - $verificationTime > $expirationTime) {
    // Verification expired, require re-verification
    unset($_SESSION['captcha_verified']);
    unset($_SESSION['captcha_verified_time']);
    header('Location: security_verification.php');
    exit;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>EducAid – City of General Trias</title>
  <meta name="description" content="Educational Assistance Management System for the City of General Trias" />

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="../assets/css/website/landing_page.css" rel="stylesheet" />
  <link href="../assets/css/website/recaptcha_v2.css" rel="stylesheet" />
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
  ?>

  <!-- Hero -->
  <header id="home" class="hero">
    <div class="container">
      <div class="row align-items-center justify-content-center">
        <div class="col-12 col-lg-9">
          <div class="hero-card text-center text-lg-start">
            <div class="d-flex flex-column flex-lg-row align-items-center gap-4">
              <div class="flex-grow-1">
                <span class="badge text-bg-primary-subtle text-primary rounded-pill mb-2"><i class="bi bi-stars me-2"></i>General Trias Scholarship & Aid</span>
                <h1 class="display-5 mb-2">Educational Assistance, Simplified.</h1>
                <p class="mb-4">Apply, upload requirements, track status, and claim assistance with QR — all in one city-run portal designed for students and families in General Trias.</p>
                <div class="d-flex gap-2 justify-content-center justify-content-lg-start">
                  <a href="<?php echo $base_path; ?>register.php" class="btn cta-btn btn-primary-custom"><i class="bi bi-journal-text me-2"></i>Apply Now</a>
                  <a href="<?php echo $base_path; ?>unified_login.php" class="btn cta-btn btn-outline-custom"><i class="bi bi-box-arrow-in-right me-2"></i>Sign In</a>
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
  <div class="quick-links">
    <div class="container">
      <div class="row g-3 g-md-4">
        <div class="col-6 col-lg">
          <a class="ql-card" href="announcements.php"><span class="ql-icon"><i class="bi bi-megaphone"></i></span><span>Latest Announcements</span></a>
        </div>
        <div class="col-6 col-lg">
          <a class="ql-card" href="requirements.php"><span class="ql-icon"><i class="bi bi-list-check"></i></span><span>Requirements</span></a>
        </div>
        <div class="col-6 col-lg">
          <a class="ql-card" href="how-it-works.php"><span class="ql-icon"><i class="bi bi-gear-wide-connected"></i></span><span>How It Works</span></a>
        </div>
        <div class="col-6 col-lg">
          <a class="ql-card" href="#faq"><span class="ql-icon"><i class="bi bi-question-circle"></i></span><span>FAQs</span></a>
        </div>
        <div class="col-12 col-lg">
          <a class="ql-card" href="#contact"><span class="ql-icon"><i class="bi bi-telephone"></i></span><span>Contact & Helpdesk</span></a>
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
          <h2 class="section-title mb-2">Message from the Mayor</h2>
          <p class="mb-2">Welcome to the City Government of General Trias' online platform — built to enhance connectivity, accessibility, and transparency for our thriving community. Our vision is a modern and sustainable city where every citizen can prosper.</p>
          <p class="mb-2">Through this portal, we aim to empower students and families with timely information and accessible services, upholding transparency and accountability in governance.</p>
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
          <h2 class="section-title mb-3">What is <span class="text-primary">EducAid</span>?</h2>
          <p class="section-lead">EducAid is the City of General Trias' official Educational Assistance Management System. Built with transparency and accessibility in mind, it streamlines application, evaluation, release, and reporting of aid for qualified students.</p>
          <div class="row g-3 mt-2">
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-lock-fill text-success"></i><strong>Secure & Private</strong></div>
                <p class="mb-0 small text-body-secondary">Data protected under RA 10173 and city policies.</p>
              </div>
            </div>
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-qr-code text-success"></i><strong>QR-based Claiming</strong></div>
                <p class="mb-0 small text-body-secondary">Fast verification on distribution day via secure QR codes.</p>
              </div>
            </div>
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-bell-fill text-success"></i><strong>Real-time Updates</strong></div>
                <p class="mb-0 small text-body-secondary">Get notified on slots, schedules, and requirements.</p>
              </div>
            </div>
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-people-fill text-success"></i><strong>LGU-Managed</strong></div>
                <p class="mb-0 small text-body-secondary">Powered by the Office of the Mayor and partner departments.</p>
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
          <h2 class="section-title">How it works</h2>
          <p class="section-lead">A simple four-step process from online application to aid claiming.</p>
        </div>
      </div>
      <div class="row g-3 g-lg-4 fade-in-stagger">
        <div class="col-md-6 col-lg-3 fade-in">
          <div class="soft-card p-3 h-100">
            <div class="step mb-2"><div class="step-badge">1</div><h6 class="mb-0">Create & Verify</h6></div>
            <p class="small text-body-secondary mb-0">Register using your email and mobile. Verify via OTP to secure your account.</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3 fade-in">
          <div class="soft-card p-3 h-100">
            <div class="step mb-2"><div class="step-badge">2</div><h6 class="mb-0">Apply Online</h6></div>
            <p class="small text-body-secondary mb-0">Complete your profile, select your barangay, and upload required documents.</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3 fade-in">
          <div class="soft-card p-3 h-100">
            <div class="step mb-2"><div class="step-badge">3</div><h6 class="mb-0">Get Evaluated</h6></div>
            <p class="small text-body-secondary mb-0">Admins validate eligibility and post status updates with reminders.</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3 fade-in">
          <div class="soft-card p-3 h-100">
            <div class="step mb-2"><div class="step-badge">4</div><h6 class="mb-0">Claim with QR</h6></div>
            <p class="small text-body-secondary mb-0">Receive your QR code and bring it on distribution day for quick claiming.</p>
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
          <h2 class="section-title">Basic Requirements</h2>
          <p class="section-lead">Prepare clear photos or PDFs of the following. Additional documents may be requested for verification.</p>
        </div>
      </div>
      <div class="row g-4">
        <div class="col-md-6">
          <div class="soft-card p-4 h-100">
            <h6 class="fw-bold mb-3"><i class="bi bi-person-vcard me-2 text-success"></i>Identity & Enrollment</h6>
            <ul class="list-unstyled m-0 d-grid gap-2">
              <li><i class="bi bi-check2 check me-2"></i>Valid School ID</li>
              <li><i class="bi bi-check2 check me-2"></i>Enrollment Assessment Form</li>
              <li><i class="bi bi-check2 check me-2"></i>Certificate of Indigency (after approval)</li>
              <li><i class="bi bi-check2 check me-2"></i>Letter to the Mayor (PDF)</li>
            </ul>
          </div>
        </div>
        <div class="col-md-6">
          <div class="soft-card p-4 h-100">
            <h6 class="fw-bold mb-3"><i class="bi bi-shield-lock me-2 text-success"></i>Account & Contact</h6>
            <ul class="list-unstyled m-0 d-grid gap-2">
              <li><i class="bi bi-check2 check me-2"></i>Active email (OTP verification)</li>
              <li><i class="bi bi-check2 check me-2"></i>Mobile number for SMS updates</li>
              <li><i class="bi bi-check2 check me-2"></i>Barangay information</li>
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
          <h2 class="section-title">Frequently Asked Questions</h2>
          <p class="section-lead">Quick answers to common concerns about eligibility, slots, and claiming.</p>
        </div>
      </div>
      <div class="accordion soft-card" id="faqAcc">
        <div class="accordion-item">
          <h2 class="accordion-header" id="q1">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#a1">Who can apply?</button>
          </h2>
          <div id="a1" class="accordion-collapse collapse show" data-bs-parent="#faqAcc">
            <div class="accordion-body">Students residing in General Trias who meet program criteria set by the LGU and partner agencies.</div>
          </div>
        </div>
        <div class="accordion-item">
          <h2 class="accordion-header" id="q2">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2">How are slots allocated?</button>
          </h2>
          <div id="a2" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
            <div class="accordion-body">Slots are released per batch and barangay. Availability appears during registration and closes automatically when filled.</div>
          </div>
        </div>
        <div class="accordion-item">
          <h2 class="accordion-header" id="q3">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a3">What if I lose my QR code?</button>
          </h2>
          <div id="a3" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
            <div class="accordion-body">You can re-download it from your dashboard. Bring a valid ID on distribution day for identity verification.</div>
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
            <h5 class="fw-bold mb-3">Contact & Helpdesk</h5>
            <p class="text-body-secondary">For inquiries about requirements, schedules, or account issues, reach us here:</p>
            <ul class="list-unstyled d-grid gap-2 m-0">
              <li><i class="bi bi-envelope me-2 text-primary"></i>educaid@generaltrias.gov.ph</li>
              <li><i class="bi bi-telephone me-2 text-primary"></i>(046) 886-4454</li>
              <li><i class="bi bi-geo-alt me-2 text-primary"></i>City Government of General Trias, Cavite</li>
            </ul>
            <div class="d-flex gap-2 mt-3">
              <a href="<?php echo $base_path; ?>register.php" class="btn btn-green cta-btn"><i class="bi bi-journal-text me-2"></i>Start Application</a>
              <a href="announcements.php" class="btn btn-outline-custom cta-btn">See Announcements</a>
              <a href="contact.php" class="btn btn-primary cta-btn"><i class="bi bi-chat-dots me-1"></i>Full Contact Page</a>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card h-100 overflow-hidden">
            <iframe title="Map" width="100%" height="100%" style="min-height:300px;border:0" loading="lazy" allowfullscreen src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d30959.86166204313!2d120.879!3d14.384!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397d4126e2c2d1d%3A0x4b3f6b4df55c9a47!2sGeneral%20Trias%2C%20Cavite!5e0!3m2!1sen!2sph!4v1687412345678"></iframe>
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
              <div class="footer-logo">EducAid • General Trias</div>
              <small>Let's join forces for a more progressive GenTrias.</small>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="row">
            <div class="col-6 col-md-4"><h6>Explore</h6><ul class="list-unstyled small"><li><a href="#about">About</a></li><li><a href="how-it-works.php">Process</a></li><li><a href="#announcements">Announcements</a></li></ul></div>
            <div class="col-6 col-md-4"><h6>Links</h6><ul class="list-unstyled small"><li><a href="#faq">FAQs</a></li><li><a href="requirements.php">Requirements</a></li><li><a href="#contact">Contact</a></li></ul></div>
            <div class="col-12 col-md-4 mt-3 mt-md-0">
              <h6>Stay Updated</h6>
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
        <span>© <span id="year"></span> City Government of General Trias • EducAid</span>
        <span>Powered by the Office of the Mayor • IT</span>
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
</html>
