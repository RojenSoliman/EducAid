<?php
declare(strict_types=1);
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header('Location: index.php');
    exit;
}



// Path to JSON settings
$jsonPath = __DIR__ . '/../../data/deadlines.json';
$deadlines = file_exists($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $out = [];
    // Existing entries
    $keys = $_POST['key'] ?? [];
    $labels = $_POST['label'] ?? [];
    $dates = $_POST['deadline_date'] ?? [];
    $links = $_POST['link'] ?? [];
    $actives = $_POST['active'] ?? [];
    foreach ($keys as $i => $key) {
        $label = trim($labels[$i] ?? '');
        $date = trim($dates[$i] ?? '');
        if ($label === '' || $date === '') continue;
        $out[] = [
            'key' => $key,
            'label' => $label,
            'deadline_date' => $date,
            'link' => trim($links[$i] ?? ''),
            'active' => in_array($key, $actives, true)
        ];
    }
    // New entry
    if (!empty($_POST['new_label']) && !empty($_POST['new_deadline_date'])) {
        $newLabel = trim($_POST['new_label']);
        $newDate = trim($_POST['new_deadline_date']);
        $newLink = trim($_POST['new_link'] ?? '');
        // generate key
        $newKey = preg_replace('/[^a-z0-9]+/', '_', strtolower($newLabel));
        $out[] = [
            'key' => $newKey,
            'label' => $newLabel,
            'deadline_date' => $newDate,
            'link' => $newLink,
            'active' => isset($_POST['new_active'])
        ];
    }
    // Save JSON
    file_put_contents($jsonPath, json_encode($out, JSON_PRETTY_PRINT));
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Deadline Settings</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
</head>
<body>

<div id="wrapper">
    
    <!-- Sidebar (comes first for layout logic to work) -->
    <?php include '../../includes/admin/admin_sidebar.php'; ?>

    <!-- Backdrop for mobile sidebar -->
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

    <!-- Main Section -->
    <section class="home-section" id="mainContent">
      <nav>
        <div class="sidebar-toggle px-4 py-3">
          <i class="bi bi-list" id="menu-toggle"></i>
        </div>
      </nav>
      <div class="container-fluid py-4 px-4">
        <h2>Manage Deadlines</h2>
            <form method="POST">
            <table class="table table-bordered">
                <thead>
                <tr>
                    <th>Label</th>
                    <th>Deadline Date</th>
                    <th>Link</th>
                    <th>Active</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($deadlines as $d): ?>
                <tr>
                    <td>
                    <input type="hidden" name="key[]" value="<?php echo htmlspecialchars($d['key']); ?>">
                    <input type="text" name="label[]" class="form-control" value="<?php echo htmlspecialchars($d['label']); ?>" required>
                    </td>
                    <td><input type="date" name="deadline_date[]" class="form-control" value="<?php echo htmlspecialchars($d['deadline_date']); ?>" required></td>
                    <td><input type="text" name="link[]" class="form-control" value="<?php echo htmlspecialchars($d['link']); ?>"></td>
                    <td class="text-center"><input type="checkbox" name="active[]" value="<?php echo htmlspecialchars($d['key']); ?>" <?php echo !empty($d['active']) ? 'checked' : ''; ?>></td>
                </tr>
                <?php endforeach; ?>
                <!-- New entry row -->
                <tr class="table-light">
                    <td><input type="text" name="new_label" class="form-control" placeholder="New label"></td>
                    <td><input type="date" name="new_deadline_date" class="form-control"></td>
                    <td><input type="text" name="new_link" class="form-control" placeholder="Optional link"></td>
                    <td class="text-center"><input type="checkbox" name="new_active"></td>
                </tr>
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
      </div>
    </section>
  </div>
</body>
</html>
