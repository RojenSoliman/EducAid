<?php
include('phpqrcode/qrlib.php'); // Include the PHP QR code library

// Check if form is submitted
if (isset($_POST['generate'])) {
    // Generate a unique identifier (e.g., random string or timestamp)
    $unique_id = uniqid('qr_', true); // Generates a unique identifier based on the current timestamp

    // Store the unique ID in a session to pass it to the image
    session_start();
    $_SESSION['unique_id'] = $unique_id;

    // Redirect to the same page to show the generated QR code
    header('Location: generate_qr.php');
    exit;
}

// Check if there is a unique ID in the session
if (isset($_SESSION['unique_id'])) {
    $unique_id = $_SESSION['unique_id'];
    // Set the content type to image/png for generating the QR code
    header('Content-Type: image/png');
    QRcode::png($unique_id);  // Generate and output the QR code image directly to the browser
    exit; // Stop further script execution to avoid additional output
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unique QR Code Generator</title>
    <link rel="stylesheet" href="qrcode.css"> <!-- Link to qrcode.css -->
</head>
<body>

    <div class="container">
        <h1>Generate Unique QR Code</h1>
        <form action="generate_qr.php" method="POST">
            <button type="submit" name="generate">Generate QR Code</button>
        </form>

        <!-- Display QR Code after submission -->
        <?php
        if (isset($_SESSION['unique_id'])) {
            // Get the unique ID from the session to create the image URL
            echo "<h3>Your Unique QR Code:</h3>";
            // Dynamically generate the QR code by passing the unique identifier as a URL parameter
            echo "<img src='generate_qr.php' alt='Generated QR Code'>"; // The image source is the same page that generates the QR code
        }
        ?>
    </div>

</body>
</html>
