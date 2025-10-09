<?php
session_start();
include_once __DIR__ . '/../../config/database.php';
include_once __DIR__ . '/../../includes/permissions.php';
include_once __DIR__ . '/../../includes/CSRFProtection.php';
include_once __DIR__ . '/../../controllers/SidebarSettingsController.php';
include_once __DIR__ . '/../../services/SidebarThemeService.php';

// Check if user is logged in and is super admin
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../login.php");
    exit;
}

$admin_role = getCurrentAdminRole($connection);
if ($admin_role !== 'super_admin') {
    header("Location: homepage.php?error=access_denied");
    exit;
}

// Initialize services
$sidebarThemeService = new SidebarThemeService($connection);
$currentSettings = $sidebarThemeService->getCurrentSettings();

// Handle form submission
$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sidebar_theme'])) {
    if (CSRFProtection::validateToken('sidebar_settings', $_POST['csrf_token'] ?? '')) {
        $controller = new SidebarSettingsController($connection);
        $result = $controller->handleSubmission($_POST);
        
        if ($result['success']) {
            $message = $result['message'];
            $messageType = 'success';
            // Refresh settings
            $currentSettings = $sidebarThemeService->getCurrentSettings();
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    } else {
        $message = 'Invalid security token. Please try again.';
        $messageType = 'error';
    }
}
?>

<?php $page_title='Sidebar Theme Settings'; $extra_css=[]; include '../../includes/admin/admin_head.php'; ?>
<style>
  body.sidebar-settings-page .preview-sidebar {
    background: linear-gradient(180deg, <?= htmlspecialchars($currentSettings['sidebar_bg_start']) ?> 0%, <?= htmlspecialchars($currentSettings['sidebar_bg_end']) ?> 100%);
    border: 1px solid <?= htmlspecialchars($currentSettings['sidebar_border_color']) ?>;
    border-radius: 8px;
    padding: 1rem;
    min-height: 400px;
    position: sticky;
    top: 20px;
    font-family: 'Poppins', var(--bs-font-sans-serif, Arial, sans-serif);
  }
  body.sidebar-settings-page .preview-profile {display:flex;align-items:center;gap:0.75rem;padding-bottom:1rem;margin-bottom:1rem;border-bottom:1px solid <?= htmlspecialchars($currentSettings['profile_border_color']) ?>;}
  body.sidebar-settings-page .preview-avatar {width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg, <?= htmlspecialchars($currentSettings['profile_avatar_bg_start']) ?>, <?= htmlspecialchars($currentSettings['profile_avatar_bg_end']) ?>);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;}
  body.sidebar-settings-page .preview-nav-item {padding:0.5rem 0.75rem;margin:0.25rem 0;border-radius:6px;color:<?= htmlspecialchars($currentSettings['nav_text_color']) ?>;cursor:pointer;display:flex;align-items:center;gap:0.5rem;}
  body.sidebar-settings-page .preview-nav-item:hover {background:<?= htmlspecialchars($currentSettings['nav_hover_bg']) ?>;color:<?= htmlspecialchars($currentSettings['nav_hover_text']) ?>;}
  body.sidebar-settings-page .preview-nav-item.active {background:<?= htmlspecialchars($currentSettings['nav_active_bg']) ?>;color:<?= htmlspecialchars($currentSettings['nav_active_text']) ?>;}
  body.sidebar-settings-page .preview-nav-item i {color:<?= htmlspecialchars($currentSettings['nav_icon_color']) ?>;}
  body.sidebar-settings-page .preview-submenu {background:<?= htmlspecialchars($currentSettings['submenu_bg']) ?>;margin:0.25rem 0;border-radius:4px;padding:0.25rem;}
  body.sidebar-settings-page .preview-submenu-item {padding:0.375rem 0.5rem 0.375rem 1.5rem;margin:0.125rem 0;border-radius:4px;color:<?= htmlspecialchars($currentSettings['submenu_text_color']) ?>;font-size:0.85rem;cursor:pointer;}
  body.sidebar-settings-page .preview-submenu-item:hover {background:<?= htmlspecialchars($currentSettings['submenu_hover_bg']) ?>;}
  body.sidebar-settings-page .preview-submenu-item.active {background:<?= htmlspecialchars($currentSettings['submenu_active_bg']) ?>;color:<?= htmlspecialchars($currentSettings['submenu_active_text']) ?>;}
  body.sidebar-settings-page .color-input-group {display:flex;align-items:center;gap:0.5rem;}
  body.sidebar-settings-page .color-input-group input[type=color]{width:40px;height:40px;border:none;border-radius:6px;cursor:pointer;}
  body.sidebar-settings-page .color-input-group input[type=text]{width:80px;font-family:monospace;text-transform:uppercase;}
</style>
<body class="sidebar-settings-page">
    <?php include '../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include '../../includes/admin/admin_sidebar.php'; ?>
        <?php include '../../includes/admin/admin_header.php'; ?>

        <section class="home-section" id="mainContent">
            <div class="container-fluid py-4 px-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-1">Sidebar Theme Settings</h2>
                        <p class="text-muted mb-0">Customize the colors and gradients used in the admin sidebar</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="homepage.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i><?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="bi bi-palette me-2"></i>Theme Configuration</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="sidebarSettingsForm">
                                    <input type="hidden" name="csrf_token" value="<?= CSRFProtection::generateToken('sidebar_settings') ?>">
                                    <input type="hidden" name="update_sidebar_theme" value="1">

                                    <!-- Sidebar Background -->
                                    <div class="row mb-4">
                                        <div class="col-12">
                                            <h6 class="border-bottom pb-2">Sidebar Background</h6>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Background Start Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="sidebar_bg_start" name="sidebar_bg_start" value="<?= htmlspecialchars($currentSettings['sidebar_bg_start']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['sidebar_bg_start']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Background End Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="sidebar_bg_end" name="sidebar_bg_end" value="<?= htmlspecialchars($currentSettings['sidebar_bg_end']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['sidebar_bg_end']) ?>" readonly>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Navigation Colors -->
                                    <div class="row mb-4">
                                        <div class="col-12">
                                            <h6 class="border-bottom pb-2">Navigation</h6>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Text Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="nav_text_color" name="nav_text_color" value="<?= htmlspecialchars($currentSettings['nav_text_color']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_text_color']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Icon Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="nav_icon_color" name="nav_icon_color" value="<?= htmlspecialchars($currentSettings['nav_icon_color']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_icon_color']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Hover Background</label>
                                            <div class="color-input-group">
                                                <input type="color" id="nav_hover_bg" name="nav_hover_bg" value="<?= htmlspecialchars($currentSettings['nav_hover_bg']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_hover_bg']) ?>" readonly>
                                            </div>
                                        </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Hover Text Color</label>
                                                                <div class="color-input-group">
                                                                    <input type="color" id="nav_hover_text" name="nav_hover_text" value="<?= htmlspecialchars($currentSettings['nav_hover_text'] ?? '#212529') ?>">
                                                                    <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_hover_text'] ?? '#212529') ?>" readonly>
                                                                </div>
                                                            </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Active Background</label>
                                            <div class="color-input-group">
                                                <input type="color" id="nav_active_bg" name="nav_active_bg" value="<?= htmlspecialchars($currentSettings['nav_active_bg']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_active_bg']) ?>" readonly>
                                            </div>
                                        </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Active Text Color</label>
                                                                <div class="color-input-group">
                                                                    <input type="color" id="nav_active_text" name="nav_active_text" value="<?= htmlspecialchars($currentSettings['nav_active_text'] ?? '#ffffff') ?>">
                                                                    <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['nav_active_text'] ?? '#ffffff') ?>" readonly>
                                                                </div>
                                                            </div>
                                    </div>

                                    <!-- Profile Section -->
                                    <div class="row mb-4">
                                        <div class="col-12">
                                            <h6 class="border-bottom pb-2">Profile Section</h6>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Avatar Start Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="profile_avatar_bg_start" name="profile_avatar_bg_start" value="<?= htmlspecialchars($currentSettings['profile_avatar_bg_start']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['profile_avatar_bg_start']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Avatar End Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="profile_avatar_bg_end" name="profile_avatar_bg_end" value="<?= htmlspecialchars($currentSettings['profile_avatar_bg_end']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['profile_avatar_bg_end']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Name Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="profile_name_color" name="profile_name_color" value="<?= htmlspecialchars($currentSettings['profile_name_color']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['profile_name_color']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Role Color</label>
                                            <div class="color-input-group">
                                                <input type="color" id="profile_role_color" name="profile_role_color" value="<?= htmlspecialchars($currentSettings['profile_role_color']) ?>">
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['profile_role_color']) ?>" readonly>
                                            </div>
                                        </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Profile Border Color</label>
                                                                <div class="color-input-group">
                                                                    <input type="color" id="profile_border_color" name="profile_border_color" value="<?= htmlspecialchars($currentSettings['profile_border_color'] ?? '#dee2e6') ?>">
                                                                    <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['profile_border_color'] ?? '#dee2e6') ?>" readonly>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Sidebar Border Color</label>
                                                                <div class="color-input-group">
                                                                    <input type="color" id="sidebar_border_color" name="sidebar_border_color" value="<?= htmlspecialchars($currentSettings['sidebar_border_color'] ?? '#dee2e6') ?>">
                                                                    <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['sidebar_border_color'] ?? '#dee2e6') ?>" readonly>
                                                                </div>
                                                            </div>
                                    </div>

                                                        <!-- Submenu Section -->
                                                        <div class="row mb-4">
                                                            <div class="col-12">
                                                                <h6 class="border-bottom pb-2">Submenu</h6>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Submenu Background</label>
                                                                <div class="color-input-group">
                                                                    <input type="color" id="submenu_bg" name="submenu_bg" value="<?= htmlspecialchars($currentSettings['submenu_bg'] ?? '#f8f9fa') ?>">
                                                                    <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['submenu_bg'] ?? '#f8f9fa') ?>" readonly>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Submenu Text Color</label>
                                                                <div class="color-input-group">
                                                                    <input type="color" id="submenu_text_color" name="submenu_text_color" value="<?= htmlspecialchars($currentSettings['submenu_text_color'] ?? '#495057') ?>">
                                                                    <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['submenu_text_color'] ?? '#495057') ?>" readonly>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Submenu Hover Background</label>
                                                                <div class="color-input-group">
                                                                    <input type="color" id="submenu_hover_bg" name="submenu_hover_bg" value="<?= htmlspecialchars($currentSettings['submenu_hover_bg'] ?? '#e9ecef') ?>">
                                                                    <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['submenu_hover_bg'] ?? '#e9ecef') ?>" readonly>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Submenu Active Background</label>
                                                                <div class="color-input-group">
                                                                    <input type="color" id="submenu_active_bg" name="submenu_active_bg" value="<?= htmlspecialchars($currentSettings['submenu_active_bg'] ?? '#e7f3ff') ?>">
                                                                    <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['submenu_active_bg'] ?? '#e7f3ff') ?>" readonly>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Submenu Active Text</label>
                                                                <div class="color-input-group">
                                                                    <input type="color" id="submenu_active_text" name="submenu_active_text" value="<?= htmlspecialchars($currentSettings['submenu_active_text'] ?? '#0d6efd') ?>">
                                                                    <input type="text" class="form-control" value="<?= htmlspecialchars($currentSettings['submenu_active_text'] ?? '#0d6efd') ?>" readonly>
                                                                </div>
                                                            </div>
                                                        </div>

                                    <div class="d-flex justify-content-between">
                                        <button type="button" class="btn btn-outline-secondary" id="resetDefaults">
                                            <i class="bi bi-arrow-clockwise me-1"></i> Reset to Defaults
                                        </button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-lg me-1"></i> Save Settings
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="card-title mb-0">Live Preview</h6>
                            </div>
                            <div class="card-body">
                                <div class="preview-sidebar" id="previewSidebar">
                                    <div class="preview-profile">
                                        <div class="preview-avatar" id="previewAvatar">A</div>
                                        <div>
                                            <div style="color: <?= htmlspecialchars($currentSettings['profile_name_color']) ?>; font-weight: 600; font-size: 0.9rem;" id="previewName">Admin User</div>
                                            <div style="color: <?= htmlspecialchars($currentSettings['profile_role_color']) ?>; font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.75px;" id="previewRole">Super Admin</div>
                                        </div>
                                    </div>
                                    <div class="preview-nav-item active">
                                        <i class="bi bi-house-door"></i>
                                        <span>Dashboard</span>
                                    </div>
                                    <div class="preview-nav-item">
                                        <i class="bi bi-people"></i>
                                        <span>Manage Users</span>
                                    </div>
                                    <div class="preview-nav-item">
                                        <i class="bi bi-gear"></i>
                                        <span>System Controls</span>
                                    </div>
                                    <div class="preview-submenu">
                                        <div class="preview-submenu-item active">Settings</div>
                                        <div class="preview-submenu-item">Users</div>
                                        <div class="preview-submenu-item">Reports</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin/sidebar.js"></script>
    <script src="../../assets/js/admin/sidebar-theme-settings.js"></script>
</body>
</html>