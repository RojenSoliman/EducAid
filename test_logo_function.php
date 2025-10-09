<?php
/**
 * Test the build_logo_src function with various inputs
 */

function build_logo_src(?string $path): ?string {
    if ($path === null) {
        return null;
    }

    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }

    // Handle base64 data URIs
    if (preg_match('#^data:image/[^;]+;base64,#i', $path)) {
        return $path;
    }

    // Handle external URLs
    if (preg_match('#^(?:https?:)?//#i', $path)) {
        return $path;
    }

    // Normalize path separators and collapse multiple slashes
    $normalizedRaw = str_replace('\\', '/', $path);
    $normalizedRaw = preg_replace('#(?<!:)/{2,}#', '/', $normalizedRaw);

    // URL encode the path while preserving forward slashes
    // This correctly handles spaces and special characters in folder/file names
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $normalizedRaw)));

    // Handle relative paths that are already correct
    if (str_starts_with($normalizedRaw, '../') || str_starts_with($normalizedRaw, './')) {
        return $encodedPath;
    }

    // Handle absolute paths from web root (starts with /)
    if (str_starts_with($normalizedRaw, '/')) {
        // From modules/admin/, need ../../ to reach project root
        return '../..' . $encodedPath;
    }

    // Handle relative paths without leading slash
    $relativeRaw = ltrim($normalizedRaw, '/');
    $relativeEncoded = ltrim($encodedPath, '/');

    // Try to auto-detect if path should be in assets/ directory
    $docRoot = realpath(__DIR__);
    if ($docRoot) {
        $fsRelative = str_replace('/', DIRECTORY_SEPARATOR, $relativeRaw);
        $candidate = $docRoot . DIRECTORY_SEPARATOR . $fsRelative;
        
        // If file doesn't exist at root, check if it's in assets/
        if (!is_file($candidate)) {
            $assetsCandidate = $docRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $fsRelative;
            if (is_file($assetsCandidate) && !str_starts_with($relativeRaw, 'assets/')) {
                // Rebuild the path with assets/ prefix
                $relativeRaw = 'assets/' . $relativeRaw;
                $relativeEncoded = implode('/', array_map('rawurlencode', explode('/', $relativeRaw)));
            }
        }
    }

    if ($relativeEncoded === '') {
        return null;
    }

    return '../../' . $relativeEncoded;
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Logo Path Test</title>";
echo "<style>body{font-family:monospace;padding:20px;} .test{margin:10px 0;padding:10px;background:#f4f4f4;} .input{color:blue;} .output{color:green;font-weight:bold;}</style>";
echo "</head><body><h1>build_logo_src() Function Test</h1>";

$testCases = [
    '/assets/City Logos/General_Trias_City_Logo.png',
    '/assets/City Logos/Dasma_City_Logo.png',
    'assets/City Logos/Imus_City_Logo.png',
    'City Logos/Bacoor_City_Logo.png',
    '/assets/uploads/custom_logo.jpg',
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==',
    'https://example.com/logo.png',
    null,
    '',
    '  /assets/City Logos/Kawit_Logo.png  ',
];

foreach ($testCases as $input) {
    $output = build_logo_src($input);
    echo "<div class='test'>";
    echo "<div><strong>Input:</strong> <span class='input'>" . htmlspecialchars(var_export($input, true)) . "</span></div>";
    echo "<div><strong>Output:</strong> <span class='output'>" . htmlspecialchars(var_export($output, true)) . "</span></div>";
    
    if ($output && !str_starts_with($output, 'data:') && !preg_match('#^https?://#', $output)) {
        // Try to show if it would resolve to a real file
        $checkPath = __DIR__ . str_replace('../..', '', $output);
        $checkPath = str_replace('%20', ' ', $checkPath);
        $exists = file_exists($checkPath);
        echo "<div><strong>File exists?</strong> " . ($exists ? '✓ YES' : '✗ NO') . "</div>";
        if (!$exists) {
            echo "<div style='color:red;'><small>Would try: $checkPath</small></div>";
        }
    }
    echo "</div>";
}

echo "</body></html>";
?>
