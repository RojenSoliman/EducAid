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
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
                <div>
                    <h2 class="fw-bold mb-1">Municipality Content Hub</h2>
                    <p class="text-muted mb-0">Review assigned local government units and jump directly into their content editors.</p>
                </div>
                <?php if (!empty($assignedMunicipalities)): ?>
                <form method="post" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="select_municipality" value="1">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <label for="municipality_id" class="text-muted small mb-0">Active municipality</label>
                    <select id="municipality_id" name="municipality_id" class="form-select form-select-sm" style="min-width: 220px;">
                        <?php foreach ($assignedMunicipalities as $muni): ?>
                            <option value="<?= $muni['municipality_id'] ?>" <?= ($activeMunicipality && $muni['municipality_id'] === $activeMunicipality['municipality_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($muni['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-circle me-1"></i>Apply</button>
                </form>
                <?php endif; ?>
            </div>

            <?php if ($feedback): ?>
                <div class="alert alert-<?= htmlspecialchars($feedback['type']) ?> alert-dismissible fade show" role="alert">
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
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                                <span class="badge bg-success-subtle text-success fw-semibold">Assigned</span>
                                <span class="badge badge-soft">
                                    <?= $activeMunicipality['lgu_type'] === 'city' ? 'City' : 'Municipality' ?>
                                    <?php if ($activeMunicipality['district_no']): ?>
                                        · District <?= $activeMunicipality['district_no'] ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <h3 class="fw-bold mb-1 overflow-wrap-anywhere"><?= htmlspecialchars($activeMunicipality['name']) ?></h3>
                            <?php if (!empty($activeMunicipality['slug'])): ?>
                                <div class="text-muted small mb-2">Slug: <?= htmlspecialchars($activeMunicipality['slug']) ?></div>
                            <?php endif; ?>
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="color-chip" style="background: <?= htmlspecialchars($activeMunicipality['primary_color']) ?>;"></div>
                                    <div>
                                        <div class="text-uppercase text-muted small">Primary</div>
                                        <div class="fw-semibold"><?= htmlspecialchars($activeMunicipality['primary_color']) ?></div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="color-chip" style="background: <?= htmlspecialchars($activeMunicipality['secondary_color']) ?>;"></div>
                                    <div>
                                        <div class="text-uppercase text-muted small">Secondary</div>
                                        <div class="fw-semibold"><?= htmlspecialchars($activeMunicipality['secondary_color']) ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="d-flex flex-column gap-2">
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadLogoModal">
                                    <i class="bi bi-upload me-1"></i>Upload Custom Logo
                                </button>
                                <a href="topbar_settings.php" class="btn btn-outline-success btn-sm"><i class="bi bi-layout-text-window me-1"></i>Topbar Theme</a>
                                <a href="sidebar_settings.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-layout-sidebar me-1"></i>Sidebar Theme</a>
                                <a href="settings.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-sliders me-1"></i>System Settings</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="bi bi-brush me-2 text-success"></i>Content Areas</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach ($quickActions as $action): ?>
                            <div class="col-xl-4 col-lg-6">
                                <div class="card quick-action-card h-100">
                                    <div class="card-body d-flex flex-column">
                                        <div class="d-flex align-items-center gap-3 mb-3">
                                            <div class="p-2 rounded-circle bg-success-subtle text-success">
                                                <i class="bi <?= htmlspecialchars($action['icon']) ?>"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-1 fw-semibold"><?= htmlspecialchars($action['label']) ?></h6>
                                                <div class="text-muted small">Blocks synced: <?= $action['count'] === null ? '—' : number_format($action['count']) ?></div>
                                            </div>
                                        </div>
                                        <p class="text-muted small flex-grow-1 mb-3"><?= htmlspecialchars($action['description']) ?></p>
                                        <div class="d-flex flex-wrap gap-2">
                                            <a href="<?= htmlspecialchars($action['editor_url']) ?>" target="_blank" class="btn btn-success btn-sm">
                                                <i class="bi bi-pencil-square me-1"></i>Edit Content
                                            </a>
                                            <a href="<?= htmlspecialchars($action['view_url']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                                                <i class="bi bi-box-arrow-up-right me-1"></i>View Page
                                            </a>
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
                <div class="card">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0"><i class="bi bi-geo-alt me-2 text-primary"></i>Other Assigned Municipalities</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php foreach ($otherMunicipalities as $muni): ?>
                                <div class="col-xl-4 col-lg-6">
                                    <div class="p-3 other-muni-card h-100 d-flex flex-column">
                                        <div class="d-flex align-items-center gap-3 mb-3">
                                            <div class="muni-logo-wrapper" style="width:64px;height:64px;">
                                                <?php $logo = build_logo_src($muni['active_logo']); ?>
                                                <?php if ($logo): ?>
                                                    <img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($muni['name']) ?> logo" style="max-width:50px;max-height:50px;">
                                                <?php else: ?>
                                                    <span class="text-muted small">No Logo</span>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="fw-semibold overflow-wrap-anywhere"><?= htmlspecialchars($muni['name']) ?></div>
                                                <?php if (!empty($muni['slug'])): ?>
                                                    <div class="text-muted small">Slug: <?= htmlspecialchars($muni['slug']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="mt-auto">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="select_municipality" value="1">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="municipality_id" value="<?= $muni['municipality_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    <i class="bi bi-arrow-repeat me-1"></i>Set Active
                                                </button>
                                            </form>
                                            <a href="<?= htmlspecialchars(sprintf('../../website/landingpage.php?municipality_id=%d', $muni['municipality_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary ms-1">
                                                <i class="bi bi-box-arrow-up-right me-1"></i>Preview
                                            </a>
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
});
</script>
</body>
</html>
