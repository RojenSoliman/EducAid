<?php
include '../../config/database.php';


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Add Barangay (EducAid)</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            max-width: 500px;
            margin: auto;
        }

        label {
            display: block;
            margin-top: 10px;
        }

        input {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
        }

        button {
            margin-top: 15px;
            padding: 10px 20px;
        }
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
        $username = trim($_POST['barangay']);
        $municipality_id = 1; // General Trias

        if (!$connection) {
            echo "<p style='color:red;'>Connection failed.</p>";
            exit;
        }

        // Check for duplicates (case-insensitive)
        $checkQuery = "SELECT 1 FROM barangays WHERE LOWER(name) = LOWER($1) AND municipality_id = $2";
        $checkResult = pg_query_params($connection, $checkQuery, [$username, $municipality_id]);

        if (pg_num_rows($checkResult) > 0) {
            echo "<p style='color:red;'>Barangay already exists.</p>";
        } else {
            // Insert new barangay
            $query = "INSERT INTO barangays (municipality_id, name) VALUES ($1, $2)";
            $result = pg_query_params($connection, $query, [$municipality_id, $username]);

            if ($result) {
                echo "<p style='color:green;'>Barangay added successfully!</p>";
            } else {
                echo "<p style='color:red;'>Error: " . pg_last_error($connection) . "</p>";
            }
        }
    }

    // Display all barangays
    $result = pg_query($connection, "SELECT * FROM barangays WHERE municipality_id = 1 ORDER BY name ASC");

    if (!$result) {
        echo "<p style='color:red;'>An error occurred while querying the database.</p>";
    } else {
        echo "<h2>Barangays:</h2>";
        echo "<ul>";
        while ($row = pg_fetch_assoc($result)) {
            echo "<li>" . htmlspecialchars($row['name']) . "</li>";
        }
        echo "</ul>";
    }

    pg_close($connection);
    ?>
</body>

</html>