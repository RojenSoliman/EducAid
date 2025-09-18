<?php
echo "<h2>🔍 PHP Error Log Information</h2>";

echo "<h3>PHP Configuration:</h3>";
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Setting</th><th>Value</th></tr>";
echo "<tr><td>error_reporting</td><td>" . error_reporting() . "</td></tr>";
echo "<tr><td>display_errors</td><td>" . ini_get('display_errors') . "</td></tr>";
echo "<tr><td>log_errors</td><td>" . ini_get('log_errors') . "</td></tr>";
echo "<tr><td>error_log</td><td>" . (ini_get('error_log') ?: 'default') . "</td></tr>";
echo "</table>";

echo "<h3>Test Error Logging:</h3>";
error_log("=== TEST LOG ENTRY from check_error_log.php ===");
echo "<p>Test error logged. Check the error log location above.</p>";

echo "<h3>Session Information:</h3>";
session_start();
echo "<pre>" . print_r($_SESSION, true) . "</pre>";

echo "<h3>Instructions:</h3>";
echo "<ol>";
echo "<li>Try the blacklist modal again</li>";
echo "<li>Check the PHP error log file (location shown above)</li>";
echo "<li>Look for entries starting with '=== BLACKLIST SERVICE DEBUG ==='</li>";
echo "<li>Also check browser console (F12) for JavaScript errors</li>";
echo "</ol>";
?>

<!DOCTYPE html>
<html>
<head>
    <title>PHP Error Log Check</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { margin: 10px 0; }
        th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 3px; }
    </style>
</head>
<body>
</body>
</html>