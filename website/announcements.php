<?php
session_start();

$IS_EDIT_MODE = false;
$IS_EDIT_SUPER_ADMIN = false;

require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

if (isset($_GET['edit']) && ($_GET['edit'] === 'true' || $_GET['edit'] == '1')) {
  if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
    $role = @getCurrentAdminRole($connection);
    if ($role === 'super_admin') {
      $IS_EDIT_SUPER_ADMIN = true;
      $IS_EDIT_MODE = true;
    }
  } elseif (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
    $IS_EDIT_SUPER_ADMIN = true;
    $IS_EDIT_MODE = true;
  }
}

if (!$IS_EDIT_MODE) {
    if (!isset($_SESSION['captcha_verified']) || $_SESSION['captcha_verified'] !== true) {
        header('Location: security_verification.php');
        exit;
    }
  $verificationTime = $_SESSION['captcha_verified_time'] ?? 0;
  if (time() - $verificationTime > 24 * 60 * 60) {
    unset($_SESSION['captcha_verified'], $_SESSION['captcha_verified_time']);
    header('Location: security_verification.php');
    exit;
  }
}

// Load announcements content helper
require_once '../includes/website/announcements_content_helper.php';

// Optional deep-link id
$requested_id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : null;

// Fetch announcements ordered with active first then newest (full list for now)
$rows = [];
$res = @pg_query($connection, "SELECT announcement_id, title, remarks, posted_at, is_active, event_date, event_time, location, image_path FROM announcements ORDER BY is_active DESC, posted_at DESC");
if ($res) { while ($r = pg_fetch_assoc($res)) { $rows[] = $r; } pg_free_result($res); }

$featured = null; $past = []; $deep_linked = false;
if ($rows) {
  if ($requested_id !== null) {
    foreach ($rows as $r) { if ((int)$r['announcement_id'] === $requested_id) { $featured = $r; $deep_linked = true; break; } }
  }
  if (!$featured) {
    // fall back to active then newest
    foreach ($rows as $r) { if ($r['is_active'] === 't' || $r['is_active'] === true) { $featured = $r; break; } }
    if (!$featured) { $featured = $rows[0]; }
  }
  foreach ($rows as $r) { if ($r['announcement_id'] != $featured['announcement_id']) { $past[] = $r; } }
} else {
  // Sample fallback
  $featured = [ 'announcement_id'=>0,'title'=>'Orientation for New Applicants (Sample)','remarks'=>"This is a sample announcement. Admins can post real announcements from the admin portal. Provide guidance, instructions, schedules, or distribution details here.\n\nYou can include reminders about necessary documents, assembly times, and conduct expectations.",'posted_at'=>date('Y-m-d H:i:s'),'is_active'=>'t','event_date'=>date('Y-m-d', strtotime('+5 days')),'event_time'=>'09:00:00','location'=>'City Multipurpose Hall','image_path'=>null ];
  $past = [
    ['announcement_id'=>-1,'title'=>'System Maintenance Completed (Sample)','remarks'=>'The system maintenance window has concluded successfully. You may now continue using the portal normally.','posted_at'=>date('Y-m-d H:i:s', strtotime('-2 days')),'is_active'=>'f','event_date'=>null,'event_time'=>null,'location'=>null,'image_path'=>null],
    ['announcement_id'=>-2,'title'=>'Distribution Day Reminders (Sample)','remarks'=>'Bring your valid school ID, QR code, and arrive 15 minutes early for orderly processing.','posted_at'=>date('Y-m-d H:i:s', strtotime('-10 days')),'is_active'=>'f','event_date'=>date('Y-m-d', strtotime('-9 days')),'event_time'=>'07:30:00','location'=>'Plaza Grounds','image_path'=>null]
  ];
}

function format_event($row){ $parts=[]; if(!empty($row['event_date'])){ $d=DateTime::createFromFormat('Y-m-d',$row['event_date']); if($d) $parts[]=$d->format('M d, Y'); } if(!empty($row['event_time'])){ $t=DateTime::createFromFormat('H:i:s',$row['event_time']); if($t) $parts[]=$t->format('g:i A'); } return implode(' • ', $parts); }
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES,'UTF-8'); }

$base_path = '../';
$custom_nav_links = [
  ['href'=>'landingpage.php#home','label'=>'Home','active'=>false],
  ['href'=>'about.php','label'=>'About','active'=>false],
  ['href'=>'announcements.php','label'=>'Announcements','active'=>true],
  ['href'=>'requirements.php','label'=>'Requirements','active'=>false],
  ['href'=>'how-it-works.php','label'=>'How it works','active'=>false],
  ['href'=>'contact.php','label'=>'Contact','active'=>false]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Announcements – EducAid</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
<link href="../assets/css/website/landing_page.css" rel="stylesheet" />
<?php if ($IS_EDIT_MODE): ?>
<link href="../assets/css/content_editor.css" rel="stylesheet" />
<?php endif; ?>
<style>
  body { font-family: 'Manrope', system-ui, sans-serif; }
  /* Featured announcement redesigned to follow soft-card visual language */
  .featured-section { padding:3rem 0 2rem; }
  .featured-card { position:relative; max-width:1000px; margin:0 auto; background:#fff; border:1px solid #e5e7eb; border-radius:1.25rem; overflow:hidden; box-shadow:0 8px 24px -6px rgba(0,0,0,.08); transition:border-color .35s, box-shadow .35s; }
  .featured-card .featured-img { width:100%; aspect-ratio:16/7; object-fit:cover; display:block; background:#f1f5f9; }
  .featured-meta { display:flex; flex-wrap:wrap; gap:.75rem 1.1rem; font-size:.7rem; text-transform:uppercase; letter-spacing:.5px; font-weight:600; color:#2563eb; margin-bottom:.85rem; }
  .featured-title { font-size:clamp(1.7rem, 3.2vw, 2.45rem); font-weight:700; line-height:1.1; margin-bottom:.9rem; }
  .featured-event { font-size:.9rem; font-weight:600; margin-bottom:.4rem; }
  .featured-location { font-size:.78rem; color:#64748b; margin-bottom:1rem; }
  .featured-remarks { position:relative; font-size:.95rem; line-height:1.55; color:#334155; }
  .featured-remarks.collapsed { max-height:210px; overflow:hidden; }
  .gradient-fade { position:absolute; left:0; right:0; bottom:0; height:90px; background:linear-gradient(transparent, #fff); }
  .toggle-remarks { margin-top:1.1rem; font-weight:600; font-size:.8rem; }
  /* Copy link button */
  .copy-link-btn { position:absolute; top:.75rem; right:.75rem; width:40px; height:40px; border-radius:50%; border:1px solid #cbd5e1; background:rgba(255,255,255,.9); backdrop-filter:blur(4px); display:flex; align-items:center; justify-content:center; color:#2563eb; cursor:pointer; transition:.25s; box-shadow:0 2px 6px rgba(0,0,0,.12); }
  .copy-link-btn:hover { background:#2563eb; color:#fff; border-color:#2563eb; }
  .copy-link-btn.copied { background:#16a34a; border-color:#16a34a; color:#fff; }
  /* Deep-linked highlight */
  .featured-card.deep-linked { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15), 0 10px 30px -6px rgba(0,0,0,.14); animation: pulse-border 2.4s ease-in-out 1; }
  @keyframes pulse-border { 0% { box-shadow:0 0 0 0 rgba(37,99,235,.6), 0 8px 24px -6px rgba(0,0,0,.08); } 60% { box-shadow:0 0 0 16px rgba(37,99,235,0), 0 8px 24px -6px rgba(0,0,0,.08);} 100% { box-shadow:0 0 0 0 rgba(37,99,235,0), 0 8px 24px -6px rgba(0,0,0,.08);} }
  /* Past list */
  .past-section { padding:2.5rem 0 4rem; }
  .past-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(270px,1fr)); gap:1.15rem; }
  .ann-card-link { text-decoration:none; color:inherit; display:block; }
  .ann-card { background:#fff; border:1px solid #e5e7eb; border-radius:1rem; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 4px 16px -6px rgba(0,0,0,.06); transition:all .25s; }
  .ann-card-link:hover .ann-card { border-color:#93c5fd; box-shadow:0 6px 20px -6px rgba(0,0,0,.08); transform:translateY(-3px); }
  .ann-card img { width:100%; aspect-ratio:16/9; object-fit:cover; background:#f1f5f9; }
  .ann-card-body { padding:.85rem .95rem 1.05rem; display:flex; flex-direction:column; gap:.4rem; }
  .ann-date { font-size:.6rem; font-weight:600; letter-spacing:.5px; text-transform:uppercase; color:#2563eb; }
  .ann-title { font-size:.85rem; font-weight:700; line-height:1.15; margin:0; }
  .ann-remarks { font-size:.7rem; color:#475569; line-height:1.35; }
  @media (max-width: 576px){ .featured-card .featured-img { aspect-ratio:16/10; } }
</style>
 </head>
 <body>

<?php if ($IS_EDIT_MODE): ?>
  <?php
    $toolbar_config = [
      'page_title' => 'Announcements Page',
      'exit_url' => 'announcements.php'
    ];
    include '../includes/website/edit_toolbar.php';
  ?>
<?php endif; ?>

<?php

  // Custom navigation for announcements page
  $custom_nav_links = [
    ['href' => 'landingpage.php', 'label' => 'Home', 'active' => false],
    ['href' => 'about.php', 'label' => 'About', 'active' => false],
    ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => false],
    ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => false],
    ['href' => 'announcements.php', 'label' => 'Announcements', 'active' => true],
    ['href' => 'contact.php', 'label' => 'Contact', 'active' => false]
  ];
  // Modular includes placed inside body to mirror landing page structure
  include '../includes/website/topbar.php';
  include '../includes/website/navbar.php';
  include '../includes/website/cookie_consent.php';
?>

<!-- Hero (mirrors landing page hero pattern) -->
<header class="hero" id="announcements-hero"<?php echo ann_block_style('hero-bg'); ?>>
  <div class="container">
    <div class="row align-items-center justify-content-center">
      <div class="col-12 col-lg-10">
        <div class="hero-card text-center">
          <div class="d-flex flex-column align-items-center gap-3">
            <span class="badge text-bg-primary-subtle text-primary rounded-pill"<?php echo ann_block_style('hero-badge'); ?> data-lp-key="hero-badge" contenteditable="<?php echo $IS_EDIT_MODE ? 'true' : 'false'; ?>"><?php echo ann_block('hero-badge', '<i class="bi bi-megaphone me-1"></i>Official Updates'); ?></span>
            <h1 class="display-5 mb-2"<?php echo ann_block_style('hero-title'); ?> data-lp-key="hero-title" contenteditable="<?php echo $IS_EDIT_MODE ? 'true' : 'false'; ?>"><?php echo ann_block('hero-title', 'Announcements &amp; Notices'); ?></h1>
            <p class="mb-0 lead" style="max-width:760px;"<?php echo ann_block_style('hero-description'); ?> data-lp-key="hero-description" contenteditable="<?php echo $IS_EDIT_MODE ? 'true' : 'false'; ?>"><?php echo ann_block('hero-description', 'Program-wide schedules, orientations, distribution reminders, and important administrative advisories.'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>
<section class="featured-section">
  <div class="container">
    <?php if ($featured): ?>
      <?php $event_line = format_event($featured); $img = $featured['image_path'] ? '../'.$featured['image_path'] : 'https://images.unsplash.com/photo-1587825140708-dfaf72ae4b04?q=80&w=1200&auto=format&fit=crop';
        $full = trim($featured['remarks']);
        $short = mb_strlen($full) > 600 ? mb_substr($full,0,600).'…' : $full; $needToggle = $short !== $full; ?>
  <article class="featured-card fade-in<?php echo $deep_linked ? ' deep-linked' : ''; ?>">
        <button type="button" id="copyLinkBtn" class="copy-link-btn" data-announcement-id="<?php echo (int)$featured['announcement_id']; ?>" aria-label="Copy direct link" title="Copy direct link"><i class="bi bi-link-45deg"></i></button>
        <img class="featured-img" src="<?php echo esc($img); ?>" alt="Featured announcement image">
        <div class="p-4 p-lg-5">
          <div class="featured-meta">
            <span><i class="bi bi-clock-history me-1"></i><?php echo date('M d, Y', strtotime($featured['posted_at'])); ?></span>
            <?php if (!empty($featured['event_date']) || !empty($featured['event_time'])): ?><span><i class="bi bi-calendar-event me-1"></i>Event</span><?php endif; ?>
            <?php if ($featured['is_active'] === 't' || $featured['is_active'] === true): ?><span class="text-success"><i class="bi bi-lightning-charge-fill me-1"></i>Active</span><?php endif; ?>
          </div>
          <h2 class="featured-title mb-2"><?php echo esc($featured['title']); ?></h2>
          <?php if ($event_line): ?><div class="featured-event text-primary"><i class="bi bi-calendar2-week me-1"></i><?php echo esc($event_line); ?></div><?php endif; ?>
          <?php if (!empty($featured['location'])): ?><div class="featured-location"><i class="bi bi-geo-alt me-1"></i><?php echo esc($featured['location']); ?></div><?php endif; ?>
          <div id="featuredRemarks" class="featured-remarks collapsed">
            <div class="remarks-short"><?php echo nl2br(esc($short)); ?></div>
            <div class="remarks-full" style="display:none;"><?php echo nl2br(esc($full)); ?></div>
            <?php if ($needToggle): ?><div class="gradient-fade" id="fadeOverlay"></div><?php endif; ?>
          </div>
          <?php if ($needToggle): ?><button id="toggleFeatured" class="btn btn-outline-primary btn-sm toggle-remarks">Read full details</button><?php endif; ?>
        </div>
      </article>
    <?php else: ?>
      <div class="text-center py-5">
        <img src="https://illustrations.popsy.co/gray/success.svg" alt="No announcements" style="max-width:220px;" class="mb-3" />
        <h5 class="fw-bold mb-1">No Announcements Yet</h5>
        <p class="text-body-secondary small mb-0">Official updates will appear here once posted by administrators.</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="past-section bg-body-tertiary"<?php echo ann_block_style('past-section-bg'); ?>>
  <div class="container">
    <div class="d-flex justify-content-between align-items-end flex-wrap mb-3 gap-2">
      <div>
  <h2 class="h5 fw-bold mb-1"<?php echo ann_block_style('past-title'); ?> data-lp-key="past-title" contenteditable="<?php echo $IS_EDIT_MODE ? 'true' : 'false'; ?>"><?php echo ann_block('past-title', '<i class="bi bi-archive me-2 text-primary"></i>Past Announcements'); ?></h2>
  <p class="small text-body-secondary mb-0"<?php echo ann_block_style('past-subtitle'); ?> data-lp-key="past-subtitle" contenteditable="<?php echo $IS_EDIT_MODE ? 'true' : 'false'; ?>"><?php echo ann_block('past-subtitle', 'Historical updates &amp; previous schedules'); ?></p>
      </div>
      <div class="small text-body-secondary">Total: <?php echo count($past); ?></div>
    </div>
        <div id="pastGrid" class="past-grid fade-in-stagger"></div>
        <div id="pastEmpty" class="text-center py-5 d-none">
          <p class="text-body-secondary small mb-0">No past announcements to display.</p>
        </div>
        <div class="text-center mt-4">
          <button id="loadMoreBtn" class="btn btn-outline-primary btn-sm px-4">
            <span class="default-text"><i class="bi bi-arrow-down-circle me-1"></i>Load More</span>
            <span class="loading-text d-none"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</span>
          </button>
        </div>
  </div>
</section>

<!-- Footer (mirrors landing page style) -->
<footer class="pt-5 pb-4">
  <div class="container">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <div class="d-flex align-items-center gap-3">
            <div class="brand-badge">EA</div>
            <div>
              <div class="footer-logo">EducAid • General Trias</div>
              <small>Your trusted portal for educational assistance updates</small>
            </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="row">
          <div class="col-6 col-md-4">
            <h6>Explore</h6>
            <ul class="list-unstyled small">
              <li><a href="landingpage.php#home">Home</a></li>
              <li><a href="about.php">About</a></li>
              <li><a href="how-it-works.php">Process</a></li>
            </ul>
          </div>
          <div class="col-6 col-md-4">
            <h6>Resources</h6>
            <ul class="list-unstyled small">
              <li><a href="requirements.php">Requirements</a></li>
              <li><a href="landingpage.php#faq">FAQs</a></li>
              <li><a href="contact.php">Contact</a></li>
            </ul>
          </div>
          <div class="col-12 col-md-4 mt-3 mt-md-0">
            <h6>Stay Updated</h6>
            <form id="newsletterForm" class="d-flex gap-2">
              <input type="email" id="emailInput" class="form-control" placeholder="Email address" required />
              <button class="btn btn-light" type="submit" id="subscribeBtn">Subscribe</button>
            </form>
            <div id="newsletterMessage" class="small text-center mt-2" style="display:none;"></div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Year
  document.getElementById('year').textContent = new Date().getFullYear();

  // Featured remarks toggle
  const toggleBtn = document.getElementById('toggleFeatured');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', () => {
      const wrap = document.getElementById('featuredRemarks');
      const shortEl = wrap.querySelector('.remarks-short');
      const fullEl = wrap.querySelector('.remarks-full');
      const fade = document.getElementById('fadeOverlay');
      const collapsed = wrap.classList.contains('collapsed');
      if (collapsed) {
        wrap.classList.remove('collapsed');
        shortEl.style.display = 'none';
        fullEl.style.display = 'block';
        if (fade) fade.remove();
        toggleBtn.textContent = 'Show less';
      } else {
        wrap.classList.add('collapsed');
        shortEl.style.display = 'block';
        fullEl.style.display = 'none';
        if (!fade) {
          const f = document.createElement('div');
          f.className = 'gradient-fade';
          f.id = 'fadeOverlay';
          wrap.appendChild(f);
        }
        toggleBtn.textContent = 'Read full details';
      }
    });
  }

  // Scroll animations (reuse pattern from landing page)
  class ScrollAnimations {
    constructor() {
      this.observerOptions = { threshold: 0.1, rootMargin: '0px 0px -10% 0px' };
      this.init();
    }
    init() { this.createObserver(); this.observeElements(); }
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
      if (element.classList.contains('fade-in-stagger')) {
        const children = element.querySelectorAll('.fade-in');
        children.forEach((child, index) => {
          setTimeout(() => child.classList.add('visible'), index * 100);
        });
      }
    }
  }
  document.addEventListener('DOMContentLoaded', () => { new ScrollAnimations(); });

  // Newsletter form handler (duplicate of landing page logic for consistency)
  (function(){
    const form = document.getElementById('newsletterForm');
    if(!form) return;
    const msg = document.getElementById('newsletterMessage');
    const btn = document.getElementById('subscribeBtn');
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const email = document.getElementById('emailInput').value.trim();
      msg.style.display='none';
      msg.className = 'small text-center mt-2';
      if(!email || !email.includes('@')){ showMsg('Please enter a valid email address','error'); return; }
      btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Subscribing...';
      try {
        const fd = new FormData(); fd.append('email', email);
        const res = await fetch('newsletter_subscribe.php',{ method:'POST', body:fd });
        const data = await res.json();
        if(data.success){ showMsg(data.message,'success'); form.reset(); }
        else { showMsg(data.message,'error'); }
      } catch(err){ showMsg('Network error. Please try again later.','error'); }
      finally { btn.disabled=false; btn.textContent='Subscribe'; }
    });
    function showMsg(text,type){ msg.textContent=text; msg.className = `small text-center ${type==='success'?'text-success':'text-danger'}`; msg.style.display='block'; if(type==='success'){ setTimeout(()=> msg.style.display='none',5000); } }
  })();

  // Incremental loading for past announcements (excluding featured)
  (function(){
    const grid = document.getElementById('pastGrid');
    const emptyState = document.getElementById('pastEmpty');
    const loadBtn = document.getElementById('loadMoreBtn');
    if(!grid || !loadBtn) return;
    const PAGE_SIZE = 9; // cards per batch
    let offset = 0; // will skip featured client-side
    let firstFetch = true;
    let reachedEnd = false;
    const featuredId = <?php echo $featured? (int)$featured['announcement_id'] : 'null'; ?>;

    function setLoading(is){
      const def = loadBtn.querySelector('.default-text');
      const ld = loadBtn.querySelector('.loading-text');
      if(is){ loadBtn.disabled=true; def.classList.add('d-none'); ld.classList.remove('d-none'); }
      else { loadBtn.disabled=false; def.classList.remove('d-none'); ld.classList.add('d-none'); }
    }

    async function fetchBatch(){
      if(reachedEnd) return; setLoading(true);
      try {
        const res = await fetch(`announcements_feed.php?limit=${PAGE_SIZE}&offset=${offset}`);
        if(!res.ok) throw new Error('HTTP '+res.status);
        const data = await res.json();
        let anns = data.announcements || [];
        // Remove featured (active) if first batch and it appears
        if(firstFetch && featuredId){ anns = anns.filter(a => parseInt(a.announcement_id) !== featuredId); }
        firstFetch = false;
        if(anns.length === 0){
          if(offset === 0){ emptyState.classList.remove('d-none'); }
          reachedEnd = true; loadBtn.classList.add('d-none'); return;
        }
        anns.forEach(a => grid.appendChild(buildCard(a)));
        // If fewer than requested, end
        if(anns.length < PAGE_SIZE){ reachedEnd = true; loadBtn.classList.add('d-none'); }
        offset += PAGE_SIZE; // advance for next server page (even if some filtered) for simplicity
      } catch(err){
        console.error('Past announcements load error', err);
        loadBtn.classList.add('btn-danger');
        loadBtn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Retry';
        loadBtn.disabled = false;
      } finally { setLoading(false); }
    }

    function buildCard(a){
      const img = a.image_path ? '../'+a.image_path : 'https://images.unsplash.com/photo-1543269865-cbf427effbad?q=80&w=1200&auto=format&fit=crop';
      const eventParts=[];
      if(a.event_date){ try{ const d=new Date(a.event_date); if(!isNaN(d)) eventParts.push(d.toLocaleDateString('en-US',{month:'short', day:'2-digit', year:'numeric'})); }catch(e){} }
      if(a.event_time){ const tm=a.event_time.substring(0,5); const [H,M]=tm.split(':'); let h=parseInt(H); const ampm=h>=12?'PM':'AM'; h=h%12||12; eventParts.push(`${h}:${M} ${ampm}`); }
      const eventLine = eventParts.join(' • ');
      const link = document.createElement('a');
      link.href = `announcements.php?id=${encodeURIComponent(a.announcement_id)}`;
      link.className = 'ann-card-link';
      link.innerHTML = `
        <article class="ann-card fade-in">
          <img src="${img}" alt="Announcement image" />
          <div class="ann-card-body">
            <div class="ann-date">${new Date(a.posted_at).toLocaleDateString('en-US',{month:'short', day:'2-digit', year:'numeric'})}${a.is_active==='t'? ' <span class=\'badge bg-success ms-1\'>Active</span>':''}</div>
            <h6 class="ann-title">${escapeHtml(a.title||'')}</h6>
            ${eventLine? `<div class=\"small text-primary fw-semibold\">${eventLine}</div>`:''}
            ${a.location? `<div class=\"small text-body-secondary\"><i class='bi bi-geo-alt me-1'></i>${escapeHtml(a.location)}</div>`:''}
            <p class="ann-remarks mb-0">${escapeHtml(truncate(a.remarks||'',140))}</p>
          </div>
        </article>`;
      requestAnimationFrame(()=> link.querySelector('.ann-card').classList.add('visible'));
      return link;
    }
    function truncate(t,l){ t=t.trim(); return t.length>l? t.substring(0,l)+'…': t; }
    function escapeHtml(str){ return (str||'').replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); }

    loadBtn.addEventListener('click', ()=> fetchBatch());
    // Auto-load first batch
    fetchBatch();
  })();

  // Copy direct link button for featured announcement
  (function(){
    const btn = document.getElementById('copyLinkBtn');
    if(!btn) return;
    btn.addEventListener('click', async ()=>{
      const id = btn.dataset.announcementId;
      const base = window.location.origin + window.location.pathname;
      const url = id ? `${base}?id=${id}` : base;
      async function doCopy(text){
        if(navigator.clipboard && window.isSecureContext){
          return navigator.clipboard.writeText(text);
        }
        // Fallback
        const ta=document.createElement('textarea');
        ta.value=text; ta.style.position='fixed'; ta.style.top='-2000px'; document.body.appendChild(ta); ta.focus(); ta.select();
        try{ document.execCommand('copy'); } finally { document.body.removeChild(ta); }
      }
      try {
        await doCopy(url);
        btn.classList.add('copied');
        btn.innerHTML='<i class="bi bi-check-lg"></i>';
        setTimeout(()=>{ btn.classList.remove('copied'); btn.innerHTML='<i class="bi bi-link-45deg"></i>'; }, 2400);
      } catch(err){
        console.error('Copy failed', err);
      }
    });
  })();
</script>

<?php if ($IS_EDIT_MODE): ?>
<script src="../assets/js/website/content_editor.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  ContentEditor.init({
    page: 'announcements',
    pageTitle: 'Announcements Page',
    saveEndpoint: 'ajax_save_ann_content.php',
    getEndpoint: 'ajax_get_ann_blocks.php',
    resetAllEndpoint: 'ajax_reset_ann_content.php',
    history: {
      fetchEndpoint: 'ajax_get_ann_history.php',
      rollbackEndpoint: 'ajax_rollback_ann_block.php'
    }
  });
});
</script>
<?php endif; ?>

</body>
</html>
