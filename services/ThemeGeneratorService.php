<?php
/**
 * ThemeGeneratorService.php
 * Generates complete theme colors from primary/secondary colors
 * Applies generated colors to sidebar and topbar themes
 */

require_once __DIR__ . '/ColorGeneratorService.php';
require_once __DIR__ . '/SidebarThemeService.php';

class ThemeGeneratorService {
    private $connection;
    
    public function __construct($connection) {
        $this->connection = $connection;
    }
    
    /**
     * Generate complete color palette from primary and secondary colors
     * @param string $primary Primary hex color
     * @param string $secondary Secondary hex color
     * @return array Complete color palette with validation
     */
    public function generateColorPalette(string $primary, string $secondary): array {
        // Validate input colors
        $validation = $this->validateInputColors($primary, $secondary);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors']
            ];
        }
        
        $palette = [
            // Base colors
            'primary_base' => $primary,
            'secondary_base' => $secondary,
            
            // Primary variations (lightness)
            'primary_lightest' => ColorGeneratorService::lighten($primary, 0.95), // 95% lighter
            'primary_light_90' => ColorGeneratorService::lighten($primary, 0.90), // 90% lighter
            'primary_light_80' => ColorGeneratorService::lighten($primary, 0.80), // 80% lighter
            'primary_light_70' => ColorGeneratorService::lighten($primary, 0.70), // 70% lighter
            'primary_light_50' => ColorGeneratorService::lighten($primary, 0.50), // 50% lighter
            'primary_light_20' => ColorGeneratorService::lighten($primary, 0.20), // 20% lighter
            'primary_dark_05' => ColorGeneratorService::darken($primary, 0.05),   // 5% darker
            'primary_dark_10' => ColorGeneratorService::darken($primary, 0.10),   // 10% darker
            'primary_dark_20' => ColorGeneratorService::darken($primary, 0.20),   // 20% darker
            
            // Primary saturation variations
            'primary_muted' => ColorGeneratorService::desaturate($primary, 0.70), // 70% desaturated
            'primary_saturated' => ColorGeneratorService::saturate($primary, 0.20), // 20% more saturated
            
            // Secondary variations
            'secondary_light_50' => ColorGeneratorService::lighten($secondary, 0.50),
            'secondary_light_20' => ColorGeneratorService::lighten($secondary, 0.20),
            'secondary_dark_10' => ColorGeneratorService::darken($secondary, 0.10),
            
            // Neutral colors (derived from primary for consistency)
            'neutral_darkest' => '#212529',
            'neutral_dark' => '#495057',
            'neutral_medium' => '#6c757d',
            'neutral_light' => '#adb5bd',
            'neutral_lighter' => '#dee2e6',
            'neutral_lightest' => '#f8f9fa',
            
            // Auto-contrast text colors
            'text_on_primary' => ColorGeneratorService::getContrastText($primary),
            'text_on_secondary' => ColorGeneratorService::getContrastText($secondary),
            'text_on_light_bg' => '#212529',
            'text_on_dark_bg' => '#ffffff',
        ];
        
        // Add contrast ratios for validation
        $palette['_meta'] = [
            'primary_brightness' => ColorGeneratorService::validateBrightness($primary),
            'secondary_brightness' => ColorGeneratorService::validateBrightness($secondary),
            'primary_contrast_ratio' => ColorGeneratorService::getContrastRatio($primary, $palette['text_on_primary']),
            'secondary_contrast_ratio' => ColorGeneratorService::getContrastRatio($secondary, $palette['text_on_secondary']),
        ];
        
        return [
            'success' => true,
            'palette' => $palette
        ];
    }
    
    /**
     * Validate input colors meet requirements
     * @param string $primary Primary color
     * @param string $secondary Secondary color
     * @return array Validation result
     */
    private function validateInputColors(string $primary, string $secondary): array {
        $errors = [];
        
        // Validate hex format
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $primary)) {
            $errors[] = 'Primary color must be valid hex format (#RRGGBB)';
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $secondary)) {
            $errors[] = 'Secondary color must be valid hex format (#RRGGBB)';
        }
        
        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }
        
        // Validate brightness
        $primaryBrightness = ColorGeneratorService::validateBrightness($primary);
        if (!$primaryBrightness['valid']) {
            $errors[] = 'Primary color: ' . $primaryBrightness['message'];
        }
        
        $secondaryBrightness = ColorGeneratorService::validateBrightness($secondary);
        if (!$secondaryBrightness['valid']) {
            $errors[] = 'Secondary color: ' . $secondaryBrightness['message'];
        }
        
        // Check if colors are too similar
        $similarity = $this->getColorSimilarity($primary, $secondary);
        if ($similarity > 0.95) { // 95% similar
            $errors[] = 'Primary and secondary colors are too similar. Please choose more distinct colors.';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Calculate color similarity (0 = completely different, 1 = identical)
     * @param string $color1 First color
     * @param string $color2 Second color
     * @return float Similarity score
     */
    private function getColorSimilarity(string $color1, string $color2): float {
        $rgb1 = ColorGeneratorService::hexToRgb($color1);
        $rgb2 = ColorGeneratorService::hexToRgb($color2);
        
        $rDiff = abs($rgb1['r'] - $rgb2['r']) / 255;
        $gDiff = abs($rgb1['g'] - $rgb2['g']) / 255;
        $bDiff = abs($rgb1['b'] - $rgb2['b']) / 255;
        
        return 1 - (($rDiff + $gDiff + $bDiff) / 3);
    }
    
    /**
     * Generate sidebar theme colors from palette
     * @param array $palette Color palette
     * @return array Sidebar theme settings
     */
    public function generateSidebarTheme(array $palette): array {
        return [
            // Sidebar background (very light primary gradient)
            'sidebar_bg_start' => $palette['primary_light_90'],
            'sidebar_bg_end' => '#ffffff',
            
            // Sidebar border (slightly darker than primary)
            'sidebar_border_color' => $palette['primary_dark_05'],
            
            // Navigation text and icons
            'nav_text_color' => $palette['neutral_darkest'],
            'nav_icon_color' => $palette['primary_muted'],
            
            // Navigation hover state (light primary)
            'nav_hover_bg' => $palette['primary_light_80'],
            'nav_hover_text' => $palette['primary_dark_10'],
            
            // Navigation active state (primary color)
            'nav_active_bg' => $palette['primary_base'],
            'nav_active_text' => $palette['text_on_primary'],
            
            // Profile section
            'profile_avatar_bg_start' => $palette['primary_base'],
            'profile_avatar_bg_end' => $palette['secondary_base'],
            'profile_name_color' => $palette['neutral_darkest'],
            'profile_role_color' => $palette['neutral_medium'],
            'profile_border_color' => $palette['neutral_lighter'],
            
            // Submenu
            'submenu_bg' => $palette['primary_lightest'],
            'submenu_text_color' => $palette['neutral_dark'],
            'submenu_hover_bg' => $palette['primary_light_80'],
            'submenu_active_bg' => $palette['primary_light_90'],
            'submenu_active_text' => $palette['primary_base']
        ];
    }
    
    /**
     * Generate topbar theme colors from palette
     * @param array $palette Color palette
     * @return array Topbar theme settings
     */
    public function generateTopbarTheme(array $palette): array {
        // Note: Adjust these fields based on actual topbar_theme_settings table structure
        return [
            'topbar_bg' => $palette['primary_base'],
            'topbar_text' => $palette['text_on_primary'],
            'topbar_border' => $palette['primary_dark_10'],
            'topbar_link_color' => $palette['text_on_primary'],
            'topbar_link_hover' => $palette['primary_light_20'],
            'logo_bg' => $palette['secondary_base'],
            'search_bg' => $palette['primary_dark_05'],
            'search_text' => $palette['text_on_primary'],
            'notification_badge_bg' => $palette['secondary_base'],
            'notification_badge_text' => $palette['text_on_secondary']
        ];
    }
    
    /**
     * Apply generated theme to sidebar
     * @param int $municipalityId Municipality ID
     * @param array $sidebarSettings Sidebar theme settings
     * @return bool Success status
     */
    public function applySidebarTheme(int $municipalityId, array $sidebarSettings): bool {
        $sidebarService = new SidebarThemeService($this->connection);
        $result = $sidebarService->updateSettings($sidebarSettings, $municipalityId);
        return is_array($result) ? ($result['success'] ?? false) : (bool) $result;
    }
    
    /**
     * Apply generated theme to topbar
     * @param int $municipalityId Municipality ID
     * @param array $topbarSettings Topbar theme settings
     * @return bool Success status
     */
    public function applyTopbarTheme(int $municipalityId, array $topbarSettings): bool {
        // Check if topbar settings table exists
        $tableCheck = pg_query($this->connection, 
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'topbar_theme_settings')"
        );
        
        if (!$tableCheck || !pg_fetch_result($tableCheck, 0, 0)) {
            // Table doesn't exist, skip
            return true;
        }
        
        // Check if record exists
        $existsQuery = "SELECT id FROM topbar_theme_settings WHERE municipality_id = $1";
        $existsResult = pg_query_params($this->connection, $existsQuery, [$municipalityId]);
        
        if (pg_num_rows($existsResult) > 0) {
            // Update existing
            $updateFields = [];
            $values = [];
            $paramIndex = 1;
            
            foreach ($topbarSettings as $field => $value) {
                $updateFields[] = "$field = \$$paramIndex";
                $values[] = $value;
                $paramIndex++;
            }
            
            $values[] = $municipalityId;
            $updateQuery = "UPDATE topbar_theme_settings SET " . implode(', ', $updateFields) . " WHERE municipality_id = \$$paramIndex";
            $result = pg_query_params($this->connection, $updateQuery, $values);
        } else {
            // Insert new
            $topbarSettings['municipality_id'] = $municipalityId;
            $fields = array_keys($topbarSettings);
            $values = array_values($topbarSettings);
            $placeholders = [];
            
            for ($i = 1; $i <= count($values); $i++) {
                $placeholders[] = "\$$i";
            }
            
            $insertQuery = "INSERT INTO topbar_theme_settings (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $result = pg_query_params($this->connection, $insertQuery, $values);
        }
        
        return $result !== false;
    }
    
    /**
     * Generate and preview theme without applying
     * @param string $primary Primary color
     * @param string $secondary Secondary color
     * @return array Preview data with all generated colors
     */
    public function previewTheme(string $primary, string $secondary): array {
        // Generate palette
        $paletteResult = $this->generateColorPalette($primary, $secondary);
        
        if (!$paletteResult['success']) {
            return $paletteResult;
        }
        
        $palette = $paletteResult['palette'];
        
        // Generate theme settings
        $sidebarTheme = $this->generateSidebarTheme($palette);
        $topbarTheme = $this->generateTopbarTheme($palette);
        
        return [
            'success' => true,
            'palette' => $palette,
            'sidebar_theme' => $sidebarTheme,
            'topbar_theme' => $topbarTheme,
            'validation' => [
                'primary_brightness' => $palette['_meta']['primary_brightness'],
                'secondary_brightness' => $palette['_meta']['secondary_brightness'],
                'primary_contrast' => $palette['_meta']['primary_contrast_ratio'],
                'secondary_contrast' => $palette['_meta']['secondary_contrast_ratio'],
                'wcag_aa_compliant' => $palette['_meta']['primary_contrast_ratio'] >= 4.5 && 
                                       $palette['_meta']['secondary_contrast_ratio'] >= 4.5
            ]
        ];
    }
    
    /**
     * Generate and apply complete theme
     * @param int $municipalityId Municipality ID
     * @param string $primary Primary color
     * @param string $secondary Secondary color
     * @return array Result with success status and details
     */
    public function generateAndApplyTheme(int $municipalityId, string $primary, string $secondary): array {
        // Generate palette
        $paletteResult = $this->generateColorPalette($primary, $secondary);
        
        if (!$paletteResult['success']) {
            return $paletteResult;
        }
        
        $palette = $paletteResult['palette'];
        
        // Generate theme settings
        $sidebarTheme = $this->generateSidebarTheme($palette);
        $topbarTheme = $this->generateTopbarTheme($palette);
        
        // Apply themes
        $sidebarSuccess = $this->applySidebarTheme($municipalityId, $sidebarTheme);
        $topbarSuccess = $this->applyTopbarTheme($municipalityId, $topbarTheme);
        
        return [
            'success' => $sidebarSuccess && $topbarSuccess,
            'sidebar_updated' => $sidebarSuccess,
            'topbar_updated' => $topbarSuccess,
            'message' => $sidebarSuccess && $topbarSuccess 
                ? 'Theme generated and applied successfully!' 
                : 'Theme generation partially failed. Check individual components.',
            'palette' => $palette
        ];
    }
}
