<?php
echo "Testing PostgreSQL connection..." . PHP_EOL;

$conn = pg_connect("host=localhost dbname=educaid user=postgres password=postgres_dev_2025");

if ($conn) {
    echo "Connection successful!" . PHP_EOL;
    echo "PostgreSQL connection is working properly." . PHP_EOL;
    pg_close($conn);
} else {
    echo "Connection failed: " . pg_last_error() . PHP_EOL;
}
?>