<?php
    include __DIR__ . '/../../config/database.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Signup (EducAid)</title>
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .signup-card { 
            background: white; 
            border-radius: 15px; 
            box-shadow: 0 15px 35px rgba(0,0,0,0.1); 
            padding: 2rem; 
            max-width: 500px; 
            width: 100%; 
        }
        .btn-primary { 
            background: linear-gradient(45deg, #667eea, #764ba2); 
            border: none; 
        }
        .form-control:focus { 
            border-color: #667eea; 
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25); 
        }
        .logo { 
            width: 80px; 
            height: 80px; 
            margin: 0 auto 1rem; 
            display: block; 
        }
    </style>
</head>
<body>
    <div class="signup-card">
        <img src="../../assets/images/logo.png" alt="EducAid Logo" class="logo">
        <h2 class="text-center mb-4">Register New Admin</h2>
        <p class="text-center text-muted mb-4">Create admin account for unified login system</p>
        
        <form method="post" action="">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control" name="first_name" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Last Name</label>
                    <input type="text" class="form-control" name="last_name" required>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Middle Name (Optional)</label>
                <input type="text" class="form-control" name="middle_name">
            </div>

            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" class="form-control" name="email" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Username (Legacy - Optional)</label>
                <input type="text" class="form-control" name="username" placeholder="For backward compatibility">
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" class="form-control" name="password" minlength="12" required>
                <div class="form-text">Must be at least 12 characters long</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <input type="password" class="form-control" name="confirm_password" minlength="12" required>
            </div>

            <button type="submit" name="register" class="btn btn-primary w-100">Register Admin</button>
        </form>
        
        <div class="text-center mt-3">
            <a href="../../unified_login.php" class="text-decoration-none">Back to Login</a>
        </div>
    </div>

    <?php
    if (isset($_POST['register'])) {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $middle_name = trim($_POST['middle_name']) ?: null;
        $email = trim($_POST['email']);
        $username = trim($_POST['username']) ?: null; // Legacy field, optional
        $pass = $_POST['password'];
        $confirm = $_POST['confirm_password'];

        // Validation
        if ($pass !== $confirm) {
            echo "<div class='alert alert-danger mt-3'>Passwords do not match.</div>";
        } elseif (strlen($pass) < 12) {
            echo "<div class='alert alert-danger mt-3'>Password must be at least 12 characters long.</div>";
        } else {
            // Check if email already exists
            $emailCheck = pg_query_params($connection, 
                "SELECT admin_id FROM admins WHERE email = $1", 
                [$email]
            );
            
            if (pg_num_rows($emailCheck) > 0) {
                echo "<div class='alert alert-danger mt-3'>Email already exists. Please use a different email.</div>";
            } else {
                $hashed = password_hash($pass, PASSWORD_ARGON2ID);
                $municipality_id = 1; // Default municipality

                // Insert new admin with all required fields
                $query = "INSERT INTO admins (municipality_id, first_name, middle_name, last_name, email, username, password) 
                         VALUES ($1, $2, $3, $4, $5, $6, $7)";
                $result = pg_query_params($connection, $query, [
                    $municipality_id, 
                    $first_name, 
                    $middle_name, 
                    $last_name, 
                    $email, 
                    $username, 
                    $hashed
                ]);

                if ($result) {
                    echo "<div class='alert alert-success mt-3'>
                        <strong>Admin registered successfully!</strong><br>
                        Name: $first_name $last_name<br>
                        Email: $email<br>
                        You can now login using the <a href='../../unified_login.php'>unified login system</a>.
                    </div>";
                } else {
                    echo "<div class='alert alert-danger mt-3'>Error: " . pg_last_error($connection) . "</div>";
                }
            }
        }
    }
    ?>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>