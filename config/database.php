<?php
// Database configuration now sourced from environment variables (.env)
// Load environment first
require_once __DIR__ . '/env.php';

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'educaid';
$dbUser = getenv('DB_USER') ?: 'postgres';
$dbPass = getenv('DB_PASSWORD') ?: 'postgres_dev_2025'; // Default for development
$dbPort = getenv('DB_PORT') ?: '5432';

$connString = sprintf(
    'host=%s port=%s dbname=%s user=%s password=%s',
    $dbHost,
    $dbPort,
    $dbName,
    $dbUser,
    $dbPass
);

$connection = @pg_connect($connString);
if (!$connection) {
    error_log('Database connection failed using provided environment variables.');
    die('Database connection failed.');
}
?>
