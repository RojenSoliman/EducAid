<?php
/**
 * Environment Validator
 * Run manually (web or CLI) to check for missing or placeholder values.
 * This does NOT dump actual secret values—only reports status.
 */

require_once __DIR__ . '/env.php';

header('Content-Type: application/json');

$required = [
    // Core infrastructure
    'DB_HOST' => 'Database host',
    'DB_PORT' => 'Database port',
    'DB_NAME' => 'Database name',
    'DB_USER' => 'Database user',
    // Security / features
    'RECAPTCHA_SITE_KEY' => 'reCAPTCHA site key',
    'RECAPTCHA_SECRET_KEY' => 'reCAPTCHA secret key',
    'SMTP_HOST' => 'SMTP host',
    'SMTP_PORT' => 'SMTP port',
    'SMTP_USERNAME' => 'SMTP username',
    'SMTP_PASSWORD' => 'SMTP password'
];

$placeholders = [
    'replace_me_site_key',
    'replace_me_secret_key',
    'your_smtp_username_or_email',
    'your_app_password_or_secret',
    'replace_with_long_random_64_plus_chars'
];

$results = [];
$problems = 0;

// Helper to add a result
function add_check(array &$results, string $key, string $label, string $status, string $detail, int &$problems): void {
    if ($status !== 'ok') { $problems++; }
    $results[$key] = [
        'label' => $label,
        'status' => $status,
        'message' => $detail
    ];
}

foreach ($required as $key => $label) {
    $raw = getenv($key);
    $status = 'ok';
    $detail = '';
    if ($raw === false || $raw === '') {
        $status = 'missing';
        $detail = 'Value not set';
        $problems++;
    } else {
        foreach ($placeholders as $ph) {
            if (stripos($raw, $ph) !== false) {
                $status = 'placeholder';
                $detail = 'Placeholder value still in use';
                $problems++;
                break;
            }
        }
    }
    add_check($results, $key, $label, $status, $detail, $problems);
}

// Optional / security policy checks (non-fatal if missing but recommended)
$policy = [
    'PASSWORD_MIN_LENGTH' => 12,
    'PASSWORD_MIN_STRENGTH_SCORE' => 70,
    'PASSWORD_ARGON_MEMORY' => 32768,
    'PASSWORD_ARGON_TIME' => 2,
    'SESSION_IDLE_TIMEOUT_MINUTES' => 5,
    'JWT_SECRET' => null
];

// Media Encryption Keys validation (required for secure profile pictures)
function validate_media_keys(array &$results, int &$problems): void {
    $single = getenv('MEDIA_ENCRYPTION_KEY');
    $multi = getenv('MEDIA_ENCRYPTION_KEYS');
    $activeOverride = getenv('MEDIA_ENCRYPTION_ACTIVE_KEY');
    $ok = false; $messages = [];

    $keysParsed = [];
    if ($multi) {
        $parts = array_filter(array_map('trim', explode(',', $multi)));
        foreach ($parts as $p) {
            if (strpos($p, ':') === false) { $messages[] = "Malformed entry '$p' (missing colon)"; continue; }
            [$idStr,$b64] = explode(':',$p,2);
            $idStr = trim($idStr); $b64 = trim($b64);
            if (!ctype_digit($idStr)) { $messages[] = "Key id '$idStr' not numeric"; continue; }
            $id = (int)$idStr; if ($id<1 || $id>255) { $messages[] = "Key id $id out of range"; continue; }
            $raw = base64_decode($b64, true);
            if ($raw === false || strlen($raw)!==32) { $messages[] = "Key id $id invalid (must be base64 32 bytes)"; continue; }
            $keysParsed[$id] = true;
        }
        if (!empty($keysParsed)) { $ok = true; }
    }
    if (!$ok && $single) {
        $raw = base64_decode($single, true);
        if ($raw !== false && strlen($raw)===32) { $ok = true; } else { $messages[] = 'Single key invalid (must be base64 32 bytes)'; }
    }
    if ($ok && $multi && $activeOverride) {
        if (!ctype_digit($activeOverride) || !isset($keysParsed[(int)$activeOverride])) {
            $messages[] = 'Active override key id not found in MEDIA_ENCRYPTION_KEYS';
            $ok = false;
        }
    }
    if (!$ok) {
        $problems++;
        $results['MEDIA_ENCRYPTION_KEYS_STATUS'] = [
            'label' => 'Media encryption keys',
            'status' => 'missing',
            'message' => empty($messages) ? 'No valid encryption key configured (MEDIA_ENCRYPTION_KEY or MEDIA_ENCRYPTION_KEYS).' : implode('; ', $messages)
        ];
    } else {
        $results['MEDIA_ENCRYPTION_KEYS_STATUS'] = [
            'label' => 'Media encryption keys',
            'status' => 'ok',
            'message' => 'Valid key configuration detected' . ($multi ? ' (multi-key mode)' : ' (single-key mode)')
        ];
    }
}

foreach ($policy as $k => $baseline) {
    $val = getenv($k);
    if ($val === false || $val === '') {
        // Only flag JWT_SECRET as warning if entirely absent (if JWT not used yet it’s fine)
        if ($k === 'JWT_SECRET') {
            add_check($results, $k, 'JWT secret (optional until tokens added)', 'info', 'Not set (OK if JWT not implemented yet)', $problems);
        } else {
            add_check($results, $k, $k, 'warning', 'Not set; using implicit defaults in code', $problems);
        }
        continue;
    }
    // Numeric baselines
    if (is_numeric($baseline) && is_numeric($val)) {
        if ((int)$val < (int)$baseline) {
            add_check($results, $k, $k, 'warning', "Value ($val) below recommended baseline ($baseline)", $problems);
            continue;
        }
    }
    if ($k === 'JWT_SECRET') {
        if (strlen($val) < 48) {
            add_check($results, $k, 'JWT secret', 'warning', 'Too short (<48 chars). Use a long random string.', $problems);
            continue;
        }
    }
    add_check($results, $k, $k, 'ok', 'Within recommended range', $problems);
}

validate_media_keys($results, $problems);

$summary = [
    'issues' => $problems,
    'passed' => $problems === 0,
    'timestamp' => date('c'),
    'recommendation' => $problems === 0 ? 'Environment configuration looks solid.' : 'Address warnings / errors before production.'
];

echo json_encode(['summary' => $summary, 'checks' => $results], JSON_PRETTY_PRINT);
?>
