<?php
session_start();
$_SESSION['admin_username'] = 'admin';
$_SESSION['admin_id'] = 1;
$_GET['student_id'] = 'GENERALTRIAS-2025-3-P6BE0U';
include 'get_applicant_details.php';
