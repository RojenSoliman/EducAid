Student Profile.php
<?php
/** @phpstan-ignore-file */
include '../../config/database.php';
session_start();
if (!isset($_SESSION['student_username'])) {
    header("Location: student_login.php");
    exit;
}
$student_id = $_SESSION['student_id'];

// PHPMailer setup
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php';

// --------- Handle AJAX OTP Requests -----------
// Email Change OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    // --- OTP Send ---
    if ($_POST['ajax'] === 'send_otp' && isset($_POST['new_email'])) {
        $newEmail = filter_var($_POST['new_email'], FILTER_SANITIZE_EMAIL);
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
            exit;
        }
        // Check if email already used by another student (exclude current)
        $res = pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1 AND student_id != $2", [$newEmail, $student_id]);
        if (pg_num_rows($res) > 0) {
            echo json_encode(['status' => 'error', 'message' => 'This email is already registered.']);
            exit;
        }
        $otp = rand(100000, 999999);
        $_SESSION['profile_otp'] = $otp;
        $_SESSION['profile_otp_email'] = $newEmail;
        $_SESSION['profile_otp_time'] = time();

        // PHPMailer send
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'dilucayaka02@gmail.com'; // CHANGE
            $mail->Password   = 'jlld eygl hksj flvg';    // CHANGE
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->setFrom('dilucayaka02@gmail.com', 'EducAid');
            $mail->addAddress($newEmail);
            $mail->isHTML(true);
            $mail->Subject = 'EducAid Email Change OTP';
            $mail->Body    = "Your One-Time Password (OTP) for updating your EducAid email is: <strong>$otp</strong><br><br>This OTP is valid for 40 seconds.";
            $mail->AltBody = "Your OTP for updating your EducAid email is: $otp. This OTP is valid for 40 seconds.";
            $mail->send();
            echo json_encode(['status' => 'success', 'message' => 'OTP sent! Please check your email.']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to send OTP. Please try again.']);
        }
        exit;
    }

    // --- OTP Verify ---
    if ($_POST['ajax'] === 'verify_otp' && isset($_POST['otp']) && isset($_POST['new_email'])) {
        $enteredOtp = filter_var($_POST['otp'], FILTER_SANITIZE_NUMBER_INT);
        $email = filter_var($_POST['new_email'], FILTER_SANITIZE_EMAIL);

        if (!isset($_SESSION['profile_otp'], $_SESSION['profile_otp_email'], $_SESSION['profile_otp_time'])) {
            echo json_encode(['status' => 'error', 'message' => 'No OTP sent or session expired.']);
            exit;
        }
        if ($_SESSION['profile_otp_email'] !== $email) {
            echo json_encode(['status' => 'error', 'message' => 'Email mismatch.']);
            exit;
        }
        if ((time() - $_SESSION['profile_otp_time']) > 40) {
            unset($_SESSION['profile_otp'], $_SESSION['profile_otp_email'], $_SESSION['profile_otp_time'], $_SESSION['profile_otp_verified']);
            echo json_encode(['status' => 'error', 'message' => 'OTP expired. Please resend.']);
            exit;
        }
        if ((int)$enteredOtp === (int)$_SESSION['profile_otp']) {
            $_SESSION['profile_otp_verified'] = true;
            echo json_encode(['status' => 'success', 'message' => 'OTP verified!']);
            unset($_SESSION['profile_otp'], $_SESSION['profile_otp_time']);
        } else {
            $_SESSION['profile_otp_verified'] = false;
            echo json_encode(['status' => 'error', 'message' => 'Invalid OTP.']);
        }
        exit;
    }
}

// --------- Handle AJAX OTP for Change Password -----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_pwd'])) {
    header('Content-Type: application/json');
    // --- OTP Send ---
    if ($_POST['ajax_pwd'] === 'send_otp_pwd') {
        $currentPwd = $_POST['current_password'] ?? '';
        // Fetch hashed password
        $pwdRes = pg_query($connection, "SELECT password FROM students WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
        $pwdRow = pg_fetch_assoc($pwdRes);

        // Current password must be correct!
        if (!$pwdRow || empty($currentPwd) || !password_verify($currentPwd, $pwdRow['password'])) {
            echo json_encode(['status' => 'error', 'message' => 'Current password is incorrect.', 'target' => 'currentPwdInput']);
            exit;
        }

        // New password (sent by JS) is not same as current
        if (isset($_POST['new_password']) && $currentPwd === $_POST['new_password']) {
            echo json_encode(['status' => 'error', 'message' => 'The password is already in use.', 'target' => 'newPwdInput']);
            exit;
        }

        // Email
        $stuRes = pg_query($connection, "SELECT email FROM students WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
        $stu = pg_fetch_assoc($stuRes);
        $email = $stu['email'];
        if (!$email) {
            echo json_encode(['status' => 'error', 'message' => 'No email found.']);
            exit;
        }
        $otp = rand(100000, 999999);
        $_SESSION['change_pwd_otp'] = $otp;
        $_SESSION['change_pwd_otp_time'] = time();

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'dilucayaka02@gmail.com'; // CHANGE
            $mail->Password   = 'jlld eygl hksj flvg';    // CHANGE
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->setFrom('dilucayaka02@gmail.com', 'EducAid');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'EducAid Change Password OTP';
            $mail->Body    = "Your One-Time Password (OTP) for changing your EducAid password is: <strong>$otp</strong><br><br>This OTP is valid for 40 seconds.";
            $mail->AltBody = "Your OTP for changing your EducAid password is: $otp. This OTP is valid for 40 seconds.";
            $mail->send();
            echo json_encode(['status' => 'success', 'message' => 'OTP sent! Please check your email.']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to send OTP. Please try again.']);
        }
        exit;
    }

    // --- OTP Verify ---
    if ($_POST['ajax_pwd'] === 'verify_otp_pwd' && isset($_POST['otp'])) {
        $enteredOtp = filter_var($_POST['otp'], FILTER_SANITIZE_NUMBER_INT);
        if (!isset($_SESSION['change_pwd_otp'], $_SESSION['change_pwd_otp_time'])) {
            echo json_encode(['status' => 'error', 'message' => 'No OTP sent or session expired.', 'target' => 'otpPwdInput']);
            exit;
        }
        if ((time() - $_SESSION['change_pwd_otp_time']) > 40) {
            unset($_SESSION['change_pwd_otp'], $_SESSION['change_pwd_otp_time'], $_SESSION['change_pwd_otp_verified']);
            echo json_encode(['status' => 'error', 'message' => 'OTP expired. Please resend.', 'target' => 'otpPwdInput']);
            exit;
        }
        if ((int)$enteredOtp === (int)$_SESSION['change_pwd_otp']) {
            $_SESSION['change_pwd_otp_verified'] = true;
            echo json_encode(['status' => 'success', 'message' => 'OTP verified!']);
            unset($_SESSION['change_pwd_otp'], $_SESSION['change_pwd_otp_time']);
        } else {
            $_SESSION['change_pwd_otp_verified'] = false;
            echo json_encode(['status' => 'error', 'message' => 'Invalid OTP.', 'target' => 'otpPwdInput']);
        }
        exit;
    }
}

// --------- Handle Profile Update (Email) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_email'])) {
    $newEmail = filter_var($_POST['new_email'], FILTER_SANITIZE_EMAIL);

    // Require OTP verification for email change
    if (!isset($_SESSION['profile_otp_verified']) || $_SESSION['profile_otp_verified'] !== true || $_SESSION['profile_otp_email'] !== $newEmail) {
        $_SESSION['profile_flash'] = 'Please complete OTP verification for this email.';
        $_SESSION['profile_flash_type'] = 'error';
        header("Location: student_profile.php");
        exit;
    }

    if (filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        pg_query($connection, "UPDATE students SET email = '" . pg_escape_string($connection, $newEmail) . "' WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
        $msg = 'Your email has been changed to ' . $newEmail . '.';
        pg_query($connection, "INSERT INTO notifications (student_id, message) VALUES ('" . pg_escape_string($connection, $student_id) . "', '" . pg_escape_string($connection, $msg) . "')");
        $nameRes = pg_query($connection, "SELECT first_name, last_name FROM students WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
        $nm = pg_fetch_assoc($nameRes);
        $adminMsg = $nm['first_name'] . ' ' . $nm['last_name'] . ' (' . $student_id . ') updated email to ' . $newEmail . '.';
        pg_query($connection, "INSERT INTO admin_notifications (message) VALUES ('" . pg_escape_string($connection,$adminMsg) . "')");
        $_SESSION['profile_flash'] = 'Email updated successfully.';
        $_SESSION['profile_flash_type'] = 'success';
        unset($_SESSION['profile_otp_email'], $_SESSION['profile_otp_verified']);
    }
    header("Location: student_profile.php"); exit;
}

// --------- Handle Mobile Number Update ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_mobile'])) {
    $newMobile = preg_replace('/\D/', '', $_POST['new_mobile']);
    if ($newMobile) {
        pg_query($connection, "UPDATE students SET mobile = '" . pg_escape_string($connection, $newMobile) . "' WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
        $msg = 'Your mobile number has been changed to ' . $newMobile . '.';
        pg_query($connection, "INSERT INTO notifications (student_id, message) VALUES ('" . pg_escape_string($connection, $student_id) . "', '" . pg_escape_string($connection, $msg) . "')");
        $nameRes = pg_query($connection, "SELECT first_name, last_name FROM students WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
        $nm = pg_fetch_assoc($nameRes);
        $adminMsg = $nm['first_name'] . ' ' . $nm['last_name'] . ' (' . $student_id . ') updated mobile to ' . $newMobile . '.';
        pg_query($connection, "INSERT INTO admin_notifications (message) VALUES ('" . pg_escape_string($connection,$adminMsg) . "')");
        $_SESSION['profile_flash'] = 'Mobile number updated successfully.';
        $_SESSION['profile_flash_type'] = 'success';
    }
    header("Location: student_profile.php"); exit;
}

// --------- Handle Change Password Submission ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $currentPwd = $_POST['current_password'] ?? '';
    $newPwd = $_POST['new_password'] ?? '';
    $confirmPwd = $_POST['confirm_password'] ?? '';

    // Must verify OTP
    if (!isset($_SESSION['change_pwd_otp_verified']) || $_SESSION['change_pwd_otp_verified'] !== true) {
        $_SESSION['profile_flash'] = 'Please complete OTP verification before changing your password.';
        $_SESSION['profile_flash_type'] = 'error';
        header("Location: student_profile.php");
        exit;
    }

    // Validate passwords
    if (strlen($newPwd) < 12) {
        $_SESSION['profile_flash'] = 'Password must be at least 12 characters.';
        $_SESSION['profile_flash_type'] = 'error';
        header("Location: student_profile.php");
        exit;
    }
    if ($newPwd !== $confirmPwd) {
        $_SESSION['profile_flash'] = 'Passwords do not match.';
        $_SESSION['profile_flash_type'] = 'error';
        header("Location: student_profile.php");
        exit;
    }
    if ($currentPwd === $newPwd) {
        $_SESSION['profile_flash'] = 'The password is already in use.';
        $_SESSION['profile_flash_type'] = 'error';
        header("Location: student_profile.php");
        exit;
    }

    // Check old password
    $pwdRes = pg_query($connection, "SELECT password FROM students WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
    $pwdRow = pg_fetch_assoc($pwdRes);
    if (!$pwdRow || !password_verify($currentPwd, $pwdRow['password'])) {
        $_SESSION['profile_flash'] = 'Current password is incorrect.';
        $_SESSION['profile_flash_type'] = 'error';
        header("Location: student_profile.php");
        exit;
    }

    $hashed = password_hash($newPwd, PASSWORD_ARGON2ID);
    pg_query($connection, "UPDATE students SET password = '" . pg_escape_string($connection, $hashed) . "' WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
    $msg = 'Your password was changed successfully.';
    pg_query($connection, "INSERT INTO notifications (student_id, message) VALUES ('" . pg_escape_string($connection, $student_id) . "', '" . pg_escape_string($connection, $msg) . "')");
    $_SESSION['profile_flash'] = 'Password changed successfully.';
    $_SESSION['profile_flash_type'] = 'success';
    unset($_SESSION['change_pwd_otp_verified']);
    header("Location: student_profile.php");
    exit;
}

// Fetch student data
$stuRes = pg_query($connection, "SELECT first_name, middle_name, last_name, bdate, email, mobile FROM students WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
$student = pg_fetch_assoc($stuRes);

// Flash message
$flash = $_SESSION['profile_flash'] ?? '';
$flash_type = $_SESSION['profile_flash_type'] ?? '';
unset($_SESSION['profile_flash'], $_SESSION['profile_flash_type']);
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
  <link rel="stylesheet" href="../../assets/css/student/sidebar.css" />
  <style>
    .verified-indicator { color: #28a745; font-weight: bold; }
    .form-error { color:#e14343; font-size: 0.92em; font-weight: 500; min-width: 90px; text-align: left; }
    .form-success { color:#41d87d; font-size: 0.92em; font-weight: 500; min-width: 90px; text-align: left; }
  </style>
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
          <div class="card mb-4 p-4">
            <h4>Profile Information</h4>
            <table class="table borderless">
              <tr><th>Full Name</th><td><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_name']); ?></td></tr>
              <tr><th>Date of Birth</th><td><?php echo htmlspecialchars($student['bdate']); ?></td></tr>
              <tr>
                <th>Email</th>
                <td>
                  <?php echo htmlspecialchars($student['email']); ?>
                  <button class="btn btn-sm btn-link" data-bs-toggle="modal" data-bs-target="#emailModal">Edit</button>
                </td>
              </tr>
              <tr><th>Mobile</th><td><?php echo htmlspecialchars($student['mobile']); ?> <button class="btn btn-sm btn-link" data-bs-toggle="modal" data-bs-target="#mobileModal">Edit</button></td></tr>
              <tr>
                <th>Password</th>
                <td>
                  ************
                  <button class="btn btn-sm btn-link" data-bs-toggle="modal" data-bs-target="#passwordModal">Change Password</button>
                </td>
              </tr>
            </table>
          </div>
          <!-- Email Modal with OTP -->
          <div class="modal fade" id="emailModal" tabindex="-1">
            <div class="modal-dialog">
              <form id="emailUpdateForm" method="POST" class="modal-content">
                <div class="modal-header">
                  <h5>Edit Email</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3 position-relative">
                    <label>New Email Address</label>
                    <input type="email" name="new_email" id="newEmailInput" class="form-control" required>
                    <span id="emailOtpStatus" class="form-error position-absolute" style="right:15px;top:35px;"></span>
                  </div>
                  <div id="otpSection" style="display:none;">
                    <div class="mb-3 position-relative">
                      <label>Enter OTP</label>
                      <input type="text" id="otpInput" class="form-control" maxlength="6" autocomplete="off">
                      <span id="otpInputError" class="form-error position-absolute" style="right:15px;top:35px;"></span>
                    </div>
                    <button type="button" class="btn btn-info w-100 mb-2" id="verifyOtpBtn">Verify OTP</button>
                    <div id="otpTimer" class="text-danger mt-2"></div>
                    <button type="button" class="btn btn-link" id="resendOtpBtn" style="display:none;">Resend OTP</button>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button type="button" id="sendOtpBtn" class="btn btn-primary">Send OTP</button>
                  <button type="submit" name="update_email" id="saveEmailBtn" class="btn btn-success" style="display:none;">Save</button>
                </div>
              </form>
            </div>
          </div>
          <!-- Mobile Modal -->
          <div class="modal fade" id="mobileModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content">
            <div class="modal-header"><h5>Edit Mobile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><input type="text" name="new_mobile" class="form-control" value="<?php echo htmlspecialchars($student['mobile']); ?>" required></div>
            <div class="modal-footer"><button type="submit" name="update_mobile" class="btn btn-primary" onclick="return confirm('Change mobile number?');">Save</button></div>
          </form></div></div>
          <!-- Change Password Modal with OTP -->
          <div class="modal fade" id="passwordModal" tabindex="-1">
            <div class="modal-dialog">
              <form id="passwordUpdateForm" method="POST" class="modal-content" autocomplete="off">
                <div class="modal-header">
                  <h5>Change Password</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3 position-relative">
                    <label>Current Password</label>
                    <input type="password" name="current_password" id="currentPwdInput" class="form-control" required minlength="8">
                    <span id="currentPwdError" class="form-error position-absolute" style="right:15px;top:35px;"></span>
                  </div>
                  <div class="mb-3 position-relative">
                    <label>New Password</label>
                    <input type="password" name="new_password" id="newPwdInput" class="form-control" required minlength="12">
                    <span id="newPwdError" class="form-error position-absolute" style="right:15px;top:35px;"></span>
                  </div>
                  <div class="mb-3 position-relative">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirmPwdInput" class="form-control" required minlength="12">
                    <span id="confirmPwdError" class="form-error position-absolute" style="right:15px;top:35px;"></span>
                  </div>
                  <div id="otpPwdSection" style="display:none;">
                    <div class="mb-3 position-relative">
                      <label>Enter OTP</label>
                      <input type="text" id="otpPwdInput" class="form-control" maxlength="6" autocomplete="off">
                      <span id="otpPwdError" class="form-error position-absolute" style="right:15px;top:35px;"></span>
                    </div>
                    <button type="button" class="btn btn-info w-100 mb-2" id="verifyOtpPwdBtn">Verify OTP</button>
                    <div id="otpPwdTimer" class="text-danger mt-2"></div>
                    <button type="button" class="btn btn-link" id="resendOtpPwdBtn" style="display:none;">Resend OTP</button>
                  </div>
                </div>
                <div class="modal-footer">
                  <span id="otpPwdStatus" class="ms-2"></span>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button type="button" id="sendOtpPwdBtn" class="btn btn-primary">Send OTP</button>
                  <button type="submit" name="update_password" id="savePwdBtn" class="btn btn-success" style="display:none;">Save</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/homepage.js"></script>
  <script>
    // OTP logic for email change
    let otpCountdown, secondsLeft = 0;
    let otpVerified = false;
    function startOtpTimer() {
      secondsLeft = 40;
      document.getElementById('otpTimer').textContent = `Time left: ${secondsLeft} seconds`;
      clearInterval(otpCountdown);
      otpCountdown = setInterval(() => {
        secondsLeft--;
        document.getElementById('otpTimer').textContent = `Time left: ${secondsLeft} seconds`;
        if (secondsLeft <= 0) {
          clearInterval(otpCountdown);
          document.getElementById('otpTimer').textContent = "OTP expired. Please resend.";
          document.getElementById('verifyOtpBtn').disabled = true;
          document.getElementById('resendOtpBtn').style.display = 'inline-block';
        }
      }, 1000);
    }

    document.getElementById('sendOtpBtn').onclick = function(e) {
      e.preventDefault();
      const email = document.getElementById('newEmailInput').value;
      document.getElementById('emailOtpStatus').textContent = '';
      if (!email || !/\S+@\S+\.\S+/.test(email)) {
        document.getElementById('emailOtpStatus').textContent = "Enter a valid email.";
        return;
      }
      this.disabled = true;
      this.textContent = "Sending...";
      fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `ajax=send_otp&new_email=${encodeURIComponent(email)}`
      }).then(res => res.json()).then(data => {
        if (data.status === 'success') {
          document.getElementById('emailOtpStatus').textContent = "OTP sent! Check email.";
          document.getElementById('emailOtpStatus').className = 'form-success position-absolute';
          document.getElementById('otpSection').style.display = 'block';
          document.getElementById('verifyOtpBtn').disabled = false;
          document.getElementById('resendOtpBtn').style.display = 'none';
          startOtpTimer();
        } else {
          document.getElementById('emailOtpStatus').textContent = data.message;
          document.getElementById('emailOtpStatus').className = 'form-error position-absolute';
          this.disabled = false;
          this.textContent = "Send OTP";
        }
      }).catch(()=>{
        document.getElementById('emailOtpStatus').textContent = "Failed to send. Try again.";
        document.getElementById('emailOtpStatus').className = 'form-error position-absolute';
        this.disabled = false; this.textContent = "Send OTP";
      });
    };

    document.getElementById('resendOtpBtn').onclick = function() {
      document.getElementById('sendOtpBtn').disabled = false;
      document.getElementById('sendOtpBtn').textContent = "Send OTP";
      document.getElementById('otpSection').style.display = 'none';
      document.getElementById('emailOtpStatus').textContent = '';
    };

    document.getElementById('verifyOtpBtn').onclick = function() {
      const otp = document.getElementById('otpInput').value;
      const email = document.getElementById('newEmailInput').value;
      document.getElementById('otpInputError').textContent = '';
      if (!otp) {
        document.getElementById('otpInputError').textContent = "Enter the OTP.";
        return;
      }
      this.disabled = true; this.textContent = "Verifying...";
      fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `ajax=verify_otp&otp=${encodeURIComponent(otp)}&new_email=${encodeURIComponent(email)}`
      }).then(res=>res.json()).then(data=>{
        if (data.status === 'success') {
          document.getElementById('emailOtpStatus').textContent = "OTP verified!";
          document.getElementById('emailOtpStatus').className = 'form-success position-absolute';
          otpVerified = true;
          document.getElementById('otpInput').disabled = true;
          document.getElementById('saveEmailBtn').style.display = 'inline-block';
          document.getElementById('sendOtpBtn').style.display = 'none';
          document.getElementById('verifyOtpBtn').style.display = 'none';
          document.getElementById('resendOtpBtn').style.display = 'none';
          clearInterval(otpCountdown);
        } else {
          document.getElementById('otpInputError').textContent = data.message;
          this.disabled = false; this.textContent = "Verify OTP";
          otpVerified = false;
        }
      }).catch(()=>{
        document.getElementById('otpInputError').textContent = "Failed to verify. Try again.";
        this.disabled = false; this.textContent = "Verify OTP";
      });
    };

    document.getElementById('emailUpdateForm').onsubmit = function(e) {
      if (!otpVerified) {
        document.getElementById('emailOtpStatus').textContent = "You must verify OTP before saving.";
        document.getElementById('emailOtpStatus').className = 'form-error position-absolute';
        e.preventDefault();
        return false;
      }
    };

    // Password OTP logic for change password modal
    let otpPwdCountdown, otpPwdSecondsLeft = 0;
    let otpPwdVerified = false;

    function startOtpPwdTimer() {
      otpPwdSecondsLeft = 40;
      document.getElementById('otpPwdTimer').textContent = `Time left: ${otpPwdSecondsLeft} seconds`;
      clearInterval(otpPwdCountdown);
      otpPwdCountdown = setInterval(() => {
        otpPwdSecondsLeft--;
        document.getElementById('otpPwdTimer').textContent = `Time left: ${otpPwdSecondsLeft} seconds`;
        if (otpPwdSecondsLeft <= 0) {
          clearInterval(otpPwdCountdown);
          document.getElementById('otpPwdTimer').textContent = "OTP expired. Please resend.";
          document.getElementById('verifyOtpPwdBtn').disabled = true;
          document.getElementById('resendOtpPwdBtn').style.display = 'inline-block';
        }
      }, 1000);
    }

    function clearFieldErrors() {
      document.getElementById('currentPwdError').textContent = '';
      document.getElementById('newPwdError').textContent = '';
      document.getElementById('confirmPwdError').textContent = '';
      document.getElementById('otpPwdError').textContent = '';
    }

    document.getElementById('sendOtpPwdBtn').onclick = function(e) {
      e.preventDefault();
      clearFieldErrors();
      const currentPwd = document.getElementById('currentPwdInput').value;
      const newPwd = document.getElementById('newPwdInput').value;
      const confirmPwd = document.getElementById('confirmPwdInput').value;

      if (!currentPwd) {
        document.getElementById('currentPwdError').textContent = "Required";
        return;
      }
      if (!newPwd) {
        document.getElementById('newPwdError').textContent = "Required";
        return;
      }
      if (newPwd.length < 12) {
        document.getElementById('newPwdError').textContent = "Min 12 characters";
        return;
      }
      if (!confirmPwd) {
        document.getElementById('confirmPwdError').textContent = "Required";
        return;
      }
      if (newPwd !== confirmPwd) {
        document.getElementById('confirmPwdError').textContent = "Passwords do not match";
        return;
      }

      this.disabled = true;
      this.textContent = "Sending...";

      fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `ajax_pwd=send_otp_pwd&current_password=${encodeURIComponent(currentPwd)}&new_password=${encodeURIComponent(newPwd)}`
      }).then(res => res.json()).then(data => {
        if (data.status === 'success') {
          document.getElementById('otpPwdStatus').innerHTML = "<span class='form-success'>OTP sent! Check your email.</span>";
          document.getElementById('otpPwdSection').style.display = 'block';
          document.getElementById('verifyOtpPwdBtn').disabled = false;
          document.getElementById('resendOtpPwdBtn').style.display = 'none';
          startOtpPwdTimer();
        } else {
          if (data.target) {
            if (data.target === 'currentPwdInput') document.getElementById('currentPwdError').textContent = data.message;
            if (data.target === 'newPwdInput') document.getElementById('newPwdError').textContent = data.message;
            if (data.target === 'otpPwdInput') document.getElementById('otpPwdError').textContent = data.message;
          }
          document.getElementById('otpPwdStatus').innerHTML = `<span class='form-error'>${data.message}</span>`;
          this.disabled = false;
          this.textContent = "Send OTP";
        }
      }).catch(()=>{
        document.getElementById('otpPwdStatus').innerHTML = "<span class='form-error'>Failed to send. Try again.</span>";
        this.disabled = false; this.textContent = "Send OTP";
      });
    };

    document.getElementById('resendOtpPwdBtn').onclick = function() {
      document.getElementById('sendOtpPwdBtn').disabled = false;
      document.getElementById('sendOtpPwdBtn').textContent = "Send OTP";
      document.getElementById('otpPwdSection').style.display = 'none';
      document.getElementById('otpPwdStatus').innerHTML = '';
      clearFieldErrors();
    };

    document.getElementById('verifyOtpPwdBtn').onclick = function() {
      const otp = document.getElementById('otpPwdInput').value;
      document.getElementById('otpPwdError').textContent = '';
      if (!otp) {
        document.getElementById('otpPwdError').textContent = "Required";
        return;
      }
      this.disabled = true; this.textContent = "Verifying...";
      fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `ajax_pwd=verify_otp_pwd&otp=${encodeURIComponent(otp)}`
      }).then(res=>res.json()).then(data=>{
        if (data.status === 'success') {
          document.getElementById('otpPwdStatus').innerHTML = "<span class='form-success'>OTP verified!</span>";
          otpPwdVerified = true;
          document.getElementById('otpPwdInput').disabled = true;
          document.getElementById('savePwdBtn').style.display = 'inline-block';
          document.getElementById('sendOtpPwdBtn').style.display = 'none';
          document.getElementById('verifyOtpPwdBtn').style.display = 'none';
          document.getElementById('resendOtpPwdBtn').style.display = 'none';
          clearInterval(otpPwdCountdown);
        } else {
          if (data.target) document.getElementById(data.target).textContent = data.message;
          document.getElementById('otpPwdStatus').innerHTML = `<span class='form-error'>${data.message}</span>`;
          this.disabled = false; this.textContent = "Verify OTP";
          otpPwdVerified = false;
        }
      }).catch(()=>{
        document.getElementById('otpPwdStatus').innerHTML = "<span class='form-error'>Failed to verify. Try again.</span>";
        this.disabled = false; this.textContent = "Verify OTP";
      });
    };

    document.getElementById('passwordUpdateForm').onsubmit = function(e) {
      if (!otpPwdVerified) {
        document.getElementById('otpPwdStatus').innerHTML = "<span class='form-error'>You must verify OTP before saving.</span>";
        e.preventDefault();
        return false;
      }
    };
  </script>
</body>
</html>
