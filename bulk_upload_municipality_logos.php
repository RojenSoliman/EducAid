<?php
/**
 * Bulk Upload Preset Municipality Logos
 * Scans /assets/City Logos/ directory and updates database with preset logo paths
 */

require_once __DIR__ . '/config/database.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Bulk Logo Upload</title>";
echo "<style>
body { font-family: 'Segoe UI', Arial, sans-serif; padding: 30px; background: #f5f5f5; }
.container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
h1 { color: #2e7d32; margin-bottom: 20px; }
.info { background: #e3f2fd; padding: 15px; border-left: 4px solid #2196f3; margin-bottom: 20px; }
.success { background: #e8f5e9; padding: 10px 15px; border-left: 4px solid #4caf50; margin: 10px 0; }
.error { background: #ffebee; padding: 10px 15px; border-left: 4px solid #f44336; margin: 10px 0; }
.warning { background: #fff3e0; padding: 10px 15px; border-left: 4px solid #ff9800; margin: 10px 0; }
table { width: 100%; border-collapse: collapse; margin: 20px 0; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
th { background: #2e7d32; color: white; font-weight: 600; }
tr:hover { background: #f5f5f5; }
.badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
.badge-success { background: #4caf50; color: white; }
.badge-error { background: #f44336; color: white; }
.badge-skip { background: #9e9e9e; color: white; }
.badge-new { background: #2196f3; color: white; }
.btn { display: inline-block; padding: 10px 20px; background: #2e7d32; color: white; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; font-size: 14px; }
.btn:hover { background: #1b5e20; }
.btn-secondary { background: #757575; }
.btn-secondary:hover { background: #616161; }
code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-family: 'Courier New', monospace; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
.stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; }
.stat-card h3 { margin: 0; font-size: 36px; }
.stat-card p { margin: 5px 0 0 0; opacity: 0.9; }
</style></head><body><div class='container'>";

echo "<h1>🏛️ Bulk Municipality Logo Upload</h1>";

// Scan the City Logos directory
$logoDirectory = __DIR__ . '/assets/City Logos';
$logoWebPath = '/assets/City Logos';

if (!is_dir($logoDirectory)) {
    echo "<div class='error'><strong>Error:</strong> Logo directory not found: <code>$logoDirectory</code></div>";
    echo "</div></body></html>";
    exit;
}

$files = scandir($logoDirectory);
$logoFiles = [];

foreach ($files as $file) {
    if ($file === '.' || $file === '..') {
        continue;
    }
    
    $fullPath = $logoDirectory . DIRECTORY_SEPARATOR . $file;
    if (!is_file($fullPath)) {
        continue;
    }
    
    // Check if it's an image file
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
        continue;
    }
    
    $logoFiles[] = [
        'filename' => $file,
        'filepath' => $fullPath,
        'webpath' => $logoWebPath . '/' . $file,
        'size' => filesize($fullPath),
        'ext' => $ext
    ];
}

echo "<div class='info'>";
echo "<strong>📁 Directory:</strong> <code>$logoDirectory</code><br>";
echo "<strong>🖼️ Images Found:</strong> " . count($logoFiles) . " files<br>";
echo "<strong>🌐 Web Path:</strong> <code>$logoWebPath</code>";
echo "</div>";

if (empty($logoFiles)) {
    echo "<div class='warning'>No image files found in the logo directory.</div>";
    echo "</div></body></html>";
    exit;
}

// Get all municipalities from database
$municipalitiesQuery = "SELECT municipality_id, name, slug, preset_logo_image FROM municipalities ORDER BY name ASC";
$municipalitiesResult = pg_query($connection, $municipalitiesQuery);

if (!$municipalitiesResult) {
    echo "<div class='error'><strong>Database Error:</strong> " . pg_last_error($connection) . "</div>";
    echo "</div></body></html>";
    exit;
}

$municipalities = [];
while ($row = pg_fetch_assoc($municipalitiesResult)) {
    $municipalities[] = $row;
}
pg_free_result($municipalitiesResult);

echo "<h2>📊 Statistics</h2>";
echo "<div class='stats'>";
echo "<div class='stat-card'><h3>" . count($logoFiles) . "</h3><p>Logo Files</p></div>";
echo "<div class='stat-card'><h3>" . count($municipalities) . "</h3><p>Municipalities</p></div>";
echo "</div>";

// Try to match logos to municipalities
$results = [];
$matched = 0;
$updated = 0;
$skipped = 0;
$errors = 0;

echo "<h2>🔄 Processing Results</h2>";
echo "<table>";
echo "<thead><tr>";
echo "<th>Municipality</th>";
echo "<th>Logo File</th>";
echo "<th>File Size</th>";
echo "<th>Status</th>";
echo "<th>Action</th>";
echo "</tr></thead><tbody>";

foreach ($municipalities as $municipality) {
    $municipalityId = $municipality['municipality_id'];
    $municipalityName = $municipality['name'];
    $currentLogo = $municipality['preset_logo_image'];
    
    // Try to find matching logo file
    $matchedFile = null;
    
    $searchName = str_replace(['City of ', 'Municipality of '], '', $municipalityName);
    
    // Normalize function for matching
    $normalize = function($str) {
        $str = str_replace(['ñ', 'Ñ'], ['n', 'N'], $str); // Handle Spanish characters
        $str = str_replace([' ', '.', ',', '-', "'"], '_', $str); // Replace separators
        $str = preg_replace('/_+/', '_', $str); // Collapse multiple underscores
        return trim($str, '_');
    };
    
    foreach ($logoFiles as $logo) {
        $logoBaseName = pathinfo($logo['filename'], PATHINFO_FILENAME);
        $normalizedLogo = strtolower($normalize($logoBaseName));
        
        // Generate various search patterns
        $patterns = [
            strtolower($normalize($searchName)),
            strtolower($normalize($municipalityName)),
            strtolower($normalize(str_replace('City of ', '', $municipalityName))),
            strtolower($normalize(str_replace('Municipality of ', '', $municipalityName))),
        ];
        
        // Special case mappings
        $specialMappings = [
            'dasmarinas' => 'dasma',
            'general_emilio_aguinaldo' => 'gen_emilio_aguinaldo',
            'mendez_nunez' => 'mendez',
        ];
        
        foreach ($patterns as $pattern) {
            // Check direct match
            if (strpos($normalizedLogo, $pattern) !== false) {
                $matchedFile = $logo;
                break 2;
            }
            
            // Check special mappings
            foreach ($specialMappings as $full => $short) {
                if ($pattern === $full && strpos($normalizedLogo, $short) !== false) {
                    $matchedFile = $logo;
                    break 3;
                }
            }
        }
    }
    
    echo "<tr>";
    echo "<td><strong>" . htmlspecialchars($municipalityName) . "</strong><br><small>ID: $municipalityId</small></td>";
    
    if ($matchedFile) {
        $matched++;
        $webPath = $matchedFile['webpath'];
        $fileSize = number_format($matchedFile['size'] / 1024, 2) . ' KB';
        
        echo "<td><code>" . htmlspecialchars($matchedFile['filename']) . "</code></td>";
        echo "<td>$fileSize</td>";
        
        // Check if already set and same
        if ($currentLogo === $webPath) {
            $skipped++;
            echo "<td><span class='badge badge-skip'>ALREADY SET</span></td>";
            echo "<td>—</td>";
            $results[] = ['municipality' => $municipalityName, 'status' => 'skipped', 'file' => $matchedFile['filename']];
        } else {
            // Update database
            $updateQuery = "UPDATE municipalities SET preset_logo_image = $1, updated_at = NOW() WHERE municipality_id = $2";
            $updateResult = pg_query_params($connection, $updateQuery, [$webPath, $municipalityId]);
            
            if ($updateResult) {
                $updated++;
                echo "<td><span class='badge badge-success'>✓ UPDATED</span></td>";
                echo "<td>";
                if ($currentLogo) {
                    echo "Changed from:<br><small><code>" . htmlspecialchars($currentLogo) . "</code></small>";
                } else {
                    echo "<span class='badge badge-new'>NEW</span>";
                }
                echo "</td>";
                $results[] = ['municipality' => $municipalityName, 'status' => 'updated', 'file' => $matchedFile['filename']];
            } else {
                $errors++;
                echo "<td><span class='badge badge-error'>✗ ERROR</span></td>";
                echo "<td>" . htmlspecialchars(pg_last_error($connection)) . "</td>";
                $results[] = ['municipality' => $municipalityName, 'status' => 'error', 'file' => $matchedFile['filename']];
            }
        }
    } else {
        echo "<td colspan='2'><em style='color:#999;'>No matching logo file found</em></td>";
        echo "<td><span class='badge badge-skip'>NO MATCH</span></td>";
        echo "<td>—</td>";
        $results[] = ['municipality' => $municipalityName, 'status' => 'no_match', 'file' => null];
    }
    
    echo "</tr>";
}

echo "</tbody></table>";

// Summary
echo "<h2>📈 Summary</h2>";
echo "<div class='stats'>";
echo "<div class='stat-card' style='background: linear-gradient(135deg, #4caf50 0%, #45a049 100%);'><h3>$updated</h3><p>Updated</p></div>";
echo "<div class='stat-card' style='background: linear-gradient(135deg, #9e9e9e 0%, #757575 100%);'><h3>$skipped</h3><p>Skipped (Already Set)</p></div>";
echo "<div class='stat-card' style='background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%);'><h3>$errors</h3><p>Errors</p></div>";
echo "<div class='stat-card' style='background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);'><h3>" . (count($municipalities) - $matched) . "</h3><p>No Match Found</p></div>";
echo "</div>";

if ($updated > 0) {
    echo "<div class='success'><strong>✓ Success!</strong> Updated $updated municipality logo(s) in the database.</div>";
}

if ($skipped > 0) {
    echo "<div class='info'><strong>ℹ️ Info:</strong> Skipped $skipped municipality(s) that already have the correct logo set.</div>";
}

if ($errors > 0) {
    echo "<div class='error'><strong>⚠️ Warning:</strong> $errors error(s) occurred during update.</div>";
}

// List unmatched logos
$unmatchedLogos = array_filter($logoFiles, function($logo) use ($results) {
    foreach ($results as $result) {
        if ($result['file'] === $logo['filename']) {
            return false;
        }
    }
    return true;
});

if (!empty($unmatchedLogos)) {
    echo "<h2>⚠️ Unused Logo Files</h2>";
    echo "<div class='warning'>";
    echo "<p>The following logo files were not matched to any municipality:</p>";
    echo "<ul>";
    foreach ($unmatchedLogos as $logo) {
        echo "<li><code>" . htmlspecialchars($logo['filename']) . "</code></li>";
    }
    echo "</ul>";
    echo "<p><em>You may need to manually assign these or update the municipality names in the database.</em></p>";
    echo "</div>";
}

echo "<div style='margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd;'>";
echo "<a href='modules/admin/municipality_content.php' class='btn'>← Back to Municipality Hub</a> ";
echo "<a href='debug_municipality_logos.php' class='btn btn-secondary'>View All Logos</a>";
echo "</div>";

echo "</div></body></html>";
?>
