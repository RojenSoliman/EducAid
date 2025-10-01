<?php

class CSRFProtection {
    private static $session_key = 'csrf_tokens';
    
    /**
     * Generate a CSRF token for a specific form
     */
    public static function generateToken($form_name) {
        if (!isset($_SESSION[self::$session_key])) {
            $_SESSION[self::$session_key] = [];
        }
        
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::$session_key][$form_name] = $token;
        
        return $token;
    }
    
    /**
     * Validate a CSRF token
     */
    public static function validateToken($form_name, $token) {
        if (!isset($_SESSION[self::$session_key][$form_name])) {
            return false;
        }
        
        $valid = hash_equals($_SESSION[self::$session_key][$form_name], $token);
        
        // Remove token after validation (one-time use)
        unset($_SESSION[self::$session_key][$form_name]);
        
        return $valid;
    }
    
    /**
     * Generate hidden input field with CSRF token
     */
    public static function getTokenField($form_name) {
        $token = self::generateToken($form_name);
        return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$token}\">";
    }
}