<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Checking sidebar_theme_settings columns ===\n\n";

$query = "SELECT column_name FROM information_schema.columns WHERE table_name = 'sidebar_theme_settings' ORDER BY ordinal_position";
$result = pg_query($connection, $query);

if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        echo "- " . $row['column_name'] . "\n";
    }
} else {
    echo "Error: " . pg_last_error($connection);
}

echo "\n=== Checking if primary_color and secondary_color exist ===\n";
$checkPrimary = pg_query($connection, "SELECT 1 FROM information_schema.columns WHERE table_name = 'sidebar_theme_settings' AND column_name = 'primary_color'");
$checkSecondary = pg_query($connection, "SELECT 1 FROM information_schema.columns WHERE table_name = 'sidebar_theme_settings' AND column_name = 'secondary_color'");

echo "primary_color exists: " . (pg_num_rows($checkPrimary) > 0 ? "YES" : "NO") . "\n";
echo "secondary_color exists: " . (pg_num_rows($checkSecondary) > 0 ? "YES" : "NO") . "\n";
