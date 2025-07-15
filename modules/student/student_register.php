<?php
include_once '../../config/database.php';

$municipality_id = 1;
$activeSlot = pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
$slotInfo = pg_fetch_assoc($activeSlot);

$slotsLeft = 0;
if ($slotInfo) {
    $countQuery = "
        SELECT COUNT(*) AS total FROM students 
        WHERE status = 'applicant' AND application_date >= $1
    ";
    $countResult = pg_query_params($connection, $countQuery, [$slotInfo['created_at']]);
    $countRow = pg_fetch_assoc($countResult);
    $slotsUsed = intval($countRow['total']) + (isset($_POST['register']) ? 1 : 0);
    $slotsLeft = intval($slotInfo['slot_count']) - $slotsUsed;
}

if ($slotsLeft <= 0) {
  header("Location: student_login.html");
    echo "<div class='alert alert-danger mt-4'>The slots are full. Please wait for the next announcement.</div>";
    exit;
}

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
    echo "<script>alert('Password must be at least 12 characters.');</script>";
    exit;
  }

  if ($pass !== $confirm) {
    echo "<script>alert('Passwords do not match.');</script>";
    exit;
  }

  $hashed = password_hash($pass, PASSWORD_ARGON2ID);
  $municipality_id = 1;
  $payroll_no = 0;
  $qr_code = 0;

  if (!$connection) {
    echo "<script>alert('Connection failed.');</script>";
    exit;
  }

  // Check for duplicate email
  $checkEmailQuery = "SELECT 1 FROM students WHERE email = $1 LIMIT 1";
  $checkEmailResult = pg_query_params($connection, $checkEmailQuery, [$email]);

  if (pg_num_rows($checkEmailResult) > 0) {
    echo "<script>alert('Email already exists. Please use a different one.');</script>";
    exit;
  }

  // Check for duplicate mobile
  $checkMobileQuery = "SELECT 1 FROM students WHERE mobile = $1 LIMIT 1";
  $checkMobileResult = pg_query_params($connection, $checkMobileQuery, [$mobile]);

  if (pg_num_rows($checkMobileResult) > 0) {
    echo "<script>alert('Mobile number already exists. Please use a different one.');</script>";
    exit;
  }

  $query = "INSERT INTO students (municipality_id, first_name, middle_name, last_name, email, mobile, password, sex, status, payroll_no, qr_code, has_received, application_date, bdate, barangay_id)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)";
  $result = pg_query_params($connection, $query, [$municipality_id, $firstname, $middlename, $lastname, $email, $mobile, $hashed, $sex, 'applicant', $payroll_no, $qr_code, 'f', date('Y-m-d H:i:s'), $age, $barangay]);

  if ($result) {
    echo "<script>alert('Student registered successfully!'); window.location.href = 'student_login.html';</script>";

    exit;
  } else {
    echo "<script>alert('Error: " . pg_last_error($connection) . "');</script>";
  }

  pg_close($connection);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>EducAid – Multi-Step Registration</title>

  <!-- Bootstrap CSS -->
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <!-- Custom CSS -->
  <link rel="stylesheet" href="../../assets/css/registration.css" />
</head>
<body>
  <div class="container py-5">
    <div class="register-card mx-auto p-4 rounded shadow-sm bg-white" style="max-width: 600px;">
      <h4 class="mb-4 text-center text-primary">
        <i class="bi bi-person-plus-fill me-2"></i>Register for EducAid
      </h4>

      <!-- Step Progress -->
      <div class="step-indicator mb-4 text-center">
        <span class="step active">1</span>
        <span class="step">2</span>
        <span class="step">3</span>
        <span class="step">4</span>
      </div>

      <form id="multiStepForm" method="POST">
        <!-- Step 1: Name -->
        <div class="step-panel" id="step-1">
          <div class="mb-3">
            <label for="firstName" class="form-label">First Name</label>
            <input type="text" class="form-control" id="firstName" name="first_name" required />
          </div>
          <div class="mb-3">
            <label for="middleName" class="form-label">Middle Name</label>
            <input type="text" class="form-control" id="middleName" name="middle_name"/>
          </div>
          <div class="mb-3">
            <label for="lastName" class="form-label">Last Name</label>
            <input type="text" class="form-control" id="lastName" name="last_name" required />
          </div>
          <button type="button" class="btn btn-primary w-100" onclick="nextStep(1)">Next</button>
        </div>

        <!-- Step 2: Age, Gender, Barangay -->
        <div class="step-panel d-none" id="step-2">
          <div class="mb-3">
            <label for="bdate" class="form-label">Birthdate</label>
            <input type="date" class="form-control" id="bdate" name="bdate" required />
          </div>

          <div class="mb-3">
            <label class="form-label d-block">Sex</label>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="sex" id="sex_male" value="Male" required>
              <label class="form-check-label" for="sex_male">Male</label>
            </div>
            <div class="form-check form-check-inline mb-3">
              <input class="form-check-input" type="radio" name="sex" id="sex_female" value="Female" required>
              <label class="form-check-label" for="sex_female">Female</label>
            </div>

            <div class="mb-3">
              <label for="barangay_id" class="form-label">Barangay</label>
              <div class="responsive-select-wrapper">
                <select name="barangay_id" id="barangay_id" class="form-select" required>
                  <option value="" disabled selected>Select your barangay</option>
                  <?php
                    include __DIR__ . '/../../config/database.php';
                    $municipality_id = 1;
                    $query = "SELECT barangay_id, name FROM barangays WHERE municipality_id = $1 ORDER BY name ASC";
                    $result = pg_query_params($connection, $query, [$municipality_id]);

                    if ($result && pg_num_rows($result) > 0) {
                      while ($row = pg_fetch_assoc($result)) {
                        $id = htmlspecialchars($row['barangay_id']);
                        $name = htmlspecialchars($row['name']);
                        echo "<option value='$id'>$name</option>";
                      }
                    } else {
                      echo "<option disabled>No barangays found</option>";
                    }
                  ?>
                </select>
              </div>
            </div>
          </div>

          <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep(2)">Back</button>
          <button type="button" class="btn btn-primary w-100" onclick="nextStep(2)">Next</button>
        </div>

        <!-- Step 3: Contact -->
        <div class="step-panel d-none" id="step-3">
          <div class="mb-3">
            <label for="email" class="form-label">Email Address</label>
            <input type="email" class="form-control" id="email" name="email" required />
          </div>
          <div class="mb-3">
            <label for="phone" class="form-label">Phone Number</label>
            <input type="tel" class="form-control" id="phone" name="phone" placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" maxlength="11" required />
          </div>
          <!-- <div class="mb-3">
            <label for="otp" class="form-label">OTP</label>
            <input type="text" class="form-control" id="otp" name="otp" placeholder="6-digit code" maxlength="6" pattern="\d{6}" required />
          </div>
          <div class="mb-3 d-flex justify-content-between align-items-center">
            <button type="button" id="requestOtpBtn" class="btn btn-outline-primary btn-sm">Request OTP</button>
            <div id="otpTimer" class="text-muted small"></div>
          </div> -->
          <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep(3)">Back</button>
          <button type="button" class="btn btn-primary w-100" onclick="nextStep(3)">Next</button>
        </div>

        <!-- Step 4: Password -->
        <div class="step-panel d-none" id="step-4">
          <div class="mb-1">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" minlength="12" required />
            <div class="form-text">Must be at least 12 characters long with letters, numbers, and symbols.</div>
          </div>
          <div class="mb-3">
            <label for="confirmPassword" class="form-label">Confirm Password</label>
            <input type="password" class="form-control" id="confirmPassword" name="confirm_password" minlength="12" required />
          </div>
          <div class="mb-3">
            <label class="form-label">Password Strength</label>
            <div class="progress">
              <div id="strengthBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
            </div>
            <small id="strengthText" class="text-muted"></small>
          </div>
          <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" id="terms" required />
            <label class="form-check-label" for="terms">I agree to the Terms and Conditions</label>
          </div>
          <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep(4)">Back</button>
          <button type="submit" name="register" class="btn btn-success w-100">Submit</button>
        </div>
      </form>
    </div>
  </div>

  <!-- JS Libraries -->
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/registration.js"></script>

</body>
</html>