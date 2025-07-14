<?php
include '../../config/database.php';


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Barangay (EducAid)</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 500px; margin: auto; }
        label { display: block; margin-top: 10px; }
        input { width: 100%; padding: 8px; margin-top: 5px; }
        button { margin-top: 15px; padding: 10px 20px; }
    </style>
</head>
<body>
    <h2>Register New Barangay (General Trias)</h2>
    <form method="post" action="">
        <label>Barangay Name:</label>
        <input type="text" name="barangay" required>

        <button type="submit" name="register">Register Barangay</button>
    </form>

    <?php
    if (isset($_POST['register'])) {
        $username = $_POST['barangay'];

        // Connect to PostgreSQL (update credentials)
        if (!$connection) {
            echo "<p style='color:red;'>Connection failed.</p>";
            exit;
        }

        // Insert into admins (no email or full_name)
        $query = "INSERT INTO barangays (municipality_id, name) VALUES ($1, $2)";
        $result = pg_query_params($connection, $query, [$municipality_id, $username]);

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