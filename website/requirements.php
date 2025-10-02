<?php
session_start();
// Determine super admin edit mode for Requirements page (?edit=1)
$IS_EDIT_MODE = false; $is_super_admin = false;
@include_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
  $role = @getCurrentAdminRole($connection);
  if ($role === 'super_admin') { $is_super_admin = true; }
}
if ($is_super_admin && isset($_GET['edit']) && $_GET['edit'] == '1') { $IS_EDIT_MODE = true; }
// Load dedicated requirements page content helper (separate storage)
@include_once __DIR__ . '/../includes/website/requirements_content_helper.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo strip_tags(req_block('req_page_title','Requirements – EducAid City of General Trias')); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars(strip_tags(req_block('req_page_meta_desc','Complete list of requirements and documents needed for EducAid educational assistance application'))); ?>" />

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
  // Custom navigation for requirements page
  $custom_nav_links = [
    ['href' => 'landingpage.php', 'label' => 'Home', 'active' => false],
    ['href' => 'about.php', 'label' => 'About', 'active' => false],
    ['href' => 'how-it-works.php', 'label' => 'How it works', 'active' => false],
    ['href' => 'requirements.php', 'label' => 'Requirements', 'active' => true],
    ['href' => 'announcements.php', 'label' => 'Announcements', 'active' => false],
    ['href' => 'contact.php', 'label' => 'Contact', 'active' => false]
  ];
  
  include '../includes/website/topbar.php';
  include '../includes/website/navbar.php';
  ?>
<?php if($IS_EDIT_MODE): ?>
<div id="lp-edit-toolbar" class="lp-edit-toolbar shadow-sm">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <strong class="small">Requirements Editor</strong>
    <div class="d-flex gap-1 flex-wrap">
  <a href="../modules/admin/homepage.php" class="btn btn-sm btn-outline-primary" title="Return to Admin Dashboard"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
  <button id="lp-save-btn" class="btn btn-sm btn-success" disabled><i class="bi bi-save me-1"></i>Save</button>
  <button id="lp-save-all-btn" class="btn btn-sm btn-outline-success" title="Save all editable content"><i class="bi bi-cloud-arrow-up me-1"></i>Save All</button>
      <button id="lp-reset-all" class="btn btn-sm btn-outline-danger" title="Reset ALL blocks"><i class="bi bi-trash3"></i></button>
      <button id="lp-history-btn" class="btn btn-sm btn-outline-primary" title="View history"><i class="bi bi-clock-history"></i></button>
      <a href="requirements.php" id="lp-exit-btn" class="btn btn-sm btn-outline-secondary" title="Exit"><i class="bi bi-x-lg"></i></a>
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
            <h1 class="display-4 fw-bold mb-3" data-lp-key="req_hero_title"<?php echo req_block_style('req_hero_title'); ?>><?php echo req_block('req_hero_title','Application <span class="text-primary">Requirements</span>'); ?></h1>
            <p class="lead" data-lp-key="req_hero_lead"<?php echo req_block_style('req_hero_lead'); ?>><?php echo req_block('req_hero_lead','Complete checklist of documents and information needed for your EducAid application.'); ?></p>
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
        <h2 class="section-title" data-lp-key="req_overview_title"<?php echo req_block_style('req_overview_title'); ?>><?php echo req_block('req_overview_title','Requirements at a Glance'); ?></h2>
        <p class="section-lead mx-auto" style="max-width: 700px;" data-lp-key="req_overview_lead"<?php echo req_block_style('req_overview_lead'); ?>><?php echo req_block('req_overview_lead','Essential documents you\'ll need to prepare'); ?></p>
      </div>
      <div class="row g-4">
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-primary bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-person-vcard text-primary fs-3"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="req_cat1_title"<?php echo req_block_style('req_cat1_title'); ?>><?php echo req_block('req_cat1_title','Identity Documents'); ?></h5>
            <p class="text-body-secondary small" data-lp-key="req_cat1_desc"<?php echo req_block_style('req_cat1_desc'); ?>><?php echo req_block('req_cat1_desc','School ID, birth certificate, and valid government ID'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-success bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-mortarboard text-success fs-3"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="req_cat2_title"<?php echo req_block_style('req_cat2_title'); ?>><?php echo req_block('req_cat2_title','Academic Records'); ?></h5>
            <p class="text-body-secondary small" data-lp-key="req_cat2_desc"<?php echo req_block_style('req_cat2_desc'); ?>><?php echo req_block('req_cat2_desc','Enrollment forms, grades, and school certifications'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-warning bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-file-earmark-text text-warning fs-3"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="req_cat3_title"<?php echo req_block_style('req_cat3_title'); ?>><?php echo req_block('req_cat3_title','Financial Documents'); ?></h5>
            <p class="text-body-secondary small" data-lp-key="req_cat3_desc"<?php echo req_block_style('req_cat3_desc'); ?>><?php echo req_block('req_cat3_desc','Income statements, certificates of indigency'); ?></p>
          </div>
        </div>
        <div class="col-md-6 col-lg-3">
          <div class="soft-card p-4 text-center h-100">
            <div class="bg-info bg-opacity-10 rounded-circle p-3 d-inline-flex mb-3">
              <i class="bi bi-house text-info fs-3"></i>
            </div>
            <h5 class="fw-bold" data-lp-key="req_cat4_title"<?php echo req_block_style('req_cat4_title'); ?>><?php echo req_block('req_cat4_title','Residency Proof'); ?></h5>
            <p class="text-body-secondary small" data-lp-key="req_cat4_desc"<?php echo req_block_style('req_cat4_desc'); ?>><?php echo req_block('req_cat4_desc','Barangay certificates and utility bills'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Detailed Requirements -->
  <section id="checklist" class="py-5">
    <div class="container">
      <h2 class="section-title text-center mb-5" data-lp-key="req_checklist_title"<?php echo req_block_style('req_checklist_title'); ?>><?php echo req_block('req_checklist_title','Complete Requirements Checklist'); ?></h2>
      
      <!-- Primary Requirements -->
      <div class="row g-5">
        <div class="col-lg-6">
          <div class="soft-card p-4">
            <div class="d-flex align-items-center gap-3 mb-4">
              <div class="bg-primary rounded p-3">
                <i class="bi bi-star-fill text-white fs-4"></i>
              </div>
              <div>
                <h4 class="fw-bold mb-0" data-lp-key="req_primary_title"<?php echo req_block_style('req_primary_title'); ?>><?php echo req_block('req_primary_title','Primary Requirements'); ?></h4>
                <p class="text-body-secondary mb-0" data-lp-key="req_primary_subtitle"<?php echo req_block_style('req_primary_subtitle'); ?>><?php echo req_block('req_primary_subtitle','Essential documents for all applicants'); ?></p>
              </div>
            </div>
            
            <div class="d-grid gap-3">
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1" data-lp-key="req_item1_title"<?php echo req_block_style('req_item1_title'); ?>><?php echo req_block('req_item1_title','Valid School ID'); ?></h6>
                    <p class="text-body-secondary small mb-1" data-lp-key="req_item1_desc"<?php echo req_block_style('req_item1_desc'); ?>><?php echo req_block('req_item1_desc','Current academic year school identification card'); ?></p>
                    <span class="badge text-bg-primary-subtle">Required</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1" data-lp-key="req_item2_title"<?php echo req_block_style('req_item2_title'); ?>><?php echo req_block('req_item2_title','Certificate of Enrollment'); ?></h6>
                    <p class="text-body-secondary small mb-1" data-lp-key="req_item2_desc"<?php echo req_block_style('req_item2_desc'); ?>><?php echo req_block('req_item2_desc','Official enrollment certificate from your school'); ?></p>
                    <span class="badge text-bg-primary-subtle">Required</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1" data-lp-key="req_item3_title"<?php echo req_block_style('req_item3_title'); ?>><?php echo req_block('req_item3_title','Enrollment Assessment Form'); ?></h6>
                    <p class="text-body-secondary small mb-1" data-lp-key="req_item3_desc"<?php echo req_block_style('req_item3_desc'); ?>><?php echo req_block('req_item3_desc','Statement of account showing tuition and fees'); ?></p>
                    <span class="badge text-bg-primary-subtle">Required</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1" data-lp-key="req_item4_title"<?php echo req_block_style('req_item4_title'); ?>><?php echo req_block('req_item4_title','Letter to the Mayor'); ?></h6>
                    <p class="text-body-secondary small mb-1" data-lp-key="req_item4_desc"<?php echo req_block_style('req_item4_desc'); ?>><?php echo req_block('req_item4_desc','Formal application letter explaining your need for assistance'); ?></p>
                    <span class="badge text-bg-primary-subtle">Required</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1" data-lp-key="req_item5_title"<?php echo req_block_style('req_item5_title'); ?>><?php echo req_block('req_item5_title','Birth Certificate (PSA)'); ?></h6>
                    <p class="text-body-secondary small mb-1" data-lp-key="req_item5_desc"<?php echo req_block_style('req_item5_desc'); ?>><?php echo req_block('req_item5_desc','Original PSA-issued birth certificate'); ?></p>
                    <span class="badge text-bg-primary-subtle">Required</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-check-circle text-success fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1" data-lp-key="req_item6_title"<?php echo req_block_style('req_item6_title'); ?>><?php echo req_block('req_item6_title','Barangay Certificate'); ?></h6>
                    <p class="text-body-secondary small mb-1" data-lp-key="req_item6_desc"<?php echo req_block_style('req_item6_desc'); ?>><?php echo req_block('req_item6_desc','Proof of residency in General Trias'); ?></p>
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
                <h4 class="fw-bold mb-0" data-lp-key="req_additional_title"<?php echo req_block_style('req_additional_title'); ?>><?php echo req_block('req_additional_title','Additional Requirements'); ?></h4>
                <p class="text-body-secondary mb-0" data-lp-key="req_additional_subtitle"<?php echo req_block_style('req_additional_subtitle'); ?>><?php echo req_block('req_additional_subtitle','May be required based on your situation'); ?></p>
              </div>
            </div>
            
            <div class="d-grid gap-3">
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-exclamation-circle text-warning fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1" data-lp-key="req_add1_title"<?php echo req_block_style('req_add1_title'); ?>><?php echo req_block('req_add1_title','Certificate of Indigency'); ?></h6>
                    <p class="text-body-secondary small mb-1" data-lp-key="req_add1_desc"<?php echo req_block_style('req_add1_desc'); ?>><?php echo req_block('req_add1_desc','From your barangay (required after initial approval)'); ?></p>
                    <span class="badge text-bg-warning-subtle">Conditional</span>
                  </div>
                </div>
              </div>
              
              <div class="requirement-item">
                <div class="d-flex gap-3">
                  <i class="bi bi-info-circle text-info fs-5 mt-1"></i>
                  <div>
                    <h6 class="fw-bold mb-1" data-lp-key="req_add2_title"<?php echo req_block_style('req_add2_title'); ?>><?php echo req_block('req_add2_title','Parent/Guardian Income Statement'); ?></h6>
                    <p class="text-body-secondary small mb-1" data-lp-key="req_add2_desc"<?php echo req_block_style('req_add2_desc'); ?>><?php echo req_block('req_add2_desc','ITR, Certificate of Employment, or Affidavit of Income'); ?></p>
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
        <h2 class="section-title" data-lp-key="req_prep_title"<?php echo req_block_style('req_prep_title'); ?>><?php echo req_block('req_prep_title','Document Preparation Guide'); ?></h2>
        <p class="section-lead mx-auto" style="max-width: 700px;" data-lp-key="req_prep_lead"<?php echo req_block_style('req_prep_lead'); ?>><?php echo req_block('req_prep_lead','How to properly prepare and upload your documents'); ?></p>
      </div>
      
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="soft-card p-4">
            <h4 class="fw-bold mb-4" data-lp-key="req_photo_title"<?php echo req_block_style('req_photo_title'); ?>>
              <i class="bi bi-camera text-primary me-2"></i><?php echo req_block('req_photo_title','Photography Guidelines'); ?>
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
            <h5 class="fw-bold mb-3" data-lp-key="req_upload_title"<?php echo req_block_style('req_upload_title'); ?>>
              <i class="bi bi-file-earmark-arrow-up text-info me-2"></i><?php echo req_block('req_upload_title','Upload Specifications'); ?>
            </h5>
            <div class="d-grid gap-3">
              <div class="border-start border-primary border-3 ps-3">
                <h6 class="fw-semibold" data-lp-key="req_spec1_title"<?php echo req_block_style('req_spec1_title'); ?>><?php echo req_block('req_spec1_title','File Formats'); ?></h6>
                <p class="small text-body-secondary mb-0" data-lp-key="req_spec1_desc"<?php echo req_block_style('req_spec1_desc'); ?>><?php echo req_block('req_spec1_desc','JPG, PNG, PDF'); ?></p>
              </div>
              <div class="border-start border-success border-3 ps-3">
                <h6 class="fw-semibold" data-lp-key="req_spec2_title"<?php echo req_block_style('req_spec2_title'); ?>><?php echo req_block('req_spec2_title','Maximum Size'); ?></h6>
                <p class="small text-body-secondary mb-0" data-lp-key="req_spec2_desc"<?php echo req_block_style('req_spec2_desc'); ?>><?php echo req_block('req_spec2_desc','5MB per file'); ?></p>
              </div>
              <div class="border-start border-warning border-3 ps-3">
                <h6 class="fw-semibold" data-lp-key="req_spec3_title"<?php echo req_block_style('req_spec3_title'); ?>><?php echo req_block('req_spec3_title','Resolution'); ?></h6>
                <p class="small text-body-secondary mb-0" data-lp-key="req_spec3_desc"<?php echo req_block_style('req_spec3_desc'); ?>><?php echo req_block('req_spec3_desc','Minimum 300 DPI'); ?></p>
              </div>
              <div class="border-start border-info border-3 ps-3">
                <h6 class="fw-semibold" data-lp-key="req_spec4_title"<?php echo req_block_style('req_spec4_title'); ?>><?php echo req_block('req_spec4_title','Color'); ?></h6>
                <p class="small text-body-secondary mb-0" data-lp-key="req_spec4_desc"<?php echo req_block_style('req_spec4_desc'); ?>><?php echo req_block('req_spec4_desc','Color or clear grayscale'); ?></p>
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
        <h2 class="section-title" data-lp-key="req_faq_title"<?php echo req_block_style('req_faq_title'); ?>><?php echo req_block('req_faq_title','Frequently Asked Questions'); ?></h2>
        <p class="section-lead mx-auto" style="max-width: 700px;" data-lp-key="req_faq_lead"<?php echo req_block_style('req_faq_lead'); ?>><?php echo req_block('req_faq_lead','Common questions about requirements and documentation'); ?></p>
      </div>
      
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="accordion soft-card" id="requirementsFaq">
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1" data-lp-key="req_faq1_q"<?php echo req_block_style('req_faq1_q'); ?>>
                  <?php echo req_block('req_faq1_q','What if I don\'t have all the required documents?'); ?>
                </button>
              </h2>
              <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#requirementsFaq">
                <div class="accordion-body" data-lp-key="req_faq1_a"<?php echo req_block_style('req_faq1_a'); ?>>
                  <?php echo req_block('req_faq1_a','You can still submit your application with the available documents. However, missing primary requirements may delay processing. Contact our support team for guidance on alternative documents or procedures.'); ?>
                </div>
              </div>
            </div>
            
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2" data-lp-key="req_faq2_q"<?php echo req_block_style('req_faq2_q'); ?>>
                  <?php echo req_block('req_faq2_q','Can I submit photocopies instead of original documents?'); ?>
                </button>
              </h2>
              <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#requirementsFaq">
                <div class="accordion-body" data-lp-key="req_faq2_a"<?php echo req_block_style('req_faq2_a'); ?>>
                  <?php echo req_block('req_faq2_a','Yes, clear photocopies or digital scans are acceptable for online submission. However, you may be required to present original documents for verification during the claiming process.'); ?>
                </div>
              </div>
            </div>
            
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3" data-lp-key="req_faq3_q"<?php echo req_block_style('req_faq3_q'); ?>>
                  <?php echo req_block('req_faq3_q','How recent should my documents be?'); ?>
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

  <!-- CTA Section - Eye-catching design -->
  <section class="py-5" style="background: linear-gradient(135deg, #0d47a1 0%, #1976d2 50%, #42a5f5 100%); position: relative; overflow: hidden;">
    <!-- Decorative elements -->
    <div style="position: absolute; top: -50px; right: -50px; width: 300px; height: 300px; background: rgba(255,255,255,0.1); border-radius: 50%; filter: blur(60px);"></div>
    <div style="position: absolute; bottom: -80px; left: -80px; width: 400px; height: 400px; background: rgba(255,255,255,0.08); border-radius: 50%; filter: blur(80px);"></div>
    
    <div class="container" style="position: relative; z-index: 1;">
      <div class="row align-items-center g-5">
        <!-- Left side - Icon & Stats -->
        <div class="col-lg-5">
          <div class="text-white">
            <div class="d-flex align-items-center gap-3 mb-4">
              <div class="bg-white bg-opacity-25 rounded-circle p-4 d-flex align-items-center justify-content-center" style="width: 80px; height: 80px; backdrop-filter: blur(10px);">
                <i class="bi bi-check-circle-fill text-white" style="font-size: 2.5rem;"></i>
              </div>
              <div>
                <h3 class="fw-bold mb-1" data-lp-key="req_cta_heading"<?php echo req_block_style('req_cta_heading'); ?>><?php echo req_block('req_cta_heading','All Set?'); ?></h3>
                <p class="mb-0 opacity-75" data-lp-key="req_cta_subheading"<?php echo req_block_style('req_cta_subheading'); ?>><?php echo req_block('req_cta_subheading','You\'re ready to begin'); ?></p>
              </div>
            </div>
            <div class="row g-3 text-center">
              <div class="col-4">
                <div class="bg-white bg-opacity-25 rounded p-3 border border-white border-opacity-25" style="backdrop-filter: blur(10px);">
                  <h4 class="fw-bold mb-1 text-white">4</h4>
                  <small class="text-white" style="opacity: 0.9;">Categories</small>
                </div>
              </div>
              <div class="col-4">
                <div class="bg-white bg-opacity-25 rounded p-3 border border-white border-opacity-25" style="backdrop-filter: blur(10px);">
                  <h4 class="fw-bold mb-1 text-white">10+</h4>
                  <small class="text-white" style="opacity: 0.9;">Documents</small>
                </div>
              </div>
              <div class="col-4">
                <div class="bg-white bg-opacity-25 rounded p-3 border border-white border-opacity-25" style="backdrop-filter: blur(10px);">
                  <h4 class="fw-bold mb-1 text-white">24/7</h4>
                  <small class="text-white" style="opacity: 0.9;">Upload</small>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right side - CTA Content -->
        <div class="col-lg-7">
          <div class="text-white">
            <span class="badge bg-white text-primary rounded-pill px-3 py-2 mb-3">
              <i class="bi bi-stars me-2"></i>Ready to Apply
            </span>
            <h2 class="display-5 fw-bold mb-3">Start Your Application Journey</h2>
            <p class="lead mb-4 opacity-90">Gather your requirements and begin your EducAid application today. Our streamlined process makes it easy to upload documents and track your progress.</p>
            
            <!-- Feature highlights -->
            <div class="row g-3 mb-4">
              <div class="col-md-6">
                <div class="d-flex align-items-start gap-2">
                  <i class="bi bi-check-circle-fill mt-1"></i>
                  <div>
                    <strong class="d-block">Quick & Easy Upload</strong>
                    <small class="opacity-75">Scan or photo from any device</small>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="d-flex align-items-start gap-2">
                  <i class="bi bi-check-circle-fill mt-1"></i>
                  <div>
                    <strong class="d-block">Real-time Tracking</strong>
                    <small class="opacity-75">Monitor your application status</small>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="d-flex align-items-start gap-2">
                  <i class="bi bi-check-circle-fill mt-1"></i>
                  <div>
                    <strong class="d-block">Secure Processing</strong>
                    <small class="opacity-75">Your documents are safe with us</small>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="d-flex align-items-start gap-2">
                  <i class="bi bi-check-circle-fill mt-1"></i>
                  <div>
                    <strong class="d-block">24/7 Availability</strong>
                    <small class="opacity-75">Apply anytime, anywhere</small>
                  </div>
                </div>
              </div>
            </div>

            <!-- Action buttons -->
            <div class="d-flex gap-3 flex-wrap">
              <a href="../register.php" class="btn btn-light btn-lg px-4 py-3 shadow" style="border-radius: 12px;">
                <i class="bi bi-rocket-takeoff me-2"></i>Start Application Now
              </a>
              <a href="how-it-works.php" class="btn btn-outline-light btn-lg px-4 py-3" style="border-radius: 12px; backdrop-filter: blur(10px); background: rgba(255,255,255,0.1);">
                <i class="bi bi-question-circle me-2"></i>Learn the Process
              </a>
            </div>
            
            <div class="mt-4 d-flex align-items-center gap-3 flex-wrap">
              <small class="opacity-75"><i class="bi bi-clock me-1"></i> Application takes ~15 minutes</small>
              <small class="opacity-75"><i class="bi bi-people me-1"></i> Join 5,000+ beneficiaries</small>
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
                <li><a href="contact.php">Contact Support</a></li>
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
  <script src="../assets/js/website/mobile-navbar.js"></script>
  <?php if($IS_EDIT_MODE): ?>
  <script src="../assets/js/website/content_editor.js"></script>
  <script>
  // Initialize shared ContentEditor for Requirements page
  ContentEditor.init({
    page: 'requirements',
    saveEndpoint: 'ajax_save_req_content.php',
    resetAllEndpoint: 'ajax_reset_req_content.php',
    history: { fetchEndpoint: 'ajax_get_req_history.php', rollbackEndpoint: 'ajax_rollback_req_block.php' },
    refreshAfterSave: async (keys)=>{
      try {
        const r = await fetch('ajax_get_req_blocks.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({keys})});
        const d = await r.json(); if(!d.success) return;
        (d.blocks||[]).forEach(b=>{ const el=document.querySelector('[data-lp-key="'+CSS.escape(b.block_key)+'"]'); if(!el) return; el.innerHTML=b.html; if(b.text_color) el.style.color=b.text_color; else el.style.removeProperty('color'); if(b.bg_color) el.style.backgroundColor=b.bg_color; else el.style.removeProperty('background-color'); });
      } catch(err){ console.error('Refresh error', err); }
    }
  });
  </script>
  <?php endif; ?>
</body>
</html>