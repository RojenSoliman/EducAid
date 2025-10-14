<?php
/**
 * ColorGeneratorService.php
 * Generates derivative colors from base colors using HSL color math
 * Includes strict validation for contrast ratios and brightness limits
 */

class ColorGeneratorService {
    
    // WCAG AA minimum contrast ratio
    const MIN_CONTRAST_RATIO = 4.5;
    
    // Brightness limits (0-100)
    const MIN_BRIGHTNESS = 10;
    const MAX_BRIGHTNESS = 95;
    
    /**
     * Convert hex color to RGB
     * @param string $hex Hex color (e.g., #2e7d32)
     * @return array ['r' => int, 'g' => int, 'b' => int]
     */
    public static function hexToRgb(string $hex): array {
        $hex = ltrim($hex, '#');
        
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2))
        ];
    }
    
    /**
     * Convert RGB to hex color
     * @param int $r Red (0-255)
     * @param int $g Green (0-255)
     * @param int $b Blue (0-255)
     * @return string Hex color with #
     */
    public static function rgbToHex(int $r, int $g, int $b): string {
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));
        
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
    
    /**
     * Convert RGB to HSL
     * @param int $r Red (0-255)
     * @param int $g Green (0-255)
     * @param int $b Blue (0-255)
     * @return array ['h' => float (0-360), 's' => float (0-100), 'l' => float (0-100)]
     */
    public static function rgbToHsl(int $r, int $g, int $b): array {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;
        
        $l = ($max + $min) / 2;
        
        if ($delta == 0) {
            $h = 0;
            $s = 0;
        } else {
            $s = $l > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);
            
            switch ($max) {
                case $r:
                    $h = (($g - $b) / $delta) + ($g < $b ? 6 : 0);
                    break;
                case $g:
                    $h = (($b - $r) / $delta) + 2;
                    break;
                case $b:
                    $h = (($r - $g) / $delta) + 4;
                    break;
            }
            
            $h /= 6;
        }
        
        return [
            'h' => round($h * 360, 2),
            's' => round($s * 100, 2),
            'l' => round($l * 100, 2)
        ];
    }
    
    /**
     * Convert HSL to RGB
     * @param float $h Hue (0-360)
     * @param float $s Saturation (0-100)
     * @param float $l Lightness (0-100)
     * @return array ['r' => int, 'g' => int, 'b' => int]
     */
    public static function hslToRgb(float $h, float $s, float $l): array {
        $h /= 360;
        $s /= 100;
        $l /= 100;
        
        if ($s == 0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            
            $r = self::hueToRgb($p, $q, $h + 1/3);
            $g = self::hueToRgb($p, $q, $h);
            $b = self::hueToRgb($p, $q, $h - 1/3);
        }
        
        return [
            'r' => (int) round($r * 255),
            'g' => (int) round($g * 255),
            'b' => (int) round($b * 255)
        ];
    }
    
    /**
     * Helper function for HSL to RGB conversion
     */
    private static function hueToRgb(float $p, float $q, float $t): float {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1/6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1/2) return $q;
        if ($t < 2/3) return $p + ($q - $p) * (2/3 - $t) * 6;
        return $p;
    }
    
    /**
     * Lighten a color by a percentage
     * @param string $hex Hex color
     * @param float $percent Amount to lighten (0.0 - 1.0)
     * @return string Lightened hex color
     */
    public static function lighten(string $hex, float $percent): string {
        $rgb = self::hexToRgb($hex);
        $hsl = self::rgbToHsl($rgb['r'], $rgb['g'], $rgb['b']);
        
        // Increase lightness
        $hsl['l'] = min(100, $hsl['l'] + ($percent * 100));
        
        // Enforce max brightness limit
        if ($hsl['l'] > self::MAX_BRIGHTNESS) {
            $hsl['l'] = self::MAX_BRIGHTNESS;
        }
        
        $rgb = self::hslToRgb($hsl['h'], $hsl['s'], $hsl['l']);
        return self::rgbToHex($rgb['r'], $rgb['g'], $rgb['b']);
    }
    
    /**
     * Darken a color by a percentage
     * @param string $hex Hex color
     * @param float $percent Amount to darken (0.0 - 1.0)
     * @return string Darkened hex color
     */
    public static function darken(string $hex, float $percent): string {
        $rgb = self::hexToRgb($hex);
        $hsl = self::rgbToHsl($rgb['r'], $rgb['g'], $rgb['b']);
        
        // Decrease lightness
        $hsl['l'] = max(0, $hsl['l'] - ($percent * 100));
        
        // Enforce min brightness limit
        if ($hsl['l'] < self::MIN_BRIGHTNESS) {
            $hsl['l'] = self::MIN_BRIGHTNESS;
        }
        
        $rgb = self::hslToRgb($hsl['h'], $hsl['s'], $hsl['l']);
        return self::rgbToHex($rgb['r'], $rgb['g'], $rgb['b']);
    }
    
    /**
     * Saturate a color by a percentage
     * @param string $hex Hex color
     * @param float $percent Amount to saturate (0.0 - 1.0)
     * @return string Saturated hex color
     */
    public static function saturate(string $hex, float $percent): string {
        $rgb = self::hexToRgb($hex);
        $hsl = self::rgbToHsl($rgb['r'], $rgb['g'], $rgb['b']);
        
        $hsl['s'] = min(100, $hsl['s'] + ($percent * 100));
        
        $rgb = self::hslToRgb($hsl['h'], $hsl['s'], $hsl['l']);
        return self::rgbToHex($rgb['r'], $rgb['g'], $rgb['b']);
    }
    
    /**
     * Desaturate a color by a percentage
     * @param string $hex Hex color
     * @param float $percent Amount to desaturate (0.0 - 1.0)
     * @return string Desaturated hex color
     */
    public static function desaturate(string $hex, float $percent): string {
        $rgb = self::hexToRgb($hex);
        $hsl = self::rgbToHsl($rgb['r'], $rgb['g'], $rgb['b']);
        
        $hsl['s'] = max(0, $hsl['s'] - ($percent * 100));
        
        $rgb = self::hslToRgb($hsl['h'], $hsl['s'], $hsl['l']);
        return self::rgbToHex($rgb['r'], $rgb['g'], $rgb['b']);
    }
    
    /**
     * Calculate relative luminance of a color (WCAG formula)
     * @param string $hex Hex color
     * @return float Luminance value (0-1)
     */
    public static function getLuminance(string $hex): float {
        $rgb = self::hexToRgb($hex);
        
        $r = $rgb['r'] / 255;
        $g = $rgb['g'] / 255;
        $b = $rgb['b'] / 255;
        
        $r = ($r <= 0.03928) ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
        $g = ($g <= 0.03928) ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
        $b = ($b <= 0.03928) ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);
        
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
    
    /**
     * Calculate contrast ratio between two colors (WCAG formula)
     * @param string $color1 First hex color
     * @param string $color2 Second hex color
     * @return float Contrast ratio (1-21)
     */
    public static function getContrastRatio(string $color1, string $color2): float {
        $lum1 = self::getLuminance($color1);
        $lum2 = self::getLuminance($color2);
        
        $lighter = max($lum1, $lum2);
        $darker = min($lum1, $lum2);
        
        return ($lighter + 0.05) / ($darker + 0.05);
    }
    
    /**
     * Get contrasting text color (black or white) for a background
     * Ensures WCAG AA compliance (4.5:1 ratio)
     * @param string $bgHex Background color hex
     * @return string '#ffffff' or '#000000'
     */
    public static function getContrastText(string $bgHex): string {
        $whiteContrast = self::getContrastRatio($bgHex, '#ffffff');
        $blackContrast = self::getContrastRatio($bgHex, '#000000');
        
        // Return color with better contrast
        return $whiteContrast > $blackContrast ? '#ffffff' : '#000000';
    }
    
    /**
     * Validate if contrast ratio meets WCAG AA standards
     * @param string $color1 First color
     * @param string $color2 Second color
     * @return bool True if meets WCAG AA (4.5:1)
     */
    public static function meetsContrastStandards(string $color1, string $color2): bool {
        $ratio = self::getContrastRatio($color1, $color2);
        return $ratio >= self::MIN_CONTRAST_RATIO;
    }
    
    /**
     * Adjust color until it meets contrast requirements
     * @param string $colorHex Color to adjust
     * @param string $bgHex Background color
     * @param bool $lighten True to lighten, false to darken
     * @return string Adjusted color that meets contrast ratio
     */
    public static function ensureContrast(string $colorHex, string $bgHex, bool $lighten = true): string {
        $maxAttempts = 20;
        $step = 0.05; // 5% adjustment per step
        
        for ($i = 0; $i < $maxAttempts; $i++) {
            if (self::meetsContrastStandards($colorHex, $bgHex)) {
                return $colorHex;
            }
            
            if ($lighten) {
                $colorHex = self::lighten($colorHex, $step);
            } else {
                $colorHex = self::darken($colorHex, $step);
            }
        }
        
        // If still doesn't meet standards, return high-contrast fallback
        return self::getContrastText($bgHex);
    }
    
    /**
     * Validate color brightness is within acceptable limits
     * @param string $hex Hex color
     * @return array ['valid' => bool, 'brightness' => float, 'message' => string]
     */
    public static function validateBrightness(string $hex): array {
        $rgb = self::hexToRgb($hex);
        $hsl = self::rgbToHsl($rgb['r'], $rgb['g'], $rgb['b']);
        
        $brightness = $hsl['l'];
        
        if ($brightness < self::MIN_BRIGHTNESS) {
            return [
                'valid' => false,
                'brightness' => $brightness,
                'message' => "Color too dark (brightness: {$brightness}%). Minimum: " . self::MIN_BRIGHTNESS . "%"
            ];
        }
        
        if ($brightness > self::MAX_BRIGHTNESS) {
            return [
                'valid' => false,
                'brightness' => $brightness,
                'message' => "Color too bright (brightness: {$brightness}%). Maximum: " . self::MAX_BRIGHTNESS . "%"
            ];
        }
        
        return [
            'valid' => true,
            'brightness' => $brightness,
            'message' => 'Brightness within acceptable range'
        ];
    }
    
    /**
     * Mix two colors together
     * @param string $color1 First hex color
     * @param string $color2 Second hex color
     * @param float $weight Weight of first color (0.0 - 1.0)
     * @return string Mixed hex color
     */
    public static function mix(string $color1, string $color2, float $weight = 0.5): string {
        $rgb1 = self::hexToRgb($color1);
        $rgb2 = self::hexToRgb($color2);
        
        $r = (int) round($rgb1['r'] * $weight + $rgb2['r'] * (1 - $weight));
        $g = (int) round($rgb1['g'] * $weight + $rgb2['g'] * (1 - $weight));
        $b = (int) round($rgb1['b'] * $weight + $rgb2['b'] * (1 - $weight));
        
        return self::rgbToHex($r, $g, $b);
    }
}
