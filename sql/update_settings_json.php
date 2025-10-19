<?php
/**
 * Update municipal_settings.json with archiving settings
 */

$settingsPath = __DIR__ . '/../data/municipal_settings.json';

echo "<h1>⚙️ Update Settings JSON File</h1>";
echo "<hr>";

if (!file_exists($settingsPath)) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "<h3 style='color: #721c24;'>❌ Error</h3>";
    echo "<p>Settings file not found at: <code>$settingsPath</code></p>";
    echo "</div>";
    exit;
}

// Read current settings
$currentSettings = json_decode(file_get_contents($settingsPath), true);

echo "<h2>Current Settings:</h2>";
echo "<pre style='background: #f0f0f0; padding: 15px; border-radius: 5px;'>";
print_r($currentSettings);
echo "</pre>";

// Add new archiving settings
$newSettings = [
    'archive_file_retention_years' => 5,
    'auto_compress_distributions' => true,
    'compress_after_days' => 30,
    'max_storage_gb' => 100,
    'enable_file_archiving' => true
];

$added = [];
$existing = [];

foreach ($newSettings as $key => $value) {
    if (!isset($currentSettings[$key])) {
        $currentSettings[$key] = $value;
        $added[] = $key;
    } else {
        $existing[] = $key;
    }
}

if (!empty($added)) {
    // Save updated settings
    file_put_contents($settingsPath, json_encode($currentSettings, JSON_PRETTY_PRINT));
    
    echo "<h2>✅ Settings Updated</h2>";
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3 style='color: #155724;'>Added Settings:</h3>";
    echo "<ul>";
    foreach ($added as $setting) {
        echo "<li><strong>$setting</strong> = " . json_encode($newSettings[$setting]) . "</li>";
    }
    echo "</ul>";
    echo "</div>";
}

if (!empty($existing)) {
    echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3 style='color: #856404;'>⚠️ Already Existed:</h3>";
    echo "<ul>";
    foreach ($existing as $setting) {
        echo "<li><strong>$setting</strong></li>";
    }
    echo "</ul>";
    echo "</div>";
}

echo "<h2>Updated Settings File:</h2>";
echo "<pre style='background: #f0f0f0; padding: 15px; border-radius: 5px;'>";
echo file_get_contents($settingsPath);
echo "</pre>";

echo "<hr>";
echo "<div style='background: #d4edda; border: 3px solid #28a745; padding: 20px; border-radius: 8px; text-align: center;'>";
echo "<h2 style='color: #155724; margin: 0;'>✅ Settings Updated Successfully!</h2>";
echo "<p><a href='setup_folder_structure.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>Next: Create Folder Structure →</a></p>";
echo "</div>";
?>
