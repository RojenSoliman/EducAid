<?php
// Start output buffering to prevent any accidental output before JSON response
ob_start();

session_start();

// Security checks for regular page load
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Include dependencies
include_once __DIR__ . '/../../includes/permissions.php';
include_once __DIR__ . '/../../config/database.php';
include_once __DIR__ . '/../../services/FooterThemeService.php';
include_once __DIR__ . '/../../includes/CSRFProtection.php';

// Check if super admin
$admin_role = getCurrentAdminRole($connection);
if ($admin_role !== 'super_admin') {
    header("Location: homepage.php");
    exit;
}

// Initialize service
$footerService = new FooterThemeService($connection);

// Handle form submission
$successMessage = '';
$errorMessage = '';
$isPostRequest = ($_SERVER['REQUEST_METHOD'] === 'POST');
$isAjaxRequest = $isPostRequest && (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_POST['ajax']) && $_POST['ajax'] === '1')
);

if ($isPostRequest) {
    // Validate CSRF token
    $csrfValid = CSRFProtection::validateToken('footer_settings', $_POST['csrf_token'] ?? '', false);
    
    if ($csrfValid) {
        $result = $footerService->save($_POST, $_SESSION['admin_id'] ?? 0);
        
        if ($isAjaxRequest) {
            // Clear output buffer before sending JSON
            ob_end_clean();
            
            // Generate new CSRF token
            $newCsrfToken = CSRFProtection::generateToken('footer_settings');
            $latestSettings = $footerService->getCurrentSettings();
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? ($result['message'] ?: 'Footer settings updated successfully.') : '',
                'error' => $result['success'] ? '' : ($result['message'] ?: 'Unable to save footer settings.'),
                'footer_settings' => $latestSettings,
                'csrf_token' => $newCsrfToken
            ]);
            exit;
        }
        
        if ($result['success']) {
            $successMessage = $result['message'];
        } else {
            $errorMessage = $result['message'];
        }
    } else {
        $errorMessage = 'Security token validation failed. Please try again.';
    }
}

// Get current footer settings
$current_settings = $footerService->getCurrentSettings();

// Page title and extra CSS
$page_title = 'Footer Settings';
$extra_css = [];
include '../../includes/admin/admin_head.php';
?>
<body>
  <?php include '../../includes/admin/admin_topbar.php'; ?>
  
  <div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">
      <div class="container-fluid py-4 px-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h2 class="mb-1">Footer Settings</h2>
            <p class="text-muted mb-0">Customize the footer appearance for the landing page</p>
          </div>
          <div class="d-flex align-items-center gap-3">
            <a href="homepage.php" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
            </a>
          </div>
        </div>
        
        <?php if ($successMessage): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <?php if ($errorMessage): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <!-- Footer Preview -->
        <div class="settings-card mb-3">
          <h5 class="mb-3"><i class="bi bi-eye me-2"></i>Footer Preview</h5>
          <div class="preview-footer" id="preview-footer" style="background: <?= htmlspecialchars($current_settings['footer_bg_color']) ?>; color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>; padding: 3rem 2rem; border-radius: 8px;">
            <div class="row g-4 align-items-center">
              <div class="col-lg-6">
                <div class="d-flex align-items-center gap-3">
                  <div id="preview-badge" style="width: 48px; height: 48px; background: <?= htmlspecialchars($current_settings['footer_link_hover_color']) ?>; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; color: <?= htmlspecialchars($current_settings['footer_bg_color']) ?>;">
                    EA
                  </div>
                  <div>
                    <div id="preview-title" style="font-size: 1.2rem; font-weight: 600; color: <?= htmlspecialchars($current_settings['footer_heading_color']) ?>;">
                      <?= htmlspecialchars($current_settings['footer_title']) ?>
                    </div>
                    <small id="preview-description" style="color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>; opacity: 0.9;">
                      <?= htmlspecialchars($current_settings['footer_description']) ?>
                    </small>
                  </div>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="row">
                  <div class="col-6 col-md-4">
                    <h6 style="color: <?= htmlspecialchars($current_settings['footer_heading_color']) ?>; font-weight: 600; font-size: 0.95rem;">Explore</h6>
                    <ul class="list-unstyled small mb-0">
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">Home</a></li>
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">About</a></li>
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">Process</a></li>
                    </ul>
                  </div>
                  <div class="col-6 col-md-4">
                    <h6 style="color: <?= htmlspecialchars($current_settings['footer_heading_color']) ?>; font-weight: 600; font-size: 0.95rem;">Resources</h6>
                    <ul class="list-unstyled small mb-0">
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">Requirements</a></li>
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">FAQs</a></li>
                      <li><a href="#" style="color: <?= htmlspecialchars($current_settings['footer_link_color']) ?>; text-decoration: none;">Contact</a></li>
                    </ul>
                  </div>
                  <div class="col-12 col-md-4 mt-3 mt-md-0">
                    <h6 style="color: <?= htmlspecialchars($current_settings['footer_heading_color']) ?>; font-weight: 600; font-size: 0.95rem;">Contact Info</h6>
                    <ul class="list-unstyled small mb-0">
                      <li class="mb-2" style="color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>;"><i class="bi bi-geo-alt me-2"></i><span id="preview-address"><?= htmlspecialchars($current_settings['contact_address']) ?></span></li>
                      <li class="mb-2" style="color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>;"><i class="bi bi-telephone me-2"></i><span id="preview-phone"><?= htmlspecialchars($current_settings['contact_phone']) ?></span></li>
                      <li class="mb-2" style="color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>;"><i class="bi bi-envelope me-2"></i><span id="preview-email"><?= htmlspecialchars($current_settings['contact_email']) ?></span></li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>
            <hr id="preview-divider" style="border-color: <?= htmlspecialchars($current_settings['footer_divider_color']) ?>; opacity: 0.25; margin: 1.5rem 0;">
            <div class="d-flex justify-content-between flex-wrap gap-2 small" style="color: <?= htmlspecialchars($current_settings['footer_text_color']) ?>;">
              <span>© <span id="year"><?= date('Y') ?></span> City Government of General Trias • EducAid</span>
              <span>Powered by the Office of the Mayor • IT</span>
            </div>
          </div>
        </div>

        <!-- Settings Form -->
        <form method="POST" id="settingsForm" action="">
          <?= CSRFProtection::getTokenField('footer_settings') ?>
          
          <div class="row">
            <div class="col-lg-8">
              
              <!-- Color Settings -->
              <div class="settings-card">
                <h5 class="mb-4"><i class="bi bi-palette me-2"></i>Footer Colors</h5>
                
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label for="footer_bg_color" class="form-label">
                      <i class="bi bi-paint-bucket"></i> Background Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_bg_color" 
                             name="footer_bg_color" 
                             value="<?= htmlspecialchars($current_settings['footer_bg_color']) ?>"
                             title="Choose background color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_bg_color']) ?>"
                             readonly>
                    </div>
                  </div>
                  
                  <div class="col-md-6">
                    <label for="footer_text_color" class="form-label">
                      <i class="bi bi-fonts"></i> Text Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_text_color" 
                             name="footer_text_color" 
                             value="<?= htmlspecialchars($current_settings['footer_text_color']) ?>"
                             title="Choose text color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_text_color']) ?>"
                             readonly>
                    </div>
                  </div>
                </div>
                
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label for="footer_heading_color" class="form-label">
                      <i class="bi bi-type-h1"></i> Heading Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_heading_color" 
                             name="footer_heading_color" 
                             value="<?= htmlspecialchars($current_settings['footer_heading_color']) ?>"
                             title="Choose heading color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_heading_color']) ?>"
                             readonly>
                    </div>
                  </div>
                  
                  <div class="col-md-6">
                    <label for="footer_link_color" class="form-label">
                      <i class="bi bi-link-45deg"></i> Link Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_link_color" 
                             name="footer_link_color" 
                             value="<?= htmlspecialchars($current_settings['footer_link_color']) ?>"
                             title="Choose link color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_link_color']) ?>"
                             readonly>
                    </div>
                  </div>
                </div>
                
                <div class="row mb-0">
                  <div class="col-md-6">
                    <label for="footer_link_hover_color" class="form-label">
                      <i class="bi bi-cursor"></i> Link Hover Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_link_hover_color" 
                             name="footer_link_hover_color" 
                             value="<?= htmlspecialchars($current_settings['footer_link_hover_color']) ?>"
                             title="Choose link hover color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_link_hover_color']) ?>"
                             readonly>
                    </div>
                  </div>
                  
                  <div class="col-md-6">
                    <label for="footer_divider_color" class="form-label">
                      <i class="bi bi-dash-lg"></i> Divider Color
                    </label>
                    <div class="input-group">
                      <input type="color" 
                             class="form-control form-control-color" 
                             id="footer_divider_color" 
                             name="footer_divider_color" 
                             value="<?= htmlspecialchars($current_settings['footer_divider_color']) ?>"
                             title="Choose divider color">
                      <input type="text" 
                             class="form-control" 
                             value="<?= htmlspecialchars($current_settings['footer_divider_color']) ?>"
                             readonly>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Content Settings -->
              <div class="settings-card">
                <h5 class="mb-4"><i class="bi bi-pencil-square me-2"></i>Footer Content</h5>
                
                <div class="mb-3">
                  <label for="footer_title" class="form-label">Footer Title <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-mortarboard"></i></span>
                    <input type="text" 
                           class="form-control" 
                           id="footer_title" 
                           name="footer_title" 
                           value="<?= htmlspecialchars($current_settings['footer_title']) ?>" 
                           placeholder="EducAid" 
                           required>
                  </div>
                  <div class="form-text">The main title displayed in the footer.</div>
                </div>
                
                <div class="mb-0">
                  <label for="footer_description" class="form-label">Footer Description</label>
                  <textarea class="form-control" 
                            id="footer_description" 
                            name="footer_description" 
                            rows="3" 
                            placeholder="Making education accessible..."><?= htmlspecialchars($current_settings['footer_description']) ?></textarea>
                  <div class="form-text">Brief description about the system.</div>
                </div>
              </div>
              
              <!-- Contact Information -->
              <div class="settings-card">
                <h5 class="mb-4"><i class="bi bi-info-circle me-2"></i>Contact Information</h5>
                
                <div class="mb-3">
                  <label for="contact_address" class="form-label">Address</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                    <input type="text" 
                           class="form-control" 
                           id="contact_address" 
                           name="contact_address" 
                           value="<?= htmlspecialchars($current_settings['contact_address']) ?>" 
                           placeholder="City Hall, Address">
                  </div>
                </div>
                
                <div class="mb-3">
                  <label for="contact_phone" class="form-label">Phone Number</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                    <input type="text" 
                           class="form-control" 
                           id="contact_phone" 
                           name="contact_phone" 
                           value="<?= htmlspecialchars($current_settings['contact_phone']) ?>" 
                           placeholder="+63 (046) 123-4567">
                  </div>
                </div>
                
                <div class="mb-0">
                  <label for="contact_email" class="form-label">Email Address</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" 
                           class="form-control" 
                           id="contact_email" 
                           name="contact_email" 
                           value="<?= htmlspecialchars($current_settings['contact_email']) ?>" 
                           placeholder="info@example.gov.ph">
                  </div>
                </div>
              </div>
              
              <div class="d-flex gap-2 mb-4">
                <a href="homepage.php" class="btn btn-secondary">
                  <i class="bi bi-x-lg me-2"></i>Cancel
                </a>
                <button type="submit" class="btn btn-success" id="footerSettingsSubmit">
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
                    Changes apply to the landing page footer
                  </li>
                  <li class="mb-2">
                    <i class="bi bi-check text-success me-2"></i>
                    Use the preview above to see your changes
                  </li>
                  <li class="mb-2">
                    <i class="bi bi-check text-success me-2"></i>
                    Color pickers update the preview in real-time
                  </li>
                  <li class="mb-2">
                    <i class="bi bi-check text-success me-2"></i>
                    Footer title is required
                  </li>
                  <li class="mb-0">
                    <i class="bi bi-check text-success me-2"></i>
                    Email must be a valid format
                  </li>
                </ul>
              </div>
              
              <div class="settings-card">
                <h6 class="mb-3"><i class="bi bi-palette me-2"></i>Color Guide</h6>
                <ul class="list-unstyled small">
                  <li class="mb-2">
                    <strong>Background:</strong> Main footer background color
                  </li>
                  <li class="mb-2">
                    <strong>Text:</strong> Regular text and contact info
                  </li>
                  <li class="mb-2">
                    <strong>Heading:</strong> Footer title and section headings
                  </li>
                  <li class="mb-2">
                    <strong>Link:</strong> Clickable links color
                  </li>
                  <li class="mb-2">
                    <strong>Link Hover:</strong> Color when hovering over links
                  </li>
                  <li class="mb-0">
                    <strong>Divider:</strong> Horizontal line separating content
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </form>
        
      </div>
    </section>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/footer-settings.js"></script>
</body>
</html>

<style>
.settings-card {
  background: #ffffff;
  border-radius: 12px;
  padding: 1.5rem;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  border: 1px solid #e5e7eb;
  margin-bottom: 1.5rem;
}

.settings-card h5 {
  color: #1f2937;
  font-weight: 600;
  font-size: 1.1rem;
}

.settings-card h6 {
  color: #374151;
  font-weight: 600;
}

.form-control-color {
  width: 60px;
  height: 40px;
  border-radius: 8px 0 0 8px;
}

.preview-footer {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.preview-footer h5 {
  font-weight: 600;
}

.preview-footer p {
  margin-bottom: 0.5rem;
  line-height: 1.6;
}
</style>
