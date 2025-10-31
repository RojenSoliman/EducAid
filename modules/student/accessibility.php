<?php
/** @phpstan-ignore-file */
include '../../config/database.php';
session_start();
if (!isset($_SESSION['student_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
$student_id = $_SESSION['student_id'];

// Track session activity
include __DIR__ . '/../../includes/student_session_tracker.php';

// Get student info for header dropdown
$student_info_query = "SELECT first_name, last_name FROM students WHERE student_id = $1";
$student_info_result = pg_query_params($connection, $student_info_query, [$student_id]);
$student_info = pg_fetch_assoc($student_info_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Accessibility - EducAid</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" />
  <link href="../../assets/css/student/sidebar.css" rel="stylesheet" />
  <style>
    body { background: #f7fafc; }
    
    /* Main Content Area Layout */
    .home-section {
      margin-left: 250px;
      width: calc(100% - 250px);
      min-height: calc(100vh - var(--topbar-h, 60px));
      background: #f7fafc;
      padding-top: 56px; /* Account for fixed header height */
      position: relative;
      z-index: 1;
      box-sizing: border-box;
    }

    .sidebar.close ~ .home-section {
      margin-left: 70px;
      width: calc(100% - 70px);
    }

    @media (max-width: 768px) {
      .home-section {
        margin-left: 0 !important;
        width: 100% !important;
      }
    }

    /* Settings Header */
    .settings-header {
      background: transparent;
      border-bottom: none;
      padding: 0;
      margin-bottom: 2rem;
    }
    
    .settings-header h1 {
      color: #1a202c;
      font-weight: 600;
      font-size: 2rem;
      margin: 0;
    }

    /* YouTube-Style Settings Navigation */
    .settings-nav {
      background: #f7fafc;
      border-radius: 12px;
      padding: 0.5rem;
      border: 1px solid #e2e8f0;
    }

    .settings-nav-item {
      display: flex;
      align-items: center;
      padding: 0.75rem 1rem;
      color: #4a5568;
      text-decoration: none;
      border-radius: 8px;
      font-weight: 500;
      font-size: 0.95rem;
      transition: all 0.2s ease;
      margin-bottom: 0.25rem;
    }

    .settings-nav-item:last-child {
      margin-bottom: 0;
    }

    .settings-nav-item:hover {
      background: #edf2f7;
      color: #2d3748;
      text-decoration: none;
    }

    .settings-nav-item.active {
      background: #4299e1;
      color: white;
    }

    .settings-nav-item.active:hover {
      background: #3182ce;
    }

    /* Settings Content Sections */
    .settings-content-section {
      margin-bottom: 3rem;
    }

    .section-title {
      color: #1a202c;
      font-weight: 600;
      font-size: 1.5rem;
      margin: 0 0 0.5rem 0;
    }

    .section-description {
      color: #718096;
      font-size: 0.95rem;
      margin: 0 0 1.5rem 0;
    }

    /* Settings Section Cards */
    .settings-section {
      background: white;
      border-radius: 12px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      border: 1px solid #e2e8f0;
      margin-bottom: 2rem;
      overflow: hidden;
    }

    .settings-section-body {
      padding: 2rem;
    }

    .setting-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1.5rem 0;
      border-bottom: 1px solid #f1f5f9;
    }

    .setting-item:last-child {
      border-bottom: none;
      padding-bottom: 0;
    }

    .setting-info {
      flex: 1;
    }

    .setting-label {
      font-weight: 600;
      color: #2d3748;
      font-size: 1rem;
      margin-bottom: 0.25rem;
    }

    .setting-value {
      color: #718096;
      font-size: 0.95rem;
      margin-bottom: 0.25rem;
    }

    .setting-description {
      color: #a0aec0;
      font-size: 0.875rem;
    }

    .setting-actions {
      display: flex;
      gap: 0.75rem;
    }

    .btn-setting {
      padding: 0.5rem 1.25rem;
      border-radius: 8px;
      font-weight: 500;
      font-size: 0.9rem;
      border: 1px solid transparent;
      transition: all 0.2s ease;
    }

    .btn-setting-primary {
      background: #4299e1;
      color: white;
      border-color: #4299e1;
    }

    .btn-setting-primary:hover {
      background: #3182ce;
      border-color: #3182ce;
      color: white;
    }

    .btn-setting-outline {
      background: transparent;
      color: #4a5568;
      border-color: #e2e8f0;
    }

    .btn-setting-outline:hover {
      background: #f7fafc;
      color: #2d3748;
    }

    /* Toggle Switch Styling */
    .form-check-input:checked {
      background-color: #4299e1;
      border-color: #4299e1;
    }

    /* Accessibility Features CSS */
    /* Text Size Options */
    html.text-small {
      font-size: 14px;
    }

    html.text-normal {
      font-size: 16px;
    }

    html.text-large {
      font-size: 18px;
    }

    /* High Contrast Mode */
    html.high-contrast {
      filter: contrast(1.5);
    }

    html.high-contrast body {
      background: #000 !important;
      color: #fff !important;
    }

    html.high-contrast .settings-section,
    html.high-contrast .content-card,
    html.high-contrast .settings-nav {
      background: #1a1a1a !important;
      border-color: #444 !important;
      color: #fff !important;
    }

    html.high-contrast .btn {
      border: 2px solid #fff !important;
      font-weight: 600 !important;
    }

    /* Reduce Animations */
    html.reduce-animations *,
    html.reduce-animations *::before,
    html.reduce-animations *::after {
      animation-duration: 0.01ms !important;
      animation-iteration-count: 1 !important;
      transition-duration: 0.01ms !important;
      scroll-behavior: auto !important;
    }

    @media (max-width: 768px) {
      .setting-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }

      .setting-actions {
        width: 100%;
        justify-content: flex-end;
      }

      .settings-section-body {
        padding: 1.5rem;
      }
    }
  </style>
</head>
<body>
  <!-- Student Topbar -->
  <?php include __DIR__ . '/../../includes/student/student_topbar.php'; ?>

  <div id="wrapper" style="padding-top: var(--topbar-h);">
    <!-- Include Sidebar -->
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    
    <!-- Student Header -->
    <?php include __DIR__ . '/../../includes/student/student_header.php'; ?>
    
    <!-- Main Content Area -->
    <section class="home-section" id="page-content-wrapper">
      <div class="container-fluid py-4 px-4">
        <!-- Settings Header -->
        <div class="settings-header mb-4">
          <h1 class="mb-1">Settings</h1>
        </div>

        <!-- YouTube-style Layout: Sidebar + Content -->
        <div class="row g-4">
          <!-- Settings Navigation Sidebar -->
          <div class="col-12 col-lg-3">
            <div class="settings-nav sticky-top" style="top: 100px;">
              <a href="student_settings.php#account" class="settings-nav-item">
                <i class="bi bi-person-circle me-2"></i>
                Account
              </a>
              <a href="student_settings.php#security" class="settings-nav-item">
                <i class="bi bi-shield-lock me-2"></i>
                Security & Privacy
              </a>
              <a href="accessibility.php" class="settings-nav-item active">
                <i class="bi bi-universal-access me-2"></i>
                Accessibility
              </a>
              <a href="active_sessions.php" class="settings-nav-item">
                <i class="bi bi-laptop me-2"></i>
                Active Sessions
              </a>
              <a href="security_activity.php" class="settings-nav-item">
                <i class="bi bi-clock-history me-2"></i>
                Security Activity
              </a>
            </div>
          </div>

          <!-- Main Content -->
          <div class="col-12 col-lg-9">
            <!-- Accessibility Section -->
            <div class="settings-content-section">
              <h2 class="section-title">Accessibility</h2>
              <p class="section-description">Customize your experience for better accessibility</p>
              
              <div class="settings-section">
                <div class="settings-section-body">
                  <!-- Text Size -->
                  <div class="setting-item">
                    <div class="setting-info">
                      <div class="setting-label">Text Size</div>
                      <div class="setting-value">Normal</div>
                      <div class="setting-description">Adjust the size of text throughout the application</div>
                    </div>
                    <div class="setting-actions">
                      <div class="btn-group" role="group">
                        <button type="button" class="btn btn-setting btn-setting-outline" id="textSizeSmall">
                          <i class="bi bi-fonts me-1"></i>Small
                        </button>
                        <button type="button" class="btn btn-setting btn-setting-primary active" id="textSizeNormal">
                          <i class="bi bi-fonts me-1"></i>Normal
                        </button>
                        <button type="button" class="btn btn-setting btn-setting-outline" id="textSizeLarge">
                          <i class="bi bi-fonts me-1"></i>Large
                        </button>
                      </div>
                    </div>
                  </div>

                  <!-- High Contrast Mode -->
                  <div class="setting-item">
                    <div class="setting-info">
                      <div class="setting-label">High Contrast Mode</div>
                      <div class="setting-value">
                        <span class="badge bg-secondary">Disabled</span>
                      </div>
                      <div class="setting-description">Enhance visibility for visually impaired students</div>
                    </div>
                    <div class="setting-actions">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="highContrastToggle" style="width: 3rem; height: 1.5rem; cursor: pointer;">
                        <label class="form-check-label ms-2" for="highContrastToggle"></label>
                      </div>
                    </div>
                  </div>

                  <!-- Reduce Animations -->
                  <div class="setting-item">
                    <div class="setting-info">
                      <div class="setting-label">Reduce Animations</div>
                      <div class="setting-value">
                        <span class="badge bg-secondary">Disabled</span>
                      </div>
                      <div class="setting-description">Minimize motion effects for students with motion sensitivity</div>
                    </div>
                    <div class="setting-actions">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="reduceAnimationsToggle" style="width: 3rem; height: 1.5rem; cursor: pointer;">
                        <label class="form-check-label ms-2" for="reduceAnimationsToggle"></label>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/student/sidebar.js"></script>
  
  <script>
    // Accessibility Features
    document.addEventListener('DOMContentLoaded', function() {
      // Load saved preferences
      const savedTextSize = localStorage.getItem('textSize') || 'normal';
      const savedHighContrast = localStorage.getItem('highContrast') === 'true';
      const savedReduceAnimations = localStorage.getItem('reduceAnimations') === 'true';

      // Apply saved preferences
      applyTextSize(savedTextSize);
      applyHighContrast(savedHighContrast);
      applyReduceAnimations(savedReduceAnimations);

      // Text Size Buttons
      const textSizeButtons = {
        small: document.getElementById('textSizeSmall'),
        normal: document.getElementById('textSizeNormal'),
        large: document.getElementById('textSizeLarge')
      };

      Object.entries(textSizeButtons).forEach(([size, button]) => {
        if (button) {
          button.addEventListener('click', function() {
            // Remove active from all buttons
            Object.values(textSizeButtons).forEach(btn => {
              btn.classList.remove('active', 'btn-setting-primary');
              btn.classList.add('btn-setting-outline');
            });
            // Add active to clicked button
            this.classList.add('active', 'btn-setting-primary');
            this.classList.remove('btn-setting-outline');
            
            // Apply and save
            applyTextSize(size);
            localStorage.setItem('textSize', size);
            
            // Update display value
            const settingValue = this.closest('.setting-item').querySelector('.setting-value');
            settingValue.textContent = size.charAt(0).toUpperCase() + size.slice(1);
          });

          // Set initial active state
          if (size === savedTextSize) {
            button.classList.add('active', 'btn-setting-primary');
            button.classList.remove('btn-setting-outline');
            // Update display value
            const settingValue = button.closest('.setting-item').querySelector('.setting-value');
            settingValue.textContent = size.charAt(0).toUpperCase() + size.slice(1);
          }
        }
      });

      // High Contrast Toggle
      const highContrastToggle = document.getElementById('highContrastToggle');
      if (highContrastToggle) {
        highContrastToggle.checked = savedHighContrast;
        // Update initial badge
        const badge = highContrastToggle.closest('.setting-item').querySelector('.badge');
        badge.textContent = savedHighContrast ? 'Enabled' : 'Disabled';
        badge.className = savedHighContrast ? 'badge bg-success' : 'badge bg-secondary';
        
        highContrastToggle.addEventListener('change', function() {
          applyHighContrast(this.checked);
          localStorage.setItem('highContrast', this.checked);
          
          // Update badge
          const badge = this.closest('.setting-item').querySelector('.badge');
          badge.textContent = this.checked ? 'Enabled' : 'Disabled';
          badge.className = this.checked ? 'badge bg-success' : 'badge bg-secondary';
        });
      }

      // Reduce Animations Toggle
      const reduceAnimationsToggle = document.getElementById('reduceAnimationsToggle');
      if (reduceAnimationsToggle) {
        reduceAnimationsToggle.checked = savedReduceAnimations;
        // Update initial badge
        const badge = reduceAnimationsToggle.closest('.setting-item').querySelector('.badge');
        badge.textContent = savedReduceAnimations ? 'Enabled' : 'Disabled';
        badge.className = savedReduceAnimations ? 'badge bg-success' : 'badge bg-secondary';
        
        reduceAnimationsToggle.addEventListener('change', function() {
          applyReduceAnimations(this.checked);
          localStorage.setItem('reduceAnimations', this.checked);
          
          // Update badge
          const badge = this.closest('.setting-item').querySelector('.badge');
          badge.textContent = this.checked ? 'Enabled' : 'Disabled';
          badge.className = this.checked ? 'badge bg-success' : 'badge bg-secondary';
        });
      }

      function applyTextSize(size) {
        document.documentElement.classList.remove('text-small', 'text-normal', 'text-large');
        document.documentElement.classList.add('text-' + size);
      }

      function applyHighContrast(enabled) {
        if (enabled) {
          document.documentElement.classList.add('high-contrast');
        } else {
          document.documentElement.classList.remove('high-contrast');
        }
      }

      function applyReduceAnimations(enabled) {
        if (enabled) {
          document.documentElement.classList.add('reduce-animations');
        } else {
          document.documentElement.classList.remove('reduce-animations');
        }
      }
    });
  </script>
</body>
</html>
