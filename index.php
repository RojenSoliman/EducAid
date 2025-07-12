<?php
    include 'config/database.php';
    include 'includes/header.php';
    // Database connection can be tested here if needed
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Index Page</title>
        <link rel="stylesheet" href="styles.css">
        <script src="script.js"></script>
    </head>
    <body>
        <h1>Welcome to the Test Page</h1>
        <p>This is a simple HTML page to test the structure and functionality.</p>
        <button id="testButton">Click Me!</button>

        <script>
            document.getElementById('testButton').addEventListener('click', function() {
                alert('Button was clicked!');
            });
        </script>

        <?php
            $result = pg_query($connection, "SELECT * FROM admins");
            if(!$result){
                echo "An error occurred while querying the database.";
            } else {
                echo "<h2>Database Query Result:</h2>";
                echo "<ul>";
                while ($row = pg_fetch_assoc($result)) {
                    echo "<li>" . htmlspecialchars($row['username']) . "</li>";
                }
                echo "</ul>";
            }
        ?>

        <br>

        <a href="config/database.php">Open db connection test</a>
        <a href="test.html">Open QR scanning test</a>
        <?php
            include 'includes/footer.php';
        ?>
    </body>
</html>