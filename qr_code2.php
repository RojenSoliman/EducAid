<?php
session_start();

// Initialize or retrieve QR code list
if (!isset($_SESSION['qr_codes'])) {
    $_SESSION['qr_codes'] = [];
}

// Generate a new QR entry
if (isset($_POST['generate'])) {
    $payroll_number = rand(1, 50);
    $unique_id = uniqid('qr_');
    $_SESSION['qr_codes'][] = [
        'payroll_number' => $payroll_number,
        'unique_id' => $unique_id,
        'status' => 'Pending'
    ];
}

// Remove a specific QR
if (isset($_POST['remove'])) {
    $index = $_POST['remove'];
    unset($_SESSION['qr_codes'][$index]);
    $_SESSION['qr_codes'] = array_values($_SESSION['qr_codes']);
}

// Reset all
if (isset($_POST['reset'])) {
    unset($_SESSION['qr_codes']);
}

// Mark scanned
if (isset($_POST['scan'])) {
    $index = $_POST['scan'];
    $_SESSION['qr_codes'][$index]['status'] = 'Done';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Generate Unique QR Code</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body {
      font-family: Arial, sans-serif;
      margin: 0;
      padding: 0;
      background: #f4f7f6;
    }
    .container {
      max-width: 800px;
      margin: 2rem auto;
      padding: 20px 30px;
      background-color: #fff;
      border-radius: 8px;
      box-shadow: 0 5px 15px rgba(0,0,0,0.1);
      text-align: center;
    }
    button, .download-button {
      background-color: #4CAF50;
      color: white;
      padding: 10px 20px;
      margin: 8px 5px;
      border: none;
      border-radius: 5px;
      font-size: 16px;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
    }
    button:hover, .download-button:hover {
      background-color: #45a049;
    }
    .qr-container {
      margin-top: 20px;
    }
    img {
      margin-top: 10px;
      width: 200px;
      height: 200px;
      border: 1px solid #ccc;
      border-radius: 6px;
      padding: 5px;
    }
    .payroll-number {
      font-size: 18px;
      font-weight: bold;
      color: #333;
    }
    table {
      width: 100%;
      margin-top: 30px;
      border-collapse: collapse;
    }
    th, td {
      padding: 10px;
      text-align: center;
      border: 1px solid #ddd;
    }
    th {
      background-color: #f2f2f2;
    }
    .remove-button { background-color: #dc3545; }
    .remove-button:hover { background-color: #c82333; }
    .reset-button { background-color: #17a2b8; }
    .reset-button:hover { background-color: #138496; }
    .scan-button { background-color: #007bff; }
    .scan-button:hover { background-color: #0056b3; }
    .done-status { border: 2px solid green; color: green; }
    .fade-in { animation: fadeIn 0.8s ease-in-out; }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 600px) {
      .container { width: 95%; padding: 15px; }
      img { width: 150px; height: 150px; }
      button, .download-button { font-size: 14px; padding: 8px 14px; }
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>Generate Unique QR Code</h1>

    <form method="post">
      <button type="submit" name="generate">Generate QR Code</button>
    </form>

    <?php if (isset($_POST['generate'])): ?>
      <?php
        $last = $_SESSION['qr_codes'][count($_SESSION['qr_codes']) - 1];
        $qr_url = "phpqrcode/generate_qr.php?data=" . urlencode($last['unique_id']);
      ?>
      <div class="qr-container fade-in">
        <div class="payroll-number">Payroll Number: <?= $last['payroll_number'] ?></div>
        <h2>Your Unique QR Code</h2>
        <img src="<?= $qr_url ?>" alt="Generated QR Code">
        <a href="<?= $qr_url ?>" download="qr_<?= $last['payroll_number'] ?>.png" class="download-button">Download QR</a>
      </div>
    <?php endif; ?>

    <h2>Generated QR Codes List</h2>
    <table>
      <thead>
        <tr>
          <th>Payroll Number</th>
          <th>Unique ID</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($_SESSION['qr_codes'] as $index => $qr): ?>
          <tr class="<?= ($qr['status'] == 'Done') ? 'done-status' : ''; ?>">
            <td><?= $qr['payroll_number'] ?></td>
            <td><?= $qr['unique_id'] ?></td>
            <td><?= $qr['status'] ?></td>
            <td>
              <?php if ($qr['status'] != 'Done'): ?>
                <form method="post" style="display:inline;">
                  <button type="submit" name="scan" value="<?= $index ?>" class="scan-button">Scan</button>
                </form>
              <?php endif; ?>
              <form method="post" style="display:inline;">
                <button type="submit" name="remove" value="<?= $index ?>" class="remove-button">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <form method="post">
      <button type="submit" name="reset" class="reset-button">Reset All</button>
    </form>
  </div>
</body>
</html>
