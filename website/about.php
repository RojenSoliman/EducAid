<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>About EducAid – City of General Trias</title>
  <meta name="description" content="Learn more about EducAid - Educational Assistance Management System for General Trias students" />

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="../assets/css/website/landing_page.css" rel="stylesheet" />
</head>
<body>
  <?php
  // Custom navigation for about page
  $custom_nav_links = [
    ['href' => 'landingpage.php', 'label' => 'Home', 'active' => false],
    ['href' => 'about.php', 'label' => 'About', 'active' => true],
    ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => false],
    ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => false],
    ['href' => 'announcements.php', 'label' => 'Announcements', 'active' => false],
    ['href' => 'landingpage.php#contact', 'label' => 'Contact', 'active' => false]
  ];
  
  include '../includes/website/topbar.php';
  include '../includes/website/navbar.php';
  ?>

  <!-- Hero Section -->
  <section class="hero py-5" style="min-height: 50vh;">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-lg-10">
          <div class="hero-card text-center">
            <h1 class="display-4 fw-bold mb-3">About <span class="text-primary">EducAid</span></h1>
            <p class="lead">Empowering General Trias students through transparent, accessible, and efficient educational assistance management.</p>
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
              <h3 class="section-title mb-0">Our Mission</h3>
            </div>
            <p class="mb-0">To provide equitable access to educational assistance for all qualified students in General Trias through a transparent, efficient, and technology-driven platform that eliminates barriers and ensures fair distribution of resources.</p>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4 h-100">
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="bg-success rounded-circle p-3">
                <i class="bi bi-eye text-white fs-4"></i>
              </div>
              <h3 class="section-title mb-0">Our Vision</h3>
            </div>
            <p class="mb-0">To be the leading model for digital educational assistance management in the Philippines, fostering an educated community where every student has the opportunity to pursue their academic dreams without financial barriers.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Core Values -->
  <section class="py-5 bg-body-tertiary">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="section-title">Our Core Values</h2>
        <p class="section-lead">The principles that guide our commitment to educational assistance</p>
      </div>
      <div class="row g-4">
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-primary bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-shield-check text-primary fs-3"></i>
            </div>
            <h5 class="fw-bold">Transparency</h5>
            <p class="text-body-secondary mb-0">Open processes, clear criteria, and accessible information for all stakeholders.</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-success bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-people text-success fs-3"></i>
            </div>
            <h5 class="fw-bold">Equity</h5>
            <p class="text-body-secondary mb-0">Fair distribution of assistance based on need and qualification, not privilege.</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-warning bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-lightning text-warning fs-3"></i>
            </div>
            <h5 class="fw-bold">Efficiency</h5>
            <p class="text-body-secondary mb-0">Streamlined processes that save time for students, families, and administrators.</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-info bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-heart text-info fs-3"></i>
            </div>
            <h5 class="fw-bold">Compassion</h5>
            <p class="text-body-secondary mb-0">Understanding the struggles of students and families in need of assistance.</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-danger bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-gear text-danger fs-3"></i>
            </div>
            <h5 class="fw-bold">Innovation</h5>
            <p class="text-body-secondary mb-0">Embracing technology to improve service delivery and user experience.</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-secondary bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-hand-thumbs-up text-secondary fs-3"></i>
            </div>
            <h5 class="fw-bold">Accountability</h5>
            <p class="text-body-secondary mb-0">Responsible stewardship of public resources and student data.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- System Features -->
  <section class="py-5">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="section-title">What Makes EducAid Special</h2>
        <p class="section-lead">Advanced features designed for transparency, security, and ease of use</p>
      </div>
      <div class="row g-4">
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <div class="d-flex gap-3">
              <div class="bg-primary rounded p-3 flex-shrink-0">
                <i class="bi bi-qr-code text-white fs-4"></i>
              </div>
              <div>
                <h5 class="fw-bold">QR-Based Claiming System</h5>
                <p class="text-body-secondary mb-0">Secure, fast verification on distribution day. Each student receives a unique QR code that prevents fraud and speeds up the claiming process.</p>
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
                <h5 class="fw-bold">Real-Time Notifications</h5>
                <p class="text-body-secondary mb-0">Instant updates via SMS and email about application status, requirements, schedules, and important announcements.</p>
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
                <h5 class="fw-bold">Digital Document Management</h5>
                <p class="text-body-secondary mb-0">Upload and manage all required documents digitally. Automated validation and secure storage with backup systems.</p>
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
                <h5 class="fw-bold">Analytics & Reporting</h5>
                <p class="text-body-secondary mb-0">Comprehensive reporting for administrators and transparent statistics for the public on distribution and impact metrics.</p>
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
        <h2 class="fw-bold">EducAid by the Numbers</h2>
        <p class="opacity-75">Making a measurable impact in General Trias education</p>
      </div>
      <div class="row text-center g-4">
        <div class="col-6 col-md-3">
          <div class="h1 fw-bold">50+</div>
          <div class="small opacity-75">Barangays Served</div>
        </div>
        <div class="col-6 col-md-3">
          <div class="h1 fw-bold">5,000+</div>
          <div class="small opacity-75">Students Assisted</div>
        </div>
        <div class="col-6 col-md-3">
          <div class="h1 fw-bold">₱10M+</div>
          <div class="small opacity-75">Total Assistance Distributed</div>
        </div>
        <div class="col-6 col-md-3">
          <div class="h1 fw-bold">99.9%</div>
          <div class="small opacity-75">System Uptime</div>
        </div>
      </div>
    </div>
  </section>

  <!-- Team & Partnership -->
  <section class="py-5">
    <div class="container">
      <div class="row g-5 align-items-center">
        <div class="col-lg-6">
          <h2 class="section-title">Partnerships & Collaboration</h2>
          <p class="section-lead">EducAid is a collaborative effort between multiple departments and agencies working together for student success.</p>
          
          <div class="d-flex flex-column gap-3">
            <div class="d-flex gap-3">
              <i class="bi bi-building text-primary fs-5 mt-1"></i>
              <div>
                <h6 class="fw-bold mb-1">Office of the Mayor</h6>
                <small class="text-body-secondary">Policy direction and executive oversight</small>
              </div>
            </div>
            <div class="d-flex gap-3">
              <i class="bi bi-mortarboard text-primary fs-5 mt-1"></i>
              <div>
                <h6 class="fw-bold mb-1">Education Department</h6>
                <small class="text-body-secondary">Program development and student outreach</small>
              </div>
            </div>
            <div class="d-flex gap-3">
              <i class="bi bi-laptop text-primary fs-5 mt-1"></i>
              <div>
                <h6 class="fw-bold mb-1">IT Department</h6>
                <small class="text-body-secondary">System development and technical support</small>
              </div>
            </div>
            <div class="d-flex gap-3">
              <i class="bi bi-people text-primary fs-5 mt-1"></i>
              <div>
                <h6 class="fw-bold mb-1">Social Services</h6>
                <small class="text-body-secondary">Needs assessment and verification</small>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <h5 class="fw-bold mb-3">Contact Our Team</h5>
            <div class="d-flex flex-column gap-2">
              <div class="d-flex gap-2">
                <i class="bi bi-envelope text-primary"></i>
                <span>educaid@generaltrias.gov.ph</span>
              </div>
              <div class="d-flex gap-2">
                <i class="bi bi-telephone text-primary"></i>
                <span>(046) 886-4454</span>
              </div>
              <div class="d-flex gap-2">
                <i class="bi bi-geo-alt text-primary"></i>
                <span>City Government of General Trias, Cavite</span>
              </div>
              <div class="d-flex gap-2">
                <i class="bi bi-clock text-primary"></i>
                <span>Monday - Friday, 8:00 AM - 5:00 PM</span>
              </div>
            </div>
            <div class="mt-3 pt-3 border-top">
              <a href="landingpage.php#contact" class="btn btn-primary me-2">Get Support</a>
              <a href="how-it-works.php" class="btn btn-outline-primary">Learn How It Works</a>
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
              <div class="footer-logo">EducAid • General Trias</div>
              <small>Empowering students through accessible educational assistance</small>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="row">
            <div class="col-6 col-md-4">
              <h6>Explore</h6>
              <ul class="list-unstyled small">
                <li><a href="landingpage.php">Home</a></li>
                <li><a href="about.php">About</a></li>
                <li><a href="how-it-works.php">How It Works</a></li>
              </ul>
            </div>
            <div class="col-6 col-md-4">
              <h6>Resources</h6>
              <ul class="list-unstyled small">
                <li><a href="requirements.php">Requirements</a></li>
                <li><a href="landingpage.php#faq">FAQs</a></li>
                <li><a href="landingpage.php#contact">Contact</a></li>
              </ul>
            </div>
            <div class="col-12 col-md-4 mt-3 mt-md-0">
              <h6>Stay Updated</h6>
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
  const apiUrl = 'chatbot/gemini_chat.php'; // Update this path as needed
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
  <script src="assets/js/website/mobile-navbar.js"></script>
</body>
</html>