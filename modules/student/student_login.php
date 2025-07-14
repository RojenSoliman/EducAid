<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (isset($_SESSION['student_username'])) {
    header("Location: homepage.php");
    exit;
}
?>
<!-- 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Login</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        label { display: block; margin-top: 10px; }
        input { width: 100%; padding: 8px; margin-top: 5px; }
        button { margin-top: 15px; padding: 10px 20px; }
    </style>
</head>
<body>
    <h2>Student Login</h2>
    <form method="post" action="login.php">
        <label>First Name:</label>
        <input type="text" name="firstname" required>
        
        <label>Middle Name:</label>
        <input type="text" name="middlename" required>

        <label>Last Name:</label>
        <input type="text" name="lastname" required>

        <label>Password:</label>
        <input type="password" name="password" required>
        
        <button type="submit" name="login">Login</button>
    </form> -->

    <?php
    if (isset($_POST['student_login'])) {
        // $firstname = $_POST['firstname'];
        // $middlename = $_POST['middlename'];
        // $lastname = $_POST['lastname'];
        // Get email and password from the HTML form
        $email = $_POST['email'];
        $password = $_POST['password'];
    
        if (!isset($connection) || !$connection) {
            echo "<p style='color:red;'>" . htmlspecialchars("Connection failed.") . "</p>";
            exit;
        }
    
        $result = pg_query_params(
            $connection,
            // "SELECT * FROM students WHERE first_name = $1 AND middle_name = $2 AND last_name = $3",
            // [$firstname, $middlename, $lastname]
            "SELECT * FROM students WHERE email = $1",
            [$email]
        );

        if ($result === false) {
            echo "<p style='color:red;'>Database query error.</p>";
        } elseif ($row = pg_fetch_assoc($result)) {
            if (password_verify($password, $row['password'])) {
                // $_SESSION['student_username'] = $firstname . ' ' . $middlename . ' ' . $lastname;
                $_SESSION['student_username'] = $row['email'];
                $_SESSION['student_id'] = $row['student_id'];
                header("Location: homepage.php");
                echo "<p style='color:red;'>" . htmlspecialchars("Invalid password.") . "</p>";
            } else {
                echo "<p style='color:red;'>Invalid password.</p>";
            }
        } else {
            echo "<p style='color:red;'>User not found.</p>";
        }
    
        if (isset($connection) && $connection) {
            pg_close($connection);
        }
    }
    ?>
<!-- </body>
</html> -->