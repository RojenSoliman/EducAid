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

// Ensure fresh CSRF token on GET requests (clear old tokens)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Clear any existing tokens for this form and generate a fresh one
    if (isset($_SESSION['csrf_tokens']['topbar_settings'])) {
        unset($_SESSION['csrf_tokens']['topbar_settings']);
    }
    CSRFProtection::generateToken('topbar_settings');
}

// Unified form submission (topbar + header)
$form_result = [
  'success' => false,
  'message' => '',
  'data' => $themeService->getCurrentSettings()
];
$successMessage = '';
$errorMessage = '';
$combinedSuccess = false;
$isPostRequest = ($_SERVER['REQUEST_METHOD'] === 'POST');
$isAjaxRequest = $isPostRequest && (
  (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
  (isset($_POST['ajax']) && $_POST['ajax'] === '1') ||
  (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

if ($isPostRequest) {
  $headerSave = [ 'success' => false, 'message' => '' ];
  // Validate CSRF token with consume = false to allow resubmissions
  $csrfValid = CSRFProtection::validateToken('topbar_settings', $_POST['csrf_token'] ?? '', false);
  
  if ($csrfValid) {
    $form_result = $controller->handleFormSubmission();
    $headerSave = $headerThemeService->save($_POST, (int)($_SESSION['admin_id'] ?? 0));

    $topbarMessage = $form_result['message'] ?? '';
    $headerMessage = $headerSave['message'] ?? '';
    if ($headerSave['success'] && $headerMessage === '') {
      $headerMessage = 'Header theme updated.';
    }
    if (!$headerSave['success'] && $headerMessage === '') {
      $headerMessage = 'Header theme save failed.';
    }

    $combinedSuccess = ($form_result['success'] && $headerSave['success']);

    $successParts = [];
    $errorParts = [];

    if ($form_result['success']) {
      $successParts[] = $topbarMessage !== '' ? $topbarMessage : 'Topbar settings updated.';
    } elseif ($topbarMessage !== '') {
      $errorParts[] = $topbarMessage;
    }

    if ($headerSave['success']) {
      $successParts[] = $headerMessage;
    } else {
      $errorParts[] = $headerMessage;
    }

    $successMessage = trim(implode(' ', array_filter($successParts)));
    $errorMessage = trim(implode(' ', array_filter($errorParts)));

    if ($combinedSuccess && isset($_SESSION['csrf_tokens']['topbar_settings'])) {
      unset($_SESSION['csrf_tokens']['topbar_settings']);
    }
  } else {
    $combinedSuccess = false;
    $form_result['success'] = false;
    $form_result['message'] = '';

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
      $debug_info = ' (No csrf_token in POST data)';
    }
    $successMessage = '';
    $errorMessage = 'Security token validation failed. Please try again.' . $debug_info;
  }

  if ($isAjaxRequest) {
    if (isset($_SESSION['csrf_tokens']['topbar_settings'])) {
      unset($_SESSION['csrf_tokens']['topbar_settings']);
    }
    $newCsrfToken = CSRFProtection::generateToken('topbar_settings');
    $latestTopbarSettings = $themeService->getCurrentSettings();
    $latestHeaderSettings = $headerThemeService->getCurrentSettings();

    header('Content-Type: application/json');
    echo json_encode([
      'success' => $combinedSuccess,
      'message' => $combinedSuccess ? ($successMessage !== '' ? $successMessage : 'Settings updated successfully.') : '',
      'error' => $combinedSuccess ? '' : ($errorMessage !== '' ? $errorMessage : 'Unable to save settings.'),
      'topbar_settings' => $latestTopbarSettings,
      'header_settings' => $latestHeaderSettings,
      'csrf_token' => $newCsrfToken,
    ]);
    exit;
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

$defaults = $themeService->getDefaultSettings();
$topbar_bg_color = $current_settings['topbar_bg_color'] ?? ($defaults['topbar_bg_color'] ?? '#2e7d32');
if (empty($topbar_bg_color)) {
  $topbar_bg_color = $defaults['topbar_bg_color'] ?? '#2e7d32';
}
$topbar_bg_gradient_raw = $current_settings['topbar_bg_gradient'] ?? null;
$gradient_enabled = !empty($topbar_bg_gradient_raw);
$topbar_bg_gradient = $gradient_enabled ? $topbar_bg_gradient_raw : '';
$preview_background = $gradient_enabled
  ? sprintf('linear-gradient(135deg, %s 0%%, %s 100%%)', $topbar_bg_color, $topbar_bg_gradient_raw)
  : $topbar_bg_color;
$gradient_color_input_value = $gradient_enabled
  ? $topbar_bg_gradient_raw
  : ($defaults['topbar_bg_gradient'] ?? '#1b5e20');
$gradient_text_display = $gradient_enabled ? $topbar_bg_gradient_raw : 'Solid color only';
$preview_text_color = $current_settings['topbar_text_color'] ?? ($defaults['topbar_text_color'] ?? '#ffffff');
if (empty($preview_text_color)) {
  $preview_text_color = $defaults['topbar_text_color'] ?? '#ffffff';
}
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
    color: #fff;
    padding: 0.75rem 1rem;
    border-radius: 0.375rem;
    margin-bottom: 1.5rem;
    font-size: 0.875rem;
    font-family: 'Poppins', var(--bs-font-sans-serif, Arial, sans-serif);
  }
  body.topbar-settings-page .form-label { font-weight:600; color:#374151; }
  body.topbar-settings-page .form-control:focus { border-color:#2e7d32; box-shadow:0 0 0 0.2rem rgba(46,125,50,0.25); }
  body.topbar-settings-page .input-group.gradient-disabled {
    opacity: 0.65;
  }
  body.topbar-settings-page .input-group.gradient-disabled input[type="text"] {
    font-style: italic;
    color: #4b5563;
  }
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
          <div class="preview-topbar" id="preview-topbar" style="background: <?= htmlspecialchars($preview_background, ENT_QUOTES) ?>; color: <?= htmlspecialchars($preview_text_color, ENT_QUOTES) ?>;">
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
  <form method="POST" id="settingsForm" action="">
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
                    <div class="d-flex justify-content-between align-items-center">
                      <label for="topbar_bg_gradient" class="form-label mb-0">
                        <i class="bi bi-circle-half"></i> Gradient Color
                      </label>
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="topbar_gradient_enabled" name="topbar_gradient_enabled" value="1" <?= $gradient_enabled ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="topbar_gradient_enabled">Use gradient</label>
                      </div>
                    </div>
                    <div class="input-group <?= $gradient_enabled ? '' : 'gradient-disabled' ?> mt-2" data-gradient-group>
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="topbar_bg_gradient" 
                             name="topbar_bg_gradient" 
                             value="<?= htmlspecialchars($gradient_color_input_value) ?>"
                             data-default="<?= htmlspecialchars($defaults['topbar_bg_gradient'] ?? '#1b5e20') ?>"
                             title="Choose gradient color" <?= $gradient_enabled ? '' : 'disabled' ?>>
                      <input type="text" 
                             class="form-control" 
                             id="topbar_bg_gradient_text"
                             value="<?= htmlspecialchars($gradient_text_display) ?>"
                             readonly>
                    </div>
                    <div class="form-text">Toggle off to use a solid background without a gradient overlay.</div>
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
                <button type="submit" class="btn btn-success" id="topbarSettingsSubmit">
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