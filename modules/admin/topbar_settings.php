<?php
session_start();

// Security checks for regular page load
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Include dependencies
include_once __DIR__ . '/../../includes/permissions.php';
include_once __DIR__ . '/../../config/database.php';
include_once __DIR__ . '/../../services/ThemeSettingsService.php';
include_once __DIR__ . '/../../controllers/TopbarSettingsController.php';
include_once __DIR__ . '/../../includes/CSRFProtection.php';
include_once __DIR__ . '/../../services/HeaderThemeService.php';

// Check if super admin
$admin_role = getCurrentAdminRole($connection);
if ($admin_role !== 'super_admin') {
    header("Location: homepage.php");
    exit;
}

// Initialize services
$themeService = new ThemeSettingsService($connection);
$headerThemeService = new HeaderThemeService($connection);
$controller = new TopbarSettingsController($themeService, $_SESSION['admin_id'] ?? 0, $connection);

// Unified form submission (topbar + header)
$form_result = [ 'success' => false, 'message' => '', 'data' => $themeService->getCurrentSettings() ];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Validate token but don't consume it yet (consume=false)
  // We'll manually consume it only after successful save
  if (CSRFProtection::validateToken('topbar_settings', $_POST['csrf_token'] ?? '', false)) {
    // Save topbar first
    $form_result = $controller->handleFormSubmission();
    $success = $form_result['success'];
    $msgTop = $form_result['message'] ?? '';
    // Save header theme (ignore validation errors merging for simplicity, collect message)
    $headerSave = $headerThemeService->save($_POST, (int)($_SESSION['admin_id'] ?? 0));
    $msgHeader = $headerSave['success'] ? 'Header theme updated.' : ($headerSave['message'] ?? 'Header theme save failed.');
    if ($form_result['success'] && $headerSave['success']) {
      $successMessage = trim($msgTop . ' ' . $msgHeader);
      $errorMessage = '';
      // Both saves successful - now consume the token
      if (isset($_SESSION['csrf_tokens']['topbar_settings'])) {
        unset($_SESSION['csrf_tokens']['topbar_settings']);
      }
    } else {
      $successMessage = ($form_result['success'] ? $msgTop : '') . ($headerSave['success'] ? (' ' . $msgHeader) : '');
      $errorMessage = (!$form_result['success'] ? $msgTop : '') . (!$headerSave['success'] ? (' ' . $msgHeader) : '');
      // Don't consume token on error - allow retry
    }
  } else {
    $successMessage = '';
    // Add debug info to error message
    $debug_info = '';
  if (isset($_POST['csrf_token'])) {
    $submitted_token = substr($_POST['csrf_token'], 0, 16) . '...';
    $session_data = $_SESSION['csrf_tokens']['topbar_settings'] ?? null;
    if (is_array($session_data)) {
      $session_preview = array_map(function ($token) {
        return substr($token, 0, 16) . '...';
      }, $session_data);
      $session_token = 'MULTI [' . implode(', ', $session_preview) . ']';
    } elseif (is_string($session_data)) {
      $session_token = substr($session_data, 0, 16) . '...';
    } else {
      $session_token = 'NO TOKEN IN SESSION';
    }
    $debug_info = " (Submitted: $submitted_token, Session: $session_token)";
    } else {
        $debug_info = " (No csrf_token in POST data)";
    }
    $errorMessage = 'Security token validation failed. Please try again.' . $debug_info;
  }
}

// Derive success/error strings (set above when POST)
$success = $successMessage ?? '';
$error = $errorMessage ?? '';

// Get current settings (updated if form was submitted successfully)
$current_settings = $form_result['success'] && isset($form_result['data']) 
  ? $form_result['data'] 
  : $themeService->getCurrentSettings();

// Header theme settings retrieval (after any save)
$header_settings = $headerThemeService->getCurrentSettings();
?>
<?php $page_title='Topbar Settings'; $extra_css=[]; include '../../includes/admin/admin_head.php'; ?>
<style>
  /* Page-specific styling (scoped where possible) */
  body.topbar-settings-page .settings-card {
    background: #ffffff;
    border-radius: 0.5rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
  }
  body.topbar-settings-page .preview-topbar {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    color: #fff;
    padding: 0.75rem 1rem;
    border-radius: 0.375rem;
    margin-bottom: 1.5rem;
    font-size: 0.875rem;
    font-family: 'Poppins', var(--bs-font-sans-serif, Arial, sans-serif);
  }
  body.topbar-settings-page .form-label { font-weight:600; color:#374151; }
  body.topbar-settings-page .form-control:focus { border-color:#2e7d32; box-shadow:0 0 0 0.2rem rgba(46,125,50,0.25); }
</style>
<body class="topbar-settings-page">
  <?php include '../../includes/admin/admin_topbar.php'; ?>
  
  <div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">
      <div class="container-fluid py-4 px-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h2 class="mb-1">Topbar Settings</h2>
            <p class="text-muted mb-0">Customize the contact information displayed in the admin topbar</p>
          </div>
          <div class="d-flex align-items-center gap-3">
            <a href="homepage.php" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
            </a>
          </div>
        </div>
        
        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <!-- Topbar Live Preview -->
        <div class="settings-card mb-3">
          <h5 class="mb-3"><i class="bi bi-eye me-2"></i>Topbar Preview</h5>
          <div class="preview-topbar" id="preview-topbar">
            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
              <div class="d-flex align-items-center gap-3 small">
                <i class="bi bi-shield-lock" id="preview-topbar-icon"></i>
                <span>Administrative Panel</span>
                <span class="vr mx-2 d-none d-md-inline"></span>
                <i class="bi bi-envelope"></i>
                <a href="#" class="text-decoration-none" id="preview-email" style="color: <?= htmlspecialchars($current_settings['topbar_link_color']) ?>;">
                  <?= htmlspecialchars($current_settings['topbar_email']) ?>
                </a>
                <span class="vr mx-2 d-none d-lg-inline"></span>
                <i class="bi bi-telephone"></i>
                <span class="d-none d-sm-inline" id="preview-phone">
                  <?= htmlspecialchars($current_settings['topbar_phone']) ?>
                </span>
              </div>
              <div class="d-flex align-items-center gap-3 small">
                <i class="bi bi-clock"></i>
                <span class="d-none d-md-inline" id="preview-hours">
                  <?= htmlspecialchars($current_settings['topbar_office_hours']) ?>
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- (Header preview now moved next to header color changers below) -->
        
        <!-- Unified Settings Form (Topbar + Header) -->
        <form method="POST" id="settingsForm">
          <?= CSRFProtection::getTokenField('topbar_settings') ?>
          <div class="row">
            <div class="col-lg-8">
              <div class="settings-card">
                <h5 class="mb-4"><i class="bi bi-gear me-2"></i>Topbar Contact Information</h5>
                
                <div class="mb-3">
                  <label for="topbar_email" class="form-label">Email Address <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="topbar_email" name="topbar_email" 
                           value="<?= htmlspecialchars($current_settings['topbar_email']) ?>" 
                           placeholder="educaid@generaltrias.gov.ph" required>
                  </div>
                  <div class="form-text">This email will be displayed in the admin topbar and used for contact purposes.</div>
                </div>
                
                <div class="mb-3">
                  <label for="topbar_phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                    <input type="text" class="form-control" id="topbar_phone" name="topbar_phone" 
                           value="<?= htmlspecialchars($current_settings['topbar_phone']) ?>" 
                           placeholder="(046) 886-4454" required>
                  </div>
                  <div class="form-text">Phone number for administrative inquiries.</div>
                </div>
                
                <div class="mb-0">
                  <label for="topbar_office_hours" class="form-label">Office Hours <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-clock"></i></span>
                    <input type="text" class="form-control" id="topbar_office_hours" name="topbar_office_hours" 
                           value="<?= htmlspecialchars($current_settings['topbar_office_hours']) ?>" 
                           placeholder="Mon–Fri 8:00AM - 5:00PM" required>
                  </div>
                  <div class="form-text">Operating hours for administrative services.</div>
                </div>
              </div>
              
              <div class="settings-card">
                <h5 class="mb-4"><i class="bi bi-building me-2"></i>System Information</h5>
                
                <div class="mb-3">
                  <label for="system_name" class="form-label">System Name</label>
                  <input type="text" class="form-control" id="system_name" name="system_name" 
                         value="<?= htmlspecialchars($current_settings['system_name']) ?>" 
                         placeholder="EducAid">
                  <div class="form-text">Name of the system/application.</div>
                </div>
                
                <div class="mb-0">
                  <label for="municipality_name" class="form-label">Municipality Name</label>
                  <input type="text" class="form-control" id="municipality_name" name="municipality_name" 
                         value="<?= htmlspecialchars($current_settings['municipality_name']) ?>" 
                         placeholder="City of General Trias">
                  <div class="form-text">Name of the municipality or local government unit.</div>
                </div>
              </div>
              
              <!-- Color Settings Section -->
              <div class="settings-card">
                <h5 class="mb-4"><i class="bi bi-palette me-2"></i>Topbar Color Settings</h5>
                
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label for="topbar_bg_color" class="form-label">
                      <i class="bi bi-paint-bucket"></i> Background Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="topbar_bg_color" 
                             name="topbar_bg_color" 
                             value="<?= htmlspecialchars($current_settings['topbar_bg_color']) ?>"
                             title="Choose background color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['topbar_bg_color']) ?>"
                             readonly>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <label for="topbar_bg_gradient" class="form-label">
                      <i class="bi bi-circle-half"></i> Gradient Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="topbar_bg_gradient" 
                             name="topbar_bg_gradient" 
                             value="<?= htmlspecialchars($current_settings['topbar_bg_gradient']) ?>"
                             title="Choose gradient color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['topbar_bg_gradient']) ?>"
                             readonly>
                    </div>
                  </div>
                </div>
                
                <div class="row mb-0">
                  <div class="col-md-6">
                    <label for="topbar_text_color" class="form-label">
                      <i class="bi bi-fonts"></i> Text Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="topbar_text_color" 
                             name="topbar_text_color" 
                             value="<?= htmlspecialchars($current_settings['topbar_text_color']) ?>"
                             title="Choose text color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['topbar_text_color']) ?>"
                             readonly>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <label for="topbar_link_color" class="form-label">
                      <i class="bi bi-link-45deg"></i> Link Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="topbar_link_color" 
                             name="topbar_link_color" 
                             value="<?= htmlspecialchars($current_settings['topbar_link_color']) ?>"
                             title="Choose link color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['topbar_link_color']) ?>"
                             readonly>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Header Color Settings (with inline preview) -->
              <div class="settings-card">
                <h5 class="mb-4"><i class="bi bi-layout-three-columns me-2"></i>Header Appearance</h5>
                <div class="row mb-4 g-3 align-items-stretch">
                  <div class="col-lg-7">
                    <!-- Header color inputs -->
                    <div class="row mb-3">
                      <div class="col-md-6">
                        <label class="form-label">Header Background</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_bg_color" id="header_bg_color" value="<?= htmlspecialchars($header_settings['header_bg_color']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_bg_color']) ?>" readonly>
                        </div>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Header Border Color</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_border_color" id="header_border_color" value="<?= htmlspecialchars($header_settings['header_border_color']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_border_color']) ?>" readonly>
                        </div>
                      </div>
                    </div>
                    <div class="row mb-3">
                      <div class="col-md-6">
                        <label class="form-label">Header Text Color</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_text_color" id="header_text_color" value="<?= htmlspecialchars($header_settings['header_text_color']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_text_color']) ?>" readonly>
                        </div>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Header Icon Color</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_icon_color" id="header_icon_color" value="<?= htmlspecialchars($header_settings['header_icon_color']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_icon_color']) ?>" readonly>
                        </div>
                      </div>
                    </div>
                    <div class="row mb-0">
                      <div class="col-md-6">
                        <label class="form-label">Header Hover Background</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_hover_bg" id="header_hover_bg" value="<?= htmlspecialchars($header_settings['header_hover_bg']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_hover_bg']) ?>" readonly>
                        </div>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Header Hover Icon Color</label>
                        <div class="input-group">
                          <input type="color" class="form-control form-control-color" name="header_hover_icon_color" id="header_hover_icon_color" value="<?= htmlspecialchars($header_settings['header_hover_icon_color']) ?>">
                          <input type="text" class="form-control" value="<?= htmlspecialchars($header_settings['header_hover_icon_color']) ?>" readonly>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="col-lg-5">
                    <div class="border rounded p-3 h-100 d-flex flex-column" style="background: var(--bs-body-bg,#fff);">
                      <div class="fw-semibold small text-muted mb-2"><i class="bi bi-eye me-1"></i>Header Preview</div>
                      <div class="preview-header border rounded p-3 flex-grow-1" id="preview-header" style="background: <?= htmlspecialchars($header_settings['header_bg_color']) ?>; border:1px solid <?= htmlspecialchars($header_settings['header_border_color']) ?>;">
                        <div class="d-flex align-items-center justify-content-between">
                          <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm" id="preview-menu-btn" style="background: <?= htmlspecialchars($header_settings['header_hover_bg']) ?>; color: <?= htmlspecialchars($header_settings['header_icon_color']) ?>;">
                              <i class="bi bi-list"></i>
                            </button>
                            <span class="fw-semibold" id="preview-header-title" style="color: <?= htmlspecialchars($header_settings['header_text_color']) ?>;">Header Area</span>
                          </div>
                          <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm" style="background:#f8fbf8; color: <?= htmlspecialchars($header_settings['header_icon_color']) ?>;"><i class="bi bi-bell"></i></button>
                            <button type="button" class="btn btn-sm" style="background:#f8fbf8; color: <?= htmlspecialchars($header_settings['header_icon_color']) ?>;"><i class="bi bi-person-circle"></i></button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="row mb-3">
                  <!-- (Legacy placeholder row removed; preview integrated above) -->
                </div>
              </div>

              <div class="d-flex justify-content-end gap-2">
                <a href="homepage.php" class="btn btn-secondary">
                  <i class="bi bi-x-lg me-2"></i>Cancel
                </a>
                <button type="submit" class="btn btn-success">
                  <i class="bi bi-check-lg me-2"></i>Save Changes
                </button>
              </div>
            </div>
            
            <div class="col-lg-4">
              <div class="settings-card">
                <h6 class="mb-3"><i class="bi bi-info-circle me-2"></i>Instructions</h6>
                <ul class="list-unstyled small">
                  <li class="mb-2">
                    <i class="bi bi-check text-success me-2"></i>
                    Changes will be applied immediately after saving
                  </li>
                  <li class="mb-2">
                    <i class="bi bi-check text-success me-2"></i>
                    Email must be a valid email address format
                  </li>
                  <li class="mb-2">
                    <i class="bi bi-check text-success me-2"></i>
                    Phone number can include formatting characters
                  </li>
                  <li class="mb-2">
                    <i class="bi bi-check text-success me-2"></i>
                    Colors must be valid hex codes (e.g., #2e7d32)
                  </li>
                  <li class="mb-0">
                    <i class="bi bi-check text-success me-2"></i>
                    Use the preview above to see how changes will look
                  </li>
                </ul>
                
                <div class="mt-4">
                  <h6 class="mb-3"><i class="bi bi-palette me-2"></i>Color Guide</h6>
                  <ul class="list-unstyled small">
                    <li class="mb-2">
                      <strong>Background Color:</strong> Main topbar background
                    </li>
                    <li class="mb-2">
                      <strong>Gradient Color:</strong> Creates depth with background
                    </li>
                    <li class="mb-2">
                      <strong>Text Color:</strong> Main text and icon color
                    </li>
                    <li class="mb-0">
                      <strong>Link Color:</strong> Email and other link colors
                    </li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </form>
        
      </div>
    </section>
  </div>
  
  
  <!-- Use unified Bootstrap version (5.3.0) to match admin_head include -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/topbar-settings.js"></script>
</body>
</html>