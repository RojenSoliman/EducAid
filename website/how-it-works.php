<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>How EducAid Works – City of General Trias</title>
  <meta name="description" content="Step-by-step guide on how to apply and use the EducAid system for educational assistance" />

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
  // Custom navigation for how-it-works page
  $custom_nav_links = [
    ['href' => 'landingpage.php', 'label' => 'Home', 'active' => false],
    ['href' => 'about.php', 'label' => 'About', 'active' => false],
    ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => true],
    ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => false],
    ['href' => 'announcements.php', 'label' => 'Announcements', 'active' => false],
    ['href' => 'contact.php', 'label' => 'Contact', 'active' => false]
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
            <h1 class="display-4 fw-bold mb-3">How <span class="text-primary">EducAid</span> Works</h1>
            <p class="lead">A comprehensive guide to applying for and receiving educational assistance through our digital platform.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Process Overview -->
  <section class="py-5">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="section-title">Simple 4-Step Process</h2>
        <p class="section-lead mx-auto" style="max-width: 700px;">From registration to claiming your assistance</p>
      </div>
      <div class="row g-4">
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100 border-primary border-2">
            <div class="bg-primary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">1</span>
            </div>
            <h5 class="fw-bold text-primary">Register & Verify</h5>
            <p class="text-body-secondary">Create your account and verify your identity</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-secondary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">2</span>
            </div>
            <h5 class="fw-bold">Apply & Upload</h5>
            <p class="text-body-secondary">Complete application and submit documents</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-secondary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">3</span>
            </div>
            <h5 class="fw-bold">Get Evaluated</h5>
            <p class="text-body-secondary">Admin reviews and approves your application</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-secondary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">4</span>
            </div>
            <h5 class="fw-bold">Claim with QR</h5>
            <p class="text-body-secondary">Receive QR code and claim on distribution day</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Detailed Steps -->
  <section class="py-5 bg-body-tertiary">
    <div class="container">
      <h2 class="section-title text-center mb-5">Detailed Process Guide</h2>
      
      <!-- Step 1 -->
      <div class="row g-5 align-items-center mb-5">
        <div class="col-lg-6">
          <div class="d-flex gap-3 mb-3">
            <div class="bg-primary rounded-circle p-3 flex-shrink-0" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">1</span>
            </div>
            <div>
              <h3 class="fw-bold">Registration & Account Verification</h3>
              <p class="text-primary mb-0">Setting up your secure EducAid account</p>
            </div>
          </div>
          
          <div class="ps-5">
            <h5 class="fw-semibold mb-3">What you'll need:</h5>
            <ul class="list-unstyled d-grid gap-2">
              <li><i class="bi bi-check2-circle text-success me-2"></i>Valid email address</li>
              <li><i class="bi bi-check2-circle text-success me-2"></i>Active mobile number</li>
              <li><i class="bi bi-check2-circle text-success me-2"></i>Basic personal information</li>
              <li><i class="bi bi-check2-circle text-success me-2"></i>Barangay of residence</li>
            </ul>
            
            <h5 class="fw-semibold mb-3 mt-4">The process:</h5>
            <div class="d-grid gap-3">
              <div class="d-flex gap-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px;">
                  <i class="bi bi-1-circle text-primary"></i>
                </div>
                <div>
                  <strong>Visit the Registration Page</strong>
                  <p class="text-body-secondary mb-0 small">Click "Apply Now" from the homepage or go directly to the registration form.</p>
                </div>
              </div>
              <div class="d-flex gap-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px;">
                  <i class="bi bi-2-circle text-primary"></i>
                </div>
                <div>
                  <strong>Fill Out Basic Information</strong>
                  <p class="text-body-secondary mb-0 small">Provide your name, contact details, and select your barangay from the dropdown.</p>
                </div>
              </div>
              <div class="d-flex gap-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px;">
                  <i class="bi bi-3-circle text-primary"></i>
                </div>
                <div>
                  <strong>Verify Email & Phone</strong>
                  <p class="text-body-secondary mb-0 small">Check your email and SMS for verification codes. Enter them to activate your account.</p>
                </div>
              </div>
              <div class="d-flex gap-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px;">
                  <i class="bi bi-4-circle text-primary"></i>
                </div>
                <div>
                  <strong>Set Strong Password</strong>
                  <p class="text-body-secondary mb-0 small">Create a secure password with at least 8 characters, including numbers and symbols.</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <img src="https://images.unsplash.com/photo-1516321318423-f06f85e504b3?q=80&w=1000&auto=format&fit=crop" alt="Registration" class="img-fluid rounded mb-3" />
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              <strong>Security Note:</strong> Your data is protected with encryption and stored securely according to RA 10173 (Data Privacy Act).
            </div>
          </div>
        </div>
      </div>

      <!-- Step 2 -->
      <div class="row g-5 align-items-center mb-5">
        <div class="col-lg-6 order-lg-2">
          <div class="d-flex gap-3 mb-3">
            <div class="bg-warning rounded-circle p-3 flex-shrink-0" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">2</span>
            </div>
            <div>
              <h3 class="fw-bold">Complete Application & Upload Documents</h3>
              <p class="text-warning mb-0">Providing your academic and financial information</p>
            </div>
          </div>
          
          <div class="ps-5">
            <h5 class="fw-semibold mb-3">Application Form Sections:</h5>
            <div class="accordion" id="applicationSections">
              <div class="accordion-item">
                <h2 class="accordion-header">
                  <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#section1">
                    Personal & Academic Information
                  </button>
                </h2>
                <div id="section1" class="accordion-collapse collapse show" data-bs-parent="#applicationSections">
                  <div class="accordion-body small">
                    School name, course/grade level, year level, student ID, academic year, and semester information.
                  </div>
                </div>
              </div>
              <div class="accordion-item">
                <h2 class="accordion-header">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#section2">
                    Family & Financial Background
                  </button>
                </h2>
                <div id="section2" class="accordion-collapse collapse" data-bs-parent="#applicationSections">
                  <div class="accordion-body small">
                    Parents' information, household income, number of dependents, and other scholarship recipients in the family.
                  </div>
                </div>
              </div>
              <div class="accordion-item">
                <h2 class="accordion-header">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#section3">
                    Document Upload
                  </button>
                </h2>
                <div id="section3" class="accordion-collapse collapse" data-bs-parent="#applicationSections">
                  <div class="accordion-body small">
                    Clear photos or PDFs of required documents. Maximum 5MB per file. Accepted formats: JPG, PNG, PDF.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6 order-lg-1">
          <div class="soft-card p-4">
            <img src="https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?q=80&w=1000&auto=format&fit=crop" alt="Documents" class="img-fluid rounded mb-3" />
            <div class="alert alert-warning">
              <i class="bi bi-exclamation-triangle me-2"></i>
              <strong>Important:</strong> Ensure all documents are clear, complete, and up-to-date. Blurry or incomplete documents will delay processing.
            </div>
          </div>
        </div>
      </div>

      <!-- Step 3 -->
      <div class="row g-5 align-items-center mb-5">
        <div class="col-lg-6">
          <div class="d-flex gap-3 mb-3">
            <div class="bg-info rounded-circle p-3 flex-shrink-0" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">3</span>
            </div>
            <div>
              <h3 class="fw-bold">Evaluation & Approval Process</h3>
              <p class="text-info mb-0">Admin review and verification of your application</p>
            </div>
          </div>
          
          <div class="ps-5">
            <h5 class="fw-semibold mb-3">What happens during evaluation:</h5>
            <div class="timeline">
              <div class="d-flex gap-3 mb-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                  <i class="bi bi-file-earmark-check text-info"></i>
                </div>
                <div>
                  <strong>Document Review (2-3 days)</strong>
                  <p class="text-body-secondary mb-0 small">Admin staff verify all uploaded documents for completeness and authenticity.</p>
                </div>
              </div>
              <div class="d-flex gap-3 mb-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                  <i class="bi bi-search text-info"></i>
                </div>
                <div>
                  <strong>Eligibility Check (1-2 days)</strong>
                  <p class="text-body-secondary mb-0 small">Cross-reference with eligibility criteria and existing beneficiary database.</p>
                </div>
              </div>
              <div class="d-flex gap-3 mb-3">
                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                  <i class="bi bi-person-check text-info"></i>
                </div>
                <div>
                  <strong>Final Approval (1 day)</strong>
                  <p class="text-body-secondary mb-0 small">Supervisor review and final decision on application status.</p>
                </div>
              </div>
            </div>
            
            <div class="alert alert-info mt-3">
              <strong>Status Updates:</strong> You'll receive SMS and email notifications at each stage. Log in to your dashboard to see detailed status.
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <h5 class="fw-bold mb-3">Possible Application Status</h5>
            <div class="d-grid gap-2">
              <div class="d-flex justify-content-between align-items-center p-2 bg-warning bg-opacity-10 rounded">
                <span><i class="bi bi-clock text-warning me-2"></i>Under Review</span>
                <small class="text-body-secondary">In progress</small>
              </div>
              <div class="d-flex justify-content-between align-items-center p-2 bg-info bg-opacity-10 rounded">
                <span><i class="bi bi-question-circle text-info me-2"></i>Needs Clarification</span>
                <small class="text-body-secondary">Action required</small>
              </div>
              <div class="d-flex justify-content-between align-items-center p-2 bg-success bg-opacity-10 rounded">
                <span><i class="bi bi-check-circle text-success me-2"></i>Approved</span>
                <small class="text-body-secondary">Ready for QR</small>
              </div>
              <div class="d-flex justify-content-between align-items-center p-2 bg-danger bg-opacity-10 rounded">
                <span><i class="bi bi-x-circle text-danger me-2"></i>Not Eligible</span>
                <small class="text-body-secondary">Final decision</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Step 4 -->
      <div class="row g-5 align-items-center">
        <div class="col-lg-6 order-lg-2">
          <div class="d-flex gap-3 mb-3">
            <div class="bg-success rounded-circle p-3 flex-shrink-0" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">4</span>
            </div>
            <div>
              <h3 class="fw-bold">QR Code Generation & Claiming</h3>
              <p class="text-success mb-0">Receiving your assistance on distribution day</p>
            </div>
          </div>
          
          <div class="ps-5">
            <h5 class="fw-semibold mb-3">After approval:</h5>
            <div class="d-grid gap-3">
              <div class="soft-card p-3">
                <h6 class="fw-bold text-success mb-2">
                  <i class="bi bi-qr-code me-2"></i>QR Code Ready
                </h6>
                <p class="small mb-0">Download your unique QR code from your dashboard. You can also receive it via email.</p>
              </div>
              <div class="soft-card p-3">
                <h6 class="fw-bold text-primary mb-2">
                  <i class="bi bi-calendar-event me-2"></i>Distribution Schedule
                </h6>
                <p class="small mb-0">You'll receive notification about the date, time, and venue for assistance distribution.</p>
              </div>
              <div class="soft-card p-3">
                <h6 class="fw-bold text-warning mb-2">
                  <i class="bi bi-card-checklist me-2"></i>What to Bring
                </h6>
                <ul class="small mb-0 ps-3">
                  <li>Your QR code (printed or on phone)</li>
                  <li>Valid school ID</li>
                  <li>One government-issued ID</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6 order-lg-1">
          <div class="soft-card p-4 text-center">
            <div class="bg-success bg-opacity-10 rounded p-4 mb-3">
              <i class="bi bi-qr-code display-1 text-success"></i>
            </div>
            <h5 class="fw-bold">Sample QR Code</h5>
            <p class="text-body-secondary small">Each student gets a unique, secure QR code linked to their approved application.</p>
            <div class="alert alert-success">
              <i class="bi bi-shield-check me-2"></i>
              <strong>Secure & Fraud-Proof:</strong> QR codes are encrypted and single-use to prevent duplication or misuse.
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Tips & Best Practices -->
  <section class="py-5">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="section-title">Tips for Success</h2>
        <p class="section-lead mx-auto" style="max-width: 700px;">Best practices to ensure smooth application processing</p>
      </div>
      <div class="row g-4">
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 h-100">
            <div class="text-success mb-3">
              <i class="bi bi-camera fs-2"></i>
            </div>
            <h5 class="fw-bold">Document Quality</h5>
            <ul class="small text-body-secondary">
              <li>Use good lighting when taking photos</li>
              <li>Ensure text is clearly readable</li>
              <li>Avoid shadows or glare</li>
              <li>Take photos straight-on, not at angles</li>
            </ul>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 h-100">
            <div class="text-primary mb-3">
              <i class="bi bi-clock fs-2"></i>
            </div>
            <h5 class="fw-bold">Timing</h5>
            <ul class="small text-body-secondary">
              <li>Apply early when slots open</li>
              <li>Don't wait until deadlines</li>
              <li>Check announcements regularly</li>
              <li>Respond quickly to admin requests</li>
            </ul>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <div class="soft-card p-4 h-100">
            <div class="text-warning mb-3">
              <i class="bi bi-shield-check fs-2"></i>
            </div>
            <h5 class="fw-bold">Security</h5>
            <ul class="small text-body-secondary">
              <li>Keep login credentials secure</li>
              <li>Don't share QR codes</li>
              <li>Log out after using public computers</li>
              <li>Report suspicious activity immediately</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="py-5 bg-primary text-white">
    <div class="container text-center">
      <h2 class="fw-bold mb-3">Ready to Get Started?</h2>
      <p class="lead mb-4">Join thousands of General Trias students who have successfully received educational assistance through EducAid.</p>
      <div class="d-flex gap-3 justify-content-center flex-wrap">
        <a href="landingpage.php#apply" class="btn btn-light btn-lg">
          <i class="bi bi-journal-text me-2"></i>Start Your Application
        </a>
        <a href="requirements.php" class="btn btn-outline-light btn-lg">
          <i class="bi bi-list-check me-2"></i>View Requirements
        </a>
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
              <small>Simplifying educational assistance for every student</small>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="row">
            <div class="col-6 col-md-4">
              <h6>Process</h6>
              <ul class="list-unstyled small">
                <li><a href="#step1">Registration</a></li>
                <li><a href="#step2">Application</a></li>
                <li><a href="#step3">Evaluation</a></li>
                <li><a href="#step4">Claiming</a></li>
              </ul>
            </div>
            <div class="col-6 col-md-4">
              <h6>Support</h6>
              <ul class="list-unstyled small">
                <li><a href="requirements.php">Requirements</a></li>
                <li><a href="landingpage.php#faq">FAQs</a></li>
                <li><a href="contact.php">Contact</a></li>
              </ul>
            </div>
            <div class="col-12 col-md-4 mt-3 mt-md-0">
              <h6>Get Help</h6>
              <div class="d-flex flex-column gap-1 small">
                <span>📧 educaid@generaltrias.gov.ph</span>
                <span>📞 (046) 886-4454</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <hr class="border-light opacity-25 my-4" />
      <div class="d-flex justify-content-between flex-wrap gap-2 small">
        <span>© <span id="year"></span> City Government of General Trias • EducAid</span>
        <span>Step-by-step guide to educational assistance</span>
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
    
    // Enhanced scroll animations
    const observerOptions = {
      threshold: 0.1,
      rootMargin: '0px 0px -5% 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry, index) => {
        if (entry.isIntersecting) {
          setTimeout(() => {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
          }, index * 100);
        }
      });
    }, observerOptions);
    
    // Observe all cards with staggered animation
    document.querySelectorAll('.soft-card').forEach((el, index) => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(30px)';
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