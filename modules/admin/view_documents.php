<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

include '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_id'])) {
    $student_id = $_POST['student_id'];

    // Mark student as 'verified' once all documents are uploaded
    $updateQuery = "UPDATE students SET status = 'active' WHERE student_id = $1";
    pg_query_params($connection, $updateQuery, [$student_id]);

    echo "<script>alert('Student marked as verified.'); window.location.href = 'manage_applicants.php';</script>";
}
?>