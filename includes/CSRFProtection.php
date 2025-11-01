<?php
// Include JSON fallback only when native functions are missing.
if (!function_exists('json_encode')) {
    // compat/json_fallback.php provides minimal json_encode/json_decode implementations
    @include_once __DIR__ . '/compat/json_fallback.php';
}

class CSRFProtection {
    private static $session_key = 'csrf_tokens';
    
    /**
     * Generate a CSRF token for a specific form
     * If a token already exists for this form, return it instead of creating a new one
     */
    public static function generateToken($form_name) {
        if (!isset($_SESSION[self::$session_key])) {
            $_SESSION[self::$session_key] = [];
        }

        $existing = $_SESSION[self::$session_key][$form_name] ?? [];
        if (!is_array($existing)) {
            $existing = $existing ? [$existing] : [];
        }

        $token = bin2hex(random_bytes(32));
        $existing[] = $token;

        // Keep only the last 5 tokens to prevent unbounded growth
        $existing = array_slice($existing, -5);
        $_SESSION[self::$session_key][$form_name] = $existing;

        // Debug logging (truncate token for safety)
        error_log(sprintf(
            'CSRF: Issued token for %s (stored: %d, latest: %s...)',
            $form_name,
            count($existing),
            substr($token, 0, 16)
        ));

        return $token;
    }
    
    /**
     * Validate a CSRF token
     * @param string $form_name The form identifier
     * @param string $token The token to validate
     * @param bool $consume Whether to consume (delete) the token after validation (default: true)
     * @return bool
     */
    public static function validateToken($form_name, $token, $consume = true) {
        if (!isset($_SESSION[self::$session_key][$form_name]) || empty($token)) {
            return false;
        }

        $stored = $_SESSION[self::$session_key][$form_name];

        if (is_array($stored)) {
            foreach ($stored as $index => $storedToken) {
                if (hash_equals($storedToken, $token)) {
                    if ($consume) {
                        unset($stored[$index]);
                        $stored = array_values($stored);
                        if (empty($stored)) {
                            unset($_SESSION[self::$session_key][$form_name]);
                        } else {
                            $_SESSION[self::$session_key][$form_name] = $stored;
                        }
                    } else {
                        $_SESSION[self::$session_key][$form_name] = $stored;
                    }
                    return true;
                }
            }
            return false;
        }

        $valid = hash_equals($stored, $token);

        if ($consume && $valid) {
            unset($_SESSION[self::$session_key][$form_name]);
        }

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