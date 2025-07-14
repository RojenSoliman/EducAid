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
            <input type="text" class="form-control" id="firstName" name="firstName" required />
          </div>
          <div class="mb-3">
            <label for="middlename" class="form-label">Middle Name</label>
            <input type="text" class="form-control" id="middlename" name="middlename" maxlength="1" />
          </div>
          <div class="mb-3">
            <label for="lastName" class="form-label">Last Name</label>
            <input type="text" class="form-control" id="lastName" name="lastName" required />
          </div>
          <button type="button" class="btn btn-primary w-100" onclick="nextStep(1)">Next</button>
        </div>

        <!-- Step 2: Contact -->
        <div class="step-panel d-none" id="step-2">
          <div class="mb-3">
            <label for="email" class="form-label">Email Address</label>
            <input type="email" class="form-control" id="email" name="email" required />
          </div>
          <div class="mb-3">
            <label for="phone" class="form-label">Phone Number</label>
            <input type="tel" class="form-control" id="phone" name="phone" placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" maxlength="11" required />
          </div>
          <div class="mb-3">
            <label for="otp" class="form-label">OTP</label>
            <input type="text" class="form-control" id="otp" name="otp" placeholder="6-digit code" maxlength="6" pattern="\d{6}" required />
          </div>
          <div class="mb-3 d-flex justify-content-between align-items-center">
            <button type="button" id="requestOtpBtn" class="btn btn-outline-primary btn-sm">Request OTP</button>
            <div id="otpTimer" class="text-muted small"></div>
          </div>
          <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep(2)">Back</button>
          <button type="button" class="btn btn-primary w-100" onclick="nextStep(2)">Next</button>
        </div>

        <!-- Step 3: Password -->
        <div class="step-panel d-none" id="step-2">
          <div class="mb-3">
            <label for="email" class="form-label">Email Address</label>
            <input type="email" class="form-control" id="email" name="email" required />
          </div>
          <div class="mb-3">
            <label for="phone" class="form-label">Phone Number</label>
            <input type="tel" class="form-control" id="phone" name="phone" placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" maxlength="11" required />
          </div>
          <div class="mb-3">
            <label for="otp" class="form-label">OTP</label>
            <input type="text" class="form-control" id="otp" name="otp" placeholder="6-digit code" maxlength="6" pattern="\d{6}" required />
          </div>
          <div class="mb-3 d-flex justify-content-between align-items-center">
            <button type="button" id="requestOtpBtn" class="btn btn-outline-primary btn-sm">Request OTP</button>
            <div id="otpTimer" class="text-muted small"></div>
          </div>
          <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep(3)">Back</button>
          <button type="button" class="btn btn-primary w-100" onclick="nextStep(3)">Next</button>
        </div>

        <!-- Step 4: Password -->
        <div class="step-panel d-none" id="step-3">
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
          <button type="submit" class="btn btn-success w-100">Submit</button>
        </div>
      </form>
    </div>
  </div>

  <!-- JS Libraries -->
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/registration.js"></script>
</body>
</html>

<?php
  include_once '../../config/database.php';
  if (isset($_POST['register'])) {
    $firstname = $_POST['firstname'];
    $middlename = $_POST['middlename'];
    $lastname = $_POST['lastname'];
    $age = $_POST['age'];
    $sex = $_POST['sex'];
    $barangay = $_POST['barangay'];
    $mobile = $_POST['contnumber'];
    $email = $_POST['email'];
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    // Password validation: minimum 12 characters
    if (strlen($pass) < 12) {
       echo "<p style='color:red;'>Password must be at least 12 characters long.</p>";
       exit;
    }
    // Confirm password validation
    if ($pass !== $confirm) {
      echo "<p style='color:red;'>Passwords do not match.</p>";
      exit;
    }

    $hashed = password_hash($pass, PASSWORD_ARGON2ID);
    $municipality_id = 1;
    $payroll_no = 0;
    $qr_code = 0;

    // Connect to PostgreSQL (update credentials)
    if (!$connection) {
      echo "<p style='color:red;'>Connection failed.</p>";
      exit;
    }

    // Insert into students
    $query = "INSERT INTO students (municipality_id, first_name, middle_name, last_name, age, barangay, email, mobile, password, sex, status, payroll_no, qr_code, has_received, application_date)
      VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)";
    $result = pg_query_params($connection, $query, [$municipality_id, $firstname, $middlename, $lastname, $age, $barangay, $email, $mobile, $hashed, $sex, 'applicant', $payroll_no, $qr_code, 'f', date('Y-m-d H:i:s')]);
    if ($result) {
      echo "<script>alert('Student registered successfully!'); window.location.href = 'student_login.html';</script>";
    } else {
       echo "<p style='color:red;'>Error: " . pg_last_error($connection) . "</p>";
    }

    pg_close($connection);
  }
?>