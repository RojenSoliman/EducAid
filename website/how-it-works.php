<?php
session_start();
// Determine super admin edit mode for How It Works page (?edit=1)
$IS_EDIT_MODE = false; $is_super_admin = false;
@include_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
  $role = @getCurrentAdminRole($connection);
  if ($role === 'super_admin') { $is_super_admin = true; }
}
if ($is_super_admin && isset($_GET['edit']) && $_GET['edit'] == '1') { $IS_EDIT_MODE = true; }
// Load dedicated how-it-works page content helper (separate storage)
@include_once __DIR__ . '/../includes/website/how_it_works_content_helper.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo strip_tags(hiw_block('hiw_page_title','How EducAid Works – City of General Trias')); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars(strip_tags(hiw_block('hiw_page_meta_desc','Step-by-step guide on how to apply and use the EducAid system for educational assistance'))); ?>" />

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="../assets/css/website/landing_page.css" rel="stylesheet" />
  <style>
    <?php if($IS_EDIT_MODE): ?>
    .lp-edit-toolbar { position:fixed; top:70px; right:12px; width:300px; background:#fff; border:1px solid #d1d9e0; border-radius:12px; z-index:4000; padding:.75rem .85rem; font-family: system-ui, sans-serif; }
    .lp-edit-highlight { outline:2px dashed #2563eb; outline-offset:2px; cursor:text; position:relative; }
    .lp-edit-highlight[data-lp-dirty="1"]::after { content:'●'; position:absolute; top:-6px; right:-6px; background:#dc2626; color:#fff; width:14px; height:14px; font-size:.55rem; display:flex; align-items:center; justify-content:center; border-radius:50%; font-weight:700; box-shadow:0 0 0 2px #fff; }
    .lp-edit-toolbar textarea { font-size:.7rem; }
    .lp-edit-toolbar .form-label { font-size:.6rem; letter-spacing:.5px; text-transform:uppercase; }
    body.lp-editing { scroll-padding-top:90px; }
    .lp-edit-badge { position:fixed; left:12px; top:70px; background:#1d4ed8; color:#fff; padding:4px 10px; font-size:.65rem; font-weight:600; letter-spacing:.5px; border-radius:30px; z-index:4000; display:flex; align-items:center; gap:4px; box-shadow:0 2px 4px rgba(0,0,0,.2);}    
    .lp-edit-badge .dot { width:6px; height:6px; background:#22c55e; border-radius:50%; box-shadow:0 0 0 2px rgba(255,255,255,.4); }
    .lp-inline-hist-btn { position:absolute; top:-10px; left:-10px; background:#fff; border:1px solid #94a3b8; color:#334155; font-size:.55rem; padding:2px 4px; border-radius:4px; display:none; cursor:pointer; line-height:1; }
    [data-lp-key]:hover > .lp-inline-hist-btn { display:inline-block; }
    .lp-inline-hist-btn:hover { background:#2563eb; color:#fff; border-color:#2563eb; }
    /* History modal styles (shared) */
    .lp-history-modal { position:fixed; inset:0; z-index:5000; display:none; }
    .lp-history-modal.show { display:block; }
    .lp-history-modal .lp-hist-backdrop { position:absolute; inset:0; background:rgba(0,0,0,.45); backdrop-filter:blur(2px); }
    .lp-history-modal .lp-hist-dialog { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:780px; max-width:95%; background:#fff; border-radius:14px; box-shadow:0 10px 40px -10px rgba(0,0,0,.35); display:flex; flex-direction:column; max-height:85vh; }
    .lp-history-modal .lp-hist-header { padding:.55rem .8rem; border-bottom:1px solid #e2e8f0; }
    .lp-history-modal .lp-hist-body { padding:.7rem .85rem .9rem; overflow:auto; }
    .lp-history-modal .lp-hist-footer { padding:.45rem .85rem; border-top:1px solid #e2e8f0; background:#f8fafc; border-bottom-left-radius:14px; border-bottom-right-radius:14px; font-size:.65rem; }
    .lp-hist-item { border:1px solid #e2e8f0; border-radius:6px; padding:.38rem .45rem; margin-bottom:.4rem; cursor:pointer; background:#fff; transition:background .15s,border-color .15s; }
    .lp-hist-item:hover { background:#f1f5f9; }
    .lp-hist-item.active { border-color:#2563eb; background:#eff6ff; }
    @media (max-width:620px){ .lp-history-modal .lp-hist-dialog { width:95%; } }
    <?php endif; ?>
  </style>
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
<?php if($IS_EDIT_MODE): ?>
<div id="lp-edit-toolbar" class="lp-edit-toolbar shadow-sm">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <strong class="small">How It Works Editor</strong>
    <div class="d-flex gap-1 flex-wrap">
  <a href="../modules/admin/homepage.php" class="btn btn-sm btn-outline-primary" title="Return to Admin Dashboard"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
  <button id="lp-save-btn" class="btn btn-sm btn-success" disabled><i class="bi bi-save me-1"></i>Save</button>
  <button id="lp-save-all-btn" class="btn btn-sm btn-outline-success" title="Save all editable content"><i class="bi bi-cloud-arrow-up me-1"></i>Save All</button>
      <button id="lp-reset-all" class="btn btn-sm btn-outline-danger" title="Reset ALL blocks"><i class="bi bi-trash3"></i></button>
      <button id="lp-history-btn" class="btn btn-sm btn-outline-primary" title="View history"><i class="bi bi-clock-history"></i></button>
      <a href="how-it-works.php" id="lp-exit-btn" class="btn btn-sm btn-outline-secondary" title="Exit"><i class="bi bi-x-lg"></i></a>
    </div>
  </div>
  <div class="mb-2">
    <label class="form-label mb-1">Selected</label>
    <div id="lp-current-target" class="form-control form-control-sm bg-body-tertiary" style="height:auto;min-height:32px;font-size:.65rem"></div>
  </div>
  <div class="mb-2">
    <label class="form-label mb-1">Text</label>
    <textarea id="lp-edit-text" class="form-control form-control-sm" rows="3" placeholder="Click an editable element"></textarea>
  </div>
  <div class="row g-2 mb-2">
    <div class="col-6"><label class="form-label mb-1">Text Color</label><input type="color" id="lp-text-color" class="form-control form-control-color form-control-sm" value="#000000" /></div>
    <div class="col-6"><label class="form-label mb-1">BG Color</label><input type="color" id="lp-bg-color" class="form-control form-control-color form-control-sm" value="#ffffff" /></div>
  </div>
  <div class="d-flex gap-2 mb-2">
    <button id="lp-reset-btn" class="btn btn-sm btn-outline-warning w-100" disabled><i class="bi bi-arrow-counterclockwise me-1"></i>Reset Block</button>
    <button id="lp-highlight-toggle" class="btn btn-sm btn-outline-primary w-100" data-active="1"><i class="bi bi-bounding-box-circles me-1"></i>Hide Boxes</button>
  </div>
  <div class="text-end"><small class="text-muted" id="lp-status">Idle</small></div>
</div>
<div class="lp-edit-badge"><span class="dot"></span> EDIT MODE</div>
<?php endif; ?>

  <!-- Hero Section -->
  <section class="hero py-5" style="min-height: 50vh;">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-lg-10">
          <div class="hero-card text-center">
            <h1 class="display-4 fw-bold mb-3" data-lp-key="hiw_hero_title"<?php echo hiw_block_style('hiw_hero_title'); ?>><?php echo hiw_block('hiw_hero_title','How <span class="text-primary">EducAid</span> Works'); ?></h1>
            <p class="lead" data-lp-key="hiw_hero_lead"<?php echo hiw_block_style('hiw_hero_lead'); ?>><?php echo hiw_block('hiw_hero_lead','A comprehensive guide to applying for and receiving educational assistance through our digital platform.'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Process Overview -->
  <section class="py-5">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="section-title" data-lp-key="hiw_overview_title"<?php echo hiw_block_style('hiw_overview_title'); ?>><?php echo hiw_block('hiw_overview_title','Simple 4-Step Process'); ?></h2>
        <p class="section-lead mx-auto" style="max-width: 700px;" data-lp-key="hiw_overview_lead"<?php echo hiw_block_style('hiw_overview_lead'); ?>><?php echo hiw_block('hiw_overview_lead','From registration to claiming your assistance'); ?></p>
      </div>
      <div class="row g-4">
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100 border-primary border-2">
            <div class="bg-primary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">1</span>
            </div>
            <h5 class="fw-bold text-primary" data-lp-key="hiw_step1_title"<?php echo hiw_block_style('hiw_step1_title'); ?>><?php echo hiw_block('hiw_step1_title','Register & Verify'); ?></h5>
            <p class="text-body-secondary" data-lp-key="hiw_step1_desc"<?php echo hiw_block_style('hiw_step1_desc'); ?>><?php echo hiw_block('hiw_step1_desc','Create your account and verify your identity'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-secondary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">2</span>
            </div>
            <h5 class="fw-bold" data-lp-key="hiw_step2_title"<?php echo hiw_block_style('hiw_step2_title'); ?>><?php echo hiw_block('hiw_step2_title','Apply & Upload'); ?></h5>
            <p class="text-body-secondary" data-lp-key="hiw_step2_desc"<?php echo hiw_block_style('hiw_step2_desc'); ?>><?php echo hiw_block('hiw_step2_desc','Complete application and submit documents'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-secondary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">3</span>
            </div>
            <h5 class="fw-bold" data-lp-key="hiw_step3_title"<?php echo hiw_block_style('hiw_step3_title'); ?>><?php echo hiw_block('hiw_step3_title','Get Evaluated'); ?></h5>
            <p class="text-body-secondary" data-lp-key="hiw_step3_desc"<?php echo hiw_block_style('hiw_step3_desc'); ?>><?php echo hiw_block('hiw_step3_desc','Admin reviews and approves your application'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-secondary rounded-circle p-3 d-inline-flex mb-3" style="width: 60px; height: 60px; align-items: center; justify-content: center;">
              <span class="text-white fw-bold fs-4">4</span>
            </div>
            <h5 class="fw-bold" data-lp-key="hiw_step4_title"<?php echo hiw_block_style('hiw_step4_title'); ?>><?php echo hiw_block('hiw_step4_title','Claim with QR'); ?></h5>
            <p class="text-body-secondary" data-lp-key="hiw_step4_desc"<?php echo hiw_block_style('hiw_step4_desc'); ?>><?php echo hiw_block('hiw_step4_desc','Receive QR code and claim on distribution day'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Detailed Steps -->
  <section class="py-5 bg-body-tertiary">
    <div class="container">
      <h2 class="section-title text-center mb-5" data-lp-key="hiw_detailed_title"<?php echo hiw_block_style('hiw_detailed_title'); ?>><?php echo hiw_block('hiw_detailed_title','Detailed Process Guide'); ?></h2>
      
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
  <script src="../assets/js/website/mobile-navbar.js"></script>
  <?php if($IS_EDIT_MODE): ?>
  <script src="../assets/js/website/content_editor.js"></script>
  <script>
  // Initialize shared ContentEditor for How It Works page
  ContentEditor.init({
    page: 'how-it-works',
    saveEndpoint: 'ajax_save_hiw_content.php',
    resetAllEndpoint: 'ajax_reset_hiw_content.php',
    history: { fetchEndpoint: 'ajax_get_hiw_history.php', rollbackEndpoint: 'ajax_rollback_hiw_block.php' },
    refreshAfterSave: async (keys)=>{
      try {
        const r = await fetch('ajax_get_hiw_blocks.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({keys})});
        const d = await r.json(); if(!d.success) return;
        (d.blocks||[]).forEach(b=>{ const el=document.querySelector('[data-lp-key="'+CSS.escape(b.block_key)+'"]'); if(!el) return; el.innerHTML=b.html; if(b.text_color) el.style.color=b.text_color; else el.style.removeProperty('color'); if(b.bg_color) el.style.backgroundColor=b.bg_color; else el.style.removeProperty('background-color'); });
      } catch(err){ console.error('Refresh error', err); }
    }
  });
  </script>
  <?php endif; ?>
</body>
</html>