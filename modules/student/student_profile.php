<?php
/** @phpstan-ignore-file */
include '../../config/database.php';
// Check if student is logged in
session_start();
if (!isset($_SESSION['student_username'])) {
    header("Location: student_login.php");
    exit;
}
$student_id = $_SESSION['student_id'];
// Handle profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_email'])) {
        $newEmail = filter_var($_POST['new_email'], FILTER_SANITIZE_EMAIL);
        if (filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            // Update email
            // Update email
            pg_query($connection, "UPDATE students SET email = '" . pg_escape_string($connection, $newEmail) . "' WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
            // Student notification
            $msg = 'Your email has been changed to ' . $newEmail . '.';
            // Student notification
            pg_query($connection, "INSERT INTO notifications (student_id, message) VALUES ('" . pg_escape_string($connection, $student_id) . "', '" . pg_escape_string($connection, $msg) . "')");
            // Admin notification
            // Fetch name for admin notification
            $nameRes = pg_query($connection, "SELECT first_name, last_name FROM students WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
            $nm = pg_fetch_assoc($nameRes);
            $adminMsg = $nm['first_name'] . ' ' . $nm['last_name'] . ' (' . $student_id . ') updated email to ' . $newEmail . '.';
            pg_query($connection, "INSERT INTO admin_notifications (message) VALUES ('" . pg_escape_string($connection,$adminMsg) . "')");
            $_SESSION['profile_flash'] = 'Email updated successfully.';
        }
    }
    if (isset($_POST['update_mobile'])) {
        $newMobile = preg_replace('/\D/', '', $_POST['new_mobile']);
        if ($newMobile) {
            // Update mobile
            pg_query($connection, "UPDATE students SET mobile = '" . pg_escape_string($connection, $newMobile) . "' WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
            $msg = 'Your mobile number has been changed to ' . $newMobile . '.';
            // Student notification for mobile
            pg_query($connection, "INSERT INTO notifications (student_id, message) VALUES ('" . pg_escape_string($connection, $student_id) . "', '" . pg_escape_string($connection, $msg) . "')");
            // Fetch name for admin notification on mobile
            $nameRes = pg_query($connection, "SELECT first_name, last_name FROM students WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
            $nm = pg_fetch_assoc($nameRes);
            $adminMsg = $nm['first_name'] . ' ' . $nm['last_name'] . ' (' . $student_id . ') updated mobile to ' . $newMobile . '.';
            pg_query($connection, "INSERT INTO admin_notifications (message) VALUES ('" . pg_escape_string($connection,$adminMsg) . "')");
            $_SESSION['profile_flash'] = 'Mobile number updated successfully.';
        }
    }
    header("Location: student_profile.php"); exit;
}
// Fetch student data
$stuRes = pg_query($connection, "SELECT first_name, middle_name, last_name, bdate, email, mobile FROM students WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
$student = pg_fetch_assoc($stuRes);
// Flash message
$flash = $_SESSION['profile_flash'] ?? '';
unset($_SESSION['profile_flash']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Profile</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/homepage.css" />
</head>
<body>
  <div class="container-fluid">
    <div class="row">
      <!-- Include Sidebar -->
      <?php include '../../includes/student/student_sidebar.php' ?>
      <!-- Main Content Area -->
      <section class="home-section" id="page-content-wrapper">
        <nav class="px-4 py-3"><i class="bi bi-list" id="menu-toggle"></i></nav>
        <div class="container py-5">
          <?php if ($flash): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($flash); ?></div>
          <?php endif; ?>
          <div class="card mb-4 p-4">
            <h4>Profile Information</h4>
            <table class="table borderless">
              <tr><th>Full Name</th><td><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_name']); ?></td></tr>
              <tr><th>Date of Birth</th><td><?php echo htmlspecialchars($student['bdate']); ?></td></tr>
              <tr><th>Email</th><td><?php echo htmlspecialchars($student['email']); ?> <button class="btn btn-sm btn-link" data-bs-toggle="modal" data-bs-target="#emailModal">Edit</button></td></tr>
              <tr><th>Mobile</th><td><?php echo htmlspecialchars($student['mobile']); ?> <button class="btn btn-sm btn-link" data-bs-toggle="modal" data-bs-target="#mobileModal">Edit</button></td></tr>
            </table>
          </div>
          <!-- Email Modal -->
          <div class="modal fade" id="emailModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content">
            <div class="modal-header"><h5>Edit Email</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><input type="email" name="new_email" class="form-control" value="<?php echo htmlspecialchars($student['email']); ?>" required></div>
            <div class="modal-footer"><button type="submit" name="update_email" class="btn btn-primary" onclick="return confirm('Change email?');">Save</button></div>
          </form></div></div>
          <!-- Mobile Modal -->
          <div class="modal fade" id="mobileModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content">
            <div class="modal-header"><h5>Edit Mobile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><input type="text" name="new_mobile" class="form-control" value="<?php echo htmlspecialchars($student['mobile']); ?>" required></div>
            <div class="modal-footer"><button type="submit" name="update_mobile" class="btn btn-primary" onclick="return confirm('Change mobile number?');">Save</button></div>
          </form></div></div>
        </div>
      </section>
    </div>
  </div>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/homepage.js"></script>
</body>
</html>