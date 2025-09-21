<?php
/**
 * Google reCAPTCHA v3 Configuration
 * 
 * To get your v3 keys:
 * 1. Go to https://www.google.com/recaptcha/admin/create
 * 2. Choose reCAPTCHA v3 (Score based) - NOT v2!
 * 3. Add your domain (localhost for development)
 * 4. Copy the Site Key and Secret Key below
 * 
 * IMPORTANT: v2 keys will NOT work with v3!
 */

// Replace these with your actual reCAPTCHA keys
define('RECAPTCHA_SITE_KEY', '6LcJx9ArAAAAAMWCducps6tY8ELJvfgvkuf2_Xb_'); // Your actual v3 site key
define('RECAPTCHA_SECRET_KEY', '6LcJx9ArAAAAACvYGGHsVokjQplnb1k7oakrw7sN'); // Your actual v3 secret key

// For development, you can use test keys:
// Site Key: 6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI
// Secret Key: 6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe
// Note: Test keys always return success, use only for testing!

// Production configuration
define('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify');
?>