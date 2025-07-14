<?php
    include __DIR__ . '/../../config/database.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Signup (EducAid)</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 500px; margin: auto; }
        label { display: block; margin-top: 10px; }
        input { width: 100%; padding: 8px; margin-top: 5px; }
        button { margin-top: 15px; padding: 10px 20px; }
    </style>
</head>
<body>
    <h2>Register New Student Applicant (General Trias)</h2>
    <form method="post" action="">
        <label>First Name:</label>
        <input type="text" name="firstname" required>

        <label>Middle Name:</label>
        <input type="text" name="middlename" required>

        <label>Last Name:</label>
        <input type="text" name="lastname" required>

        <label>Age:</label>
        <input type="number" name="age" required min="1" max="75">

        <fieldset style="border:none; padding:0; margin:0;">
            <legend>Sex:</legend>
            <input type="radio" id="sex_male" name="sex" value="Male" required>
            <label for="sex_male" style="display:inline;">Male</label>
            <input type="radio" id="sex_female" name="sex" value="Female">
            <label for="sex_female" style="display:inline;">Female</label>
        </fieldset>

        <label>Barangay:</label>
        <input type="text" name="barangay" required>

        <label>Email Address:</label>
        <input type="text" name="email" required>

        <label>Contact Number:</label>
        <input type="tel" name="contnumber" required>

        <label>Password:</label>
        <input type="password" name="password" id="password" required>
        <button type="button" onclick="togglePassword('password', this)">Show</button>

        <label>Confirm Password:</label>
        <input type="password" name="confirm_password" id="confirm_password" required>
        <button type="button" onclick="togglePassword('confirm_password', this)">Show</button>

        <script>
        function togglePassword(fieldId, btn) {
            const input = document.getElementById(fieldId);
            if (input.type === "password") {
            input.type = "text";
            btn.textContent = "Hide";
            } else {
            input.type = "password";
            btn.textContent = "Show";
            }
        }
        </script>

        <button type="submit" name="register">Apply Now!</button>
    </form>

    <?php
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
</body>
</html>