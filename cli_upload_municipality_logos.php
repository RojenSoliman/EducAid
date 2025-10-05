<?php
/**
 * CLI Script: Bulk Upload Preset Municipality Logos
 * Usage: php cli_upload_municipality_logos.php [--dry-run] [--force]
 */

require_once __DIR__ . '/config/database.php';

// Parse CLI arguments
$options = getopt('', ['dry-run', 'force', 'help']);
$dryRun = isset($options['dry-run']);
$force = isset($options['force']);

if (isset($options['help'])) {
    echo "Municipality Logo Bulk Upload Tool\n";
    echo "===================================\n\n";
    echo "Usage: php cli_upload_municipality_logos.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --dry-run    Show what would be updated without making changes\n";
    echo "  --force      Update all logos even if already set\n";
    echo "  --help       Show this help message\n\n";
    echo "Examples:\n";
    echo "  php cli_upload_municipality_logos.php --dry-run\n";
    echo "  php cli_upload_municipality_logos.php\n";
    echo "  php cli_upload_municipality_logos.php --force\n\n";
    exit(0);
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║   Municipality Logo Bulk Upload Tool                      ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

if ($dryRun) {
    echo "🔍 DRY RUN MODE - No changes will be made\n\n";
}

if ($force) {
    echo "⚡ FORCE MODE - Will update all logos even if already set\n\n";
}

// Scan logo directory
$logoDirectory = __DIR__ . '/assets/City Logos';
$logoWebPath = '/assets/City Logos';

echo "📁 Scanning directory: $logoDirectory\n";

if (!is_dir($logoDirectory)) {
    echo "❌ ERROR: Logo directory not found!\n";
    exit(1);
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
    
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
        continue;
    }
    
    $logoFiles[] = [
        'filename' => $file,
        'filepath' => $fullPath,
        'webpath' => $logoWebPath . '/' . $file,
        'size' => filesize($fullPath)
    ];
}

echo "🖼️  Found " . count($logoFiles) . " image files\n\n";

// Get municipalities
$query = "SELECT municipality_id, name, slug, preset_logo_image FROM municipalities ORDER BY name ASC";
$result = pg_query($connection, $query);

if (!$result) {
    echo "❌ DATABASE ERROR: " . pg_last_error($connection) . "\n";
    exit(1);
}

$municipalities = [];
while ($row = pg_fetch_assoc($result)) {
    $municipalities[] = $row;
}
pg_free_result($result);

echo "🏛️  Found " . count($municipalities) . " municipalities\n";
echo str_repeat("─", 60) . "\n\n";

// Process each municipality
$stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0, 'no_match' => 0];

foreach ($municipalities as $municipality) {
    $municipalityId = $municipality['municipality_id'];
    $municipalityName = $municipality['name'];
    $currentLogo = $municipality['preset_logo_image'];
    
    // Find matching logo
    $searchName = str_replace(['City of ', 'Municipality of '], '', $municipalityName);
    $matchedFile = null;
    
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
    
    if (!$matchedFile) {
        $stats['no_match']++;
        echo "⚠️  [$municipalityName] No matching logo file found\n";
        continue;
    }
    
    $webPath = $matchedFile['webpath'];
    $fileSize = number_format($matchedFile['size'] / 1024, 1);
    
    // Check if update needed
    if (!$force && $currentLogo === $webPath) {
        $stats['skipped']++;
        echo "⏭️  [$municipalityName] Already set: {$matchedFile['filename']}\n";
        continue;
    }
    
    if ($dryRun) {
        $stats['updated']++;
        echo "🔄 [$municipalityName] Would update to: {$matchedFile['filename']} ({$fileSize}KB)\n";
        if ($currentLogo) {
            echo "   └─ Current: $currentLogo\n";
        }
        continue;
    }
    
    // Perform update
    $updateQuery = "UPDATE municipalities SET preset_logo_image = $1, updated_at = NOW() WHERE municipality_id = $2";
    $updateResult = pg_query_params($connection, $updateQuery, [$webPath, $municipalityId]);
    
    if ($updateResult) {
        $stats['updated']++;
        echo "✅ [$municipalityName] Updated to: {$matchedFile['filename']} ({$fileSize}KB)\n";
    } else {
        $stats['errors']++;
        echo "❌ [$municipalityName] ERROR: " . pg_last_error($connection) . "\n";
    }
}

// Summary
echo "\n" . str_repeat("═", 60) . "\n";
echo "📊 SUMMARY\n";
echo str_repeat("═", 60) . "\n";
echo "✅ Updated:      " . $stats['updated'] . "\n";
echo "⏭️  Skipped:      " . $stats['skipped'] . "\n";
echo "❌ Errors:       " . $stats['errors'] . "\n";
echo "⚠️  No Match:     " . $stats['no_match'] . "\n";
echo "─────────────────────\n";
echo "📋 Total:        " . array_sum($stats) . " municipalities\n";
echo str_repeat("═", 60) . "\n\n";

if ($dryRun) {
    echo "💡 Run without --dry-run to apply changes\n\n";
} elseif ($stats['updated'] > 0) {
    echo "🎉 Successfully updated {$stats['updated']} logo(s)!\n\n";
}

exit($stats['errors'] > 0 ? 1 : 0);
?>
