<?php
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Generate CSRF tokens
$csrfTokenPost = CSRFProtection::generateToken('post_announcement');
$csrfTokenToggle = CSRFProtection::generateToken('toggle_announcement');

// Handle form submission for general announcements (create)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Validate CSRF token first
  $token = $_POST['csrf_token'] ?? '';
  
  // Toggle activation request (repost/unpost)
  if (isset($_POST['announcement_id'], $_POST['toggle_active'])) {
    if (!CSRFProtection::validateToken('toggle_announcement', $token)) {
      header('Location: ' . $_SERVER['PHP_SELF'] . '?error=csrf');
      exit;
    }
    
    $aid = (int)$_POST['announcement_id'];
    $toggle = (int)$_POST['toggle_active']; // 1 => set active, 0 => deactivate
    if ($toggle === 1) {
      pg_query($connection, "UPDATE announcements SET is_active = FALSE");
      pg_query_params($connection, "UPDATE announcements SET is_active=TRUE, updated_at=now() WHERE announcement_id=$1", [$aid]);
    } else {
      pg_query_params($connection, "UPDATE announcements SET is_active=FALSE, updated_at=now() WHERE announcement_id=$1", [$aid]);
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?toggled=1');
    exit;
  }

  if (isset($_POST['post_announcement'])) {
    if (!CSRFProtection::validateToken('post_announcement', $token)) {
      header('Location: ' . $_SERVER['PHP_SELF'] . '?error=csrf');
      exit;
    }
    $title = trim($_POST['title']);
    $remarks = trim($_POST['remarks']);
    $event_date = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
    $event_time = !empty($_POST['event_time']) ? $_POST['event_time'] : null;
    $location = !empty($_POST['location']) ? trim($_POST['location']) : null;
    $image_path = null;

    // Handle image upload (optional)
    if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
      $uploadDir = __DIR__ . '/../../assets/uploads/announcements';
      if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
      $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
        $ext = 'jpg'; // normalize
      }
      $fname = 'ann_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
      $dest = $uploadDir . '/' . $fname;
      if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        // Store relative path for serving
        $image_path = 'assets/uploads/announcements/' . $fname;
      }
    }

    // Deactivate previous active
    pg_query($connection, "UPDATE announcements SET is_active = FALSE");
    $query = "INSERT INTO announcements (title, remarks, event_date, event_time, location, image_path, is_active) VALUES ($1,$2,$3,$4,$5,$6,TRUE)";
    $result = pg_query_params($connection, $query, [$title, $remarks, $event_date, $event_time, $location, $image_path]);
    if ($result) {
      $notification_msg = "New announcement posted: " . $title;
      pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?posted=1');
    exit;
  }
}

// Check for success flag
$posted = isset($_GET['posted']);
?>
<?php $page_title='Manage Announcements'; $extra_css=['../../assets/css/admin/manage_announcements.css']; include '../../includes/admin/admin_head.php'; ?>
<style>
  .card:hover { transform:none!important; transition:none!important; }
  .card h5 { font-size:1.25rem; font-weight:600; color:#333; }
  /* Layout */
  .announcement-form-grid { display:grid; gap:1.25rem; }
  @media (min-width:992px){ .announcement-form-grid { grid-template-columns:2fr 1fr; align-items:start; } }
  .event-block { background:#f8fafc; border:1px solid #e2e8f0; border-radius:.5rem; padding:1rem 1.1rem; position:relative; }
  .event-block h6 { font-size:.9rem; font-weight:600; text-transform:uppercase; margin:0 0 .75rem; color:#2563eb; letter-spacing:.5px; }
  .event-inline { display:flex; flex-wrap:wrap; gap:.75rem; }
  .event-inline .form-control { min-width:160px; }
  .image-upload-wrap { border:2px dashed #cbd5e1; padding:1.1rem; text-align:center; border-radius:.75rem; cursor:pointer; background:#fff; transition:border-color .2s, background .2s; }
  .image-upload-wrap.dragover { border-color:#2563eb; background:#f1f8ff; }
  .image-upload-wrap input { display:none; }
  .image-preview { margin-top:.75rem; display:flex; gap:1rem; flex-wrap:wrap; }
  .image-preview figure { margin:0; position:relative; }
  .image-preview img { max-width:160px; max-height:110px; object-fit:cover; border:1px solid #cbd5e1; border-radius:.4rem; box-shadow:0 2px 4px rgba(0,0,0,.06); }
  .image-preview .remove-btn { position:absolute; top:4px; right:4px; background:#ef4444; border:none; color:#fff; width:26px; height:26px; border-radius:50%; font-size:.8rem; display:flex; align-items:center; justify-content:center; cursor:pointer; }
  /* Inline (in-zone) preview */
  .image-upload-wrap { position:relative; }
  .image-upload-wrap .inline-preview { display:flex; justify-content:center; align-items:center; min-height:140px; }
  .image-upload-wrap .inline-preview figure { margin:0; position:relative; }
  .image-upload-wrap .inline-preview img { max-width:100%; max-height:220px; object-fit:contain; display:block; border-radius:.5rem; box-shadow:0 2px 6px rgba(0,0,0,.08); }
  .image-upload-wrap .remove-btn { position:absolute; top:6px; right:6px; background:#ef4444; border:none; color:#fff; width:30px; height:30px; border-radius:50%; font-size:.85rem; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 2px 4px rgba(0,0,0,.15); }
  .image-upload-wrap.dragover .placeholder { opacity:.4; }
  .image-preview figcaption { font-size:.65rem; text-align:center; margin-top:.3rem; max-width:160px; color:#475569; }
  .form-section-title { font-size:.75rem; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin:0 0 .5rem; }
  /* Table */
  #ann-body td { vertical-align:top; }
  #ann-body tr.active-ann { box-shadow:0 0 0 2px #16a34a33 inset; background:#f0fff4; }
  .remarks-trunc { position:relative; max-width:420px; }
  .remarks-trunc.collapsed .full-text { display:none; }
  .remarks-trunc.expanded .truncate-text { display:none; }
  .remarks-toggle { color:#2563eb; font-size:.7rem; cursor:pointer; display:inline-block; margin-top:.25rem; }
  .badge-success { background:#16a34a!important; }
  .badge-secondary { background:#64748b!important; }
  .announcement-img-thumb { border:1px solid #e2e8f0; background:#fff; padding:2px; border-radius:.35rem; display:inline-block; box-shadow:0 1px 2px rgba(0,0,0,.08); }
  .announcement-img-thumb img { max-width:80px; max-height:50px; object-fit:cover; border-radius:.25rem; display:block; }
  .pagination-controls { display:flex; gap:.5rem; align-items:center; justify-content:center; margin-top:1rem; }
  .pagination-controls input[type='number'] { width:60px; text-align:center; }
</style>
</head>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
  <?php include '../../includes/admin/admin_sidebar.php'; ?>
  <?php include '../../includes/admin/admin_header.php'; ?>
  <section class="home-section" id="mainContent">
  <div class="container-fluid py-4 px-4">
      <h2 class="fw-bold mb-4"><i class="bi bi-megaphone-fill text-primary me-2"></i>Manage Announcements</h2>

      <div class="card p-4 mb-4">
        <form method="POST" enctype="multipart/form-data" id="announcementForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenPost) ?>">
          <div class="mb-3">
            <label class="form-label">Title</label>
            <input type="text" name="title" class="form-control form-control-lg" placeholder="Scholarship Orientation" required>
          </div>
          <div class="event-block mb-3">
            <h6><i class="bi bi-calendar-event me-1"></i> Event Schedule (Optional)</h6>
            <div class="event-inline mb-2">
              <input type="date" name="event_date" class="form-control" aria-label="Event Date">
              <input type="time" name="event_time" class="form-control" aria-label="Event Time">
            </div>
            <input type="text" name="location" class="form-control" placeholder="Location / Venue" aria-label="Location">
          </div>
          <div class="mb-3">
            <label class="form-label">Remarks / Description</label>
            <textarea name="remarks" class="form-control form-control-lg" rows="6" placeholder="Provide details, agenda, instructions, deadlines..." required></textarea>
          </div>
          <div class="mb-3">
            <div class="form-section-title">Image (Optional)</div>
            <label class="image-upload-wrap" id="imageDropZone">
              <input type="file" name="image" id="imageInput" accept="image/*">
              <div class="placeholder small text-muted text-center w-100"><i class="bi bi-image me-1"></i>Drag & drop or click to select an image (jpg/png/gif/webp)</div>
              <div class="inline-preview" id="inlineImagePreview" hidden></div>
            </label>
          </div>
          <button type="submit" name="post_announcement" class="btn btn-primary">
            <i class="bi bi-send me-1"></i> Post Announcement
          </button>
        </form>
        <?php if ($posted): ?>
          <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
            Announcement posted successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
      </div>

      <h2 class="fw-bold mb-4"><i class="bi bi-card-text text-primary me-2"></i>Existing Announcements</h2>
      <div class="card p-4">
        <?php
  $annRes = pg_query($connection, "SELECT announcement_id, title, remarks, posted_at, is_active, event_date, event_time, location, image_path FROM announcements ORDER BY posted_at DESC");
        $announcements = [];
        while ($a = pg_fetch_assoc($annRes)) {
          $announcements[] = $a;
        }
        pg_free_result($annRes);
        ?>
        <div class="table-responsive">
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Title</th>
                <th>Remarks</th>
                <th>Event</th>
                <th>Posted At</th>
                <th>Active</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="ann-body"></tbody>
          </table>
        </div>
        <div class="pagination-controls">
          <label>Page:</label>
          <input type="number" id="page-input" min="1" value="1">
          <button id="prev-btn" class="btn btn-outline-secondary btn-sm">&laquo;</button>
          <button id="next-btn" class="btn btn-outline-secondary btn-sm">&raquo;</button>
          <span id="page-info" class="ms-2"></span>
        </div>
      </div>
    </div>
  </section>
</div>
<script>
const announcements = <?php echo json_encode($announcements); ?>;
let currentPage = 0;
const pageSize = 5;
const totalPages = Math.ceil(announcements.length / pageSize);
const latestId = announcements.length > 0 ? announcements[0].announcement_id : null;

function renderPage() {
  const start = currentPage * pageSize;
  const slice = announcements.slice(start, start + pageSize);
  const tbody = document.getElementById('ann-body');
  tbody.innerHTML = '';
  slice.forEach(a => {
    const isLatest = a.announcement_id === latestId;
    const badge = isLatest
      ? '<span class="badge bg-success">Active</span>'
      : '<span class="badge bg-secondary">Inactive</span>';
    const btnLabel = isLatest ? 'Unpost' : 'Repost';
    const btnClass = isLatest ? 'danger' : 'success';
    const toggleValue = isLatest ? 0 : 1;
    const tr = document.createElement('tr');
    // Event summary formatting
    let eventCell = '';
    if (a.event_date || a.event_time || a.location) {
      const d = a.event_date ? a.event_date : '';
      const t = a.event_time ? a.event_time.substring(0,5) : '';
      const loc = a.location ? a.location : '';
      eventCell = `<div class='small'>${d} ${t}</div>${loc ? `<div class='text-muted small'>${loc}</div>` : ''}`;
    } else {
      eventCell = '<span class="text-muted small">—</span>';
    }
    const imgThumb = a.image_path ? `<div class='mt-1'><img src='../../${a.image_path}' alt='img' style='max-width:80px; max-height:50px; object-fit:cover; border:1px solid #ddd; border-radius:4px;'></div>` : '';
    // Truncate remarks & build cell
    let truncated = a.remarks.length > 220 ? a.remarks.substring(0,220) + '…' : a.remarks;
    const needsToggle = a.remarks.length > 220;
    const remarksHtml = `
      <div class="remarks-trunc collapsed">
        <div class="truncate-text">${escapeHtml(truncated)}</div>
        <div class="full-text">${escapeHtml(a.remarks)}</div>
        ${needsToggle ? '<span class="remarks-toggle">Show more</span>' : ''}
      </div>`;
    tr.classList.toggle('active-ann', isLatest);
    tr.innerHTML = `
      <td><strong>${escapeHtml(a.title)}</strong>${imgThumb}</td>
      <td>${remarksHtml}</td>
      <td>${eventCell}</td>
      <td>${a.posted_at}</td>
      <td>${badge}</td>
      <td>
        <form method="POST" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenToggle) ?>">
          <input type="hidden" name="announcement_id" value="${a.announcement_id}">
          <input type="hidden" name="toggle_active" value="${toggleValue}">
          <button type="submit" class="btn btn-sm btn-outline-${btnClass}">${btnLabel}</button>
        </form>
      </td>`;
    tbody.appendChild(tr);
  });
  document.getElementById('page-info').textContent = `Page ${currentPage + 1} of ${totalPages}`;
  document.getElementById('prev-btn').disabled = currentPage === 0;
  document.getElementById('next-btn').disabled = currentPage >= totalPages - 1;
  document.getElementById('page-input').value = currentPage + 1;
}

renderPage();
function escapeHtml(str){ return str.replace(/[&<>"']/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c]||c)); }
document.getElementById('prev-btn').addEventListener('click', () => {
  if (currentPage > 0) {
    currentPage--;
    renderPage();
  }
});
document.getElementById('next-btn').addEventListener('click', () => {
  if (currentPage < totalPages - 1) {
    currentPage++;
    renderPage();
  }
});
document.getElementById('page-input').addEventListener('change', (e) => {
  const value = parseInt(e.target.value);
  if (!isNaN(value) && value >= 1 && value <= totalPages) {
    currentPage = value - 1;
    renderPage();
  }
});
// Expand / collapse remarks
document.addEventListener('click', e=>{
  if(e.target.classList.contains('remarks-toggle')){
    const wrap = e.target.closest('.remarks-trunc');
    if(!wrap) return;
    const expanded = wrap.classList.toggle('expanded');
    wrap.classList.toggle('collapsed', !expanded);
    e.target.textContent = expanded? 'Show less' : 'Show more';
  }
});
// Image preview & drag-drop (inline centered)
const dropZone = document.getElementById('imageDropZone');
const imgInput = document.getElementById('imageInput');
const inlinePreview = document.getElementById('inlineImagePreview');
if(dropZone && imgInput && inlinePreview){
  const placeholder = dropZone.querySelector('.placeholder');
  const clearPreview = () => { inlinePreview.innerHTML=''; inlinePreview.hidden=true; if(placeholder) placeholder.hidden=false; };
  const showPreview = (file) => {
    if(!file) return clearPreview();
    const url = URL.createObjectURL(file);
    inlinePreview.innerHTML = `<figure><button type="button" class="remove-btn" title="Remove">&times;</button><img src="${url}" alt="Selected image"></figure>`;
    inlinePreview.hidden = false;
    if(placeholder) placeholder.hidden = true;
  };
  imgInput.addEventListener('change', e => showPreview(e.target.files[0]));
  inlinePreview.addEventListener('click', e => { if(e.target.classList.contains('remove-btn')) { imgInput.value=''; clearPreview(); }});
  ['dragenter','dragover'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dropZone.classList.add('dragover'); }));
  ['dragleave','drop'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dropZone.classList.remove('dragover'); }));
  dropZone.addEventListener('drop', e => { const file = e.dataTransfer.files && e.dataTransfer.files[0]; if(file){ imgInput.files = e.dataTransfer.files; imgInput.dispatchEvent(new Event('change')); }});
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>