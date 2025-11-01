Student Profile.php
<?php
/** @phpstan-ignore-file */
include '../../config/database.php';
session_start();
if (!isset($_SESSION['student_username'])) {
    header("Location: ../../unified_login.php");
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

// --------- Handle Profile Picture Upload (Encrypted) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_picture'])) {
  // Debug logger helper
  $uploadDebugLog = __DIR__ . '/../../data/profile_upload.log';
  $log = function(string $m) use ($uploadDebugLog, $student_id) { @file_put_contents($uploadDebugLog, '['.date('c')."] student={$student_id} ".$m."\n", FILE_APPEND); };
  $log('--- BEGIN UPLOAD HANDLER ---');
  $log('Incoming FILES keys: '.implode(',', array_keys($_FILES)));  
  if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $log('profile_picture received: name='.$_FILES['profile_picture']['name'].' size='.$_FILES['profile_picture']['size'].' tmp='.$_FILES['profile_picture']['tmp_name']);
    $uploadDir = '../../assets/uploads/student_pictures/';
    if (!is_dir($uploadDir)) {
      mkdir($uploadDir, 0755, true);
      $log('Created upload directory '.$uploadDir);
    }
    $fileInfo = pathinfo($_FILES['profile_picture']['name']);
    $fileExtension = strtolower($fileInfo['extension'] ?? 'png');
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    if (in_array($fileExtension, $allowedExtensions)) {
      if ($_FILES['profile_picture']['size'] <= 5 * 1024 * 1024) {
        // Load raw image data
        $rawData = file_get_contents($_FILES['profile_picture']['tmp_name']);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['profile_picture']['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, ['image/jpeg','image/png','image/gif'])) {
          $mime = 'image/png';
        }
        $log('Detected mime='.$mime.' raw_size='.strlen($rawData));
        // ENCRYPTION REMOVED: store plaintext image directly
        try {
          $fileName = $student_id . '_' . time() . '.' . $fileExtension;
          $uploadPath = $uploadDir . $fileName;
          $log('Attempting write (plaintext mode) fileName='.$fileName.' target='.$uploadPath.' size='.strlen($rawData));
          if (file_put_contents($uploadPath, $rawData) !== false) {
          // Delete old picture if exists
          $oldPictureQuery = pg_query_params($connection, "SELECT student_picture FROM students WHERE student_id = $1", [$student_id]);
          $oldPicture = pg_fetch_assoc($oldPictureQuery);
          if ($oldPicture && $oldPicture['student_picture'] && file_exists('../../' . $oldPicture['student_picture'])) {
            @unlink('../../' . $oldPicture['student_picture']);
              $log('Deleted old picture: '.$oldPicture['student_picture']);
          }
          $relativePath = 'assets/uploads/student_pictures/' . $fileName;
          $resUpd = pg_query_params($connection, "UPDATE students SET student_picture = $1 WHERE student_id = $2", [$relativePath, $student_id]);
          if ($resUpd === false) {
            $log('DB update FAILED: '.pg_last_error($connection));
          } else {
            $aff = pg_affected_rows($resUpd);
            $log('DB update OK affected_rows='.$aff.' new_path='.$relativePath);
          }
            $_SESSION['profile_flash'] = 'Profile picture updated successfully.';
          $_SESSION['profile_flash_type'] = 'success';
          // cache bust token
          $_SESSION['profile_pic_cache_bust'] = time();
        } else {
            $_SESSION['profile_flash'] = 'Failed to store picture.';
          $_SESSION['profile_flash_type'] = 'error';
            $log('FAILED writing file to disk');
        }
        } catch (Throwable $e) {
          $_SESSION['profile_flash'] = 'Upload failure: ' . htmlspecialchars($e->getMessage());
          $_SESSION['profile_flash_type'] = 'error';
          $log('Upload exception: '.$e->getMessage());
        }
      } else {
        $_SESSION['profile_flash'] = 'Picture size must be less than 5MB.';
        $_SESSION['profile_flash_type'] = 'error';
        $log('Rejected: file too large size='.$_FILES['profile_picture']['size']);
      }
    } else {
      $_SESSION['profile_flash'] = 'Only JPG, JPEG, PNG, and GIF files are allowed.';
      $_SESSION['profile_flash_type'] = 'error';
      $log('Rejected: invalid extension='.$fileExtension);
    }
  } else {
    $_SESSION['profile_flash'] = 'Please select a picture to upload.';
    $_SESSION['profile_flash_type'] = 'error';
    $log('No file or upload error code='.($_FILES['profile_picture']['error'] ?? 'missing'));    
  }
  $log('--- END UPLOAD HANDLER ---');
  header("Location: student_profile.php");
  exit;
}

// Fetch student data including profile picture
$stuRes = pg_query($connection, "SELECT first_name, middle_name, last_name, bdate, email, mobile, student_picture FROM students WHERE student_id = '" . pg_escape_string($connection, $student_id) . "'");
$student = pg_fetch_assoc($stuRes);

// Get student info for header dropdown
$student_info_query = "SELECT first_name, last_name FROM students WHERE student_id = $1";
$student_info_result = pg_query_params($connection, $student_info_query, [$student_id]);
$student_info = pg_fetch_assoc($student_info_result);

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
  <link href="../../assets/css/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/student/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/student/sidebar.css" />
  <link rel="stylesheet" href="../../assets/css/student/accessibility.css" />
  <script src="../../assets/js/student/accessibility.js"></script>
  <style>
    .verified-indicator { color: #28a745; font-weight: bold; }
    .form-error { color:#e14343; font-size: 0.92em; font-weight: 500; min-width: 90px; text-align: left; }
    .form-success { color:#41d87d; font-size: 0.92em; font-weight: 500; min-width: 90px; text-align: left; }
    /* Ensure header is flush under topbar like other pages */
    .home-section { padding-top: 0 !important; }
    .home-section > .main-header:first-child { margin-top: 0 !important; }
    
    /* Minimal, soft profile header (low contrast, neutral) */
    .profile-header {
      background: linear-gradient(145deg, #f5f7fa 0%, #eef1f4 100%);
      border: 1px solid #e3e7ec;
      border-radius: 16px;
      color: #2f3a49;
      padding: 2.5rem 1.75rem 2rem 1.75rem;
      margin-bottom: 2rem;
      position: relative;
      overflow: hidden;
      text-align: center;
    }

    .profile-header::before,
    .profile-header::after {
      content: '';
      position: absolute;
      border-radius: 50%;
      background: radial-gradient(circle at 30% 30%, rgba(0,0,0,0.04), transparent 70%);
      opacity: 0.6;
      pointer-events: none;
    }
    .profile-header::before { width: 220px; height: 220px; top: -60px; right: -40px; }
    .profile-header::after { width: 160px; height: 160px; bottom: -50px; left: -30px; }

    .profile-avatar {
      width: 160px;
      height: 160px;
      background: linear-gradient(145deg,#ffffff,#f1f3f5);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 3.25rem;
      margin: 0 auto 1.25rem auto;
      border: 1px solid #dcdfe3;
      box-shadow: 0 4px 10px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.06) inset;
      position: relative;
      z-index: 2;
      transition: box-shadow .25s ease, transform .25s ease;
    }
    .profile-avatar:hover {
      box-shadow: 0 6px 14px rgba(0,0,0,0.08), 0 0 0 4px rgba(0,0,0,0.03) inset;
      transform: translateY(-2px);
    }
    .profile-avatar img.profile-image { border-radius: 50%; width: 100%; height: 100%; object-fit: cover; }
    .profile-avatar .bi-person-fill { color: #9aa4b1; }
    .profile-avatar .change-picture-btn {
      background: #ffffff;
      border: 1px solid #cfd5db;
      box-shadow: 0 2px 4px rgba(0,0,0,0.08);
      transition: background .2s ease, border-color .2s ease;
    }
    .profile-avatar .change-picture-btn:hover {
      background: #f4f6f8;
      border-color: #b8c0c7;
    }
    
    .profile-info { position: relative; z-index: 2; }
    .profile-info h2 {
      margin: 0 0 .35rem 0;
      font-weight: 600;
      font-size: 1.95rem;
      letter-spacing: -.5px;
      color: #303a44;
    }
    .profile-info p {
      margin: 0;
      font-size: .95rem;
      color: #5c6773;
      font-weight: 500;
    }
    
    .info-card {
      background: linear-gradient(180deg,#ffffff 0%,#fafbfc 100%);
      border-radius: 14px;
      border: 1px solid #e2e6ea;
      margin-bottom: 1.5rem;
      overflow: hidden;
      transition: border-color .25s ease, box-shadow .25s ease;
      box-shadow: 0 2px 4px rgba(0,0,0,0.03);
    }
    .info-card:hover { border-color:#d5dae0; box-shadow:0 4px 14px rgba(0,0,0,0.06); }
    .info-card-header {
      background:#f5f7f9;
      padding:1.1rem 1.35rem;
      border-bottom:1px solid #e3e7eb;
      display:flex; align-items:center; gap:.75rem;
    }
    .info-card-header h5 { margin:0; color:#313b44; font-weight:600; font-size:1.02rem; letter-spacing:.25px; }
    .info-card-header .bi { color:#7c8792; font-size:1.15rem; }
    .info-card-body { padding:1.35rem 1.4rem 1.25rem 1.4rem; }
    .info-item { display:flex; align-items:flex-start; justify-content:space-between; padding:.65rem 0; border-top:1px dashed #e4e8ec; gap:1rem; }
    .info-item:first-of-type { border-top:none; }
    .info-item:last-child { padding-bottom:.2rem; }
    .info-label { font-weight:600; color:#46515c; font-size:.78rem; text-transform:uppercase; letter-spacing:.5px; min-width:140px; }
    .info-value { flex:1; color:#303a44; margin:0 .75rem; font-weight:500; }
    .info-actions { display:flex; gap:.5rem; align-items:center; }
    .settings-icon-btn { background:#ffffff; border:1px solid #d5dadf; width:40px; height:40px; display:flex; align-items:center; justify-content:center; border-radius:50%; transition:background .2s ease, border-color .2s ease, box-shadow .2s ease; color:#5e6974; }
    .settings-icon-btn:hover { background:#f3f5f7; border-color:#c5cbd1; box-shadow:0 2px 6px rgba(0,0,0,0.05); }
    .avatar-initials { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:2.9rem; font-weight:600; color:#5c6773; user-select:none; }
    
    .btn-edit {
      background: #667eea;
      border-color: #667eea;
      color: white;
      padding: 0.375rem 1rem;
      border-radius: 6px;
      font-size: 0.875rem;
      font-weight: 500;
      transition: all 0.2s ease;
    }
    
    .btn-edit:hover {
      background: #5a67d8;
      border-color: #5a67d8;
      color: white;
      transform: translateY(-1px);
    }
    
    .btn-change-pwd {
      background: #ed8936;
      border-color: #ed8936;
      color: white;
      padding: 0.375rem 1rem;
      border-radius: 6px;
      font-size: 0.875rem;
      font-weight: 500;
      transition: all 0.2s ease;
    }
    
    .btn-change-pwd:hover {
      background: #dd7724;
      border-color: #dd7724;
      color: white;
      transform: translateY(-1px);
    }
    
    /* Modal Improvements */
    .modal-content {
      border-radius: 12px;
      border: none;
      box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    }
    
    /* Fix modal backdrop z-index issues - disable backdrop completely */
    .modal-backdrop {
      display: none !important;
    }
    
    .modal {
      z-index: 1050 !important;
      background: rgba(0, 0, 0, 0.5) !important;
    }
    
    /* Ensure sidebar stays above modal background */
    .sidebar {
      z-index: 1055 !important;
    }
    
    /* Ensure topbar stays above modal background */
    .student-topbar {
      z-index: 1060 !important;
    }
    
    .modal-header {
      background: #f8f9fa;
      border-bottom: 1px solid #e9ecef;
      border-radius: 12px 12px 0 0;
      padding: 1.5rem;
    }
    
    .modal-title {
      font-weight: 600;
      color: #495057;
    }
    
    .modal-body {
      padding: 1.5rem;
    }
    
    .modal-footer {
      border-top: 1px solid #e9ecef;
      padding: 1.5rem;
      background: #f8f9fa;
      border-radius: 0 0 12px 12px;
    }
    
    /* Form Controls */
    .form-control {
      border-radius: 8px;
      border: 1px solid #d1d5db;
      padding: 0.75rem 1rem;
      font-size: 0.95rem;
      transition: all 0.2s ease;
    }
    
    .form-control:focus {
      border-color: #667eea;
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .form-label {
      font-weight: 600;
      color: #374151;
      margin-bottom: 0.5rem;
    }
    
    /* Flash Messages */
    .alert {
      border-radius: 10px;
      padding: 1rem 1.25rem;
      margin-bottom: 1.5rem;
      border: none;
    }
    
    .alert-success {
      background: #d1fae5;
      color: #065f46;
    }
    
    .alert-danger {
      background: #fee2e2;
      color: #991b1b;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .profile-header {
        padding: 1.5rem;
        text-align: center;
      }
      
      .info-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
      }
      
      .info-actions {
        width: 100%;
        justify-content: center;
      }
    }
  </style>
</head>
<body>
  <!-- Student Topbar -->
  <?php include __DIR__ . '/../../includes/student/student_topbar.php'; ?>

  <div id="wrapper" style="padding-top: var(--topbar-h);">
    <!-- Include Sidebar -->
    <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
    
    <!-- Student Header -->
    <?php include __DIR__ . '/../../includes/student/student_header.php'; ?>
    
    <!-- Main Content Area -->
    <section class="home-section" id="page-content-wrapper">
  <div class="container-fluid py-4 px-4">
    <!-- Flash Messages -->
    <?php if ($flash): ?>
      <div class="alert alert-<?php echo $flash_type === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
        <i class="bi bi-<?php echo $flash_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
        <?php echo htmlspecialchars($flash); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Profile Header -->
    <div class="profile-header">
      <div class="profile-avatar position-relative">
        <?php
        // Display student profile picture
    if ($student['student_picture']) {
      // Serve through secure endpoint to decrypt
  $cacheBust = isset($_SESSION['profile_pic_cache_bust']) ? '&v=' . urlencode($_SESSION['profile_pic_cache_bust']) : '';
  echo '<img src="serve_profile_image.php?sid=' . urlencode($student_id) . $cacheBust . '" alt="Profile Picture" class="profile-image rounded-circle" style="width: 140px; height: 140px; object-fit: cover;">';
        } else {
            echo '<i class="bi bi-person-fill"></i>';
        }
        ?>
  <button class="change-picture-btn btn btn-sm position-absolute rounded-circle" 
    data-bs-toggle="modal" data-bs-target="#profilePictureModal" 
    title="Change Profile Picture"
    style="bottom: 8px; right: 8px; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center;">
    <i class="bi bi-camera" style="font-size: 1rem; color: #5c6773;"></i>
  </button>
      </div>
      
      <div class="profile-info">
        <h2><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h2>
        <p><i class="bi bi-person-badge me-2"></i>Student ID: <?php echo htmlspecialchars($student_id); ?></p>
      </div>
    </div>

    <!-- Personal & Contact Information Card -->
    <div class="info-card">
      <div class="info-card-header d-flex justify-content-between align-items-center">
        <div>
          <i class="bi bi-person-lines-fill"></i>
          <h5 class="d-inline mb-0">Personal & Contact Information</h5>
        </div>
        <a href="student_settings.php" class="settings-icon-btn text-decoration-none" title="Settings">
          <i class="bi bi-gear" style="font-size:1.05rem;"></i>
        </a>
      </div>
      <div class="info-card-body">
        <!-- Personal Information Section -->
        <div class="mb-4">
          <h6 class="text-muted mb-3 fw-bold">
            <i class="bi bi-person-fill me-2"></i>Personal Information
          </h6>
          <div class="info-item">
            <div class="info-label">Full Name</div>
            <div class="info-value"><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_name']); ?></div>
            <div class="info-actions">
              <span class="text-muted small">Read-only</span>
            </div>
          </div>
          <div class="info-item">
            <div class="info-label">Date of Birth</div>
            <div class="info-value"><?php echo htmlspecialchars(date('F j, Y', strtotime($student['bdate']))); ?></div>
            <div class="info-actions">
              <span class="text-muted small">Read-only</span>
            </div>
          </div>
        </div>
        
        <!-- Contact Information Section -->
        <div class="mb-0">
          <h6 class="text-muted mb-3 fw-bold">
            <i class="bi bi-envelope-fill me-2"></i>Contact Information
          </h6>
          <div class="info-item">
            <div class="info-label">Email Address</div>
            <div class="info-value"><?php echo htmlspecialchars($student['email']); ?></div>
            <div class="info-actions">
              <span class="text-muted small">Editable in settings</span>
            </div>
          </div>
          <div class="info-item">
            <div class="info-label">Mobile Number</div>
            <div class="info-value"><?php echo htmlspecialchars($student['mobile']); ?></div>
            <div class="info-actions">
              <span class="text-muted small">Editable in settings</span>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- Enhanced Profile Picture Upload Modal -->
  <div class="modal fade" id="profilePictureModal" tabindex="-1" data-bs-backdrop="false" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-camera me-2"></i>Update Profile Picture
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <!-- Step 1: Current Profile and File Selection -->
          <div id="step1" class="upload-step">
            <div class="row">
              <div class="col-md-6">
                <h6 class="mb-3">Current Profile Picture</h6>
                <div class="text-center">
                  <div class="current-profile-preview">
                    <?php
                    if ($student['student_picture'] && file_exists('../../' . $student['student_picture'])) {
                        echo '<img src="../../' . htmlspecialchars($student['student_picture']) . '" alt="Current Profile" class="rounded-circle mb-3" style="width: 150px; height: 150px; object-fit: cover; border: 3px solid #e0e0e0;">';
                    } else {
                        echo '<div class="bg-light rounded-circle d-flex align-items-center justify-content-center mb-3" style="width: 150px; height: 150px; margin: 0 auto; border: 3px solid #e0e0e0;"><i class="bi bi-person-fill text-muted" style="font-size: 4rem;"></i></div>';
                    }
                    ?>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <h6 class="mb-3">Upload New Picture</h6>
                <div class="mb-3">
                  <label class="form-label">Choose New Profile Picture</label>
                  <input type="file" id="profilePictureInput" class="form-control" accept=".jpg,.jpeg,.png,.gif">
                  <div class="form-text">
                    <i class="bi bi-info-circle me-1"></i>
                    Accepted formats: JPG, JPEG, PNG, GIF. Max size: 5MB
                  </div>
                </div>
                <div class="d-grid">
                  <button type="button" id="proceedToEdit" class="btn btn-primary" disabled>
                    <i class="bi bi-arrow-right me-2"></i>Proceed to Edit
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 2: Image Editor -->
          <div id="step2" class="upload-step" style="display: none;">
            <div class="row">
              <div class="col-md-8">
                <h6 class="mb-3">Edit Your Picture</h6>
                <div class="image-editor-container" style="border: 2px solid #e0e0e0; border-radius: 10px; overflow: hidden; background: #f8f9fa;">
                  <div class="editor-canvas-container" style="position: relative; width: 100%; height: 400px; overflow: hidden; display: flex; align-items: center; justify-content: center;">
                    <img id="editingImage" style="max-width: 100%; max-height: 100%; transform-origin: center; cursor: grab;">
                  </div>
                </div>
                
                <!-- Editor Controls -->
                <div class="editor-controls mt-3">
                  <div class="row g-2">
                    <div class="col-6">
                      <label class="form-label small">Zoom</label>
                      <input type="range" id="zoomSlider" class="form-range" min="0.5" max="3" step="0.1" value="1">
                    </div>
                    <div class="col-6">
                      <label class="form-label small">Position</label>
                      <div class="btn-group w-100">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="centerImage">
                          <i class="bi bi-arrows-move"></i> Center
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="resetImage">
                          <i class="bi bi-arrow-clockwise"></i> Reset
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              
              <div class="col-md-4">
                <h6 class="mb-3">Preview</h6>
                <div class="text-center">
                  <div class="preview-container">
                    <canvas id="previewCanvas" width="200" height="200" style="border-radius: 50%; border: 3px solid #007bff;"></canvas>
                  </div>
                  <p class="text-muted small mt-2">This is how your profile picture will appear</p>
                </div>
                
                <div class="mt-4">
                  <div class="d-grid gap-2">
                    <button type="button" id="backToStep1" class="btn btn-outline-secondary">
                      <i class="bi bi-arrow-left me-2"></i>Back to Upload
                    </button>
                    <button type="button" id="confirmUpload" class="btn btn-success">
                      <i class="bi bi-check-circle me-2"></i>Confirm & Upload
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Hidden form for actual upload -->
          <form id="hiddenUploadForm" method="POST" enctype="multipart/form-data" style="display: none;">
            <input type="file" name="profile_picture" id="hiddenFileInput">
            <input type="hidden" name="crop_data" id="cropData">
            <input type="hidden" name="update_picture" value="1">
          </form>
        </div>
        
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <div id="step1Footer">
            <span class="text-muted">Select an image to continue</span>
          </div>
          <div id="step2Footer" style="display: none;">
            <span class="text-muted">Adjust your image and preview the result</span>
          </div>
        </div>
      </div>
    </div>
  </div>

      </section>
  </div>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/student/sidebar.js"></script>
  
  <script>
    // Profile Picture Editor JavaScript
    let currentImage = null;
    let currentScale = 1;
    let currentX = 0;
    let currentY = 0;
    let isDragging = false;
    let dragStartX = 0;
    let dragStartY = 0;

    const profileInput = document.getElementById('profilePictureInput');
    const proceedBtn = document.getElementById('proceedToEdit');
    const editingImage = document.getElementById('editingImage');
    const zoomSlider = document.getElementById('zoomSlider');
    const previewCanvas = document.getElementById('previewCanvas');
    const previewCtx = previewCanvas.getContext('2d');
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step1Footer = document.getElementById('step1Footer');
    const step2Footer = document.getElementById('step2Footer');

    // File input change handler
    profileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            // Validate file size
            if (file.size > 5 * 1024 * 1024) {
                alert('File size must be less than 5MB');
                return;
            }
            
            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!allowedTypes.includes(file.type)) {
                alert('Only JPG, JPEG, PNG, and GIF files are allowed');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                currentImage = new Image();
                currentImage.onload = function() {
                    proceedBtn.disabled = false;
                };
                currentImage.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    });

    // Proceed to edit button
    proceedBtn.addEventListener('click', function() {
        if (currentImage) {
            step1.style.display = 'none';
            step2.style.display = 'block';
            step1Footer.style.display = 'none';
            step2Footer.style.display = 'block';
            
            editingImage.src = currentImage.src;
            resetImagePosition();
            updatePreview();
        }
    });

    // Back to step 1 button
    document.getElementById('backToStep1').addEventListener('click', function() {
        step2.style.display = 'none';
        step1.style.display = 'block';
        step2Footer.style.display = 'none';
        step1Footer.style.display = 'block';
    });

    // Zoom slider
    zoomSlider.addEventListener('input', function() {
        currentScale = parseFloat(this.value);
        updateImageTransform();
        updatePreview();
    });

    // Center image button
    document.getElementById('centerImage').addEventListener('click', function() {
        currentX = 0;
        currentY = 0;
        updateImageTransform();
        updatePreview();
    });

    // Reset image button
    document.getElementById('resetImage').addEventListener('click', function() {
        resetImagePosition();
        updatePreview();
    });

    // Mouse events for dragging
    editingImage.addEventListener('mousedown', function(e) {
        isDragging = true;
        dragStartX = e.clientX - currentX;
        dragStartY = e.clientY - currentY;
        editingImage.style.cursor = 'grabbing';
        e.preventDefault();
    });

    document.addEventListener('mousemove', function(e) {
        if (isDragging) {
            currentX = e.clientX - dragStartX;
            currentY = e.clientY - dragStartY;
            updateImageTransform();
            updatePreview();
        }
    });

    document.addEventListener('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            editingImage.style.cursor = 'grab';
        }
    });

    // Touch events for mobile
    editingImage.addEventListener('touchstart', function(e) {
        isDragging = true;
        const touch = e.touches[0];
        dragStartX = touch.clientX - currentX;
        dragStartY = touch.clientY - currentY;
        e.preventDefault();
    });

    document.addEventListener('touchmove', function(e) {
        if (isDragging) {
            const touch = e.touches[0];
            currentX = touch.clientX - dragStartX;
            currentY = touch.clientY - dragStartY;
            updateImageTransform();
            updatePreview();
            e.preventDefault();
        }
    });

    document.addEventListener('touchend', function() {
        isDragging = false;
    });

    // Confirm upload button
    document.getElementById('confirmUpload').addEventListener('click', function() {
        if (currentImage) {
            // Create a canvas to crop the image
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = 400;
            canvas.height = 400;

            // Calculate the cropping area
            const containerWidth = 400;
            const containerHeight = 400;
            const imageAspect = currentImage.width / currentImage.height;
            
            let drawWidth, drawHeight, drawX, drawY;
            
            if (imageAspect > 1) {
                drawHeight = containerHeight * currentScale;
                drawWidth = drawHeight * imageAspect;
            } else {
                drawWidth = containerWidth * currentScale;
                drawHeight = drawWidth / imageAspect;
            }
            
            drawX = (containerWidth - drawWidth) / 2 + currentX;
            drawY = (containerHeight - drawHeight) / 2 + currentY;

            // Draw the image on canvas
            ctx.drawImage(currentImage, drawX, drawY, drawWidth, drawHeight);

            // Convert canvas to blob
            canvas.toBlob(function(blob) {
                const formData = new FormData();
                formData.append('profile_picture', blob, 'profile_picture.png');
                formData.append('update_picture', '1');

                // Submit the form
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                }).then(response => {
                    if (response.ok) {
                        location.reload();
                    } else {
                        alert('Error uploading image. Please try again.');
                    }
                }).catch(error => {
                    alert('Error uploading image. Please try again.');
                });
            }, 'image/png', 0.9);
        }
    });

    function updateImageTransform() {
        editingImage.style.transform = `translate(${currentX}px, ${currentY}px) scale(${currentScale})`;
    }

    function resetImagePosition() {
        currentScale = 1;
        currentX = 0;
        currentY = 0;
        zoomSlider.value = 1;
        updateImageTransform();
    }

    function updatePreview() {
        if (!currentImage) return;

        const canvas = previewCanvas;
        const ctx = previewCtx;
        const size = 200;

        // Clear canvas
        ctx.clearRect(0, 0, size, size);

        // Create circular clipping path
        ctx.save();
        ctx.beginPath();
        ctx.arc(size / 2, size / 2, size / 2, 0, Math.PI * 2);
        ctx.clip();

        // Calculate image dimensions and position
        const imageAspect = currentImage.width / currentImage.height;
        let drawWidth, drawHeight, drawX, drawY;
        
        if (imageAspect > 1) {
            drawHeight = size * currentScale;
            drawWidth = drawHeight * imageAspect;
        } else {
            drawWidth = size * currentScale;
            drawHeight = drawWidth / imageAspect;
        }
        
        drawX = (size - drawWidth) / 2 + (currentX * size / 400);
        drawY = (size - drawHeight) / 2 + (currentY * size / 400);

        // Draw image
        ctx.drawImage(currentImage, drawX, drawY, drawWidth, drawHeight);
        ctx.restore();
    }

    // Reset modal when closed
    document.getElementById('profilePictureModal').addEventListener('hidden.bs.modal', function() {
        step1.style.display = 'block';
        step2.style.display = 'none';
        step1Footer.style.display = 'block';
        step2Footer.style.display = 'none';
        profileInput.value = '';
        proceedBtn.disabled = true;
        currentImage = null;
        resetImagePosition();
    });
    
    // Fix for duplicate backdrop issue - remove any extra backdrops when modal opens
    document.getElementById('profilePictureModal').addEventListener('show.bs.modal', function() {
        // Remove any existing backdrops before opening
        const existingBackdrops = document.querySelectorAll('.modal-backdrop');
        if (existingBackdrops.length > 0) {
            existingBackdrops.forEach((backdrop, index) => {
                // Keep only the first one, remove extras
                if (index > 0) {
                    backdrop.remove();
                }
            });
        }
    });
    
    // Clean up any leftover backdrops after modal is fully hidden
    document.getElementById('profilePictureModal').addEventListener('hidden.bs.modal', function() {
        // Remove any lingering backdrops
        setTimeout(() => {
            const allBackdrops = document.querySelectorAll('.modal-backdrop');
            allBackdrops.forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }, 100);
    });
  </script>
</body>
</html>
