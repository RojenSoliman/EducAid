<?php
// Standalone debug page for EAF submission and server diagnostics
session_start();

// Helpers
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function bool_str($v) { return $v ? 'ON' : 'OFF'; }

$inspect = isset($_GET['inspect']);
$checkDb = isset($_GET['check_db']);

// Handle local inspection upload (does not touch app DB)
$localUploadResult = null;
if ($inspect && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['eaf_file'])) {
    $f = $_FILES['eaf_file'];
    $details = [
        'name' => $f['name'] ?? '',
        'type' => $f['type'] ?? '',
        'size' => $f['size'] ?? 0,
        'tmp_name' => $f['tmp_name'] ?? '',
        'error' => $f['error'] ?? 0,
    ];
    $errorMap = [
        0 => 'UPLOAD_ERR_OK',
        1 => 'UPLOAD_ERR_INI_SIZE',
        2 => 'UPLOAD_ERR_FORM_SIZE',
        3 => 'UPLOAD_ERR_PARTIAL',
        4 => 'UPLOAD_ERR_NO_FILE',
        6 => 'UPLOAD_ERR_NO_TMP_DIR',
        7 => 'UPLOAD_ERR_CANT_WRITE',
        8 => 'UPLOAD_ERR_EXTENSION',
    ];
    $details['error_text'] = $errorMap[$details['error']] ?? 'UNKNOWN';

    $moveStatus = null;
    $targetPath = null;
    if ($details['error'] === UPLOAD_ERR_OK && is_uploaded_file($details['tmp_name'])) {
        $ext = strtolower(pathinfo($details['name'], PATHINFO_EXTENSION));
        $targetPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'debug_eaf_' . uniqid() . '.' . $ext;
        $moveStatus = move_uploaded_file($details['tmp_name'], $targetPath);
        if ($moveStatus && file_exists($targetPath)) {
            // Clean up immediately
            @unlink($targetPath);
        }
    }
    $localUploadResult = [
        'details' => $details,
        'move_status' => $moveStatus,
        'target_path' => $targetPath,
    ];
}

// Basic paths
$docRoot = __DIR__;
$uploadDocPath = __DIR__ . '/modules/student/upload_document.php';
$studentsUploadDir = __DIR__ . '/assets/uploads/students';

// DB diagnostics (optional)
$dbInfo = null;
if ($checkDb) {
    $dbInfo = [
        'connected' => false,
        'error' => null,
        'constraint' => null,
    ];
    // Reuse app connection if available
    $connection = null;
    $dbFile = __DIR__ . '/config/database.php';
    if (file_exists($dbFile)) {
        include $dbFile; // should define $connection (pg)
    }
    if (!empty($connection)) {
        $dbInfo['connected'] = true;
        // Get constraint definition for documents table
        $sql = "SELECT conname, pg_get_constraintdef(oid) AS def
                FROM pg_constraint
                WHERE conrelid = 'public.documents'::regclass";
        $res = @pg_query($connection, $sql);
        if ($res) {
            $defs = [];
            while ($row = pg_fetch_assoc($res)) { $defs[] = $row; }
            $dbInfo['constraint'] = $defs;
        } else {
            $dbInfo['error'] = 'Failed to query constraint: ' . pg_last_error($connection);
        }
    } else {
        $dbInfo['error'] = 'No DB connection from config/database.php';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Debug: EAF Upload</title>
  <link href="assets/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    body{padding:24px;background:#f8fafc}
    .card{box-shadow:0 4px 12px rgba(0,0,0,.06);border-radius:12px}
    pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;white-space:pre-wrap}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
  </style>
  <script>
    function copyText(id){
      const el=document.getElementById(id);
      if(!el) return;
      navigator.clipboard.writeText(el.innerText);
      alert('Copied to clipboard');
    }
  </script>
  </head>
<body>
  <h1>Debug: EAF Upload</h1>
  <p>Use this page to isolate issues with EAF submissions. It bypasses most UI JavaScript.</p>

  <div class="grid">
    <div class="card p-3">
      <h5>Environment</h5>
      <ul>
        <li>file_uploads: <strong><?= bool_str(ini_get('file_uploads')) ?></strong></li>
        <li>upload_max_filesize: <strong><?= h(ini_get('upload_max_filesize')) ?></strong></li>
        <li>post_max_size: <strong><?= h(ini_get('post_max_size')) ?></strong></li>
        <li>max_file_uploads: <strong><?= h(ini_get('max_file_uploads')) ?></strong></li>
        <li>Temp dir: <code><?= h(sys_get_temp_dir()) ?></code></li>
      </ul>
    </div>

    <div class="card p-3">
      <h5>Session</h5>
      <ul>
        <li>student_username: <strong><?= h($_SESSION['student_username'] ?? '(not set)') ?></strong></li>
        <li>student_id: <strong><?= h($_SESSION['student_id'] ?? '(not set)') ?></strong></li>
      </ul>
      <form method="post">
        <div class="mb-2">
          <label class="form-label">Set student_username</label>
          <input name="_dbg_user" class="form-control" placeholder="e.g. testuser" />
        </div>
        <div class="mb-2">
          <label class="form-label">Set student_id</label>
          <input name="_dbg_id" class="form-control" placeholder="e.g. 123" />
        </div>
        <button class="btn btn-sm btn-secondary" name="_dbg_set" value="1">Set Session (debug only)</button>
      </form>
      <?php if (!empty($_POST['_dbg_set'])) { $_SESSION['student_username'] = $_POST['_dbg_user'] ?? null; $_SESSION['student_id'] = $_POST['_dbg_id'] ?? null; echo '<div class="mt-2 alert alert-success">Session updated. Refresh this page.</div>'; } ?>
    </div>

    <div class="card p-3">
      <h5>Paths</h5>
      <ul>
        <li>App root: <code><?= h($docRoot) ?></code></li>
        <li>upload_document.php: <strong><?= file_exists($uploadDocPath) ? 'FOUND' : 'MISSING' ?></strong></li>
        <li>uploads/students dir: <strong><?= is_dir($studentsUploadDir) ? 'EXISTS' : 'MISSING' ?></strong> (writable: <?= is_writable($studentsUploadDir) ? 'YES' : 'NO' ?>)</li>
      </ul>
    </div>

    <div class="card p-3">
      <h5>Database (optional)</h5>
      <a class="btn btn-sm btn-outline-primary" href="?check_db=1">Check documents constraint</a>
      <?php if ($dbInfo): ?>
        <div class="mt-2">
          <div>Connected: <strong><?= $dbInfo['connected'] ? 'YES' : 'NO' ?></strong></div>
          <?php if ($dbInfo['error']): ?><div class="text-danger">Error: <?= h($dbInfo['error']) ?></div><?php endif; ?>
          <?php if ($dbInfo['constraint']): ?>
            <div class="mt-2">
              <div class="fw-bold">Constraints for public.documents</div>
              <pre id="constrText"><?php foreach ($dbInfo['constraint'] as $c) { echo $c['conname'] . ': ' . $c['def'] . "\n"; } ?></pre>
              <button class="btn btn-sm btn-outline-secondary" onclick="copyText('constrText')">Copy</button>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card p-3 mt-3">
    <h5>Submit EAF directly to upload_document.php</h5>
    <p>This bypasses the main UI and JS. If this works, the issue is likely front-end interception.</p>
    <form method="post" action="modules/student/upload_document.php" enctype="multipart/form-data">
      <div class="mb-2">
        <label class="form-label">EAF file (PDF/JPG/PNG)</label>
        <input type="file" name="eaf_file" accept=".pdf,.jpg,.jpeg,.png" class="form-control" required />
      </div>
      <button class="btn btn-primary">Submit to upload_document.php</button>
    </form>
  </div>

  <div class="card p-3 mt-3">
    <h5>Local inspection (no DB write)</h5>
    <p>Uploads to this page, inspects $_FILES, and tests moving to the temp directory.</p>
    <form method="post" action="?inspect=1" enctype="multipart/form-data">
      <div class="mb-2">
        <label class="form-label">EAF file</label>
        <input type="file" name="eaf_file" accept=".pdf,.jpg,.jpeg,.png" class="form-control" required />
      </div>
      <button class="btn btn-secondary">Upload (inspect only)</button>
    </form>
    <?php if ($localUploadResult): ?>
      <div class="mt-3">
        <div class="fw-bold">Inspection Result</div>
        <pre><?php echo h(print_r($localUploadResult, true)); ?></pre>
      </div>
    <?php endif; ?>
  </div>

  <div class="card p-3 mt-3">
    <h5>Troubleshooting tips</h5>
    <ul>
      <li>Ensure you are logged in (session shows student_username and student_id above). Use the debug form to set them if testing.</li>
      <li>If direct submit works here but not on Upload Documents, the front-end script may be intercepting submit.</li>
      <li>If error shows UPLOAD_ERR_INI_SIZE or FORM_SIZE, increase upload_max_filesize and post_max_size.</li>
      <li>Check Apache/PHP error log for entries from upload_document.php (we log errors with error_log()).</li>
    </ul>
  </div>

  <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
