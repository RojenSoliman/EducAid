<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (isset($_SESSION['student_username'])) {
    header("Location: homepage.php");
    exit;
}

    if (isset($_POST['student_login'])) {
        //gets all the form data from student_login.html
        $firstname = $_POST['firstname'];
        $lastname = $_POST['lastname'];
        $email = $_POST['email'];
        $password = $_POST['password'];

        // Check if the connection is established
        if (!isset($connection) || !$connection) {
            echo "<p style='color:red;'>" . htmlspecialchars("Connection failed.") . "</p>";
            exit;
        }

        // Prepare and execute the query to find the student
        $result = pg_query_params(
            $connection,
            "SELECT * FROM students WHERE first_name =$1 AND last_name = $2 and email = $3",
            [$firstname, $lastname, $email]
        );

        // Check if the query was successful and if a student was found
        if ($result === false) {
            echo "<p style='color:red;'>Database query error.</p>";
        } elseif ($row = pg_fetch_assoc($result)) {
            if (password_verify($password, $row['password'])) {
                $_SESSION['student_username'] = $firstname . ' ' . $lastname;
                $_SESSION['student_id'] = $row['student_id'];
                header("Location: homepage.php");
                exit;
            } else {
                // Invalid password
                echo "<p style='color:red;'>Invalid password.</p>";
                header("Location: student_login.html");
            }
        } else {
            // User not found
            echo "<p style='color:red;'>User not found.</p>";
            header("Location: student_login.html");
            
        }

        // Close the database connection if it was established
        if (isset($connection) && $connection) {
            pg_close($connection);
        }
    }
?>