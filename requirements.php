<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Requirements – EducAid City of General Trias</title>
  <meta name="description" content="Complete list of requirements and documents needed for EducAid educational assistance application" />

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="assets/css/website/landing_page.css" rel="stylesheet" />
</head>
<body>
  <?php
  // Custom navigation for requirements page
  $custom_nav_links = [
    ['href' => 'landingpage.php', 'label' => 'Home', 'active' => false],
    ['href' => 'about.php', 'label' => 'About', 'active' => false],
    ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => false],
    ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => true],
    ['href' => 'landingpage.php#announcements', 'label' => 'Announcements', 'active' => false],
    ['href' => 'landingpage.php#contact', 'label' => 'Contact', 'active' => false]
  ];
  
  include 'includes/website/topbar.php';
  include 'includes/website/navbar.php';
  ?>

  <!-- Hero Section -->
  <section class="hero py-5" style="min-height: 50vh;">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-lg-10">
          <div class="hero-card text-center">
            <h1 class="display-4 fw-bold mb-3">Application <span class="text-primary">Requirements</span></h1>
            <p class="lead">Complete checklist of documents and information needed for your EducAid application.</p>
            <div class="mt-4">
              <a href="#checklist" class="btn btn-primary btn-lg me-3">
                <i class="bi bi-list-check me-2"></i>View Checklist
              </a>
              <a href="#preparation" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-camera me-2"></i>Document Tips
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Quick Requirements Overview -->
  <section class="py-5 bg-body-tertiary">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="section-title">Requirements at a Glance</h2>
        <p class="section-lead">Essential documents you'll need to prepare</p>
      </div>
      <div class="row g-4">
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-primary bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-person-vcard text-primary fs-3"></i>
            </div>
            <h5 class="fw-bold">Identity Documents</h5>
            <p class="text-body-secondary small">School ID, birth certificate, and valid government ID</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-success bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-mortarboard text-success fs-3"></i>
            </div>
            <h5 class="fw-bold">Academic Records</h5>
            <p class="text-body-secondary small">Enrollment forms, grades, and school certifications</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-warning bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-file-earmark-text text-warning fs-3"></i>
            </div>
            <h5 class="fw-bold">Financial Documents</h5>
            <p class="text-body-secondary small">Income statements, certificates of indigency</p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-info bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-house text-info fs-3"></i>
            </div>
            <h5 class="fw-bold">Residency Proof</h5>
            <p class="text-body-secondary small">Barangay certificates and utility bills</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Detailed Requirements -->
  <section id="checklist" class="py-5">
    <div class="container">
      <h2 class="section-title text-center mb-5">Complete Requirements Checklist</h2>
      
      <!-- Primary Requirements -->
      <div class="row g-5">
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <div class="d-flex align-items-center gap-3 mb-4">
              <div class="bg-primary rounded p-3">
                <i class="bi bi-star-fill text-white fs-4"></i>
              </div>
              <div>
                <h4 class="fw-bold mb-0">Primary Requirements</h4>
                <p class="text-body-secondary mb-0">Essential documents for all applicants</p>
              </div>
            </div>
            
            <div class="d-grid gap-3">
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Valid School ID</h6>
                    <p class="text-body-secondary small mb-1">Current academic year school identification card</p>
                    <span class="badge text-bg-primary-subtle">Required</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Certificate of Enrollment</h6>
                    <p class="text-body-secondary small mb-1">Official enrollment certificate from your school</p>
                    <span class="badge text-bg-primary-subtle">Required</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Enrollment Assessment Form</h6>
                    <p class="text-body-secondary small mb-1">Statement of account showing tuition and fees</p>
                    <span class="badge text-bg-primary-subtle">Required</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Letter to the Mayor</h6>
                    <p class="text-body-secondary small mb-1">Formal application letter explaining your need for assistance</p>
                    <span class="badge text-bg-primary-subtle">Required</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Birth Certificate (PSA)</h6>
                    <p class="text-body-secondary small mb-1">Original PSA-issued birth certificate</p>
                    <span class="badge text-bg-primary-subtle">Required</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Barangay Certificate</h6>
                    <p class="text-body-secondary small mb-1">Proof of residency in General Trias</p>
                    <span class="badge text-bg-primary-subtle">Required</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Secondary Requirements -->
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <div class="d-flex align-items-center gap-3 mb-4">
              <div class="bg-warning rounded p-3">
                <i class="bi bi-plus-circle text-white fs-4"></i>
              </div>
              <div>
                <h4 class="fw-bold mb-0">Additional Requirements</h4>
                <p class="text-body-secondary mb-0">May be required based on your situation</p>
              </div>
            </div>
            
            <div class="d-grid gap-3">
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-exclamation-circle text-warning fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Certificate of Indigency</h6>
                    <p class="text-body-secondary small mb-1">From your barangay (required after initial approval)</p>
                    <span class="badge text-bg-warning-subtle">Conditional</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-info-circle text-info fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Parent/Guardian Income Statement</h6>
                    <p class="text-body-secondary small mb-1">ITR, Certificate of Employment, or Affidavit of Income</p>
                    <span class="badge text-bg-info-subtle">If Applicable</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-info-circle text-info fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Previous Semester Grades</h6>
                    <p class="text-body-secondary small mb-1">For continuing students (transcript or report card)</p>
                    <span class="badge text-bg-info-subtle">Continuing Students</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-info-circle text-info fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Graduation Certificate</h6>
                    <p class="text-body-secondary small mb-1">For college freshmen (SHS diploma)</p>
                    <span class="badge text-bg-info-subtle">New College Students</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-info-circle text-info fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Medical Certificate</h6>
                    <p class="text-body-secondary small mb-1">For PWD or students with health concerns</p>
                    <span class="badge text-bg-info-subtle">Special Cases</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-info-circle text-info fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1">Utility Bills</h6>
                    <p class="text-body-secondary small mb-1">Recent water/electric bill as additional proof of address</p>
                    <span class="badge text-bg-info-subtle">Verification</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Document Preparation Tips -->
  <section id="preparation" class="py-5 bg-body-tertiary">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="section-title">Document Preparation Guide</h2>
        <p class="section-lead">How to properly prepare and upload your documents</p>
      </div>
      
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="soft-card p-4">
            <h4 class="fw-bold mb-4">
              <i class="bi bi-camera text-primary me-2"></i>
              Photography Guidelines
            </h4>
            
            <div class="row g-4">
              <div class="col-md-6">
                <h6 class="fw-semibold text-success mb-3">✅ Do This</h6>
                <ul class="list-unstyled d-grid gap-2">
                  <li class="d-flex gap-2">
                    <i class="bi bi-check text-success mt-1"></i>
                    <span class="small">Use good lighting (natural light is best)</span>
                  </li>
                  <li class="d-flex gap-2">
                    <i class="bi bi-check text-success mt-1"></i>
                    <span class="small">Take photos straight-on, not at angles</span>
                  </li>
                  <li class="d-flex gap-2">
                    <i class="bi bi-check text-success mt-1"></i>
                    <span class="small">Ensure all text is clearly readable</span>
                  </li>
                  <li class="d-flex gap-2">
                    <i class="bi bi-check text-success mt-1"></i>
                    <span class="small">Include the entire document in frame</span>
                  </li>
                  <li class="d-flex gap-2">
                    <i class="bi bi-check text-success mt-1"></i>
                    <span class="small">Use a plain, contrasting background</span>
                  </li>
                </ul>
              </div>
              <div class="col-md-6">
                <h6 class="fw-semibold text-danger mb-3">❌ Avoid This</h6>
                <ul class="list-unstyled d-grid gap-2">
                  <li class="d-flex gap-2">
                    <i class="bi bi-x text-danger mt-1"></i>
                    <span class="small">Blurry or out-of-focus images</span>
                  </li>
                  <li class="d-flex gap-2">
                    <i class="bi bi-x text-danger mt-1"></i>
                    <span class="small">Photos with shadows or glare</span>
                  </li>
                  <li class="d-flex gap-2">
                    <i class="bi bi-x text-danger mt-1"></i>
                    <span class="small">Cropped or incomplete documents</span>
                  </li>
                  <li class="d-flex gap-2">
                    <i class="bi bi-x text-danger mt-1"></i>
                    <span class="small">Extremely small file sizes</span>
                  </li>
                  <li class="d-flex gap-2">
                    <i class="bi bi-x text-danger mt-1"></i>
                    <span class="small">Documents with personal info of others visible</span>
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </div>
        
        <div class="col-lg-4">
          <div class="soft-card p-4 h-100">
            <h5 class="fw-bold mb-3">
              <i class="bi bi-file-earmark-arrow-up text-info me-2"></i>
              Upload Specifications
            </h5>
            <div class="d-grid gap-3">
              <div class="border-start border-primary border-3 ps-3">
                <h6 class="fw-semibold">File Formats</h6>
                <p class="small text-body-secondary mb-0">JPG, PNG, PDF</p>
              </div>
              <div class="border-start border-success border-3 ps-3">
                <h6 class="fw-semibold">Maximum Size</h6>
                <p class="small text-body-secondary mb-0">5MB per file</p>
              </div>
              <div class="border-start border-warning border-3 ps-3">
                <h6 class="fw-semibold">Resolution</h6>
                <p class="small text-body-secondary mb-0">Minimum 300 DPI</p>
              </div>
              <div class="border-start border-info border-3 ps-3">
                <h6 class="fw-semibold">Color</h6>
                <p class="small text-body-secondary mb-0">Color or clear grayscale</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Letter to Mayor Template -->
  <section class="py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="soft-card p-4">
            <h4 class="fw-bold mb-4">
              <i class="bi bi-file-text text-primary me-2"></i>
              Letter to the Mayor Template
            </h4>
            
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              <strong>Note:</strong> Use this template as a guide for writing your formal application letter. Personalize it with your specific situation.
            </div>
            
            <div class="bg-light p-4 rounded border">
              <div class="small font-monospace">
                <p class="mb-2">[Date]</p>
                <br>
                <p class="mb-2">Hon. Antonio C. Ferrer<br>
                City Mayor<br>
                City Government of General Trias<br>
                General Trias, Cavite</p>
                <br>
                <p class="mb-2">Dear Mayor Ferrer,</p>
                <br>
                <p class="mb-2">I am <strong>[Your Full Name]</strong>, a resident of Barangay <strong>[Your Barangay]</strong>, General Trias, Cavite. I am currently enrolled as a <strong>[Year Level]</strong> student taking <strong>[Course/Program]</strong> at <strong>[School Name]</strong>.</p>
                <br>
                <p class="mb-2">I am writing to formally request educational assistance through the EducAid program. Due to <strong>[brief explanation of financial situation - e.g., "our family's limited financial resources as my parent works as a [occupation] with minimal income"]</strong>, I am finding it challenging to cover my educational expenses.</p>
                <br>
                <p class="mb-2">This assistance will greatly help me continue my studies and achieve my goal of <strong>[brief statement about your academic/career goals]</strong>. I am committed to maintaining good academic standing and contributing positively to our community.</p>
                <br>
                <p class="mb-2">I have attached all the required documents as specified in the EducAid application guidelines. I humbly request your favorable consideration of my application.</p>
                <br>
                <p class="mb-2">Thank you for your time and for the opportunities you provide to students like me.</p>
                <br>
                <p class="mb-2">Respectfully yours,</p>
                <br>
                <p class="mb-2"><strong>[Your Signature]</strong><br>
                <strong>[Your Printed Name]</strong><br>
                <strong>[Your Contact Number]</strong><br>
                <strong>[Your Email Address]</strong></p>
              </div>
            </div>
            
            <div class="mt-3">
              <button class="btn btn-outline-primary" onclick="copyTemplate()">
                <i class="bi bi-clipboard me-2"></i>Copy Template
              </button>
              <small class="text-body-secondary ms-3">Click to copy the template text</small>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- FAQ Section -->
  <section class="py-5 bg-body-tertiary">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="section-title">Frequently Asked Questions</h2>
        <p class="section-lead">Common questions about requirements and documentation</p>
      </div>
      
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="accordion soft-card" id="requirementsFaq">
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                  What if I don't have all the required documents?
                </button>
              </h2>
              <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#requirementsFaq">
                <div class="accordion-body">
                  You can still submit your application with the available documents. However, missing primary requirements may delay processing. Contact our support team for guidance on alternative documents or procedures.
                </div>
              </div>
            </div>
            
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                  Can I submit photocopies instead of original documents?
                </button>
              </h2>
              <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#requirementsFaq">
                <div class="accordion-body">
                  Yes, clear photocopies or digital scans are acceptable for online submission. However, you may be required to present original documents for verification during the claiming process.
                </div>
              </div>
            </div>
            
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                  How recent should my documents be?
                </button>
              </h2>
              <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#requirementsFaq">
                <div class="accordion-body">
                  Most documents should be current for the academic year you're applying for. Barangay certificates and certificates of indigency should be issued within 30 days of application. Income statements should be from the most recent available period.
                </div>
              </div>
            </div>
            
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                  What if my document is rejected?
                </button>
              </h2>
              <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#requirementsFaq">
                <div class="accordion-body">
                  You'll receive notification explaining why the document was rejected and what needs to be corrected. You can re-upload the corrected document through your dashboard without starting a new application.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="py-5 bg-primary text-white">
    <div class="container text-center">
      <h2 class="fw-bold mb-3">Ready to Prepare Your Documents?</h2>
      <p class="lead mb-4">Gather your requirements and start your EducAid application today.</p>
      <div class="d-flex gap-3 justify-content-center flex-wrap">
        <a href="landingpage.php#apply" class="btn btn-light btn-lg">
          <i class="bi bi-journal-text me-2"></i>Start Application
        </a>
        <a href="how-it-works.php" class="btn btn-outline-light btn-lg">
          <i class="bi bi-question-circle me-2"></i>How It Works
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
              <small>Your guide to educational assistance requirements</small>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="row">
            <div class="col-6 col-md-4">
              <h6>Documents</h6>
              <ul class="list-unstyled small">
                <li><a href="#checklist">Primary Requirements</a></li>
                <li><a href="#checklist">Additional Requirements</a></li>
                <li><a href="#preparation">Upload Guidelines</a></li>
              </ul>
            </div>
            <div class="col-6 col-md-4">
              <h6>Help</h6>
              <ul class="list-unstyled small">
                <li><a href="#preparation">Photo Tips</a></li>
                <li><a href="landingpage.php#faq">FAQs</a></li>
                <li><a href="landingpage.php#contact">Contact Support</a></li>
              </ul>
            </div>
            <div class="col-12 col-md-4 mt-3 mt-md-0">
              <h6>Quick Contact</h6>
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
        <span>Complete requirements guide for educational assistance</span>
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
    
    // Copy template function
    function copyTemplate() {
      const template = `[Date]

Hon. Antonio C. Ferrer
City Mayor
City Government of General Trias
General Trias, Cavite

Dear Mayor Ferrer,

I am [Your Full Name], a resident of Barangay [Your Barangay], General Trias, Cavite. I am currently enrolled as a [Year Level] student taking [Course/Program] at [School Name].

I am writing to formally request educational assistance through the EducAid program. Due to [brief explanation of financial situation], I am finding it challenging to cover my educational expenses.

This assistance will greatly help me continue my studies and achieve my goal of [brief statement about your academic/career goals]. I am committed to maintaining good academic standing and contributing positively to our community.

I have attached all the required documents as specified in the EducAid application guidelines. I humbly request your favorable consideration of my application.

Thank you for your time and for the opportunities you provide to students like me.

Respectfully yours,

[Your Signature]
[Your Printed Name]
[Your Contact Number]
[Your Email Address]`;

      navigator.clipboard.writeText(template).then(() => {
        // Show success message
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check me-2"></i>Copied!';
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-success');
        
        setTimeout(() => {
          btn.innerHTML = originalText;
          btn.classList.remove('btn-success');
          btn.classList.add('btn-outline-primary');
        }, 2000);
      });
    }
    
    // Scroll animations
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
    
    // Observe requirement items with staggered animation
    document.querySelectorAll('.requirement-item').forEach((el, index) => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(20px)';
      el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
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