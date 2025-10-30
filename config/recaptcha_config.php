<?php
// reCAPTCHA v3 configuration via environment variables
require_once __DIR__ . '/env.php';

// Allow fallbacks (e.g., test keys) if not set; encourage overriding in .env
$siteKey   = getenv('RECAPTCHA_SITE_KEY')   ?: '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI'; // Test key
$secretKey = getenv('RECAPTCHA_SECRET_KEY') ?: '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe'; // Test secret

// Optional: separate v2 keys (if you use v2 widget alongside v3)
$v2SiteKey   = getenv('RECAPTCHA_V2_SITE_KEY')   ?: $siteKey;
$v2SecretKey = getenv('RECAPTCHA_V2_SECRET_KEY') ?: $secretKey;

if (!defined('RECAPTCHA_SITE_KEY')) {
	define('RECAPTCHA_SITE_KEY', $siteKey);
}
if (!defined('RECAPTCHA_SECRET_KEY')) {
	define('RECAPTCHA_SECRET_KEY', $secretKey);
}
if (!defined('RECAPTCHA_V2_SITE_KEY')) {
	define('RECAPTCHA_V2_SITE_KEY', $v2SiteKey);
}
if (!defined('RECAPTCHA_V2_SECRET_KEY')) {
	define('RECAPTCHA_V2_SECRET_KEY', $v2SecretKey);
}
if (!defined('RECAPTCHA_VERIFY_URL')) {
	define('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify');
}
?>
