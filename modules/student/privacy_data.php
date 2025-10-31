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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Privacy & Data - EducAid</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" />
  <link href="../../assets/css/student/sidebar.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/accessibility.css" />
  <script src="../../assets/js/student/accessibility.js"></script>
  <style>
    body { background: #f7fafc; }
    .home-section { margin-left: 250px; width: calc(100% - 250px); min-height: calc(100vh - var(--topbar-h, 60px)); background: #f7fafc; padding-top: 56px; position: relative; z-index: 1; box-sizing: border-box; }
    .sidebar.close ~ .home-section { margin-left: 70px; width: calc(100% - 70px); }
    @media (max-width: 768px) { .home-section { margin-left: 0 !important; width: 100% !important; } }

    .settings-header { background: transparent; border-bottom: none; padding: 0; margin-bottom: 2rem; }
    .settings-header h1 { color: #1a202c; font-weight: 600; font-size: 2rem; margin: 0; }
    .settings-nav { background: #f7fafc; border-radius: 12px; padding: 0.5rem; border: 1px solid #e2e8f0; }
    .settings-nav-item { display: flex; align-items: center; padding: 0.75rem 1rem; color: #4a5568; text-decoration: none; border-radius: 8px; font-weight: 500; font-size: 0.95rem; transition: all 0.2s ease; margin-bottom: 0.25rem; }
    .settings-nav-item:last-child { margin-bottom: 0; }
    .settings-nav-item:hover { background: #edf2f7; color: #2d3748; text-decoration: none; }
    .settings-nav-item.active { background: #4299e1; color: white; }
    .settings-nav-item.active:hover { background: #3182ce; }

    .content-card { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
    .muted { color: #718096; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../../includes/student/student_topbar.php'; ?>
  <div id="wrapper" style="padding-top: var(--topbar-h);">
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
    <?php include __DIR__ . '/../../includes/student/student_header.php'; ?>

    <section class="home-section" id="page-content-wrapper">
      <div class="container-fluid py-4 px-4">
        <div class="row g-4">
          <div class="col-12 col-md-3">
            <div class="settings-nav">
              <a class="settings-nav-item" href="security_activity.php"><i class="bi bi-shield-lock me-2"></i> Security Activity</a>
              <a class="settings-nav-item" href="active_sessions.php"><i class="bi bi-pc me-2"></i> Active Sessions</a>
              <a class="settings-nav-item active" href="privacy_data.php"><i class="bi bi-incognito me-2"></i> Privacy & Data</a>
            </div>
          </div>
          <div class="col-12 col-md-9">
            <div class="settings-header mb-3">
              <h1 class="mb-0">Privacy & Data</h1>
              <p class="muted mt-1">Download your data and manage your privacy preferences.</p>
            </div>

            <!-- Download My Data -->
            <div class="content-card mb-4">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0"><i class="bi bi-download me-2"></i>Download My Data</h5>
                <button id="requestExportBtn" class="btn btn-primary btn-sm"><i class="bi bi-cloud-arrow-down me-1"></i> Request Export</button>
              </div>
              <div id="exportStatus" class="small muted">No export requested yet.</div>
              <div id="downloadContainer" class="mt-2 d-none">
                <a id="downloadLink" href="#" class="btn btn-success btn-sm"><i class="bi bi-file-zip me-1"></i> Download ZIP</a>
                <small class="text-muted ms-2" id="fileMeta"></small>
              </div>
            </div>

            <!-- Privacy Settings section intentionally omitted per current scope -->
          </div>
        </div>
      </div>
    </section>
  </div>

  <script>
    const statusEl = document.getElementById('exportStatus');
    const btn = document.getElementById('requestExportBtn');
    const dlWrap = document.getElementById('downloadContainer');
    const dlLink = document.getElementById('downloadLink');
    const fileMeta = document.getElementById('fileMeta');

    async function fetchStatus() {
      try {
        const res = await fetch('../../api/student/export_status.php', { credentials: 'include' });
        const data = await res.json();
        if (!data.success) { statusEl.textContent = 'Unable to fetch export status.'; return; }
        if (!data.exists) { statusEl.textContent = 'No export requested yet.'; dlWrap.classList.add('d-none'); return; }

        statusEl.textContent = `Status: ${data.status}` + (data.processed_at ? ` • Processed: ${new Date(data.processed_at).toLocaleString()}` : '');
        if (data.status === 'ready' && data.download_url) {
          dlLink.href = data.download_url;
          dlWrap.classList.remove('d-none');
          if (data.file_size_bytes) {
            const mb = (data.file_size_bytes / (1024*1024)).toFixed(2);
            fileMeta.textContent = `(~${mb} MB) • Expires: ${data.expires_at ? new Date(data.expires_at).toLocaleString() : ''}`;
          }
        } else {
          dlWrap.classList.add('d-none');
        }
      } catch (e) {
        statusEl.textContent = 'Error fetching export status.';
      }
    }

    btn.addEventListener('click', async () => {
      btn.disabled = true; btn.textContent = 'Processing…';
      try {
        const res = await fetch('../../api/student/request_data_export.php', { method: 'POST', credentials: 'include' });
        const data = await res.json();
        if (!data.success) { statusEl.textContent = 'Export request failed.'; }
        await fetchStatus();
      } catch (e) {
        statusEl.textContent = 'Export request failed.';
      } finally { btn.disabled = false; btn.textContent = 'Request Export'; }
    });

    // Init: poll export status on load
    fetchStatus();

    // Privacy toggles removed in current scope
  </script>
</body>
</html>
