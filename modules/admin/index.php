<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (isset($_SESSION['admin_username'])) {
    header("Location: homepage.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Admin Login</title>
    <link rel="stylesheet" href="../../assets/css/admin/index.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
</head>
<body>
    <div class="login-wrapper">
        <div class="right-panel">
            <form class="login-form" method="post" action="index.php">
                <img src="../../assets/images/logo.png" alt="General Trias Logo" class="logo" />
                <h1 class="title">EducAid</h1>
                <p class="subtext">Welcome Back, Administrator!</p>
                
                <div class="input-group">
                    <label for="username">Email</label>
                    <input type="text" name="username" placeholder="Enter email" required />
                </div>

                <div class="input-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" placeholder="Enter password" required />
                </div>

                <button type="submit" name="login">Sign In</button>
                <div class="form-footer">
                    <a href="#">Forgot your password?</a>
                </div>

                <?php
                if (isset($_POST['login'])) {
                    $username = $_POST['username'];
                    $password = $_POST['password'];

                    if (!$connection) {
                        echo "<p class='error'>Connection failed.</p>";
                        exit;
                    }

                    $result = pg_query_params($connection, "SELECT * FROM admins WHERE username = $1", [$username]);

                    if ($row = pg_fetch_assoc($result)) {
                        if (password_verify($password, $row['password'])) {
                            $_SESSION['admin_username'] = $username;
                            $_SESSION['admin_id'] = $row['admin_id'];
                            header("Location: homepage.php");
                            exit;
                        } else {
                            echo "<p class='error'>Invalid password.</p>";
                        }
                    } else {
                        echo "<p class='error'>User not found.</p>";
                    }

                    pg_close($connection);
                }
                ?>
            </form>
        </div>
    </div>
</body>
</html>
