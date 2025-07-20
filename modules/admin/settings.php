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
    $actives = $_POST['active'] ?? [];
    // preserve existing links (admin cannot edit links)
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
                    <td class="text-center"><input type="checkbox" name="active[]" value="<?php echo htmlspecialchars($d['key']); ?>" <?php echo !empty($d['active']) ? 'checked' : ''; ?>></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
      </div>
    </section>
  </div>
</body>
</html>
