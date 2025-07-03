<?php 
//This is the database configuration file for the EducAid system.
    $connection = pg_connect("host=localhost dbname=educaid user=postgres password=postgres_dev_2025");
    if (!$connection) {
        die("Connection failed: " . pg_last_error());
    }

    


?>