<?php
session_start();
if (isset($_SESSION['student_username'])) {
    header("Location: student_homepage.php");
    exit;
}
// Redirect to unified login
header("Location: ../../unified_login.php");
exit;
?>
