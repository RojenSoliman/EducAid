


<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Fetch verified students for dropdown
$verified_students = [];
$res = pg_query($connection, "SELECT student_id, first_name, middle_name, last_name, payroll_no FROM students WHERE status = 'active' ORDER BY last_name, first_name");
if ($res) {
    while ($row = pg_fetch_assoc($res)) {
        $verified_students[] = $row;
    }
}

// Generate a new QR entry for a verified student
if (isset($_POST['generate']) && isset($_POST['student_id'])) {
    $student_id = $_POST['student_id'];
    // Get student info
    $res = pg_query_params($connection, "SELECT first_name, middle_name, last_name, payroll_no FROM students WHERE student_id = $1 AND status = 'active'", [$student_id]);
    if ($res && $student = pg_fetch_assoc($res)) {
        $payroll_number = $student['payroll_no'];
        $name = trim($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name']);
        $unique_id = uniqid('qr_');
        $status = 'Pending';
        $admin_id = null;
        pg_query_params($connection, "INSERT INTO qr_codes (payroll_number, unique_id, status, name, admin_id, student_id) VALUES ($1, $2, $3, $4, $5, $6)", [$payroll_number, $unique_id, $status, $name, $admin_id, $student_id]);
    }
}

// Remove a specific QR
if (isset($_POST['remove'])) {
    $qr_id = $_POST['remove'];
    pg_query_params($connection, "DELETE FROM qr_codes WHERE qr_id = $1", [$qr_id]);
}

// Reset all
if (isset($_POST['reset'])) {
    pg_query($connection, "DELETE FROM qr_codes");
}

// Mark scanned
if (isset($_POST['scan'])) {
    $qr_id = $_POST['scan'];
    pg_query_params($connection, "UPDATE qr_codes SET status = 'Done' WHERE qr_id = $1", [$qr_id]);
}

// Fetch all QR codes
$qr_codes = [];
$result = pg_query($connection, "SELECT qr_id, payroll_number, unique_id, status, name, student_id FROM qr_codes ORDER BY qr_id DESC");
if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        $qr_codes[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>QR Code Scanner - Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css?family=Poppins:400,500,600&display=swap" rel="stylesheet">
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../../assets/css/admin/homepage.css" rel="stylesheet">
  <link href="../../assets/css/admin/sidebar.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { font-family: 'Poppins', sans-serif; }
    .qr-container { margin-top: 20px; }
    .payroll-number { font-size: 18px; font-weight: bold; color: #333; }
    .fade-in { animation: fadeIn 0.8s ease-in-out; }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .done-status { border: 2px solid green; color: green; }
  </style>
</head>
<body>
  <div id="wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
    <section class="home-section" id="page-content-wrapper">
      <nav>
        <div class="sidebar-toggle px-4 py-3">
          <i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i>
        </div>
      </nav>
      <div class="container py-5">
        <h1>QR Code Scanner</h1>
        <div id="reader"></div>
        <div class="controls my-3">
          <select id="camera-select" class="form-select w-auto d-inline-block">
            <option value="">Select Camera</option>
          </select>
          <button id="start-button" class="btn btn-success mx-2">Start Scanner</button>
          <button id="stop-button" class="btn btn-danger mx-2" disabled>Stop Scanner</button>
        </div>
        <p><strong>Result:</strong> <span id="result">—</span></p>


        <form method="post" class="mb-3">
          <div class="row g-2 align-items-center justify-content-center">
            <div class="col-auto">
              <select name="student_id" class="form-select" required>
                <option value="">Select Verified Student</option>
                <?php foreach ($verified_students as $stud): ?>
                  <option value="<?= $stud['student_id'] ?>">
                    <?= htmlspecialchars(trim($stud['last_name'] . ', ' . $stud['first_name'] . ' ' . $stud['middle_name'])) ?> (<?= htmlspecialchars($stud['payroll_no']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-auto">
              <button type="submit" name="generate" class="btn btn-primary">Generate QR Code</button>
            </div>
          </div>
        </form>


        <?php if (isset($_POST['generate']) && count($qr_codes) > 0): ?>
          <?php
            $last = $qr_codes[0]; // Most recent QR code
            $qr_url = "phpqrcode/generate_qr.php?data=" . urlencode($last['unique_id']);
          ?>
          <div class="qr-container fade-in">
            <div class="payroll-number">Payroll Number: <?= htmlspecialchars($last['payroll_number']) ?></div>
            <h2>Your Unique QR Code</h2>
            <img src="<?= $qr_url ?>" alt="Generated QR Code">
            <a href="<?= $qr_url ?>" download="qr_<?= htmlspecialchars($last['payroll_number']) ?>.png" class="download-button btn btn-success mt-2">Download QR</a>
          </div>
        <?php endif; ?>

        <h2>Generated QR Codes List</h2>
        <table class="table table-bordered table-striped">
          <thead>
            <tr>
              <th>Payroll Number</th>
              <th>Name</th>
              <th>ID Number</th>
              <th>Unique ID</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($qr_codes as $qr): ?>
              <tr class="<?= ($qr['status'] == 'Done') ? 'done-status' : ''; ?>">
                <td><?= htmlspecialchars($qr['payroll_number']) ?></td>
                <td><?= $qr['name'] ? htmlspecialchars($qr['name']) : '-' ?></td>
                <td><?= isset($qr['student_id']) ? htmlspecialchars($qr['student_id']) : '-' ?></td>
                <td><?= htmlspecialchars($qr['unique_id']) ?></td>
                <td><?= htmlspecialchars($qr['status']) ?></td>
                <td>
                  <?php if ($qr['status'] != 'Done'): ?>
                    <form method="post" style="display:inline;">
                      <button type="submit" name="scan" value="<?= $qr['qr_id'] ?>" class="scan-button btn btn-info btn-sm">Scan</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" style="display:inline;">
                    <button type="submit" name="remove" value="<?= $qr['qr_id'] ?>" class="remove-button btn btn-danger btn-sm">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <form method="post">
          <button type="submit" name="reset" class="reset-button btn btn-secondary">Reset All</button>
        </form>
      </div>
    </section>
  </div>
  <script src="https://unpkg.com/html5-qrcode"></script>
  <script src="../../assets/js/admin/sidebar.js"></script>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script>
    const startButton = document.getElementById('start-button');
    const stopButton = document.getElementById('stop-button');
    const resultSpan = document.getElementById('result');
    const cameraSelect = document.getElementById('camera-select');
    const html5QrCode = new Html5Qrcode("reader");
    let currentCameraId = null;
    Html5Qrcode.getCameras().then(cameras => {
      if (!cameras.length) {
        alert("No cameras found.");
        return;
      }
      cameras.forEach(camera => {
        const option = document.createElement('option');
        option.value = camera.id;
        option.text = camera.label || `Camera ${camera.id}`;
        cameraSelect.appendChild(option);
      });
      const backCam = cameras.find(cam => cam.label.toLowerCase().includes('back'));
      if (backCam) {
        cameraSelect.value = backCam.id;
        currentCameraId = backCam.id;
      } else {
        cameraSelect.selectedIndex = 1;
        currentCameraId = cameras[0].id;
      }
    });
    cameraSelect.addEventListener('change', () => {
      currentCameraId = cameraSelect.value;
    });
    startButton.addEventListener('click', () => {
      if (!currentCameraId) {
        alert("Please select a camera.");
        return;
      }
      html5QrCode.start(
        currentCameraId,
        { fps: 10, qrbox: { width: 250, height: 250 } },
        decodedText => {
          resultSpan.textContent = decodedText;
          html5QrCode.stop();
          startButton.disabled = false;
          stopButton.disabled = true;
        },
        error => {
          // decode errors ignored
        }
      ).then(() => {
        startButton.disabled = true;
        stopButton.disabled = false;
      }).catch(err => {
        console.error("Failed to start scanning:", err);
      });
    });
    stopButton.addEventListener('click', () => {
      html5QrCode.stop()
        .then(() => {
          startButton.disabled = false;
          stopButton.disabled = true;
        })
        .catch(err => console.error("Failed to stop scanning:", err));
    });
  </script>
</body>
</html>

