<?php
include_once '../../config/database.php'; // Include database connection
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP; // Added for explicit SMTP constant usage

// Include PHPMailer
require 'C:/xampp/htdocs/EducAid/phpmailer/vendor/autoload.php'; // Ensure the correct path to autoload

// Define municipality ID
$municipality_id = 1; // Or fetch this dynamically based on context

// 🔹 Check on page load (not POST): prevent form from rendering if slots are full
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
                    animation: spin 1s linear infinite; /* Basic spin animation */
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

// 🔹 OTP Sending Logic (AJAX call)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['sendOtp'])) {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
        exit;
    }

    // Check if email already exists in DB before sending OTP
    $checkEmail = pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1", [$email]);
    if (pg_num_rows($checkEmail) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'This email is already registered. Please use a different email or login.']);
        exit;
    }

    // Generate OTP (6 digits)
    $otp = rand(100000, 999999);

    // Store OTP temporarily (session) for validation and add timestamp
    $_SESSION['otp'] = $otp;
    $_SESSION['otp_email'] = $email;
    $_SESSION['otp_timestamp'] = time(); // OTP valid for 40 seconds from now

    // Send OTP via PHPMailer
    $mail = new PHPMailer(true);
  
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Gmail SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'dilucayaka02@gmail.com'; // Your Gmail email
        $mail->Password   = 'jlld eygl hksj flvg'; // YOUR GENERATED GMAIL APP PASSWORD
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Use TLS encryption
        $mail->Port       = 587; // TCP port to connect to

        // Recipients
        $mail->setFrom('dilucayaka02@gmail.com', 'EducAid'); // Sender email and name
        $mail->addAddress($email); // Recipient email

        // Content
        $mail->isHTML(true); // Set email format to HTML
        $mail->Subject = 'Your EducAid OTP Code';
        $mail->Body    = "Your One-Time Password (OTP) for EducAid registration is: <strong>$otp</strong><br><br>This OTP is valid for 40 seconds.";
        $mail->AltBody = "Your One-Time Password (OTP) for EducAid registration is: $otp. This OTP is valid for 40 seconds.";

        $mail->send();
        echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email. Please check your inbox and spam folder.']);
    } catch (Exception $e) {
        error_log("PHPMailer Error: {$mail->ErrorInfo}"); // Log the error for debugging
        echo json_encode(['status' => 'error', 'message' => 'Message could not be sent. Please check your email address and try again.']);
    }
    exit; // Important: exit after sending AJAX response
}

// 🔹 OTP Verification Logic (AJAX call)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verifyOtp'])) {
    $enteredOtp = filter_var($_POST['otp'], FILTER_SANITIZE_NUMBER_INT);
    $email_for_otp = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL); // Get email to check against session

    // Check if OTP session variables exist and are not expired (e.g., 40 seconds from timestamp)
    if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_email']) || !isset($_SESSION['otp_timestamp'])) {
        echo json_encode(['status' => 'error', 'message' => 'No OTP sent or session expired. Please request a new OTP.']);
        exit;
    }

    if ($_SESSION['otp_email'] !== $email_for_otp) {
         echo json_encode(['status' => 'error', 'message' => 'Email mismatch for OTP. Please ensure you are verifying the correct email.']);
         exit;
    }

    if ((time() - $_SESSION['otp_timestamp']) > 40) { // OTP valid for 40 seconds
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']); // Clear expired OTP
        echo json_encode(['status' => 'error', 'message' => 'OTP has expired. Please request a new OTP.']);
        exit;
    }

    if ((int)$enteredOtp === (int)$_SESSION['otp']) { // Cast to int for strict comparison
        echo json_encode(['status' => 'success', 'message' => 'OTP verified successfully!']);
        // Mark OTP as verified in session to allow proceeding
        $_SESSION['otp_verified'] = true;
        // Optionally, immediately clear OTP data if it's a one-time use
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid OTP. Please try again.']);
        $_SESSION['otp_verified'] = false; // Mark as not verified
    }
    exit; // Important: exit after sending AJAX response
}

// 🔹 Registration Logic (when the final form is submitted)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['register'])) {
    // Re-check OTP verification status from session
    if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
        echo "<script>alert('OTP not verified. Please verify your email first.'); history.back();</script>";
        exit;
    }

    // Sanitize and validate all inputs
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

    // Basic server-side validation for required fields
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

    // Recheck duplicates (in case someone submitted multiple times or race condition)
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

    // Recheck slot (safety redundancy)
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

    // Insert new student
    $insertQuery = "INSERT INTO students (municipality_id, first_name, middle_name, last_name, email, mobile, password, sex, status, payroll_no, qr_code, has_received, application_date, bdate, barangay_id, university, year_level, program)
                    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'applicant', 0, 0, FALSE, NOW(), $9, $10, $11, $12) RETURNING student_id";

    $result = pg_query_params($connection, $insertQuery, [$municipality_id, $firstname, $middlename, $lastname, $email, $mobile, $hashed, $sex, $bdate, $barangay, $_POST['university'], $_POST['year_level'], $_POST['program']]);

    if ($result) {
        // Get the student_id from the returned result
        $student_id_row = pg_fetch_assoc($result);
        $student_id = $student_id_row['student_id'];

        // Insert into applications table (link student to active slot)
        $semester = $slotInfo['semester'];
        $academic_year = $slotInfo['academic_year'];
        $applicationQuery = "INSERT INTO applications (student_id, semester, academic_year) VALUES ($1, $2, $3)";
        pg_query_params($connection, $applicationQuery, [$student_id, $semester, $academic_year]);

        // Clear the OTP verification status from session after successful registration
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
        .step-panel.d-none {
            display: none !important;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .step {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #e0e0e0;
            color: #777;
            font-weight: bold;
            margin: 0 5px;
            transition: background-color 0.3s, color 0.3s;
        }
        .step.active {
            background-color: #007bff;
            color: white;
        }
        .notifier {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 15px 30px;
            background-color: #f8d7da;
            color: #721c24;
            border-radius: 5px;
            display: none;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            z-index: 1000; /* Ensure notifier is on top */
        }
        .notifier.success {
            background-color: #d4edda;
            color: #155724;
        }
        /* Style for the verified email input field */
        .verified-email {
            background-color: #e9f7e9; /* Light green background */
            color: #28a745; /* Green text color */
        }
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

            <form id="multiStepForm" method="POST">
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

                <!-- Step 3: University, Year Level, Program -->
                <div class="step-panel d-none" id="step-3">
                    <div class="mb-3">
                        <label class="form-label">University</label>
                        <select name="university" class="form-select" required>
                            <option value="" disabled selected>Select your university</option>
                            <option value="LPU">LPU</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Year Level</label>
                        <select name="year_level" class="form-select" required>
                            <option value="" disabled selected>Select your year level</option>
                            <option value="1st">1st Year</option>
                            <option value="2nd">2nd Year</option>
                            <option value="3rd">3rd Year</option>
                            <option value="4th">4th Year</option>
                            <option value="5th">5th Year</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Program</label>
                        <select name="program" class="form-select" required>
                            <option value="" disabled selected>Select your program</option>
                            <option value="Computer Science">Computer Science</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" onclick="nextStep()">Next</button>
                </div>

                <!-- Step 4: OTP Verification -->
                <div class="step-panel d-none" id="step-4">
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

                <!-- Step 5: Password and Confirmation -->
                <div class="step-panel d-none" id="step-5">
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
        let otpVerified = false; // Flag to track OTP verification status

        // Function to show a specific step
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
        }

        // Function to display notifications
        function showNotifier(message, type = 'error') {
            const notifier = document.getElementById('notifier');
            notifier.textContent = message;
            notifier.classList.remove('success', 'error');
            notifier.classList.add(type);
            notifier.style.display = 'block';

            setTimeout(() => {
                notifier.style.display = 'none';
            }, 3000); // Hide notifier after 3 seconds
        }

        // Function to go to the next step
        function nextStep() {
            let isValid = true;
            const currentPanel = document.getElementById(`step-${currentStep}`);
            const inputs = currentPanel.querySelectorAll('input[required], select[required], textarea[required]');

            // Basic required field validation for current step
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

            // Specific validation for each step
            if (currentStep === 3) {
                const university = document.querySelector('select[name="university"]').value;
                const year_level = document.querySelector('select[name="year_level"]').value;
                const program = document.querySelector('select[name="program"]').value;

                if (!university || !year_level || !program) {
                    showNotifier('Please select university, year level, and program.', 'error');
                    return;
                }
                showStep(currentStep + 1);
            } else if (currentStep === 4) {
                if (!otpVerified) {
                    showNotifier('Please verify your OTP before proceeding.', 'error');
                    return;
                }
                showStep(currentStep + 1);
            } else if (currentStep < 5) {
                showStep(currentStep + 1);
            }
        }

        // Function to go to the previous step
        function prevStep() {
            if (currentStep > 1) {
                showStep(currentStep - 1);
            }
        }

        // Initialize the first step on page load
        document.addEventListener('DOMContentLoaded', () => {
            showStep(1);
            // Disable next button on step 3 until OTP is verified
            document.getElementById('nextStep3Btn').disabled = true;

            // Attach nextStep function to nextStep3Btn
            document.getElementById('nextStep3Btn').addEventListener('click', nextStep);
        });

        // 🔹 Send OTP Button Event Listener (AJAX)
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
            document.getElementById("resendOtpBtn").disabled = true; // Disable resend button while sending OTP

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
                    document.getElementById("resendOtpBtn").style.display = 'block'; // Show resend button
                    startOtpTimer();
                } else {
                    showNotifier(data.message, 'error');
                    sendOtpBtn.disabled = false;
                    sendOtpBtn.textContent = "Send OTP (Email)";
                    document.getElementById("resendOtpBtn").disabled = true; // Disable resend if OTP sending failed
                }
            })
            .catch(error => {
                console.error('Error sending OTP:', error);
                showNotifier('Failed to send OTP. Please try again.', 'error');
                sendOtpBtn.disabled = false;
                sendOtpBtn.textContent = "Send OTP (Email)";
                document.getElementById("resendOtpBtn").disabled = true; // Disable resend if OTP sending failed
            });
        });

        // 🔹 Resend OTP Button Event Listener (AJAX)
        document.getElementById("resendOtpBtn").addEventListener("click", function() {
            const emailInput = document.getElementById('emailInput');
            const email = emailInput.value;

            // Disable resend button while the timer is active
            if (document.getElementById('timer').textContent !== "OTP expired. Please request a new OTP.") {
                return; // Prevent clicking resend if timer is active
            }

            const resendOtpBtn = this;
            resendOtpBtn.disabled = true; // Disable immediately when clicked
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
                    startOtpTimer(); // Start OTP timer after successful resend
                } else {
                    showNotifier(data.message, 'error');
                    resendOtpBtn.disabled = false;
                    resendOtpBtn.textContent = "Resend OTP"; // Re-enable button if OTP sending fails
                }
            })
            .catch(error => {
                console.error('Error sending OTP:', error);
                showNotifier('Failed to send OTP. Please try again.', 'error');
                resendOtpBtn.disabled = false;
                resendOtpBtn.textContent = "Resend OTP"; // Re-enable button if OTP sending failed
            });
        });

        // 🔹 Verify OTP Button Event Listener (AJAX)
        document.getElementById("verifyOtpBtn").addEventListener("click", function() {
            const enteredOtp = document.getElementById('otp').value;
            const emailForOtpVerification = document.getElementById('emailInput').value; // Get the email to send with OTP for verification

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
            formData.append('email', emailForOtpVerification); // Send the email along with the OTP

            fetch('student_register.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showNotifier(data.message, 'success');
                    otpVerified = true; // Set flag to true
                    document.getElementById('otp').disabled = true; // Disable OTP input after verification
                    verifyOtpBtn.classList.add('btn-success');
                    verifyOtpBtn.textContent = 'Verified!';
                    verifyOtpBtn.disabled = true; // Keep disabled after successful verification
                    clearInterval(countdown); // Stop the timer
                    document.getElementById('timer').textContent = ''; // Clear timer text
                    document.getElementById('resendOtpBtn').style.display = 'none'; // Hide resend button
                    document.getElementById('nextStep3Btn').disabled = false; // Enable next button

                    // Lock the email field and show the verified check
                    document.getElementById('emailInput').disabled = true;
                    document.getElementById('emailInput').classList.add('verified-email'); // Add the green checkmark style
                } else {
                    showNotifier(data.message, 'error');
                    verifyOtpBtn.disabled = false;
                    verifyOtpBtn.textContent = "Verify OTP";
                    otpVerified = false; // Reset flag
                }
            })
            .catch(error => {
                console.error('Error verifying OTP:', error);
                showNotifier('Failed to verify OTP. Please try again.', 'error');
                verifyOtpBtn.disabled = false;
                verifyOtpBtn.textContent = "Verify OTP";
                otpVerified = false; // Reset flag
            });
        });

        // Function to start OTP Timer
        function startOtpTimer() {
            let timeLeft = 40; // 40 seconds
            clearInterval(countdown); // Clear any previous timer
            document.getElementById('timer').textContent = `Time left: ${timeLeft} seconds`; // Initial display

            countdown = setInterval(function() {
                timeLeft--;
                document.getElementById('timer').textContent = `Time left: ${timeLeft} seconds`;

                if (timeLeft <= 0) {
                    clearInterval(countdown);
                    document.getElementById('timer').textContent = "OTP expired. Please request a new OTP.";
                    document.getElementById('otp').disabled = false; // Re-enable OTP textbox
                    document.getElementById('verifyOtpBtn').disabled = false;
                    document.getElementById('verifyOtpBtn').textContent = 'Verify OTP';
                    document.getElementById('verifyOtpBtn').classList.remove('btn-success');
                    document.getElementById('resendOtpBtn').disabled = false; // Enable resend button
                    document.getElementById('resendOtpBtn').style.display = 'block'; // Make sure resend button is visible
                    document.getElementById('sendOtpBtn').classList.add('d-none'); // Hide initial send OTP button
                    otpVerified = false; // Reset verification status
                    document.getElementById('nextStep3Btn').disabled = true; // Disable next button
                }
            }, 1000);
        }

        // Password Strength Indicator (Optional but good for UX)
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
            if (/[^A-Za-z0-9]/.test(password)) strength += 25; // Symbols

            // Cap strength at 100%
            strength = Math.min(strength, 100);

            strengthBar.style.width = strength + '%';
            strengthBar.className = 'progress-bar'; // Reset classes

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

        // Add validation for confirm password (client-side)
        confirmPasswordInput.addEventListener('input', function() {
            if (passwordInput.value !== confirmPasswordInput.value) {
                confirmPasswordInput.setCustomValidity('Passwords do not match');
            } else {
                confirmPasswordInput.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
