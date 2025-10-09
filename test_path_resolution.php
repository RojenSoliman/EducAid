<?php
/**
 * Test Path Resolution for Municipality Logos
 * Shows how paths resolve from different locations
 */

echo "<!DOCTYPE html><html><head><title>Path Resolution Test</title></head><body>";
echo "<h1>Path Resolution Test</h1>";

// Test paths
$test_logo_path = "/assets/City Logos/General Trias.png";

echo "<h2>Original Path from Database:</h2>";
echo "<code>" . htmlspecialchars($test_logo_path) . "</code>";

echo "<h2>Resolution from Different Locations:</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse:collapse;'>";
echo "<tr><th>Page Location</th><th>base_path</th><th>Resolved Path</th><th>Preview</th></tr>";

// Test different locations
$locations = [
    'Root (landingpage.php)' => '',
    'Website folder (/website/landingpage.php)' => '../',
    'Student module (/modules/student/dashboard.php)' => '../../',
    'Admin module (/modules/admin/dashboard.php)' => '../../',
];

foreach ($locations as $location => $base_path) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($location) . "</td>";
    echo "<td><code>" . ($base_path ?: '(none)') . "</code></td>";
    
    // Process the path
    $logo_path = $test_logo_path;
    
    if (str_starts_with($logo_path, '/')) {
        $relative = ltrim($logo_path, '/');
        $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));
        $resolved = $base_path . $encoded;
    } else {
        $resolved = $base_path . $logo_path;
    }
    
    echo "<td><code>" . htmlspecialchars($resolved) . "</code></td>";
    echo "<td><img src='" . htmlspecialchars($resolved) . "' style='height:40px;' onerror=\"this.parentElement.innerHTML='<span style=color:red>404</span>'\"></td>";
    echo "</tr>";
}

echo "</table>";

echo "<h2>Actual File Check:</h2>";
$actual_path = __DIR__ . '/assets/City Logos/General Trias.png';
if (file_exists($actual_path)) {
    echo "✅ File exists at: <code>" . htmlspecialchars($actual_path) . "</code><br>";
    echo "File size: " . filesize($actual_path) . " bytes<br>";
} else {
    echo "❌ File NOT found at: <code>" . htmlspecialchars($actual_path) . "</code><br>";
    
    // Check what files exist
    $logo_dir = __DIR__ . '/assets/City Logos';
    if (is_dir($logo_dir)) {
        echo "<h3>Files in City Logos directory:</h3>";
        echo "<ul>";
        $files = scandir($logo_dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                echo "<li>" . htmlspecialchars($file) . "</li>";
            }
        }
        echo "</ul>";
    } else {
        echo "❌ Directory not found: <code>" . htmlspecialchars($logo_dir) . "</code>";
    }
}

echo "</body></html>";
?>
