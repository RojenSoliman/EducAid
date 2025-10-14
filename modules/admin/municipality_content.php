<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../../unified_login.php');
    exit;
}

$adminId = (int) $_SESSION['admin_id'];
$adminRole = getCurrentAdminRole($connection);
if ($adminRole !== 'super_admin') {
    header('Location: homepage.php?error=access_denied');
    exit;
}

function table_exists($connection, string $tableName): bool {
    $res = pg_query_params(
        $connection,
        "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = $1 LIMIT 1",
        [$tableName]
    );
    $exists = $res && pg_fetch_row($res);
    if ($res) {
        pg_free_result($res);
    }
    return (bool) $exists;
}

function normalize_municipality(array $row): array {
    $row['municipality_id'] = (int) ($row['municipality_id'] ?? 0);
    $row['district_no'] = isset($row['district_no']) ? (int) $row['district_no'] : null;
    $row['primary_color'] = $row['primary_color'] ?: '#2e7d32';
    $row['secondary_color'] = $row['secondary_color'] ?: '#1b5e20';
    $row['preset_logo_image'] = isset($row['preset_logo_image']) ? trim((string) $row['preset_logo_image']) : null;
    $row['custom_logo_image'] = isset($row['custom_logo_image']) ? trim((string) $row['custom_logo_image']) : null;
    $useCustomLogo = in_array(strtolower((string) ($row['use_custom_logo'] ?? '')), ['t', 'true', '1'], true);
    $logo = null;
    if ($useCustomLogo && !empty($row['custom_logo_image'])) {
        $logo = $row['custom_logo_image'];
    } elseif (!empty($row['preset_logo_image'])) {
        $logo = $row['preset_logo_image'];
    }
    $row['active_logo'] = $logo ?: null;
    return $row;
}

function fetch_assigned_municipalities($connection, int $adminId): array {
    $baseSelect = "SELECT m.municipality_id, m.name, m.slug, m.lgu_type, m.district_no, m.preset_logo_image, m.custom_logo_image, m.use_custom_logo, m.primary_color, m.secondary_color FROM municipalities m";

    if (table_exists($connection, 'admin_municipality_access')) {
        $res = pg_query_params(
            $connection,
            $baseSelect . " INNER JOIN admin_municipality_access ama ON ama.municipality_id = m.municipality_id WHERE ama.admin_id = $1 ORDER BY m.name ASC",
            [$adminId]
        );
        $assigned = [];
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $assigned[] = normalize_municipality($row);
            }
            pg_free_result($res);
        }
        if (!empty($assigned)) {
            return $assigned;
        }
    }

    $res = pg_query_params(
        $connection,
        $baseSelect . ' INNER JOIN admins a ON a.municipality_id = m.municipality_id WHERE a.admin_id = $1 LIMIT 1',
        [$adminId]
    );
    $assigned = [];
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $assigned[] = normalize_municipality($row);
        }
        pg_free_result($res);
    }

    return $assigned;
}

function build_logo_src(?string $path): ?string {
    if ($path === null) {
        return null;
    }

    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }

    // Handle base64 data URIs
    if (preg_match('#^data:image/[^;]+;base64,#i', $path)) {
        return $path;
    }

    // Handle external URLs
    if (preg_match('#^(?:https?:)?//#i', $path)) {
        return $path;
    }

    // Normalize path separators and collapse multiple slashes
    $normalizedRaw = str_replace('\\', '/', $path);
    $normalizedRaw = preg_replace('#(?<!:)/{2,}#', '/', $normalizedRaw);

    // URL encode the path while preserving forward slashes
    // This correctly handles spaces and special characters in folder/file names
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $normalizedRaw)));

    // Handle relative paths that are already correct
    if (str_starts_with($normalizedRaw, '../') || str_starts_with($normalizedRaw, './')) {
        return $encodedPath;
    }

    // Handle absolute paths from web root (starts with /)
    if (str_starts_with($normalizedRaw, '/')) {
        // From modules/admin/, need ../../ to reach project root
        return '../..' . $encodedPath;
    }

    // Handle relative paths without leading slash
    $relativeRaw = ltrim($normalizedRaw, '/');
    $relativeEncoded = ltrim($encodedPath, '/');

    // Try to auto-detect if path should be in assets/ directory
    $docRoot = realpath(__DIR__ . '/../../');
    if ($docRoot) {
        $fsRelative = str_replace('/', DIRECTORY_SEPARATOR, $relativeRaw);
        $candidate = $docRoot . DIRECTORY_SEPARATOR . $fsRelative;
        
        // If file doesn't exist at root, check if it's in assets/
        if (!is_file($candidate)) {
            $assetsCandidate = $docRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $fsRelative;
            if (is_file($assetsCandidate) && !str_starts_with($relativeRaw, 'assets/')) {
                // Rebuild the path with assets/ prefix
                $relativeRaw = 'assets/' . $relativeRaw;
                $relativeEncoded = implode('/', array_map('rawurlencode', explode('/', $relativeRaw)));
            }
        }
    }

    if ($relativeEncoded === '') {
        return null;
    }

    return '../../' . $relativeEncoded;
}

$assignedMunicipalities = fetch_assigned_municipalities($connection, $adminId);
$_SESSION['allowed_municipalities'] = array_column($assignedMunicipalities, 'municipality_id');

$csrfToken = CSRFProtection::generateToken('municipality-switch');
$feedback = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_municipality'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('municipality-switch', $token)) {
        $feedback = ['type' => 'danger', 'message' => 'Security token expired. Please try again.'];
    } else {
        $requestedId = (int) ($_POST['municipality_id'] ?? 0);
        if ($requestedId && in_array($requestedId, $_SESSION['allowed_municipalities'], true)) {
            foreach ($assignedMunicipalities as $muni) {
                if ($muni['municipality_id'] === $requestedId) {
                    $_SESSION['active_municipality_id'] = $muni['municipality_id'];
                    $_SESSION['active_municipality_name'] = $muni['name'];
                    $_SESSION['active_municipality_slug'] = $muni['slug'];
                    break;
                }
            }
            header('Location: municipality_content.php?switched=1');
            exit;
        }
        $feedback = ['type' => 'warning', 'message' => 'Selected municipality is not assigned to your account.'];
    }
}

if (isset($_GET['switched'])) {
    $feedback = ['type' => 'success', 'message' => 'Active municipality updated.'];
}

// Handle color update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_colors'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('municipality-colors', $token)) {
        $feedback = ['type' => 'danger', 'message' => 'Security token expired. Please try again.'];
    } else {
        $municipalityId = (int) ($_POST['municipality_id'] ?? 0);
        $primaryColor = trim($_POST['primary_color'] ?? '');
        $secondaryColor = trim($_POST['secondary_color'] ?? '');
        
        // Validate hex color format
        $hexPattern = '/^#[0-9A-Fa-f]{6}$/';
        if (!preg_match($hexPattern, $primaryColor)) {
            $feedback = ['type' => 'danger', 'message' => 'Invalid primary color format. Use hex format like #2e7d32'];
        } elseif (!preg_match($hexPattern, $secondaryColor)) {
            $feedback = ['type' => 'danger', 'message' => 'Invalid secondary color format. Use hex format like #1b5e20'];
        } elseif ($municipalityId && in_array($municipalityId, $_SESSION['allowed_municipalities'], true)) {
            $updateResult = pg_query_params(
                $connection,
                'UPDATE municipalities SET primary_color = $1, secondary_color = $2 WHERE municipality_id = $3',
                [$primaryColor, $secondaryColor, $municipalityId]
            );
            
            if ($updateResult) {
                $feedback = ['type' => 'success', 'message' => 'Colors updated successfully!'];
                // Refresh the page to show new colors
                header('Location: municipality_content.php?colors_updated=1');
                exit;
            } else {
                $feedback = ['type' => 'danger', 'message' => 'Failed to update colors. Please try again.'];
            }
        } else {
            $feedback = ['type' => 'warning', 'message' => 'You do not have permission to update this municipality.'];
        }
    }
}

if (isset($_GET['colors_updated'])) {
    $feedback = ['type' => 'success', 'message' => 'Municipality colors updated successfully!'];
}

$activeMunicipalityId = $_SESSION['active_municipality_id'] ?? null;
if (!$activeMunicipalityId && !empty($assignedMunicipalities)) {
    $activeMunicipalityId = $assignedMunicipalities[0]['municipality_id'];
    $_SESSION['active_municipality_id'] = $activeMunicipalityId;
    $_SESSION['active_municipality_name'] = $assignedMunicipalities[0]['name'];
    $_SESSION['active_municipality_slug'] = $assignedMunicipalities[0]['slug'];
}

$activeMunicipality = null;
foreach ($assignedMunicipalities as $muni) {
    if ($muni['municipality_id'] === (int) $activeMunicipalityId) {
        $activeMunicipality = $muni;
        break;
    }
}

if (!$activeMunicipality && !empty($assignedMunicipalities)) {
    $activeMunicipality = $assignedMunicipalities[0];
}

$otherMunicipalities = array_filter(
    $assignedMunicipalities,
    fn($m) => $activeMunicipality && $m['municipality_id'] !== $activeMunicipality['municipality_id']
);

function content_block_count($connection, string $table, int $municipalityId): ?int {
    if (!table_exists($connection, $table)) {
        return null;
    }
    $identifier = pg_escape_identifier($connection, $table);
    $res = pg_query_params(
        $connection,
        "SELECT COUNT(*) AS total FROM {$identifier} WHERE municipality_id = $1",
        [$municipalityId]
    );
    if (!$res) {
        return null;
    }
    $row = pg_fetch_assoc($res) ?: [];
    pg_free_result($res);
    return isset($row['total']) ? (int) $row['total'] : 0;
}

$quickActions = [];
if ($activeMunicipality) {
    $mid = $activeMunicipality['municipality_id'];
    $quickActions = [
        [
            'label' => 'Landing Page',
            'description' => 'Hero, highlights, testimonials and calls to action.',
            'icon' => 'bi-stars',
            'table' => 'landing_content_blocks',
            'editor_url' => sprintf('../../website/landingpage.php?edit=1&municipality_id=%d', $mid),
            'view_url' => sprintf('../../website/landingpage.php?municipality_id=%d', $mid)
        ],
        [
            'label' => 'Login Page Info',
            'description' => 'Welcome message, features, and trust indicators.',
            'icon' => 'bi-box-arrow-in-right',
            'table' => 'login_content_blocks',
            'editor_url' => '../../unified_login.php?edit=1',
            'view_url' => '../../unified_login.php'
        ],
        [
            'label' => 'How It Works',
            'description' => 'Step-by-step guidance and program workflow.',
            'icon' => 'bi-diagram-3',
            'table' => 'how_it_works_content_blocks',
            'editor_url' => sprintf('../../website/how-it-works.php?edit=1&municipality_id=%d', $mid),
            'view_url' => sprintf('../../website/how-it-works.php?municipality_id=%d', $mid)
        ],
        [
            'label' => 'Requirements Page',
            'description' => 'Eligibility, documentation and checklist copy.',
            'icon' => 'bi-card-checklist',
            'table' => 'requirements_content_blocks',
            'editor_url' => sprintf('../../website/requirements.php?edit=1&municipality_id=%d', $mid),
            'view_url' => sprintf('../../website/requirements.php?municipality_id=%d', $mid)
        ],
        [
            'label' => 'About Page',
            'description' => 'Mission, vision and program overview sections.',
            'icon' => 'bi-building',
            'table' => 'about_content_blocks',
            'editor_url' => sprintf('../../website/about.php?edit=1&municipality_id=%d', $mid),
            'view_url' => sprintf('../../website/about.php?municipality_id=%d', $mid)
        ],
        [
            'label' => 'Contact Page',
            'description' => 'Office directory, hotline and support details.',
            'icon' => 'bi-telephone',
            'table' => 'contact_content_blocks',
            'editor_url' => sprintf('../../website/contact.php?edit=1&municipality_id=%d', $mid),
            'view_url' => sprintf('../../website/contact.php?municipality_id=%d', $mid)
        ],
        [
            'label' => 'Announcements',
            'description' => 'Manage featured updates and news alerts.',
            'icon' => 'bi-megaphone',
            'table' => 'announcements_content_blocks',
            'editor_url' => 'manage_announcements.php',
            'view_url' => sprintf('../../website/announcements.php?municipality_id=%d', $mid)
        ],
    ];

    foreach ($quickActions as &$action) {
        $action['count'] = $action['table'] ? content_block_count($connection, $action['table'], $mid) : null;
    }
    unset($action);
}

$page_title = 'Municipality Content Hub';
$extra_css = ['../../assets/css/admin/municipality_hub.css'];
include '../../includes/admin/admin_head.php';
?>
<body class="municipality-hub-page">
<?php include '../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">
        <div class="container-fluid py-4 px-4">
            <!-- Page Header -->
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-geo-alt-fill" style="font-size: 1.75rem; color: #10b981;"></i>
                        <h2 class="fw-bold mb-0" style="color: #1e293b;">Municipality Content Hub</h2>
                    </div>
                    <p class="text-muted mb-0" style="font-size: 0.95rem;">
                        <i class="bi bi-info-circle me-1"></i>
                        Review assigned local government units and jump directly into their content editors.
                    </p>
                </div>
                <?php if (!empty($assignedMunicipalities)): ?>
                <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                    <input type="hidden" name="select_municipality" value="1">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <label for="municipality_id" class="text-muted small mb-0 fw-semibold">
                        <i class="bi bi-building me-1"></i>Active municipality
                    </label>
                    <select id="municipality_id" name="municipality_id" class="form-select form-select-sm shadow-sm" style="min-width: 240px;">
                        <?php foreach ($assignedMunicipalities as $muni): ?>
                            <option value="<?= $muni['municipality_id'] ?>" <?= ($activeMunicipality && $muni['municipality_id'] === $activeMunicipality['municipality_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($muni['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-success shadow-sm">
                        <i class="bi bi-check-circle me-1"></i>Apply
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <?php if ($feedback): ?>
                <div class="alert alert-<?= htmlspecialchars($feedback['type']) ?> alert-dismissible fade show shadow-sm" role="alert" style="border-radius: 12px;">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?= htmlspecialchars($feedback['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!$activeMunicipality): ?>
                <div class="alert alert-warning">
                    No municipality assignments found for your account yet.
                </div>
            <?php else: ?>

            <div class="card muni-hero-card mb-4">
                <div class="card-body">
                    <div class="row g-4 align-items-center">
                        <div class="col-auto">
                            <div class="muni-logo-wrapper position-relative">
                                <?php $logo = build_logo_src($activeMunicipality['active_logo']); ?>
                                <?php if ($logo): ?>
                                    <img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($activeMunicipality['name']) ?> logo" id="municipalityLogo">
                                <?php else: ?>
                                    <span class="text-muted fw-semibold">No Logo</span>
                                <?php endif; ?>
                                <?php 
                                $hasPreset = !empty($activeMunicipality['preset_logo_image']);
                                $hasCustom = !empty($activeMunicipality['custom_logo_image']);
                                $usingCustom = in_array(strtolower((string) ($activeMunicipality['use_custom_logo'] ?? '')), ['t', 'true', '1'], true);
                                ?>
                                <?php if ($hasCustom): ?>
                                    <span class="position-absolute top-0 end-0 badge bg-primary" style="font-size: 0.7rem;">
                                        <?= $usingCustom ? 'Custom' : 'Preset' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col">
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                <span class="badge bg-success-subtle text-success fw-semibold">
                                    <i class="bi bi-check-circle-fill me-1"></i>Assigned
                                </span>
                                <span class="badge badge-soft">
                                    <i class="bi bi-<?= $activeMunicipality['lgu_type'] === 'city' ? 'buildings' : 'house-door' ?> me-1"></i>
                                    <?= $activeMunicipality['lgu_type'] === 'city' ? 'City' : 'Municipality' ?>
                                    <?php if ($activeMunicipality['district_no']): ?>
                                        · District <?= $activeMunicipality['district_no'] ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <h3 class="fw-bold mb-2 overflow-wrap-anywhere" style="color: #1e293b;">
                                <?= htmlspecialchars($activeMunicipality['name']) ?>
                            </h3>
                            <?php if (!empty($activeMunicipality['slug'])): ?>
                                <div class="text-muted small mb-3" style="font-family: 'Courier New', monospace;">
                                    <i class="bi bi-link-45deg me-1"></i>
                                    <strong>Slug:</strong> <?= htmlspecialchars($activeMunicipality['slug']) ?>
                                </div>
                            <?php endif; ?>
                            <div class="d-flex align-items-center gap-4 flex-wrap">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="color-chip" style="background: <?= htmlspecialchars($activeMunicipality['primary_color']) ?>;"></div>
                                    <div>
                                        <div class="text-uppercase text-muted small" style="font-size: 0.75rem; letter-spacing: 0.5px;">Primary</div>
                                        <div class="fw-bold" style="font-family: 'Courier New', monospace; font-size: 0.9rem;">
                                            <?= htmlspecialchars($activeMunicipality['primary_color']) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="color-chip" style="background: <?= htmlspecialchars($activeMunicipality['secondary_color']) ?>;"></div>
                                    <div>
                                        <div class="text-uppercase text-muted small" style="font-size: 0.75rem; letter-spacing: 0.5px;">Secondary</div>
                                        <div class="fw-bold" style="font-family: 'Courier New', monospace; font-size: 0.9rem;">
                                            <?= htmlspecialchars($activeMunicipality['secondary_color']) ?>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editColorsModal">
                                    <i class="bi bi-palette me-1"></i>Edit Colors
                                </button>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="d-flex flex-column gap-2">
                                <button type="button" class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadLogoModal">
                                    <i class="bi bi-upload me-1"></i>Upload Logo
                                </button>
                                <a href="topbar_settings.php" class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-layout-text-window me-1"></i>Topbar
                                </a>
                                <a href="sidebar_settings.php" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-layout-sidebar me-1"></i>Sidebar
                                </a>
                                <a href="settings.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-sliders me-1"></i>Settings
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="bi bi-brush me-2 text-success"></i>Content Areas
                        <span class="badge bg-light text-dark ms-2" style="font-size: 0.75rem;">
                            <?= count($quickActions) ?> sections
                        </span>
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <?php foreach ($quickActions as $action): ?>
                            <div class="col-xl-4 col-lg-6">
                                <div class="card quick-action-card h-100 shadow-sm">
                                    <div class="card-body d-flex flex-column">
                                        <div class="d-flex align-items-start gap-3 mb-3">
                                            <div class="p-3 rounded-3 bg-success-subtle text-success">
                                                <i class="bi <?= htmlspecialchars($action['icon']) ?>"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1 fw-bold" style="color: #1e293b;">
                                                    <?= htmlspecialchars($action['label']) ?>
                                                </h6>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge" style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); color: #166534; font-size: 0.75rem;">
                                                        <i class="bi bi-database me-1"></i>
                                                        <?= $action['count'] === null ? 'N/A' : $action['count'] ?> blocks
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="text-muted mb-3" style="font-size: 0.9rem; line-height: 1.6;">
                                            <?= htmlspecialchars($action['description']) ?>
                                        </p>
                                        <div class="mt-auto">
                                            <div class="d-flex flex-wrap gap-2">
                                                <a href="#" 
                                                   class="btn btn-success btn-sm flex-grow-1 edit-content-trigger" 
                                                   data-editor-url="<?= htmlspecialchars($action['editor_url']) ?>"
                                                   data-label="<?= htmlspecialchars($action['label']) ?>">
                                                    <i class="bi bi-pencil-square me-1"></i>Edit Content
                                                </a>
                                                <a href="<?= htmlspecialchars($action['view_url']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($quickActions)): ?>
                            <div class="col-12 text-center text-muted py-4">
                                No content areas available for this municipality yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($otherMunicipalities)): ?>
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">
                            <i class="bi bi-geo-alt me-2 text-primary"></i>Other Assigned Municipalities
                            <span class="badge bg-light text-dark ms-2" style="font-size: 0.75rem;">
                                <?= count($otherMunicipalities) ?>
                            </span>
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <?php foreach ($otherMunicipalities as $muni): ?>
                                <div class="col-xl-4 col-lg-6">
                                    <div class="other-muni-card h-100 d-flex flex-column">
                                        <div class="d-flex align-items-center gap-3 mb-3">
                                            <div class="muni-logo-wrapper" style="width:72px;height:72px;">
                                                <?php $logo = build_logo_src($muni['active_logo']); ?>
                                                <?php if ($logo): ?>
                                                    <img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($muni['name']) ?> logo" style="max-width:56px;max-height:56px;">
                                                <?php else: ?>
                                                    <div class="text-muted text-center">
                                                        <i class="bi bi-image" style="font-size: 1.5rem;"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-bold overflow-wrap-anywhere mb-1" style="color: #1e293b;">
                                                    <?= htmlspecialchars($muni['name']) ?>
                                                </div>
                                                <?php if (!empty($muni['slug'])): ?>
                                                    <div class="text-muted small" style="font-family: 'Courier New', monospace; font-size: 0.8rem;">
                                                        <i class="bi bi-link-45deg"></i><?= htmlspecialchars($muni['slug']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="mt-auto pt-3 border-top" style="border-color: rgba(226, 232, 240, 0.6) !important;">
                                            <div class="d-flex gap-2">
                                                <form method="post" class="flex-grow-1">
                                                    <input type="hidden" name="select_municipality" value="1">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="municipality_id" value="<?= $muni['municipality_id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success w-100">
                                                        <i class="bi bi-arrow-repeat me-1"></i>Set Active
                                                    </button>
                                                </form>
                                                <a href="<?= htmlspecialchars(sprintf('../../website/landingpage.php?municipality_id=%d', $muni['municipality_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Preview">
                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Upload Logo Modal -->
<div class="modal fade" id="uploadLogoModal" tabindex="-1" aria-labelledby="uploadLogoModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadLogoModalLabel">
                    <i class="bi bi-upload me-2"></i>Upload Custom Logo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Upload a custom logo for <strong><?= htmlspecialchars($activeMunicipality['name'] ?? 'this municipality') ?></strong>.
                    Recommended: PNG with transparent background, max 5MB.
                </div>
                
                <?php if ($hasPreset && $hasCustom): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    You have both preset and custom logos. Currently using: <strong><?= $usingCustom ? 'Custom' : 'Preset' ?></strong>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="toggleLogoType">
                        <i class="bi bi-arrow-repeat me-1"></i>Switch to <?= $usingCustom ? 'Preset' : 'Custom' ?>
                    </button>
                </div>
                <?php endif; ?>
                
                <div id="uploadFeedback"></div>
                
                <form id="logoUploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRFProtection::generateToken('municipality-logo-upload')) ?>">
                    <input type="hidden" name="municipality_id" value="<?= $activeMunicipality['municipality_id'] ?? '' ?>">
                    
                    <div class="mb-3">
                        <label for="logoFile" class="form-label">Select Image File</label>
                        <input type="file" class="form-control" id="logoFile" name="logo_file" 
                               accept="image/png,image/jpeg,image/jpg,image/gif,image/webp,image/svg+xml" required>
                        <div class="form-text">Allowed: PNG, JPG, GIF, WebP, SVG. Max size: 5MB</div>
                    </div>
                    
                    <div id="previewContainer" class="mb-3" style="display: none;">
                        <label class="form-label">Preview</label>
                        <div class="border rounded p-3 text-center bg-light">
                            <img id="previewImage" src="" alt="Preview" style="max-width: 100%; max-height: 200px;">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="uploadLogoBtn">
                    <i class="bi bi-upload me-1"></i>Upload Logo
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const logoFileInput = document.getElementById('logoFile');
    const previewContainer = document.getElementById('previewContainer');
    const previewImage = document.getElementById('previewImage');
    const uploadLogoBtn = document.getElementById('uploadLogoBtn');
    const uploadFeedback = document.getElementById('uploadFeedback');
    const logoUploadForm = document.getElementById('logoUploadForm');
    const toggleLogoTypeBtn = document.getElementById('toggleLogoType');
    
    // Preview selected image
    logoFileInput?.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            // Validate file size
            if (file.size > 5 * 1024 * 1024) {
                showFeedback('danger', 'File size exceeds 5MB limit');
                logoFileInput.value = '';
                previewContainer.style.display = 'none';
                return;
            }
            
            // Show preview
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImage.src = e.target.result;
                previewContainer.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            previewContainer.style.display = 'none';
        }
    });
    
    // Handle logo type toggle
    toggleLogoTypeBtn?.addEventListener('click', async function() {
        const currentlyUsingCustom = <?= json_encode($usingCustom ?? false) ?>;
        const municipalityId = <?= json_encode($activeMunicipality['municipality_id'] ?? 0) ?>;
        const csrfToken = '<?= htmlspecialchars(CSRFProtection::generateToken('municipality-logo-toggle')) ?>';
        
        toggleLogoTypeBtn.disabled = true;
        const originalHtml = toggleLogoTypeBtn.innerHTML;
        toggleLogoTypeBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        
        try {
            const formData = new FormData();
            formData.append('municipality_id', municipalityId);
            formData.append('use_custom', currentlyUsingCustom ? 'false' : 'true');
            formData.append('csrf_token', csrfToken);
            
            const response = await fetch('toggle_municipality_logo.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                window.location.reload();
            } else {
                alert(result.message || 'Failed to toggle logo type');
                toggleLogoTypeBtn.disabled = false;
                toggleLogoTypeBtn.innerHTML = originalHtml;
            }
        } catch (error) {
            alert('Network error: ' + error.message);
            toggleLogoTypeBtn.disabled = false;
            toggleLogoTypeBtn.innerHTML = originalHtml;
        }
    });
    
    // Handle upload
    uploadLogoBtn?.addEventListener('click', async function() {
        const formData = new FormData(logoUploadForm);
        
        if (!logoFileInput.files[0]) {
            showFeedback('warning', 'Please select a file to upload');
            return;
        }
        
        uploadLogoBtn.disabled = true;
        uploadLogoBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading...';
        uploadFeedback.innerHTML = '';
        
        try {
            const response = await fetch('upload_municipality_logo.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                showFeedback('success', result.message || 'Logo uploaded successfully!');
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showFeedback('danger', result.message || 'Upload failed');
                uploadLogoBtn.disabled = false;
                uploadLogoBtn.innerHTML = '<i class="bi bi-upload me-1"></i>Upload Logo';
            }
        } catch (error) {
            showFeedback('danger', 'Network error: ' + error.message);
            uploadLogoBtn.disabled = false;
            uploadLogoBtn.innerHTML = '<i class="bi bi-upload me-1"></i>Upload Logo';
        }
    });
    
    function showFeedback(type, message) {
        uploadFeedback.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
    }
    
    // Reset form when modal is closed
    document.getElementById('uploadLogoModal')?.addEventListener('hidden.bs.modal', function() {
        logoUploadForm?.reset();
        previewContainer.style.display = 'none';
        uploadFeedback.innerHTML = '';
        uploadLogoBtn.disabled = false;
        uploadLogoBtn.innerHTML = '<i class="bi bi-upload me-1"></i>Upload Logo';
    });

    // Content Editor Warning Modal
    const editContentModal = new bootstrap.Modal(document.getElementById('editContentWarningModal'));
    let currentEditorUrl = '';
    let currentPageLabel = '';

    document.querySelectorAll('.edit-content-trigger').forEach(trigger => {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            currentEditorUrl = this.getAttribute('data-editor-url');
            currentPageLabel = this.getAttribute('data-label');
            
            // Update modal content
            document.getElementById('editContentPageName').textContent = currentPageLabel;
            
            // Show modal
            editContentModal.show();
        });
    });

    // Confirm edit button
    document.getElementById('confirmEditContentBtn').addEventListener('click', function() {
        if (currentEditorUrl) {
            window.location.href = currentEditorUrl;
        }
    });
});
</script>

<!-- Edit Content Warning Modal -->
<div class="modal fade" id="editContentWarningModal" tabindex="-1" aria-labelledby="editContentWarningModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title" id="editContentWarningModalLabel">
          <i class="bi bi-exclamation-triangle text-warning me-2"></i>
          Proceed to <span id="editContentPageName" class="fw-bold">Content</span> Editor?
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-1">
        <p class="mb-2">You are about to enter the <strong>live content editor</strong> for this page.</p>
        <ul class="small ps-3 mb-3">
          <li>Changes you save will <strong>immediately affect</strong> what visitors see.</li>
          <li>Please review text for <strong>accuracy and professionalism</strong>.</li>
          <li>Avoid adding <strong>sensitive or internal-only information</strong>.</li>
          <li>Be mindful of <strong>formatting, grammar, and spelling</strong>.</li>
        </ul>
        <div class="alert alert-info small mb-0 d-flex align-items-start gap-2">
          <i class="bi bi-info-circle flex-shrink-0 mt-1"></i>
          <div>
            <strong>Tip:</strong> Edits are logged per block. You can review change history in the database if needed.
          </div>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i>Cancel
        </button>
        <button type="button" class="btn btn-primary" id="confirmEditContentBtn">
          <i class="bi bi-pencil-square me-1"></i>Continue & Edit
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Edit Colors Modal -->
<div class="modal fade" id="editColorsModal" tabindex="-1" aria-labelledby="editColorsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editColorsModalLabel">
          <i class="bi bi-palette me-2"></i>Edit Municipality Colors
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="colorUpdateFeedback"></div>
        
        <div class="alert alert-info small d-flex align-items-start gap-2 mb-3">
          <i class="bi bi-info-circle flex-shrink-0 mt-1"></i>
          <div>
            Choose your primary and secondary colors. These will be used for the municipality theme throughout the system.
          </div>
        </div>
        
        <input type="hidden" id="colorCsrfToken" value="<?= htmlspecialchars(CSRFProtection::generateToken('municipality-colors')) ?>">
        <input type="hidden" id="colorMunicipalityId" value="<?= $activeMunicipality['municipality_id'] ?? '' ?>">
        
        <div class="mb-4">
          <label for="primaryColorInput" class="form-label fw-bold">
            <i class="bi bi-circle-fill me-1" id="primaryColorIcon" style="color: <?= htmlspecialchars($activeMunicipality['primary_color'] ?? '#2e7d32') ?>;"></i>
            Primary Color
          </label>
          <div class="d-flex gap-3 align-items-center">
            <input 
              type="color" 
              class="form-control form-control-color" 
              id="primaryColorInput" 
              value="<?= htmlspecialchars($activeMunicipality['primary_color'] ?? '#2e7d32') ?>"
              style="width: 80px; height: 50px;"
              title="Click to open color picker">
            <input 
              type="text" 
              class="form-control font-monospace" 
              id="primaryColorText" 
              value="<?= htmlspecialchars($activeMunicipality['primary_color'] ?? '#2e7d32') ?>"
              placeholder="#2e7d32"
              maxlength="7"
              style="max-width: 120px;"
              title="Type or paste hex color (e.g., #4caf50)">
            <div 
              id="primaryColorPreview" 
              class="border rounded" 
              style="width: 50px; height: 50px; background: <?= htmlspecialchars($activeMunicipality['primary_color'] ?? '#2e7d32') ?>;"></div>
          </div>
          <small class="text-muted">Used for main buttons, headers, and primary UI elements</small>
        </div>
        
        <div class="mb-3">
          <label for="secondaryColorInput" class="form-label fw-bold">
            <i class="bi bi-circle-fill me-1" id="secondaryColorIcon" style="color: <?= htmlspecialchars($activeMunicipality['secondary_color'] ?? '#1b5e20') ?>;"></i>
            Secondary Color
          </label>
          <div class="d-flex gap-3 align-items-center">
            <input 
              type="color" 
              class="form-control form-control-color" 
              id="secondaryColorInput" 
              value="<?= htmlspecialchars($activeMunicipality['secondary_color'] ?? '#1b5e20') ?>"
              style="width: 80px; height: 50px;">
            <input 
              type="text" 
              class="form-control font-monospace" 
              id="secondaryColorText" 
              value="<?= htmlspecialchars($activeMunicipality['secondary_color'] ?? '#1b5e20') ?>"
              readonly
              style="max-width: 120px;">
            <div 
              id="secondaryColorPreview" 
              class="border rounded" 
              style="width: 50px; height: 50px; background: <?= htmlspecialchars($activeMunicipality['secondary_color'] ?? '#1b5e20') ?>;"></div>
          </div>
          <small class="text-muted">Used for accents, hover states, and secondary UI elements</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i>Cancel
        </button>
        <button type="button" class="btn btn-primary" id="saveColorsBtn">
          <i class="bi bi-check-circle me-1"></i>Save Colors
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Update color preview and hex text when color picker changes
document.getElementById('primaryColorInput')?.addEventListener('input', function(e) {
    const color = e.target.value;
    document.getElementById('primaryColorText').value = color;
    document.getElementById('primaryColorPreview').style.background = color;
    document.getElementById('primaryColorIcon').style.color = color;
});

document.getElementById('secondaryColorInput')?.addEventListener('input', function(e) {
    const color = e.target.value;
    document.getElementById('secondaryColorText').value = color;
    document.getElementById('secondaryColorPreview').style.background = color;
    document.getElementById('secondaryColorIcon').style.color = color;
});

// AJAX save colors without page refresh
document.getElementById('saveColorsBtn')?.addEventListener('click', async function() {
    const btn = this;
    const originalHTML = btn.innerHTML;
    const feedbackDiv = document.getElementById('colorUpdateFeedback');
    
    // Disable button and show loading
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
    feedbackDiv.innerHTML = '';
    
    const primaryColor = document.getElementById('primaryColorInput').value;
    const secondaryColor = document.getElementById('secondaryColorInput').value;
    const csrfToken = document.getElementById('colorCsrfToken').value;
    const municipalityId = document.getElementById('colorMunicipalityId').value;
    
    try {
        const formData = new FormData();
        formData.append('update_colors', '1');
        formData.append('csrf_token', csrfToken);
        formData.append('municipality_id', municipalityId);
        formData.append('primary_color', primaryColor);
        formData.append('secondary_color', secondaryColor);
        
        const response = await fetch('update_municipality_colors.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show success message
            feedbackDiv.innerHTML = `
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>${result.message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            // Update the color chips on the main page WITHOUT refreshing
            const mainPrimaryChip = document.querySelector('.color-chip');
            const mainSecondaryChip = document.querySelectorAll('.color-chip')[1];
            const mainPrimaryText = document.querySelector('.color-chip + div .fw-bold');
            const mainSecondaryText = document.querySelectorAll('.color-chip + div .fw-bold')[1];
            
            if (mainPrimaryChip) {
                mainPrimaryChip.style.background = primaryColor;
            }
            if (mainSecondaryChip) {
                mainSecondaryChip.style.background = secondaryColor;
            }
            if (mainPrimaryText) {
                mainPrimaryText.textContent = primaryColor;
            }
            if (mainSecondaryText) {
                mainSecondaryText.textContent = secondaryColor;
            }
            
            // Re-enable button
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            
            // Auto-close modal after 1.5 seconds
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('editColorsModal'));
                if (modal) {
                    modal.hide();
                }
            }, 1500);
            
        } else {
            throw new Error(result.message || 'Unknown error');
        }
        
    } catch (error) {
        console.error('Error saving colors:', error);
        feedbackDiv.innerHTML = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>${error.message || 'Failed to save colors. Please try again.'}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
});
</script>

</body>
</html>
