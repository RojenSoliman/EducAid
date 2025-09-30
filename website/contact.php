<?php
// Dedicated Contact & Helpdesk page
session_start();

// Reuse the same verification gate as landing page (optional - remove if you want it public)
if (!isset($_SESSION['captcha_verified']) || $_SESSION['captcha_verified'] !== true) {
    header('Location: security_verification.php');
    exit;
}
$verificationTime = $_SESSION['captcha_verified_time'] ?? 0;
if (time() - $verificationTime > 24*60*60) { // 24h expiry
    unset($_SESSION['captcha_verified'], $_SESSION['captcha_verified_time']);
    header('Location: security_verification.php');
    exit;
}

require_once '../config/database.php';
// (Optional) Mailer integration could be added later. For now: log inquiries.

$errors = [];
$successMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_inquiry'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || strlen($name) < 2) $errors[] = 'Name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
    if ($subject === '' || strlen($subject) < 3) $errors[] = 'Subject must be at least 3 characters.';
    if ($message === '' || strlen($message) < 10) $errors[] = 'Message must be at least 10 characters.';

    if (!$errors) {
        $entry = [
            'ts' => date('c'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,180),
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message
        ];
        $logFile = __DIR__ . '/../data/contact_messages.log';
        // ensure directory exists
        @mkdir(dirname($logFile), 0775, true);
        @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
        $successMsg = 'Your inquiry was received. A staff member may reach out via email if needed.';
        $_POST = []; // clear form data
    }
}

function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
$base_path = '../';
// Navigation config
$custom_nav_links = [
    ['href' => 'landingpage.php#home', 'label' => 'Home', 'active' => false],
    ['href' => 'about.php', 'label' => 'About', 'active' => false],
    ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => false],
    ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => false],
    ['href' => 'announcements.php', 'label' => 'Announcements', 'active' => false],
    ['href' => 'contact.php', 'label' => 'Contact', 'active' => true],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Contact • EducAid – City of General Trias</title>
  <meta name="description" content="Official contact & helpdesk page for EducAid – City of General Trias" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="../assets/css/website/landing_page.css" rel="stylesheet" />
  <style>
    /* Contact page custom tweaks building on landing page design */
    header.hero { background:linear-gradient(145deg,#0d47a1 0%, #1d62c4 40%, #2d7ce2 100%); position:relative; padding:4.5rem 0 4rem; }
    header.hero:before { content:''; position:absolute; inset:0; background:radial-gradient(circle at 70% 30%, rgba(255,255,255,.18), transparent 60%); pointer-events:none; }
    .contact-badge { font-size:.65rem; letter-spacing:.5px; }
    .info-card, .inquiry-card { background:#fff; border:1px solid #e2e8f0; border-radius:1rem; padding:1.2rem 1.25rem; height:100%; box-shadow:0 4px 14px -6px rgba(0,0,0,.06); }
    .info-card h6 { font-weight:600; margin-bottom:.4rem; }
    .inquiry-card { box-shadow:0 8px 22px -10px rgba(0,0,0,.15); }
    .inquiry-card textarea { resize:vertical; min-height:150px; }
    .log-note { font-size:.65rem; color:#64748b; }
    .map-wrapper { border-radius:1rem; overflow:hidden; box-shadow:0 6px 20px -10px rgba(0,0,0,.25); }
    .section-divider { height:1px; background:linear-gradient(90deg,transparent, #dbe2ea, transparent); margin:3rem 0 2.2rem; }
    .fade-in { opacity:0; transform:translateY(10px); transition:all .5s ease; }
    .fade-in.visible { opacity:1; transform:none; }
    .quick-links a.ql-card { min-height:72px; }
  </style>
</head>
<body>
<?php include '../includes/website/topbar.php'; ?>
<?php include '../includes/website/navbar.php'; ?>

<!-- Hero (mirroring landing page structure) -->
<header id="home" class="hero">
  <div class="container">
    <div class="row align-items-center justify-content-center">
      <div class="col-12 col-lg-10">
        <div class="hero-card text-center text-lg-start fade-in">
          <div class="d-flex flex-column flex-lg-row align-items-center gap-4">
            <div class="flex-grow-1">
              <span class="badge text-bg-primary-subtle text-primary rounded-pill mb-2 contact-badge"><i class="bi bi-life-preserver me-2"></i>EducAid Support • Official Channel</span>
              <h1 class="display-6 mb-2">Contact & Helpdesk</h1>
              <p class="mb-3 lead" style="font-size:1rem;">We're here to assist with application issues, document submission, schedules, QR release, and portal access concerns.</p>
              <div class="d-flex gap-2 justify-content-center justify-content-lg-start flex-wrap">
                <a href="requirements.php" class="btn btn-primary-custom cta-btn"><i class="bi bi-list-check me-2"></i>Requirements</a>
                <a href="announcements.php" class="btn btn-outline-custom cta-btn"><i class="bi bi-megaphone me-2"></i>Announcements</a>
                <a href="landingpage.php#faq" class="btn btn-outline-secondary cta-btn"><i class="bi bi-question-circle me-2"></i>FAQ</a>
              </div>
            </div>
            <div class="text-center">
              <div class="map-wrapper shadow-sm" style="max-width:360px">
                <iframe title="Map" width="100%" height="260" style="border:0" loading="lazy" allowfullscreen src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d30959.86166204313!2d120.879!3d14.384!"></iframe>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- Quick Links (consistent with landing page style) -->
<div class="quick-links fade-in">
  <div class="container">
    <div class="row g-3 g-md-4">
      <div class="col-6 col-lg"><a class="ql-card" href="requirements.php"><span class="ql-icon"><i class="bi bi-list-check"></i></span><span>Requirements</span></a></div>
      <div class="col-6 col-lg"><a class="ql-card" href="announcements.php"><span class="ql-icon"><i class="bi bi-megaphone"></i></span><span>Announcements</span></a></div>
      <div class="col-6 col-lg"><a class="ql-card" href="how-it-works.php"><span class="ql-icon"><i class="bi bi-gear-wide-connected"></i></span><span>Process</span></a></div>
      <div class="col-6 col-lg"><a class="ql-card" href="landingpage.php#faq"><span class="ql-icon"><i class="bi bi-question-circle"></i></span><span>FAQs</span></a></div>
      <div class="col-12 col-lg"><a class="ql-card" href="#inquiry"><span class="ql-icon"><i class="bi bi-chat-dots"></i></span><span>Send Inquiry</span></a></div>
    </div>
  </div>
</div>

<section class="section-spacer fade-in" id="contact-info">
  <div class="container">
  <div class="row g-4 mb-4 fade-in">
      <div class="col-md-4">
        <div class="info-card h-100">
          <h6><i class="bi bi-building text-primary me-1"></i> Main Office</h6>
          <p class="small mb-2">City Government of General Trias, Cavite</p>
          <p class="small mb-0 text-body-secondary">Open Mon–Fri, 8:00 AM – 5:00 PM (excluding holidays)</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="info-card h-100">
          <h6><i class="bi bi-telephone text-success me-1"></i> Phone</h6>
            <ul class="list-unstyled small m-0 d-grid gap-1">
              <li>(046) 886-4454</li>
              <li>(046) 509-5555 (Operator)</li>
            </ul>
        </div>
      </div>
      <div class="col-md-4">
        <div class="info-card h-100">
          <h6><i class="bi bi-envelope-at text-danger me-1"></i> Email</h6>
          <ul class="list-unstyled small m-0 d-grid gap-1">
            <li>educaid@generaltrias.gov.ph</li>
            <li class="text-body-secondary">support@ (coming soon)</li>
          </ul>
        </div>
      </div>
    </div>

  <div class="row g-4 mb-5 fade-in">
      <div class="col-lg-6">
        <div class="info-card h-100">
          <h6><i class="bi bi-people-fill text-primary me-1"></i> Program Offices</h6>
          <ul class="small list-unstyled mb-0 d-grid gap-1">
            <li><strong>Youth / Scholarship Desk:</strong> Local Youth & Dev. Office</li>
            <li><strong>Document Validation:</strong> Records & Compliance</li>
            <li><strong>Distribution / Release:</strong> Treasurer • Mayor's Office</li>
          </ul>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="info-card h-100">
          <h6><i class="bi bi-info-circle text-warning me-1"></i> Common Topics</h6>
          <ul class="small list-unstyled mb-0 d-grid gap-1">
            <li>Registration / Slot availability</li>
            <li>Document upload & verification</li>
            <li>Schedule announcements</li>
            <li>QR code release / lost access</li>
            <li>Account recovery assistance</li>
          </ul>
        </div>
      </div>
    </div>

  <div class="row g-4 fade-in" id="inquiry">
      <div class="col-lg-6">
        <div class="inquiry-card h-100">
          <h5 class="fw-bold mb-3"><i class="bi bi-chat-dots-fill me-2 text-primary"></i>Send an Inquiry</h5>
          <?php if ($errors): ?>
            <div class="alert alert-danger py-2 mb-3">
              <ul class="m-0 ps-3 small">
                <?php foreach ($errors as $er): ?>
                  <li><?= esc($er) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <?php if ($successMsg): ?>
            <div class="alert alert-success py-2 mb-3 small mb-2"><?= esc($successMsg) ?></div>
          <?php endif; ?>
          <form method="POST" novalidate>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control form-control-sm" value="<?= esc($_POST['name'] ?? '') ?>" required />
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control form-control-sm" value="<?= esc($_POST['email'] ?? '') ?>" required />
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Subject <span class="text-danger">*</span></label>
              <input type="text" name="subject" class="form-control form-control-sm" value="<?= esc($_POST['subject'] ?? '') ?>" required />
            </div>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Message <span class="text-danger">*</span></label>
              <textarea name="message" class="form-control form-control-sm" required><?= esc($_POST['message'] ?? '') ?></textarea>
            </div>
            <div class="d-flex align-items-center gap-2">
              <button type="submit" name="submit_inquiry" class="btn btn-primary btn-sm px-3"><i class="bi bi-send me-1"></i>Submit</button>
              <span class="log-note">Logged internally • Email sending to be enabled later</span>
            </div>
          </form>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="info-card h-100">
          <h5 class="fw-bold mb-3"><i class="bi bi-life-preserver me-2 text-success"></i>Self‑Help & Quick Links</h5>
          <ul class="list-unstyled small mb-3 d-grid gap-2">
            <li><a href="requirements.php" class="link-primary text-decoration-none"><i class="bi bi-list-check me-1"></i> Requirements Guide</a></li>
            <li><a href="how-it-works.php" class="link-primary text-decoration-none"><i class="bi bi-diagram-3 me-1"></i> Process Overview</a></li>
            <li><a href="announcements.php" class="link-primary text-decoration-none"><i class="bi bi-megaphone me-1"></i> Latest Announcements</a></li>
            <li><a href="landingpage.php#faq" class="link-primary text-decoration-none"><i class="bi bi-question-circle me-1"></i> FAQ Section</a></li>
            <li><a href="landingpage.php#contact" class="link-primary text-decoration-none"><i class="bi bi-arrow-left-circle me-1"></i> Back to Landing Contact</a></li>
          </ul>
          <div class="p-3 rounded bg-body-tertiary small">
            <strong>Data Privacy:</strong> Inquiries are stored securely for audit and support follow‑ups. Avoid sending sensitive identifiers here.
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Footer (mirrors landing page footer) -->
<footer class="pt-5 pb-4">
  <div class="container">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <div class="d-flex align-items-center gap-3">
          <div class="brand-badge">EA</div>
          <div>
            <div class="footer-logo">EducAid • General Trias</div>
            <small>Contact & Helpdesk Portal</small>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="row">
          <div class="col-6 col-md-4"><h6>Navigate</h6><ul class="list-unstyled small"><li><a href="landingpage.php#about">About</a></li><li><a href="how-it-works.php">Process</a></li><li><a href="announcements.php">Announcements</a></li></ul></div>
          <div class="col-6 col-md-4"><h6>Help</h6><ul class="list-unstyled small"><li><a href="landingpage.php#faq">FAQs</a></li><li><a href="#inquiry">Inquiry Form</a></li><li><a href="requirements.php">Requirements</a></li></ul></div>
          <div class="col-12 col-md-4 mt-3 mt-md-0">
            <h6>Updates</h6>
            <form id="newsletterForm" class="d-flex gap-2">
              <input type="email" id="emailInput" class="form-control" placeholder="Email" required />
              <button class="btn btn-light" type="submit" id="subscribeBtn">Join</button>
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

<!-- Chatbot (mirroring landing page) -->
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
          👋 Hi! I'm your EducAid Assistant. Ask me about requirements, process steps, announcements, or schedules.
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Newsletter (reused logic simplified)
const nf = document.getElementById('newsletterForm');
if (nf){
  const msg = document.getElementById('newsletterMessage');
  const btn = document.getElementById('subscribeBtn');
  nf.addEventListener('submit', async e => {
    e.preventDefault();
    const email = document.getElementById('emailInput').value.trim();
    if(!email || !email.includes('@')) { msg.textContent='Invalid email'; msg.className='small text-danger'; msg.style.display='block'; return; }
    btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>...';
    setTimeout(()=>{ msg.textContent='Subscribed (placeholder).'; msg.className='small text-success'; msg.style.display='block'; btn.disabled=false; btn.textContent='Join'; nf.reset(); }, 600);
  });
}
document.getElementById('year').textContent = new Date().getFullYear();

// Chatbot (lightweight reuse)
(function(){
  const apiUrl = '../chatbot/gemini_chat.php';
  const t=document.getElementById('eaToggle'),p=document.getElementById('eaPanel'),c=document.getElementById('eaClose'),b=document.getElementById('eaBody'),i=document.getElementById('eaInput'),s=document.getElementById('eaSend'),ty=document.getElementById('eaTyping');
  if(!t) return; let open=false; function toggle(){ open=!open; p.style.display=open?'block':'none'; if(open) i.focus(); }
  t.addEventListener('click',toggle); c.addEventListener('click',toggle);
  async function send(){ const txt=i.value.trim(); if(!txt) return; i.value=''; i.disabled=true; const um=document.createElement('div'); um.className='ea-chat__msg ea-chat__msg--user'; um.innerHTML='<div class="ea-chat__bubble ea-chat__bubble--user"></div>'; um.querySelector('.ea-chat__bubble').textContent=txt; b.appendChild(um); b.scrollTop=b.scrollHeight; ty.style.display='block'; try { const r= await fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:txt})}); const d= await r.json(); const reply=d.reply||'Sorry, I could not understand that.'; const bm=document.createElement('div'); bm.className='ea-chat__msg'; bm.innerHTML='<div class="ea-chat__bubble"></div>'; bm.querySelector('.ea-chat__bubble').textContent=reply; b.appendChild(bm);}catch(err){const em=document.createElement('div'); em.className='ea-chat__msg'; em.innerHTML='<div class="ea-chat__bubble">Connection issue. Try again later.</div>'; b.appendChild(em);} finally { ty.style.display='none'; i.disabled=false; i.focus(); b.scrollTop=b.scrollHeight; } }
  s.addEventListener('click',send); i.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); send(); }});
  document.addEventListener('click',e=>{ if(!e.target.closest('.ea-chat') && open){ toggle(); }});
})();

// Simple fade-in scroll (fallback if not on landing page animation script)
const observer=new IntersectionObserver(es=>{es.forEach(en=>{if(en.isIntersecting){en.target.classList.add('visible');observer.unobserve(en.target);}})},{threshold:.15});
document.querySelectorAll('.fade-in').forEach(el=>observer.observe(el));
</script>
</body>
</html>
