<?php
/** @phpstan-ignore-file */
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}

// Path to JSON settings
$jsonPath = __DIR__ . '/../../data/deadlines.json';
$deadlines = file_exists($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $out = [];
  $keys = $_POST['key'] ?? [];
  $labels = $_POST['label'] ?? [];
  $dates = $_POST['deadline_date'] ?? [];
  $actives = $_POST['active'] ?? [];
  $originalLinks = array_column($deadlines, 'link', 'key');

  foreach ($keys as $i => $key) {
    $label = trim($labels[$i] ?? '');
    $date = trim($dates[$i] ?? '');
    if ($label === '' || $date === '') continue;
    $out[] = [
      'key' => $key,
      'label' => $label,
      'deadline_date' => $date,
      'link' => $originalLinks[$key] ?? '',
      'active' => in_array($key, $actives, true)
    ];
  }

  file_put_contents($jsonPath, json_encode($out, JSON_PRETTY_PRINT));
  header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1');
  exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Deadlines</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
  <style>
    td button.btn-outline-danger {
      padding: 4px 8px;
    }
    .table-hover tbody tr:hover {
      background-color: inherit;
    }
  </style>
</head>
<body>
<div id="wrapper">
  <?php include '../../includes/admin/admin_sidebar.php'; ?>
  <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
  <section class="home-section" id="mainContent">
    <nav>
      <div class="sidebar-toggle px-4 py-3">
        <i class="bi bi-list" id="menu-toggle"></i>
      </div>
    </nav>
    <div class="container-fluid py-4 px-4">
      <h4 class="fw-bold mb-4"><i class="bi bi-calendar2-week me-2 text-primary"></i>Manage Deadlines</h4>
      <div class="card p-4">
        <form method="POST">
          <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
              <thead class="table-light">
                <tr>
                  <th>Label</th>
                  <th>Deadline Date</th>
                  <th class="text-center">Active</th>
                  <th class="text-center">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($deadlines)): ?>
                  <tr><td colspan="4" class="text-muted text-center">No deadlines configured yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($deadlines as $d): ?>
                    <tr class="<?= !empty($d['active']) ? 'table-success' : '' ?>">
                      <td>
                        <input type="hidden" name="key[]" value="<?= htmlspecialchars($d['key']) ?>">
                        <input type="text" name="label[]" class="form-control form-control-sm" value="<?= htmlspecialchars($d['label']) ?>" required>
                      </td>
                      <td>
                        <input type="date" name="deadline_date[]" class="form-control form-control-sm" value="<?= htmlspecialchars($d['deadline_date']) ?>" required>
                      </td>
                      <td class="text-center">
                        <input type="checkbox" name="active[]" value="<?= htmlspecialchars($d['key']) ?>" <?= !empty($d['active']) ? 'checked' : '' ?>></td>
                      <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">
                          <i class="bi bi-trash"></i>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary" onclick="addDeadlineRow()">
              <i class="bi bi-plus-circle me-1"></i> Add Deadline
            </button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-save me-1"></i> Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>
  </section>
</div>
<script>
function removeRow(btn) {
  const row = btn.closest('tr');
  row.remove();
}
function addDeadlineRow() {
  const tbody = document.querySelector('table tbody');
  const row = document.createElement('tr');
  const key = `key_${Date.now()}`;
  row.innerHTML = `
    <td>
      <input type="hidden" name="key[]" value="${key}">
      <input type="text" name="label[]" class="form-control form-control-sm" placeholder="Enter label" required>
    </td>
    <td>
      <input type="date" name="deadline_date[]" class="form-control form-control-sm" required>
    </td>
    <td class="text-center">
      <input type="checkbox" name="active[]" value="${key}">
    </td>
    <td class="text-center">
      <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">
        <i class="bi bi-trash"></i>
      </button>
    </td>
  `;
  tbody.appendChild(row);
}
</script>
<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>
