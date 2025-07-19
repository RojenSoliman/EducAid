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
        echo "<script>alert('Invalid email format.');</script>";
        exit;
    }

    // Check if email already exists in DB before sending OTP
    $checkEmail = pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1", [$email]);
    if (pg_num_rows($checkEmail) > 0) {
        echo "<script>alert('This email is already registered. Please use a different email or login.');</script>";
        exit;
    }

    // Generate OTP (6 digits)
    $otp = rand(100000, 999999);

    // Store OTP temporarily (session) for validation and add timestamp
    $_SESSION['otp'] = $otp;
    $_SESSION['otp_email'] = $email;
    $_SESSION['otp_timestamp'] = time(); // OTP valid for 90 seconds from now

    // Send OTP via PHPMailer
    $mail = new PHPMailer(true);
    $mail->SMTPDebug = 2;  // Enable SMTP debug output (level 2: client and server conversation)
    $mail->isSMTP(); // Set mailer to use SMTP
    $mail->SMTPDebug = 2;  // Enable debug output (level 2 for client-server conversation)
    $mail->Debugoutput = 'html';  // Output the debug in HTML format

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Gmail SMTP server
        $mail->SMTPAuth   = true;
        // IMPORTANT: Use your actual Gmail email and App Password here
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
        $mail->Body    = "Your One-Time Password (OTP) for EducAid registration is: <strong>$otp</strong><br><br>This OTP is valid for 1 minute 30 seconds.";
        $mail->AltBody = "Your One-Time Password (OTP) for EducAid registration is: $otp. This OTP is valid for 1 minute 30 seconds.";

        $mail->send();
        echo "<script>alert('OTP sent to your email. Please check your inbox and spam folder.');</script>";
        // Signal success to JS to show OTP section
        echo "<script>document.getElementById('otpSection').classList.remove('d-none'); document.getElementById('sendOtpBtn').disabled = true; startOtpTimer();</script>";
    } catch (Exception $e) {
        error_log("PHPMailer Error: {$mail->ErrorInfo}"); // Log the error for debugging
        echo "<script>alert('Message could not be sent. Please check your email address and try again. Mailer Error: " . htmlspecialchars($mail->ErrorInfo) . "');</script>";
    }
    exit; // Important: exit after sending AJAX response
}

// 🔹 OTP Verification Logic (AJAX call)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verifyOtp'])) {
    $enteredOtp = filter_var($_POST['otp'], FILTER_SANITIZE_NUMBER_INT);
    $email_for_otp = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL); // Get email to check against session

    // Check if OTP session variables exist and are not expired (e.g., 90 seconds from timestamp)
    if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_email']) || !isset($_SESSION['otp_timestamp'])) {
        echo "<script>alert('No OTP sent or session expired. Please request a new OTP.');</script>";
        exit;
    }

    if ($_SESSION['otp_email'] !== $email_for_otp) {
         echo "<script>alert('Email mismatch for OTP. Please ensure you are verifying the correct email.');</script>";
         exit;
    }

    if ((time() - $_SESSION['otp_timestamp']) > 90) { // OTP valid for 90 seconds
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']); // Clear expired OTP
        echo "<script>alert('OTP has expired. Please request a new OTP.');</script>";
        exit;
    }

    if ((int)$enteredOtp === (int)$_SESSION['otp']) { // Cast to int for strict comparison
        echo "<script>alert('OTP verified successfully!');</script>";
        // Mark OTP as verified in session to allow proceeding
        $_SESSION['otp_verified'] = true;
        // Optionally, immediately clear OTP data if it's a one-time use
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_timestamp']);
        // Signal success to JS to allow navigation to next step
        echo "<script>document.getElementById('verifyOtpBtn').disabled = true; document.getElementById('otp').disabled = true; clearInterval(countdown); document.getElementById('timer').textContent = '';</script>";
    } else {
        echo "<script>alert('Invalid OTP. Please try again.');</script>";
        $_SESSION['otp_verified'] = false; // Mark as not verified
    }
    exit; // Important: exit after sending AJAX response
}

// 🔹 Registration Logic (when the final form is submitted)
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

    // Recheck slot (safety redundancy)
    $slotRes = pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
    $slotInfo = pg_fetch_assoc($slotRes);
    if (!$slotInfo) {
        echo "<script>alert('No active slot found.'); history.back();</script>";
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
        echo "<script>alert('Slots are full. Please wait for the next round.'); window.location.href = 'student_login.html';</script>";
        exit;
    }

    // Insert new student
    $insertQuery = "INSERT INTO students (municipality_id, first_name, middle_name, last_name, email, mobile, password, sex, status, payroll_no, qr_code, has_received, application_date, bdate, barangay_id)
                    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'applicant', 0, 0, FALSE, NOW(), $9, $10) RETURNING student_id";

    $result = pg_query_params($connection, $insertQuery, [$municipality_id, $firstname, $middlename, $lastname, $email, $mobile, $hashed, $sex, $bdate, $barangay]);

    if ($result) {
        // Get the student_id from the returned result
        $student_id_row = pg_fetch_assoc($result);
        $student_id = $student_id_row['student_id'];

        // Insert into applications table (link student to active slot)
        $semester = $slotInfo['semester'];  // 1st or 2nd semester
        $academic_year = $slotInfo['academic_year'];  // Format: 2025-2026
        $applicationQuery = "INSERT INTO applications (student_id, semester, academic_year) VALUES ($1, $2, $3)";
        pg_query_params($connection, $applicationQuery, [$student_id, $semester, $academic_year]);

        echo "<script>alert('Registration successful!'); window.location.href = 'student_login.html';</script>";
        exit;
    } else {
        echo "<script>alert('Registration failed.');</script>";
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
        /* Add basic styling for hidden steps if registration.css doesn't cover it */
        .step-panel.d-none {
            display: none !important;
        }
        /* Basic styling for step indicators */
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
            background-color: #007bff; /* Primary color */
            color: white;
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
            </div>

            <form id="multiStepForm" method="POST">
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

                <div class="step-panel d-none" id="step-3">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="emailInput" required />
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
                    </div>

                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary w-100" id="nextStep3Btn" onclick="nextStep()">Next</button>
                </div>

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
                alert('Please fill in all required fields for the current step.');
                return;
            }

            // Specific validation for each step
            if (currentStep === 2) {
                const bdateInput = currentPanel.querySelector('input[name="bdate"]');
                if (bdateInput && !bdateInput.value) {
                     alert('Please enter your birthdate.');
                     return;
                }
                const barangaySelect = currentPanel.querySelector('select[name="barangay_id"]');
                if (barangaySelect && barangaySelect.value === "") {
                    alert('Please select your barangay.');
                    return;
                }
            } else if (currentStep === 3) {
                const emailInput = document.getElementById('emailInput');
                const phoneInput = currentPanel.querySelector('input[name="phone"]');

                if (!emailInput.value.trim() || !/\S+@\S+\.\S+/.test(emailInput.value)) {
                    alert('Please enter a valid email address.');
                    return;
                }
                if (!phoneInput.value.trim() || !/^09[0-9]{9}$/.test(phoneInput.value)) {
                    alert('Please enter a valid 11-digit phone number starting with 09.');
                    return;
                }

                // Crucial check for Step 3: OTP must be verified
                if (!otpVerified) {
                    alert('Please send OTP and verify it before proceeding to the next step.');
                    return;
                }
            } else if (currentStep === 4) {
                const passwordInput = document.getElementById('password');
                const confirmPasswordInput = document.getElementById('confirmPassword');
                if (passwordInput.value.length < 12) {
                    alert('Password must be at least 12 characters.');
                    return;
                }
                if (passwordInput.value !== confirmPasswordInput.value) {
                    alert('Passwords do not match.');
                    return;
                }
                const termsCheckbox = currentPanel.querySelector('input[name="agree_terms"]');
                if (termsCheckbox && !termsCheckbox.checked) {
                    alert('You must agree to the Terms to proceed.');
                    return;
                }
            }

            // If validation passes, proceed to the next step
            if (currentStep < 4) {
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
        });

        // 🔹 Send OTP Button Event Listener (AJAX)
        document.getElementById("sendOtpBtn").addEventListener("click", function() {
            const emailInput = document.getElementById('emailInput');
            const email = emailInput.value;

            if (!email || !/\S+@\S+\.\S+/.test(email)) {
                alert('Please enter a valid email address before sending OTP.');
                return;
            }

            const sendOtpBtn = this;
            sendOtpBtn.disabled = true;
            sendOtpBtn.textContent = 'Sending OTP...';

            const formData = new FormData();
            formData.append('sendOtp', 'true');
            formData.append('email', email);

            fetch('student_register.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                eval(data); // This will execute the alert from PHP
                // If OTP sent successfully, PHP script will also send JS to show otpSection and start timer
                // So, no need to manually show otpSection here.
                document.getElementById("otpSection").classList.remove("d-none"); // Ensure it's shown if PHP JS didn't fully work

                // Start 1 minute 30 seconds timer (90 seconds)
                let timeLeft = 90;
                const timerDisplay = document.getElementById("timer");
                timerDisplay.textContent = `Time remaining: 1:30`;

                clearInterval(countdown);
                countdown = setInterval(function() {
                    timeLeft--;
                    let minutes = Math.floor(timeLeft / 60);
                    let seconds = timeLeft % 60;

                    timerDisplay.textContent = `Time remaining: ${minutes}:${seconds < 10 ? '0' + seconds : seconds}`;

                    if (timeLeft <= 0) {
                        clearInterval(countdown);
                        document.getElementById("otp").disabled = true;
                        document.getElementById("verifyOtpBtn").disabled = true;
                        timerDisplay.textContent = "OTP expired. Please request a new OTP.";
                        sendOtpBtn.disabled = false;
                        sendOtpBtn.textContent = "Resend OTP";
                        otpVerified = false; // Reset verification status
                        document.getElementById('nextStep3Btn').disabled = true; // Disable next
                    }
                }, 1000);
            })
            .catch(error => {
                console.error('Error sending OTP:', error);
                alert('Failed to send OTP. Please try again.');
                sendOtpBtn.disabled = false;
                sendOtpBtn.textContent = "Send OTP (Email)";
            });
        });

        // 🔹 Verify OTP Button Event Listener (AJAX)
        document.getElementById("verifyOtpBtn").addEventListener("click", function() {
            const otpInput = document.getElementById('otp');
            const enteredOtp = otpInput.value;
            const emailInput = document.getElementById('emailInput');
            const email = emailInput.value;

            if (!enteredOtp.trim()) {
                alert('Please enter the OTP.');
                return;
            }

            const verifyOtpBtn = this;
            verifyOtpBtn.disabled = true;
            verifyOtpBtn.textContent = 'Verifying...';

            const formData = new FormData();
            formData.append('verifyOtp', 'true');
            formData.append('otp', enteredOtp);
            formData.append('email', email); // Send email along for verification

            fetch('student_register.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                eval(data); // Execute the alert from PHP
                // Check PHP's alert message to determine success/failure
                if (data.includes('OTP verified successfully')) {
                    otpVerified = true;
                    document.getElementById('nextStep3Btn').disabled = false; // Enable next button
                    otpInput.disabled = true; // Disable OTP input after verification
                    clearInterval(countdown); // Stop timer
                    document.getElementById('timer').textContent = ''; // Clear timer text
                } else {
                    otpVerified = false;
                    verifyOtpBtn.disabled = false; // Re-enable verify button on failure
                    verifyOtpBtn.textContent = 'Verify OTP';
                    document.getElementById('nextStep3Btn').disabled = true; // Keep next disabled
                }
            })
            .catch(error => {
                console.error('Error verifying OTP:', error);
                alert('Failed to verify OTP. Please try again.');
                verifyOtpBtn.disabled = false;
                verifyOtpBtn.textContent = 'Verify OTP';
                otpVerified = false;
                document.getElementById('nextStep3Btn').disabled = true;
            });
        });

        // 🔹 Password Strength Indicator
        const passwordInput = document.getElementById('password');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');
        const confirmPasswordInput = document.getElementById('confirmPassword'); // Added for real-time comparison feedback

        passwordInput.addEventListener('input', updatePasswordStrength);
        confirmPasswordInput.addEventListener('input', checkPasswordsMatch); // Listen for confirm password changes

        function updatePasswordStrength() {
            const password = passwordInput.value;
            let strength = 0;
            const feedback = [];

            // Criteria
            const hasLowercase = /[a-z]/.test(password);
            const hasUppercase = /[A-Z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const hasSpecialChar = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password);
            const isLongEnough = password.length >= 12;

            if (isLongEnough) {
                strength += 25;
            } else {
                feedback.push('At least 12 characters');
            }
            if (hasLowercase) {
                strength += 15;
            } else {
                feedback.push('Lowercase letter');
            }
            if (hasUppercase) {
                strength += 15;
            } else {
                feedback.push('Uppercase letter');
            }
            if (hasNumber) {
                strength += 20;
            } else {
                feedback.push('Number');
            }
            if (hasSpecialChar) {
                strength += 25;
            } else {
                feedback.push('Special character');
            }

            // Adjust strength based on feedback
            if (password.length === 0) {
                strengthBar.style.width = '0%';
                strengthBar.style.backgroundColor = '';
                strengthText.textContent = '';
            } else if (feedback.length > 0) {
                strengthText.textContent = 'Missing: ' + feedback.join(', ');
                if (strength < 50) {
                    strengthBar.style.backgroundColor = 'red';
                } else {
                    strengthBar.style.backgroundColor = 'orange';
                }
            } else {
                strengthText.textContent = 'Strong!';
                strengthBar.style.backgroundColor = 'green';
            }

            // Cap strength at 100%
            if (strength > 100) strength = 100;
            strengthBar.style.width = strength + '%';

            checkPasswordsMatch(); // Also check if passwords match when main password changes
        }

        function checkPasswordsMatch() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (confirmPassword.length > 0 && password !== confirmPassword) {
                confirmPasswordInput.setCustomValidity("Passwords do not match.");
            } else {
                confirmPasswordInput.setCustomValidity(""); // Clear validity if they match
            }
        }
    </script>
</body>
</html>

