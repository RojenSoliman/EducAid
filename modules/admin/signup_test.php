<?php
    include __DIR__ . '/../../config/database.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Signup (EducAid)</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 500px; margin: auto; }
        label { display: block; margin-top: 10px; }
        input { width: 100%; padding: 8px; margin-top: 5px; }
        button { margin-top: 15px; padding: 10px 20px; }
    </style>
</head>
<body>
    <h2>Register New Admin (General Trias)</h2>
    <form method="post" action="">
        <label>Username:</label>
        <input type="text" name="username" required>

        <label>Password:</label>
        <input type="password" name="password" required>

        <label>Confirm Password:</label>
        <input type="password" name="confirm_password" required>

        <button type="submit" name="register">Register Admin</button>
    </form>

    <?php
    if (isset($_POST['register'])) {
        $username = $_POST['username'];
        $pass = $_POST['password'];
        $confirm = $_POST['confirm_password'];
        $username = trim($_POST['username']);
        // Username validation: 4-32 chars, alphanumeric and underscores only
        if (!preg_match('/^\w{4,32}$/', $username)) {
            echo "<p style='color:red;'>Username must be 4-32 characters and contain only letters, numbers, and underscores.</p>";
            exit;
        }

        // Password validation: minimum 8 characters
        if (strlen($pass) < 8) {
            echo "<p style='color:red;'>Password must be at least 8 characters long.</p>";
            exit;
        }
        // Confirm password validation
        if ($pass !== $confirm) {
            echo "<p style='color:red;'>Passwords do not match.</p>";
            exit;
        }

        $hashed = password_hash($pass, PASSWORD_ARGON2ID);
        $municipality_id = 1;


        // Connect to PostgreSQL (update credentials)


        if (!$connection) {
            echo "<p style='color:red;'>Connection failed.</p>";
            exit;
        }

        // Insert into admins (no email or full_name)
        $query = "INSERT INTO admins (municipality_id, username, password) VALUES ($1, $2, $3)";
        $result = pg_query_params($connection, $query, [$municipality_id, $username, $hashed]);

        if ($result) {
            echo "<p style='color:green;'>Admin registered successfully!</p>";
        } else {
            echo "<p style='color:red;'>Error: " . pg_last_error($conn) . "</p>";
        }

        pg_close($connection);
    }
    ?>
</body>
</html>