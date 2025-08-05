<?php
session_start();
if (isset($_SESSION['admin_username'])) {
    header("Location: homepage.php");
    exit;
}
// Redirect to unified login
header("Location: ../../unified_login.php");
exit;
?>
