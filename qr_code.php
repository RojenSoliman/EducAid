<?php
session_start();

// Initialize or retrieve the array of QR codes and payroll numbers
if (!isset($_SESSION['qr_codes'])) {
    $_SESSION['qr_codes'] = [];
}

// Generate a random payroll number and a unique QR code when the button is clicked
if (isset($_POST['generate'])) {
    $payroll_number = rand(1, 50); // Random payroll number between 1 and 50
    $unique_id = uniqid('qr_'); // Unique ID for the QR code

    // Store the generated QR code and payroll number in session
    $_SESSION['qr_codes'][] = [
        'payroll_number' => $payroll_number,
        'unique_id' => $unique_id,
        'status' => 'Pending' // Default status
    ];
}

// Remove a specific QR code and payroll number from the session
if (isset($_POST['remove'])) {
    $index = $_POST['remove']; // Get index to remove
    unset($_SESSION['qr_codes'][$index]); // Remove entry
    $_SESSION['qr_codes'] = array_values($_SESSION['qr_codes']); // Reindex the array
}

// Reset all QR codes and payroll numbers
if (isset($_POST['reset'])) {
    unset($_SESSION['qr_codes']); // Clear session data
}
// up
// Mark the QR code as scanned by the admin
if (isset($_POST['scan'])) {
    $index = $_POST['scan']; // Get index to update status
    $_SESSION['qr_codes'][$index]['status'] = 'Done'; // Update status to 'Done'
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Unique QR Code with Payroll Number</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background-color: #f4f4f4;
        }
        h1 {
            color: #333;
        }
        button {
            padding: 10px 20px;
            font-size: 16px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
        }
        button:hover {
            background-color: #218838;
        }
        .qr-container {
            text-align: center;
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        img {
            border: 1px solid #ddd;
            padding: 5px;
            margin-top: 10px;
            max-width: 250px;
            height: auto;
        }
        .payroll-number {
            font-size: 20px;
            font-weight: bold;
            color: #333;
        }
        table {
            margin-top: 30px;
            border-collapse: collapse;
            width: 80%;
            max-width: 800px;
        }
        table, th, td {
            border: 1px solid #ddd;
        }
        th, td {
            padding: 8px 12px;
            text-align: center;
        }
        th {
            background-color: #f2f2f2;
        }
        .remove-button {
            background-color: #dc3545;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            padding: 5px 10px;
        }
        .remove-button:hover {
            background-color: #c82333;
        }
        .reset-button {
            background-color: #17a2b8;
            color: white;
            border: none;
            border-radius: 5px;
            padding: 10px 20px;
            margin-top: 20px;
            cursor: pointer;
        }
        .reset-button:hover {
            background-color: #138496;
        }
        .scan-button {
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            padding: 5px 10px;
        }
        .scan-button:hover {
            background-color: #0056b3;
        }
        .done-status {
            border: 2px solid green;
            color: green;
        }
    </style>
</head>
<body>
    <h1>generate unique qr code with payroll number</h1>

    <!-- form to trigger qr code generation -->
    <form action="" method="post">
        <button type="submit" name="generate">generate qr code</button>
    </form>

    <!-- show the generated qr code and payroll number if button clicked -->
    <?php if (isset($_POST['generate'])): ?>
    <div class="qr-container">
        <!-- display payroll number -->
        <div class="payroll-number">payroll number: <?php echo $_SESSION['qr_codes'][count($_SESSION['qr_codes']) - 1]['payroll_number']; ?></div>
        <h2>your unique qr code:</h2>
        <img src="phpqrcode/generate_qr.php" alt="generated qr code">
    </div>
    <?php endif; ?>

    <!-- display the list of generated qr codes and payroll numbers -->
    <div>
        <h2>generated qr codes list:</h2>
        <table>
            <thead>
                <tr>
                    <th>payroll number</th>
                    <th>unique id</th>
                    <th>status</th>
                    <th>action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($_SESSION['qr_codes'] as $index => $qr): ?>
                <tr class="<?php echo ($qr['status'] == 'Done') ? 'done-status' : ''; ?>">
                    <td><?php echo $qr['payroll_number']; ?></td>
                    <td><?php echo $qr['unique_id']; ?></td>
                    <td><?php echo $qr['status']; ?></td>
                    <td>
                        <!-- scan button for each row -->
                        <?php if ($qr['status'] != 'Done'): ?>
                            <form action="" method="post" style="display:inline;">
                                <button type="submit" name="scan" value="<?php echo $index; ?>" class="scan-button">scan</button>
                            </form>
                        <?php endif; ?>
                        <!-- remove button for each row -->
                        <form action="" method="post" style="display:inline;">
                            <button type="submit" name="remove" value="<?php echo $index; ?>" class="remove-button">remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- reset button to clear all qr codes and payroll numbers -->
    <form action="" method="post">
        <button type="submit" name="reset" class="reset-button">reset all</button>
    </form>

</body>
</html>
