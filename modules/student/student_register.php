<?php
include_once '../../config/database.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'C:/xampp/htdocs/EducAidL/phpmailer/vendor/autoload.php';

$municipality_id = 1;

// --- Slot check ---
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $slotRes = pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
    $slotInfo = pg_fetch_assoc($slotRes);
    $slotsLeft = 0;
    if ($slotInfo) {
        $countRes = pg_query_params($connection, "
            SELECT COUNT(*) AS total FROM students
            WHERE (status = 'applicant' OR status = 'active')
            AND application_date >= $1
        ", [$slotInfo['created_at']]);
        $countRow = pg_fetch_assoc($countRes);
        $slotsLeft = intval($slotInfo['slot_count']) - intval($countRow['total']);
    }
    if ($slotsLeft <= 0) {
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <title>EducAid – Registration Closed</title>
            <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
            <style>
                .alert-container {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    height: 100vh;
                }
                .spinner {
                    width: 3rem;
                    height: 3rem;
                    margin-bottom: 1rem;
                    animation: spin 1s linear infinite;
                }
                @keyframes spin {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }
            </style>
        </head>
        <body class="bg-light">
            <div class="container alert-container">
                <div class="text-center">
                    <svg class="spinner text-danger" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
                        <circle cx="50" cy="50" fill="none" stroke="currentColor" stroke-width="10" r="35" stroke-dasharray="164.93361431346415 56.97787143782138">
                            <animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="1s" values="0 50 50;360 50 50" keyTimes="0;1"/>
                        </circle>
                    </svg>
                    <h4 class="text-danger">Slots are full.</h4>
                    <p>Please wait for the next announcement before registering again.</p>
                    <a href="student_login.html" class="btn btn-outline-primary mt-3">Back to Login</a>
                </div>
            </div>
        </body>
        </html>
        HTML;
        exit;
    }
}

// --- OTP send ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['sendOtp'])) {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
        exit;
    }

    $checkEmail = pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1", [$email]);
    if (pg_num_rows($checkEmail) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'This email is already registered. Please use a different email or login.']);
        exit;
    }

    $otp = rand(100000, 999999);

    $_SESSION['otp'] = $otp;
    $_SESSION['otp_email'] = $email;
    $_SESSION['otp_timestamp'] = time();

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'dilucayaka02@gmail.com'; // CHANGE for production
        $mail->Password   = 'jlld eygl hksj flvg';    // CHANGE for production
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('dilucayaka02@gmail.com', 'EducAid');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Your EducAid OTP Code';
        $mail->Body    = "Your One-Time Password (OTP) for EducAid registration is: <strong>$otp</strong><br><br>This OTP is valid for 40 seconds.";
        $mail->AltBody = "Your One-Time Password (OTP) for EducAid registration is: $otp. This OTP is valid for 40 seconds.";

        $mail->send();
        echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email. Please check your inbox and spam folder.']);
    } catch (Exception $e) {
        error_log("PHPMailer Error: {$mail->ErrorInfo}");
        echo json_encode(['status' => 'error', 'message' => 'Message could not be sent. Please check your email address and try again.']);
    }
    exit;
}

// --- OTP verify ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verifyOtp'])) {
    $enteredOtp = filter_var($_POST['otp'], FILTER_SANITIZE_NUMBER_INT);
    $email_for_otp = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_email']) || !isset($_SESSION['otp_timestamp'])) {
        echo json_encode(['status' => 'error', 'message' => 'No OTP sent or session expired. Please request a new OTP.']);
        exit;
    }

    if ($_SESSION['otp_email'] !== $email_for_otp) {
         echo json_encode(['status' => 'error', 'message' => 'Email mismatch for OTP. Please ensure you are verifying the correct email.']);
         exit;
    }

    if ((time() - $_SESSION['otp_timestamp']) > 40) {
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']);
        echo json_encode(['status' => 'error', 'message' => 'OTP has expired. Please request a new OTP.']);
        exit;
    }

    if ((int)$enteredOtp === (int)$_SESSION['otp']) {
        echo json_encode(['status' => 'success', 'message' => 'OTP verified successfully!']);
        $_SESSION['otp_verified'] = true;
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid OTP. Please try again.']);
        $_SESSION['otp_verified'] = false;
    }
    exit;
}

// --- Registration logic ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['register'])) {
    if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
        echo "<script>alert('OTP not verified. Please verify your email first.'); history.back();</script>";
        exit;
    }

    $firstname = htmlspecialchars(trim($_POST['first_name']));
    $middlename = htmlspecialchars(trim($_POST['middle_name']));
    $lastname = htmlspecialchars(trim($_POST['last_name']));
    $bdate = $_POST['bdate'];
    $sex = htmlspecialchars(trim($_POST['sex']));
    $barangay = filter_var($_POST['barangay_id'], FILTER_VALIDATE_INT);
    $mobile = htmlspecialchars(trim($_POST['phone']));
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if (empty($firstname) || empty($lastname) || empty($bdate) || empty($sex) || empty($barangay) || empty($mobile) || empty($email) || empty($pass) || empty($confirm)) {
        echo "<script>alert('Please fill in all required fields.'); history.back();</script>";
        exit;
    }

    if (strlen($pass) < 12) {
        echo "<script>alert('Password must be at least 12 characters.'); history.back();</script>";
        exit;
    }

    if ($pass !== $confirm) {
        echo "<script>alert('Passwords do not match.'); history.back();</script>";
        exit;
    }

    $hashed = password_hash($pass, PASSWORD_ARGON2ID);

    $checkEmail = pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1", [$email]);
    if (pg_num_rows($checkEmail) > 0) {
        echo "<script>alert('Email already exists. Please use a different email or login.'); window.location.href = 'student_login.html';</script>";
        exit;
    }

    $checkMobile = pg_query_params($connection, "SELECT 1 FROM students WHERE mobile = $1", [$mobile]);
    if (pg_num_rows($checkMobile) > 0) {
        echo "<script>alert('Mobile number already exists. Please use a different mobile number.'); history.back();</script>";
        exit;
    }

    $slotRes = pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
    $slotInfo = pg_fetch_assoc($slotRes);
    if (!$slotInfo) {
        echo "<script>alert('No active slot found for your municipality.'); window.location.href = 'student_login.html';</script>";
        exit;
    }

    $countRes = pg_query_params($connection, "
        SELECT COUNT(*) AS total FROM students
        WHERE (status = 'applicant' OR status = 'active')
        AND application_date >= $1
    ", [$slotInfo['created_at']]);
    $countRow = pg_fetch_assoc($countRes);
    $slotsUsed = intval($countRow['total']);
    $slotsLeft = intval($slotInfo['slot_count']) - $slotsUsed;

    if ($slotsLeft <= 0) {
        echo "<script>alert('Registration slots are full. Please wait for the next round.'); window.location.href = 'student_login.html';</script>";
        exit;
    }

    $insertQuery = "INSERT INTO students (municipality_id, first_name, middle_name, last_name, email, mobile, password, sex, status, payroll_no, qr_code, has_received, application_date, bdate, barangay_id)
                    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'applicant', 0, 0, FALSE, NOW(), $9, $10) RETURNING student_id";

    $result = pg_query_params($connection, $insertQuery, [
        $municipality_id,
        $firstname,
        $middlename,
        $lastname,
        $email,
        $mobile,
        $hashed,
        $sex,
        $bdate,
        $barangay
    ]);

    if ($result) {
        $student_id_row = pg_fetch_assoc($result);
        $student_id = $student_id_row['student_id'];

        $semester = $slotInfo['semester'];
        $academic_year = $slotInfo['academic_year'];
        $applicationQuery = "INSERT INTO applications (student_id, semester, academic_year) VALUES ($1, $2, $3)";
        pg_query_params($connection, $applicationQuery, [$student_id, $semester, $academic_year]);

        unset($_SESSION['otp_verified']);

        echo "<script>alert('Registration successful! You can now login.'); window.location.href = 'student_login.html';</script>";
        exit;
    } else {
        echo "<script>alert('Registration failed due to a database error. Please try again.');</script>";
        
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
    <style>
        .step-panel.d-none { display: none !important; }
        .step-indicator { display: flex; justify-content: center; margin-bottom: 20px; }
        .step {
            display: flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 50%;
            background-color: #e0e0e0; color: #777; font-weight: bold;
            margin: 0 5px; transition: background-color 0.3s, color 0.3s;
        }
        .step.active { background-color: #007bff; color: white; }
        .notifier {
            position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
            padding: 15px 30px; background-color: #f8d7da; color: #721c24;
            border-radius: 5px; display: none; box-shadow: 0 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        .notifier.success { background-color: #d4edda; color: #155724; }
        .verified-email { background-color: #e9f7e9; color: #28a745; }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="register-card mx-auto p-4 rounded shadow-sm bg-white" style="max-width: 600px;">
            <h4 class="mb-4 text-center text-primary">
                <i class="bi bi-person-plus-fill me-2"></i>Register for EducAid
            </h4>
            <div class="step-indicator mb-4 text-center">
                <span class="step active" id="step-indicator-1">1</span>
                <span class="step" id="step-indicator-2">2</span>
                <span class="step" id="step-indicator-3">3</span>
                <span class="step" id="step-indicator-4">4</span>
                <span class="step" id="step-indicator-5">5</span>
            </div>
            <form id="multiStepForm" method="POST" autocomplete="off">
                <!-- Step 1: Personal Information -->
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
                    <button type="button" class="btn btn-primary w-100" onclick="nextStep()">Next</button>
                </div>
                <!-- Step 2: Birthdate and Sex -->
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
                            <option value="" disabled selected>Select your barangay</option>
                            <?php
                            $res = pg_query_params($connection, "SELECT barangay_id, name FROM barangays WHERE municipality_id = $1 ORDER BY name ASC", [$municipality_id]);
                            while ($row = pg_fetch_assoc($res)) {
                                echo "<option value='{$row['barangay_id']}'>" . htmlspecialchars($row['name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" onclick="nextStep()">Next</button>
                </div>
                <!-- Step 3: OTP Verification -->
                <div class="step-panel d-none" id="step-3">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="emailInput" required />
                        <span id="emailStatus" class="text-success d-none">Verified</span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" name="phone" maxlength="11" pattern="09[0-9]{9}" placeholder="e.g., 09123456789" required />
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-info" id="sendOtpBtn">Send OTP (Email)</button>
                    </div>
                    <div id="otpSection" class="d-none mt-3">
                        <div class="mb-3">
                            <label class="form-label">Enter OTP</label>
                            <input type="text" class="form-control" name="otp" id="otp" required />
                        </div>
                        <button type="button" class="btn btn-success w-100 mb-2" id="verifyOtpBtn">Verify OTP</button>
                        <div id="timer" class="text-danger mt-2"></div>
                        <button type="button" class="btn btn-warning w-100 mt-3" id="resendOtpBtn" style="display:none;" disabled>Resend OTP</button>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep3Btn">Next</button>
                </div>
                <!-- Step 4: Password and Confirmation -->
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
                        <input type="checkbox" class="form-check-input" name="agree_terms" required />
                        <label class="form-check-label">I agree to the Terms</label>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="submit" name="register" class="btn btn-success w-100">Submit</button>
                </div>
            </form>
        </div>
    </div>
    <div id="notifier" class="notifier"></div>
    <script>
        let countdown;
        let currentStep = 1;
        let otpVerified = false;

        function updateRequiredFields() {
            // Disable all required fields initially
            document.querySelectorAll('.step-panel input[required], .step-panel select[required], .step-panel textarea[required]').forEach(el => {
                el.disabled = true;
            });
            // Enable required fields in the visible panel only
            document.querySelectorAll(`#step-${currentStep} input[required], #step-${currentStep} select[required], #step-${currentStep} textarea[required]`).forEach(el => {
                el.disabled = false;
            });
        }

        function showStep(stepNumber) {
            document.querySelectorAll('.step-panel').forEach(panel => {
                panel.classList.add('d-none');
            });
            document.getElementById(`step-${stepNumber}`).classList.remove('d-none');

            document.querySelectorAll('.step').forEach((step, index) => {
                if (index + 1 === stepNumber) {
                    step.classList.add('active');
                } else {
                    step.classList.remove('active');
                }
            });
            currentStep = stepNumber;
            updateRequiredFields();
        }

        function showNotifier(message, type = 'error') {
            const notifier = document.getElementById('notifier');
            notifier.textContent = message;
            notifier.classList.remove('success', 'error');
            notifier.classList.add(type);
            notifier.style.display = 'block';

            setTimeout(() => {
                notifier.style.display = 'none';
            }, 3000);
        }

        function nextStep() {
            if (currentStep === 4) return;

            let isValid = true;
            const currentPanel = document.getElementById(`step-${currentStep}`);
            const inputs = currentPanel.querySelectorAll('input[required], select[required], textarea[required]');

            inputs.forEach(input => {
                if (input.type === 'radio') {
                    const radioGroupName = input.name;
                    if (!document.querySelector(`input[name="${radioGroupName}"]:checked`)) {
                        isValid = false;
                    }
                } else if (input.type === 'checkbox') {
                    if (!input.checked) {
                        isValid = false;
                    }
                } else if (!input.value.trim()) {
                    isValid = false;
                }
            });

            if (!isValid) {
                showNotifier('Please fill in all required fields for the current step.', 'error');
                return;
            }

            if (currentStep === 3) {
                if (!otpVerified) {
                    showNotifier('Please verify your OTP before proceeding.', 'error');
                    return;
                }
                showStep(currentStep + 1);
            } else if (currentStep < 4) {
                showStep(currentStep + 1);
            }
        }

        function prevStep() {
            if (currentStep > 1) {
                showStep(currentStep - 1);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            showStep(1);
            updateRequiredFields();
            document.getElementById('nextStep3Btn').disabled = true;
            document.getElementById('nextStep3Btn').addEventListener('click', nextStep);
        });

        // ---- OTP BUTTON HANDLING ----

        document.getElementById("sendOtpBtn").addEventListener("click", function() {
            const emailInput = document.getElementById('emailInput');
            const email = emailInput.value;

            if (!email || !/\S+@\S+\.\S+/.test(email)) {
                showNotifier('Please enter a valid email address before sending OTP.', 'error');
                return;
            }

            const sendOtpBtn = this;
            sendOtpBtn.disabled = true;
            sendOtpBtn.textContent = 'Sending OTP...';
            document.getElementById("resendOtpBtn").disabled = true;

            const formData = new FormData();
            formData.append('sendOtp', 'true');
            formData.append('email', email);

            fetch('student_register.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showNotifier(data.message, 'success');
                    document.getElementById("otpSection").classList.remove("d-none");
                    document.getElementById("sendOtpBtn").classList.add("d-none");
                    document.getElementById("resendOtpBtn").style.display = 'block';
                    startOtpTimer();
                } else {
                    showNotifier(data.message, 'error');
                    sendOtpBtn.disabled = false;
                    sendOtpBtn.textContent = "Send OTP (Email)";
                    document.getElementById("resendOtpBtn").disabled = true;
                }
            })
            .catch(error => {
                console.error('Error sending OTP:', error);
                showNotifier('Failed to send OTP. Please try again.', 'error');
                sendOtpBtn.disabled = false;
                sendOtpBtn.textContent = "Send OTP (Email)";
                document.getElementById("resendOtpBtn").disabled = true;
            });
        });

        document.getElementById("resendOtpBtn").addEventListener("click", function() {
            const emailInput = document.getElementById('emailInput');
            const email = emailInput.value;

            if (document.getElementById('timer').textContent !== "OTP expired. Please request a new OTP.") {
                return;
            }

            const resendOtpBtn = this;
            resendOtpBtn.disabled = true;
            resendOtpBtn.textContent = 'Resending OTP...';

            const formData = new FormData();
            formData.append('sendOtp', 'true');
            formData.append('email', email);

            fetch('student_register.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showNotifier(data.message, 'success');
                    startOtpTimer();
                } else {
                    showNotifier(data.message, 'error');
                    resendOtpBtn.disabled = false;
                    resendOtpBtn.textContent = "Resend OTP";
                }
            })
            .catch(error => {
                console.error('Error sending OTP:', error);
                showNotifier('Failed to send OTP. Please try again.', 'error');
                resendOtpBtn.disabled = false;
                resendOtpBtn.textContent = "Resend OTP";
            });
        });

        document.getElementById("verifyOtpBtn").addEventListener("click", function() {
            const enteredOtp = document.getElementById('otp').value;
            const emailForOtpVerification = document.getElementById('emailInput').value;

            if (!enteredOtp) {
                showNotifier('Please enter the OTP.', 'error');
                return;
            }

            const verifyOtpBtn = this;
            verifyOtpBtn.disabled = true;
            verifyOtpBtn.textContent = 'Verifying...';

            const formData = new FormData();
            formData.append('verifyOtp', 'true');
            formData.append('otp', enteredOtp);
            formData.append('email', emailForOtpVerification);

            fetch('student_register.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showNotifier(data.message, 'success');
                    otpVerified = true;
                    document.getElementById('otp').disabled = true;
                    verifyOtpBtn.classList.add('btn-success');
                    verifyOtpBtn.textContent = 'Verified!';
                    verifyOtpBtn.disabled = true;
                    clearInterval(countdown);
                    document.getElementById('timer').textContent = '';
                    document.getElementById('resendOtpBtn').style.display = 'none';
                    document.getElementById('nextStep3Btn').disabled = false;
                    document.getElementById('emailInput').disabled = true;
                    document.getElementById('emailInput').classList.add('verified-email');
                } else {
                    showNotifier(data.message, 'error');
                    verifyOtpBtn.disabled = false;
                    verifyOtpBtn.textContent = "Verify OTP";
                    otpVerified = false;
                }
            })
            .catch(error => {
                console.error('Error verifying OTP:', error);
                showNotifier('Failed to verify OTP. Please try again.', 'error');
                verifyOtpBtn.disabled = false;
                verifyOtpBtn.textContent = "Verify OTP";
                otpVerified = false;
            });
        });

        function startOtpTimer() {
            let timeLeft = 40;
            clearInterval(countdown);
            document.getElementById('timer').textContent = `Time left: ${timeLeft} seconds`;

            countdown = setInterval(function() {
                timeLeft--;
                document.getElementById('timer').textContent = `Time left: ${timeLeft} seconds`;

                if (timeLeft <= 0) {
                    clearInterval(countdown);
                    document.getElementById('timer').textContent = "OTP expired. Please request a new OTP.";
                    document.getElementById('otp').disabled = false;
                    document.getElementById('verifyOtpBtn').disabled = false;
                    document.getElementById('verifyOtpBtn').textContent = 'Verify OTP';
                    document.getElementById('verifyOtpBtn').classList.remove('btn-success');
                    document.getElementById('resendOtpBtn').disabled = false;
                    document.getElementById('resendOtpBtn').style.display = 'block';
                    document.getElementById('sendOtpBtn').classList.add('d-none');
                    otpVerified = false;
                    document.getElementById('nextStep3Btn').disabled = true;
                }
            }, 1000);
        }

        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirmPassword');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');

        function updatePasswordStrength() {
            const password = passwordInput.value;
            let strength = 0;

            if (password.length >= 12) strength += 25;
            if (/[A-Z]/.test(password)) strength += 25;
            if (/[a-z]/.test(password)) strength += 25;
            if (/[0-9]/.test(password)) strength += 25;
            if (/[^A-Za-z0-9]/.test(password)) strength += 25;

            strength = Math.min(strength, 100);

            strengthBar.style.width = strength + '%';
            strengthBar.className = 'progress-bar';

            if (strength < 50) {
                strengthBar.classList.add('bg-danger');
                strengthText.textContent = 'Weak';
            } else if (strength < 75) {
                strengthBar.classList.add('bg-warning');
                strengthText.textContent = 'Medium';
            } else {
                strengthBar.classList.add('bg-success');
                strengthText.textContent = 'Strong';
            }

            if (password.length === 0) {
                strengthBar.style.width = '0%';
                strengthText.textContent = '';
            }
        }

        passwordInput.addEventListener('input', updatePasswordStrength);

        confirmPasswordInput.addEventListener('input', function() {
            if (passwordInput.value !== confirmPasswordInput.value) {
                confirmPasswordInput.setCustomValidity('Passwords do not match');
            } else {
                confirmPasswordInput.setCustomValidity('');
            }
        });

        // ----- FIX FOR REQUIRED FIELD ERROR -----
        document.getElementById('multiStepForm').addEventListener('submit', function(e) {
            if (currentStep !== 4) {
                e.preventDefault();
                showNotifier('Please complete all steps first.', 'error');
                return;
            }
            // Show all panels and enable all fields for browser validation
            document.querySelectorAll('.step-panel').forEach(panel => {
                panel.classList.remove('d-none');
                panel.style.display = '';
            });
            document.querySelectorAll('input, select, textarea').forEach(el => {
                el.disabled = false;
            });
        });
    </script>
</body>
</html>
