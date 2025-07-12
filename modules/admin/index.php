<?php
    include __DIR__ . '/../../config/database.php';
    session_start();
    if (isset($_SESSION['username'])) {
        header("Location: homepage.php");
        exit;
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        label { display: block; margin-top: 10px; }
        input { width: 100%; padding: 8px; margin-top: 5px; }
        button { margin-top: 15px; padding: 10px 20px; }
    </style>
</head>
<body>
    <h2>Admin Login</h2>
    <form method="post" action="index.php">
        <label>Username:</label>
        <input type="text" name="username" required>
        
        <label>Password:</label>
        <input type="password" name="password" required>
        
        <button type="submit" name="login">Login</button>
    </form>

    <?php
    if (isset($_POST['login'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];

        if (!$connection) {
            echo "<p style='color:red;'>Connection failed.</p>";
            exit;
        }

        $result = pg_query_params($connection, "SELECT * FROM admins WHERE username = $1", [$username]);

        if ($row = pg_fetch_assoc($result)) {
            if (password_verify($password, $row['password'])) {
                $_SESSION['username'] = $username;
                $_SESSION['admin_id'] = $row['admin_id'];
                header("Location: homepage.php");
                exit;
            } else {
                echo "<p style='color:red;'>Invalid password.</p>";
            }
        } else {
            echo "<p style='color:red;'>User not found.</p>";
        }

        pg_close($connection);
    }
    ?>
</body>
</html>