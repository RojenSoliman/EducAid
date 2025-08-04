<?php

// scan_qr.php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: admin_login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Scan QR - Admin</title>
    <link href="https://fonts.googleapis.com/css?family=Poppins:400,500,600&display=swap" rel="stylesheet">
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/admin/homepage.css" rel="stylesheet">
    <link href="../../assets/css/admin/sidebar.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
      body { font-family: 'Poppins', sans-serif; }
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
                <!-- Page content goes here -->
                <h2>Scan QR</h2>
                <!-- Add your QR scanning UI here -->
            </div>
        </section>
    </div>
    <script src="../../assets/js/admin/sidebar.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>