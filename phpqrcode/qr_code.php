<?php
// Start the session to access the unique_id stored earlier
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Generator</title>
    <link rel="stylesheet" href="qrcode.css"> <!-- Link to your existing CSS -->
</head>
<body>

    <div class="container">
        <h1>Generate Unique QR Code</h1>
        
        <!-- Form that sends request to generate_qr.php to create a QR code -->
        <form action="qr_generator.php" method="POST">
            <button type="submit" name="generate">Generate QR Code</button>
        </form>

        <!-- Display QR Code after submission -->
        <?php
        // Check if the unique_id session is set
        if (isset($_SESSION['unique_id'])) {
            echo "<h3>Your Unique QR Code:</h3>";
            echo "<img src='generate_qr.php' alt='Generated QR Code'>";  
        }
        ?>
    </div>

</body>
</html>
