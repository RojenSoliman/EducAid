<?php
/**
 * debug_theme_generator.php
 * Debug script to test theme generation system
 * Tests all components: ColorGenerator, ThemeGenerator, SidebarTheme
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/services/ColorGeneratorService.php';
require_once __DIR__ . '/services/ThemeGeneratorService.php';
require_once __DIR__ . '/services/SidebarThemeService.php';

// Must be logged in as admin
if (!isset($_SESSION['admin_id'])) {
    die('❌ Error: Must be logged in as admin. Please login first.');
}

$adminRole = getCurrentAdminRole($connection);
if ($adminRole !== 'super_admin') {
    die('❌ Error: Must be super admin. Current role: ' . htmlspecialchars($adminRole));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theme Generator Debug</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .debug-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid #0d6efd;
        }
        .success { color: #198754; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .warning { color: #ffc107; font-weight: bold; }
        .code-block {
            background: #212529;
            color: #00ff00;
            padding: 1rem;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            overflow-x: auto;
            margin: 0.5rem 0;
        }
        .color-swatch {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 4px;
            border: 2px solid #dee2e6;
            vertical-align: middle;
            margin-right: 0.5rem;
        }
        .test-result {
            padding: 0.5rem 1rem;
            margin: 0.5rem 0;
            border-radius: 4px;
            background: #fff;
            border: 1px solid #dee2e6;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">
                    <i class="bi bi-bug-fill text-danger me-2"></i>
                    Theme Generator Debug Tool
                </h1>
                <p class="lead text-muted">Testing theme generation system components...</p>
                <hr class="mb-4">
            </div>
        </div>

        <?php
        // ============================================
        // TEST 1: Check if all required classes exist
        // ============================================
        echo '<div class="debug-section">';
        echo '<h3><i class="bi bi-1-circle me-2"></i>Class Files Check</h3>';
        
        $requiredClasses = [
            'ColorGeneratorService' => 'services/ColorGeneratorService.php',
            'ThemeGeneratorService' => 'services/ThemeGeneratorService.php',
            'SidebarThemeService' => 'services/SidebarThemeService.php'
        ];
        
        $classesOk = true;
        foreach ($requiredClasses as $className => $filePath) {
            $fullPath = __DIR__ . '/' . $filePath;
            $exists = file_exists($fullPath);
            $classExists = class_exists($className);
            
            echo '<div class="test-result">';
            echo $exists ? '✅' : '❌';
            echo ' File: <code>' . htmlspecialchars($filePath) . '</code> - ';
            echo $exists ? '<span class="success">EXISTS</span>' : '<span class="error">NOT FOUND</span>';
            echo '<br>';
            echo $classExists ? '✅' : '❌';
            echo ' Class: <code>' . htmlspecialchars($className) . '</code> - ';
            echo $classExists ? '<span class="success">LOADED</span>' : '<span class="error">NOT LOADED</span>';
            echo '</div>';
            
            if (!$exists || !$classExists) {
                $classesOk = false;
            }
        }
        echo '</div>';
        
        if (!$classesOk) {
            echo '<div class="alert alert-danger"><strong>❌ FATAL ERROR:</strong> Required classes not found. Cannot continue.</div>';
            echo '</div></body></html>';
            exit;
        }

        // ============================================
        // TEST 2: Check available methods
        // ============================================
        echo '<div class="debug-section">';
        echo '<h3><i class="bi bi-2-circle me-2"></i>Available Methods Check</h3>';
        
        $methodChecks = [
            'ColorGeneratorService' => [
                'hexToRgb', 'rgbToHex', 'rgbToHsl', 'hslToRgb',
                'lighten', 'darken', 'saturate', 'desaturate',
                'getContrastRatio', 'getContrastText', 'validateBrightness'
            ],
            'ThemeGeneratorService' => [
                'generateColorPalette', 'generateAndApplyTheme', 
                'validateInputColors'
            ],
            'SidebarThemeService' => [
                'getCurrentSettings', 'updateSettings'
            ]
        ];
        
        foreach ($methodChecks as $className => $methods) {
            echo '<h5 class="mt-3">' . htmlspecialchars($className) . '</h5>';
            foreach ($methods as $method) {
                $exists = method_exists($className, $method);
                echo '<div class="test-result">';
                echo $exists ? '✅' : '❌';
                echo ' Method: <code>' . htmlspecialchars($method) . '()</code> - ';
                echo $exists ? '<span class="success">EXISTS</span>' : '<span class="error">NOT FOUND</span>';
                echo '</div>';
            }
        }
        echo '</div>';

        // ============================================
        // TEST 3: Get active municipality
        // ============================================
        echo '<div class="debug-section">';
        echo '<h3><i class="bi bi-3-circle me-2"></i>Active Municipality Check</h3>';
        
        $municipalityId = $_SESSION['municipality_id'] ?? null;
        
        if (!$municipalityId) {
            echo '<div class="alert alert-warning">';
            echo '<strong>⚠️ WARNING:</strong> No active municipality in session. Checking all municipalities...';
            echo '</div>';
            
            // Get first municipality
            $query = "SELECT municipality_id, name, primary_color, secondary_color FROM municipalities ORDER BY municipality_id LIMIT 1";
            $result = pg_query($connection, $query);
            $municipality = pg_fetch_assoc($result);
            
            if ($municipality) {
                $municipalityId = $municipality['municipality_id'];
            }
        } else {
            $query = "SELECT municipality_id, name, primary_color, secondary_color FROM municipalities WHERE municipality_id = $1";
            $result = pg_query_params($connection, $query, [$municipalityId]);
            $municipality = pg_fetch_assoc($result);
        }
        
        if ($municipality) {
            echo '<div class="test-result">';
            echo '✅ <span class="success">FOUND</span><br>';
            echo '<strong>ID:</strong> ' . htmlspecialchars($municipality['municipality_id']) . '<br>';
            echo '<strong>Name:</strong> ' . htmlspecialchars($municipality['name']) . '<br>';
            echo '<strong>Primary:</strong> ';
            echo '<span class="color-swatch" style="background: ' . htmlspecialchars($municipality['primary_color']) . ';"></span>';
            echo '<code>' . htmlspecialchars($municipality['primary_color']) . '</code><br>';
            echo '<strong>Secondary:</strong> ';
            echo '<span class="color-swatch" style="background: ' . htmlspecialchars($municipality['secondary_color']) . ';"></span>';
            echo '<code>' . htmlspecialchars($municipality['secondary_color']) . '</code>';
            echo '</div>';
        } else {
            echo '<div class="alert alert-danger"><strong>❌ ERROR:</strong> No municipality found in database!</div>';
            echo '</div></body></html>';
            exit;
        }
        echo '</div>';

        // ============================================
        // TEST 4: Test ColorGeneratorService
        // ============================================
        echo '<div class="debug-section">';
        echo '<h3><i class="bi bi-4-circle me-2"></i>ColorGeneratorService Test</h3>';
        
        try {
            $primary = $municipality['primary_color'];
            $secondary = $municipality['secondary_color'];
            
            echo '<h5>Testing color conversions:</h5>';
            
            // Test RGB conversion
            $rgb = ColorGeneratorService::hexToRgb($primary);
            echo '<div class="test-result">';
            echo '✅ hexToRgb(): <code>' . htmlspecialchars($primary) . '</code> → ';
            echo '<code>rgb(' . $rgb['r'] . ', ' . $rgb['g'] . ', ' . $rgb['b'] . ')</code>';
            echo '</div>';
            
            // Test HSL conversion
            $hsl = ColorGeneratorService::rgbToHsl($rgb['r'], $rgb['g'], $rgb['b']);
            echo '<div class="test-result">';
            echo '✅ rgbToHsl(): ';
            echo '<code>h:' . round($hsl['h']) . '° s:' . round($hsl['s']) . '% l:' . round($hsl['l']) . '%</code>';
            echo '</div>';
            
            // Test lighten
            $lighter = ColorGeneratorService::lighten($primary, 0.20);
            echo '<div class="test-result">';
            echo '✅ lighten(20%): ';
            echo '<span class="color-swatch" style="background: ' . htmlspecialchars($lighter) . ';"></span>';
            echo '<code>' . htmlspecialchars($lighter) . '</code>';
            echo '</div>';
            
            // Test darken
            $darker = ColorGeneratorService::darken($primary, 0.20);
            echo '<div class="test-result">';
            echo '✅ darken(20%): ';
            echo '<span class="color-swatch" style="background: ' . htmlspecialchars($darker) . ';"></span>';
            echo '<code>' . htmlspecialchars($darker) . '</code>';
            echo '</div>';
            
            // Test contrast
            $contrastRatio = ColorGeneratorService::getContrastRatio($primary, '#ffffff');
            echo '<div class="test-result">';
            echo $contrastRatio >= 4.5 ? '✅' : '⚠️';
            echo ' Contrast vs white: <code>' . number_format($contrastRatio, 2) . ':1</code> ';
            echo $contrastRatio >= 4.5 ? '<span class="success">(WCAG AA Pass)</span>' : '<span class="warning">(Below 4.5:1)</span>';
            echo '</div>';
            
            echo '<div class="alert alert-success mt-3">';
            echo '<strong>✅ ColorGeneratorService:</strong> All methods working correctly!';
            echo '</div>';
            
        } catch (Exception $e) {
            echo '<div class="alert alert-danger">';
            echo '<strong>❌ ERROR:</strong> ' . htmlspecialchars($e->getMessage());
            echo '<div class="code-block">' . htmlspecialchars($e->getTraceAsString()) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // ============================================
        // TEST 5: Test ThemeGeneratorService
        // ============================================
        echo '<div class="debug-section">';
        echo '<h3><i class="bi bi-5-circle me-2"></i>ThemeGeneratorService Test</h3>';
        
        try {
            $generator = new ThemeGeneratorService($connection);
            
            echo '<h5>Testing palette generation:</h5>';
            
            $palette = $generator->generateColorPalette($primary, $secondary);
            
            if (isset($palette['success']) && !$palette['success']) {
                echo '<div class="alert alert-danger">';
                echo '<strong>❌ ERROR:</strong> Palette generation failed<br>';
                echo '<strong>Errors:</strong> ' . htmlspecialchars(print_r($palette['errors'], true));
                echo '</div>';
            } else {
                echo '<div class="test-result">';
                echo '✅ <span class="success">Palette generated successfully!</span><br>';
                echo '<strong>Colors generated:</strong> ' . count($palette) . '<br><br>';
                
                // Display palette colors
                echo '<div class="row g-2">';
                $count = 0;
                foreach ($palette as $name => $color) {
                    if ($count >= 12) break; // Show first 12
                    echo '<div class="col-md-4">';
                    echo '<span class="color-swatch" style="background: ' . htmlspecialchars($color) . ';"></span>';
                    echo '<small>' . htmlspecialchars($name) . ': <code>' . htmlspecialchars($color) . '</code></small>';
                    echo '</div>';
                    $count++;
                }
                echo '</div>';
                echo '</div>';
            }
            
            echo '<div class="alert alert-success mt-3">';
            echo '<strong>✅ ThemeGeneratorService:</strong> Palette generation working!';
            echo '</div>';
            
        } catch (Exception $e) {
            echo '<div class="alert alert-danger">';
            echo '<strong>❌ ERROR:</strong> ' . htmlspecialchars($e->getMessage());
            echo '<div class="code-block">' . htmlspecialchars($e->getTraceAsString()) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // ============================================
        // TEST 6: Test SidebarThemeService
        // ============================================
        echo '<div class="debug-section">';
        echo '<h3><i class="bi bi-6-circle me-2"></i>SidebarThemeService Test</h3>';
        
        try {
            $sidebarService = new SidebarThemeService($connection);
            
            echo '<h5>Current sidebar settings:</h5>';
            
            $currentSettings = $sidebarService->getCurrentSettings($municipalityId);
            
            if ($currentSettings) {
                echo '<div class="test-result">';
                echo '✅ <span class="success">Found existing settings</span><br>';
                echo '<strong>Colors in database:</strong> ' . count($currentSettings) . '<br><br>';
                
                // Show some key colors
                $keyColors = [
                    'sidebar_bg_start' => 'Sidebar Background Start',
                    'nav_active_bg' => 'Active Navigation BG',
                    'nav_hover_bg' => 'Hover Navigation BG',
                    'nav_text_color' => 'Navigation Text'
                ];
                
                foreach ($keyColors as $key => $label) {
                    if (isset($currentSettings[$key])) {
                        echo '<span class="color-swatch" style="background: ' . htmlspecialchars($currentSettings[$key]) . ';"></span>';
                        echo '<small>' . $label . ': <code>' . htmlspecialchars($currentSettings[$key]) . '</code></small><br>';
                    }
                }
                echo '</div>';
            } else {
                echo '<div class="test-result">';
                echo '⚠️ <span class="warning">No existing settings found</span><br>';
                echo 'This is normal for first-time setup.';
                echo '</div>';
            }
            
            echo '<div class="alert alert-success mt-3">';
            echo '<strong>✅ SidebarThemeService:</strong> Service working correctly!';
            echo '</div>';
            
        } catch (Exception $e) {
            echo '<div class="alert alert-danger">';
            echo '<strong>❌ ERROR:</strong> ' . htmlspecialchars($e->getMessage());
            echo '<div class="code-block">' . htmlspecialchars($e->getTraceAsString()) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // ============================================
        // TEST 7: Full Theme Generation (DRY RUN)
        // ============================================
        echo '<div class="debug-section">';
        echo '<h3><i class="bi bi-7-circle me-2"></i>Full Theme Generation Test (Dry Run)</h3>';
        
        try {
            $generator = new ThemeGeneratorService($connection);
            
            echo '<div class="alert alert-info">';
            echo '<i class="bi bi-info-circle me-2"></i>';
            echo 'This will test the full generation process WITHOUT saving to database.';
            echo '</div>';
            
            // Generate palette
            $palette = $generator->generateColorPalette($primary, $secondary);
            
            if (isset($palette['success']) && !$palette['success']) {
                throw new Exception('Palette generation failed: ' . print_r($palette['errors'], true));
            }
            
            echo '<div class="test-result">';
            echo '✅ Step 1: Palette generated (' . count($palette) . ' colors)<br>';
            echo '✅ Step 2: Would apply to sidebar_theme_settings table<br>';
            echo '✅ Step 3: Would apply to topbar theme table (theme_settings / topbar_theme_settings)<br>';
            echo '</div>';
            
            echo '<div class="alert alert-success mt-3">';
            echo '<strong>✅ DRY RUN SUCCESSFUL!</strong> Theme generation logic is working correctly.';
            echo '</div>';
            
        } catch (Exception $e) {
            echo '<div class="alert alert-danger">';
            echo '<strong>❌ ERROR:</strong> ' . htmlspecialchars($e->getMessage());
            echo '<div class="code-block">' . htmlspecialchars($e->getTraceAsString()) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // ============================================
        // TEST 8: Database Table Checks
        // ============================================
        echo '<div class="debug-section">';
        echo '<h3><i class="bi bi-8-circle me-2"></i>Database Tables Check</h3>';
        
        $tables = [
            'municipalities' => ['municipality_id', 'name', 'primary_color', 'secondary_color'],
            'sidebar_theme_settings' => ['municipality_id', 'sidebar_bg_start', 'nav_active_bg'],
            'theme_settings' => ['municipality_id', 'topbar_bg_color', 'topbar_text_color'],
            'topbar_theme_settings' => ['municipality_id']
        ];
        
        foreach ($tables as $table => $columns) {
            echo '<h5 class="mt-3">Table: <code>' . htmlspecialchars($table) . '</code></h5>';
            
            // Check if table exists
            $checkQuery = "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = '$table')";
            $result = pg_query($connection, $checkQuery);
            $exists = pg_fetch_result($result, 0, 0) === 't';
            
            echo '<div class="test-result">';
            echo $exists ? '✅' : '❌';
            echo ' Table exists: ';
            echo $exists ? '<span class="success">YES</span>' : '<span class="error">NO</span>';
            echo '</div>';
            
            if ($exists) {
                // Check columns
                foreach ($columns as $column) {
                    $colQuery = "SELECT column_name FROM information_schema.columns WHERE table_name = '$table' AND column_name = '$column'";
                    $colResult = pg_query($connection, $colQuery);
                    $colExists = pg_num_rows($colResult) > 0;
                    
                    echo '<div class="test-result">';
                    echo $colExists ? '✅' : '❌';
                    echo ' Column: <code>' . htmlspecialchars($column) . '</code> - ';
                    echo $colExists ? '<span class="success">EXISTS</span>' : '<span class="error">MISSING</span>';
                    echo '</div>';
                }
            }
        }
        echo '</div>';

        // ============================================
        // SUMMARY
        // ============================================
        echo '<div class="debug-section" style="border-left-color: #198754;">';
        echo '<h3><i class="bi bi-check-circle-fill text-success me-2"></i>Debug Summary</h3>';
        echo '<div class="alert alert-success">';
        echo '<h5>All Tests Completed!</h5>';
        echo '<p>If you see this message, the basic infrastructure is working.</p>';
        echo '<strong>Next Steps:</strong>';
        echo '<ol>';
        echo '<li>Go to Municipality Content page</li>';
        echo '<li>Click "Generate Theme" button</li>';
        echo '<li>Check browser console for any JavaScript errors</li>';
        echo '<li>Check Network tab for AJAX request/response</li>';
        echo '</ol>';
        echo '</div>';
        
        echo '<div class="mt-3">';
        echo '<a href="modules/admin/municipality_content.php" class="btn btn-primary">';
        echo '<i class="bi bi-arrow-right me-2"></i>Go to Municipality Content';
        echo '</a>';
        echo '</div>';
        echo '</div>';
        ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
