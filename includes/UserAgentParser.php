<?php
/**
 * User Agent Parser
 * Extracts device, browser, and OS information from user agent strings
 */

class UserAgentParser {
    
    /**
     * Parse user agent string and return device information
     */
    public static function parse($userAgent) {
        return [
            'device' => self::getDeviceType($userAgent),
            'browser' => self::getBrowser($userAgent),
            'os' => self::getOS($userAgent)
        ];
    }
    
    /**
     * Determine device type (mobile, tablet, desktop)
     */
    private static function getDeviceType($userAgent) {
        $userAgent = strtolower($userAgent);
        
        if (preg_match('/tablet|ipad|playbook|silk/i', $userAgent)) {
            return 'tablet';
        }
        
        if (preg_match('/mobile|iphone|ipod|android|blackberry|mini|windows\sce|palm/i', $userAgent)) {
            return 'mobile';
        }
        
        return 'desktop';
    }
    
    /**
     * Detect browser name and version
     */
    private static function getBrowser($userAgent) {
        $browsers = [
            'Edg' => 'Microsoft Edge',
            'Chrome' => 'Google Chrome',
            'Safari' => 'Safari',
            'Firefox' => 'Mozilla Firefox',
            'MSIE' => 'Internet Explorer',
            'Trident' => 'Internet Explorer',
            'Opera' => 'Opera',
            'OPR' => 'Opera'
        ];
        
        foreach ($browsers as $pattern => $name) {
            if (stripos($userAgent, $pattern) !== false) {
                // Extract version if possible
                if (preg_match('/' . $pattern . '[\/\s]?([\d\.]+)/i', $userAgent, $matches)) {
                    return $name . ' ' . $matches[1];
                }
                return $name;
            }
        }
        
        return 'Unknown Browser';
    }
    
    /**
     * Detect operating system
     */
    private static function getOS($userAgent) {
        $osList = [
            '/windows nt 10/i' => 'Windows 10/11',
            '/windows nt 6.3/i' => 'Windows 8.1',
            '/windows nt 6.2/i' => 'Windows 8',
            '/windows nt 6.1/i' => 'Windows 7',
            '/windows nt 6.0/i' => 'Windows Vista',
            '/windows nt 5.1/i' => 'Windows XP',
            '/macintosh|mac os x/i' => 'macOS',
            '/mac_powerpc/i' => 'Mac OS',
            '/linux/i' => 'Linux',
            '/ubuntu/i' => 'Ubuntu',
            '/iphone/i' => 'iOS (iPhone)',
            '/ipod/i' => 'iOS (iPod)',
            '/ipad/i' => 'iOS (iPad)',
            '/android/i' => 'Android',
            '/blackberry/i' => 'BlackBerry',
            '/webos/i' => 'Mobile'
        ];
        
        foreach ($osList as $pattern => $os) {
            if (preg_match($pattern, $userAgent)) {
                // Try to extract version for Android/iOS
                if (preg_match('/Android[\s\/]([\d\.]+)/i', $userAgent, $matches)) {
                    return 'Android ' . $matches[1];
                }
                if (preg_match('/OS ([\d_]+)/i', $userAgent, $matches)) {
                    $version = str_replace('_', '.', $matches[1]);
                    return 'iOS ' . $version;
                }
                return $os;
            }
        }
        
        return 'Unknown OS';
    }
    
    /**
     * Get device icon based on device type
     */
    public static function getDeviceIcon($deviceType) {
        $icons = [
            'mobile' => 'bi-phone',
            'tablet' => 'bi-tablet',
            'desktop' => 'bi-laptop'
        ];
        
        return $icons[$deviceType] ?? 'bi-laptop';
    }
    
    /**
     * Get browser icon based on browser name
     */
    public static function getBrowserIcon($browser) {
        if (stripos($browser, 'Chrome') !== false) {
            return 'bi-google';
        }
        if (stripos($browser, 'Firefox') !== false) {
            return 'bi-browser-firefox';
        }
        if (stripos($browser, 'Safari') !== false) {
            return 'bi-browser-safari';
        }
        if (stripos($browser, 'Edge') !== false) {
            return 'bi-browser-edge';
        }
        
        return 'bi-globe';
    }
}
