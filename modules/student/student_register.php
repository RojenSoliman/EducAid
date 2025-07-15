<?php
include_once '../../config/database.php';

$municipality_id = 1;

// Handle registration
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['register'])) {
    $firstname = $_POST['first_name'];
    $middlename = $_POST['middle_name'];
    $lastname = $_POST['last_name'];
    $age = $_POST['bdate'];
    $sex = $_POST['sex'];
    $barangay = $_POST['barangay_id'];
    $mobile = $_POST['phone'];
    $email = $_POST['email'];
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if (strlen($pass) < 12) {
        echo "<script>alert('Password must be at least 12 characters.'); history.back();</script>";
        exit;
    }

    if ($pass !== $confirm) {
        echo "<script>alert('Passwords do not match.'); history.back();</script>";
        exit;
    }

    $hashed = password_hash($pass, PASSWORD_ARGON2ID);

    // Check duplicates
    $checkEmail = pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1", [$email]);
    if (pg_num_rows($checkEmail) > 0) {
        echo "<script>alert('Email already exists.'); history.back();</script>";
        exit;
    }

    $checkMobile = pg_query_params($connection, "SELECT 1 FROM students WHERE mobile = $1", [$mobile]);
    if (pg_num_rows($checkMobile) > 0) {
        echo "<script>alert('Mobile number already exists.'); history.back();</script>";
        exit;
    }

    // Fetch active slot
    $slotRes = pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
    $slotInfo = pg_fetch_assoc($slotRes);

    if (!$slotInfo) {
        echo "<script>alert('No active slot found.'); history.back();</script>";
        exit;
    }

    // Count applicants after slot activation
    $countRes = pg_query_params($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'applicant' AND application_date >= $1", [$slotInfo['created_at']]);
    $countRow = pg_fetch_assoc($countRes);
    $slotsUsed = intval($countRow['total']);
    $slotsLeft = intval($slotInfo['slot_count']) - $slotsUsed;

    if ($slotsLeft <= 0) {
        echo "<script>alert('Slots are full. Please wait for the next round.'); window.location.href = 'student_login.html';</script>";
        exit;
    }

    // Insert student
    $insertQuery = "INSERT INTO students (municipality_id, first_name, middle_name, last_name, email, mobile, password, sex, status, payroll_no, qr_code, has_received, application_date, bdate, barangay_id)
                    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'applicant', 0, 0, FALSE, NOW(), $9, $10)";
    $result = pg_query_params($connection, $insertQuery, [$municipality_id, $firstname, $middlename, $lastname, $email, $mobile, $hashed, $sex, $age, $barangay]);

    if ($result) {
        echo "<script>alert('Registration successful!'); window.location.href = 'student_login.html';</script>";
        exit;
    } else {
        echo "<script>alert('Registration failed.');</script>";
    }
} else {
    // On load: check if slots are full
    $slotRes = pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
    $slotInfo = pg_fetch_assoc($slotRes);
    $slotsLeft = 0;

    if ($slotInfo) {
        $countRes = pg_query_params($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'applicant' AND application_date >= $1", [$slotInfo['created_at']]);
        $countRow = pg_fetch_assoc($countRes);
        $slotsLeft = intval($slotInfo['slot_count']) - intval($countRow['total']);
    }

    if ($slotsLeft <= 0) {
        echo "<div class='alert alert-danger text-center mt-4'>The slots are full. Please wait for the next announcement.</div>";
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>EducAid – Register</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/registration.css" />
</head>
<body>
  <div class="container py-5">
    <div class="register-card mx-auto p-4 rounded shadow-sm bg-white" style="max-width: 600px;">
      <h4 class="mb-4 text-center text-primary">
        <i class="bi bi-person-plus-fill me-2"></i>Register for EducAid
      </h4>

      <!-- Progress Steps -->
      <div class="step-indicator mb-4 text-center">
        <span class="step active">1</span>
        <span class="step">2</span>
        <span class="step">3</span>
        <span class="step">4</span>
      </div>

      <form id="multiStepForm" method="POST">
        <!-- Step 1 -->
        <div class="step-panel" id="step-1">
          <div class="mb-3">
            <label class="form-label">First Name</label>
            <input type="text" class="form-control" name="first_name" required />
          </div>
          <div class="mb-3">
            <label class="form-label">Middle Name</label>
            <input type="text" class="form-control" name="middle_name" />
          </div>
          <div class="mb-3">
            <label class="form-label">Last Name</label>
            <input type="text" class="form-control" name="last_name" required />
          </div>
          <button type="button" class="btn btn-primary w-100" onclick="nextStep(1)">Next</button>
        </div>

        <!-- Step 2 -->
        <div class="step-panel d-none" id="step-2">
          <div class="mb-3">
            <label class="form-label">Birthdate</label>
            <input type="date" class="form-control" name="bdate" required />
          </div>
          <div class="mb-3">
            <label class="form-label d-block">Sex</label>
            <div class="form-check form-check-inline">
              <input type="radio" class="form-check-input" name="sex" value="Male" required />
              <label class="form-check-label">Male</label>
            </div>
            <div class="form-check form-check-inline">
              <input type="radio" class="form-check-input" name="sex" value="Female" required />
              <label class="form-check-label">Female</label>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Barangay</label>
            <select name="barangay_id" class="form-select" required>
              <option disabled selected>Select your barangay</option>
              <?php
              $res = pg_query_params($connection, "SELECT barangay_id, name FROM barangays WHERE municipality_id = $1 ORDER BY name ASC", [$municipality_id]);
              while ($row = pg_fetch_assoc($res)) {
                  echo "<option value='{$row['barangay_id']}'>" . htmlspecialchars($row['name']) . "</option>";
              }
              ?>
            </select>
          </div>
          <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep(2)">Back</button>
          <button type="button" class="btn btn-primary w-100" onclick="nextStep(2)">Next</button>
        </div>

        <!-- Step 3 -->
        <div class="step-panel d-none" id="step-3">
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" required />
          </div>
          <div class="mb-3">
            <label class="form-label">Phone Number</label>
            <input type="tel" class="form-control" name="phone" maxlength="11" pattern="09[0-9]{9}" required />
          </div>
          <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep(3)">Back</button>
          <button type="button" class="btn btn-primary w-100" onclick="nextStep(3)">Next</button>
        </div>

        <!-- Step 4 -->
        <div class="step-panel d-none" id="step-4">
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" class="form-control" name="password" id="password" minlength="12" required />
          </div>
          <div class="form-text">
            Must be at least 12 characters long with letters, numbers, and symbols.
          </div>
          <div class="mb-3">
            <label class="form-label">Confirm Password</label>
            <input type="password" class="form-control" name="confirm_password" id="confirmPassword" minlength="12" required />
          </div>
          <div class="mb-3">
            <label class="form-label">Password Strength</label>
            <div class="progress">
              <div id="strengthBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
            </div>
            <small id="strengthText" class="text-muted"></small>
          </div>
          <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" required />
            <label class="form-check-label">I agree to the Terms</label>
          </div>
          <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep(4)">Back</button>
          <button type="submit" name="register" class="btn btn-success w-100">Submit</button>
        </div>
      </form>
    </div>
  </div>

  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/registration.js"></script>
</body>
</html>