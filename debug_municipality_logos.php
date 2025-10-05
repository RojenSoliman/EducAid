<?php
/**
 * Debug script to check municipality logo data in database
 */
require_once __DIR__ . '/config/database.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Municipality Logo Debug</title>";
echo "<style>
body { font-family: Arial, sans-serif; padding: 20px; }
table { border-collapse: collapse; width: 100%; margin-top: 20px; }
th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
th { background-color: #4CAF50; color: white; }
tr:nth-child(even) { background-color: #f2f2f2; }
.logo-preview { max-width: 80px; max-height: 80px; }
.code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
.error { color: red; }
.success { color: green; }
</style></head><body>";

echo "<h1>Municipality Logo Database Debug</h1>";

// Query all municipalities with logo fields
$query = "SELECT 
    municipality_id,
    name,
    slug,
    lgu_type,
    district_no,
    preset_logo_image,
    custom_logo_image,
    use_custom_logo,
    primary_color,
    secondary_color
FROM municipalities 
ORDER BY municipality_id ASC";

$result = pg_query($connection, $query);

if (!$result) {
    echo "<p class='error'>Query failed: " . pg_last_error($connection) . "</p>";
    exit;
}

$count = pg_num_rows($result);
echo "<p>Found <strong>$count</strong> municipalities in the database.</p>";

echo "<table>";
echo "<thead><tr>";
echo "<th>ID</th>";
echo "<th>Name</th>";
echo "<th>Slug</th>";
echo "<th>Type</th>";
echo "<th>District</th>";
echo "<th>Preset Logo Path</th>";
echo "<th>Custom Logo Path</th>";
echo "<th>Use Custom?</th>";
echo "<th>Preview</th>";
echo "<th>File Exists?</th>";
echo "</tr></thead><tbody>";

while ($row = pg_fetch_assoc($result)) {
    $id = htmlspecialchars($row['municipality_id']);
    $name = htmlspecialchars($row['name']);
    $slug = htmlspecialchars($row['slug'] ?? '—');
    $type = htmlspecialchars($row['lgu_type'] ?? '—');
    $district = htmlspecialchars($row['district_no'] ?? '—');
    $presetPath = $row['preset_logo_image'];
    $customPath = $row['custom_logo_image'];
    $useCustom = $row['use_custom_logo'];
    
    $presetPathDisplay = htmlspecialchars($presetPath ?: '—');
    $customPathDisplay = htmlspecialchars($customPath ?: '—');
    $useCustomDisplay = $useCustom === 't' || $useCustom === true ? 'YES' : 'NO';
    
    // Determine active logo
    $activePath = null;
    if (($useCustom === 't' || $useCustom === true) && !empty($customPath)) {
        $activePath = trim($customPath);
    } elseif (!empty($presetPath)) {
        $activePath = trim($presetPath);
    }
    
    // Check file existence
    $fileExists = '—';
    $previewSrc = null;
    
    if ($activePath) {
        // Convert database path to filesystem path
        $fsPath = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $activePath);
        $fileExists = file_exists($fsPath) ? "<span class='success'>✓ YES</span>" : "<span class='error'>✗ NO</span>";
        
        // Build preview URL
        if (str_starts_with($activePath, '/')) {
            $previewSrc = $activePath;
        } else {
            $previewSrc = '/' . ltrim($activePath, '/');
        }
        
        // URL encode spaces and special chars
        $previewSrc = str_replace(' ', '%20', $previewSrc);
    }
    
    echo "<tr>";
    echo "<td>$id</td>";
    echo "<td>$name</td>";
    echo "<td>$slug</td>";
    echo "<td>$type</td>";
    echo "<td>$district</td>";
    echo "<td><span class='code'>$presetPathDisplay</span></td>";
    echo "<td><span class='code'>$customPathDisplay</span></td>";
    echo "<td>$useCustomDisplay</td>";
    
    if ($previewSrc) {
        echo "<td><img src='$previewSrc' class='logo-preview' alt='Logo' onerror=\"this.parentElement.innerHTML='<span class=error>Failed to load</span>'\"></td>";
    } else {
        echo "<td>—</td>";
    }
    
    echo "<td>$fileExists";
    if ($activePath && !file_exists($fsPath)) {
        echo "<br><small class='error'>Expected at: " . htmlspecialchars($fsPath) . "</small>";
    }
    echo "</td>";
    echo "</tr>";
}

echo "</tbody></table>";

pg_free_result($result);

// List actual files in City Logos directory
echo "<h2>Files in /assets/City Logos/ Directory</h2>";
$logoDir = __DIR__ . '/assets/City Logos';
if (is_dir($logoDir)) {
    $files = scandir($logoDir);
    $logoFiles = array_filter($files, function($file) use ($logoDir) {
        return $file !== '.' && $file !== '..' && is_file($logoDir . '/' . $file);
    });
    
    if (!empty($logoFiles)) {
        echo "<ul>";
        foreach ($logoFiles as $file) {
            $filePath = '/assets/City Logos/' . str_replace(' ', '%20', $file);
            echo "<li><code>$file</code> → <a href='$filePath' target='_blank'>View</a></li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No files found in directory.</p>";
    }
} else {
    echo "<p class='error'>Directory does not exist: $logoDir</p>";
}

echo "</body></html>";
?>
