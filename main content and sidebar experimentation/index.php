<?php
    // Simulate user role or session for demonstration purposes
    session_start();
    $userRole = 'admin'; // Hardcoded role for testing (this could be dynamic)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic Content with PHP</title>
    <link rel="stylesheet" href="style.css"> <!-- Link to your CSS file -->
</head>
<body>

    <div class="container">
        <!-- Include Sidebar -->
        <?php include('sidebar.php'); ?>

        <!-- Include Main Content -->
        <?php include('main-content.php'); ?>
    </div>

    <script src="script.js"></script> <!-- JavaScript for Sidebar Toggle -->
</body>
</html>
