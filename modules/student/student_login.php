<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (isset($_SESSION['student_username'])) {
    header("Location: student_homepage.php");
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
                header("Location: student_homepage.php");
                exit;
            } else {
                // Invalid password
                echo '
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Login Error</title>
                    <link rel="stylesheet" href="../../assets/css/bootstrap.min.css">
                </head>
                <body class="bg-light">
                    <div class="modal show d-block" tabindex="-1" style="background: rgba(0, 123, 255, 1);">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title text-danger">Login Error</h5>
                                </div>
                                <div class="modal-body">
                                    <p>Invalid password.</p>
                                </div>
                                <div class="modal-footer">
                                    <a href="student_login.html" class="btn btn-primary">OK</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
                </body>
                </html>
                ';
                exit;
            }
        } else {
            // User not found
            echo '
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Login Error</title>
                    <link rel="stylesheet" href="../../assets/css/bootstrap.min.css">
                </head>
                <body class="bg-light">
                    <div class="modal show d-block" tabindex="-1" style="background: rgba(0, 123, 255, 1);">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title text-danger">Login Error</h5>
                                </div>
                                <div class="modal-body">
                                    <p>User not found.</p>
                                </div>
                                <div class="modal-footer">
                                    <a href="student_login.html" class="btn btn-primary">OK</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
                </body>
                </html>
            ';
            exit;
        }

        // Close the database connection if it was established
        if (isset($connection) && $connection) {
            pg_close($connection);
        }
    }
?>