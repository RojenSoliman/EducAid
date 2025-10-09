<?php
/**
 * AJAX Save Contact Content
 * Saves edited content blocks from Contact page
 * Super Admin only
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function resp_contact($ok, $msg = '', $extra = []) {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    resp_contact(false, 'Invalid method');
}

if (!isset($connection)) {
    resp_contact(false, 'Database unavailable');
}

$is_super_admin = false;
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
    $role = @getCurrentAdminRole($connection);
    if ($role === 'super_admin') {
        $is_super_admin = true;
    }
}

if (!$is_super_admin) {
    resp_contact(false, 'Unauthorized');
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['blocks']) || !is_array($payload['blocks'])) {
    resp_contact(false, 'Invalid payload');
}

// Ensure tables/indexes exist (idempotent)
@pg_query($connection, "CREATE TABLE IF NOT EXISTS contact_content_blocks (
    id SERIAL PRIMARY KEY,
    municipality_id INT NOT NULL DEFAULT 1,
    block_key VARCHAR(100) NOT NULL,
    html TEXT NOT NULL,
    text_color VARCHAR(20) DEFAULT NULL,
    bg_color VARCHAR(20) DEFAULT NULL,
    updated_at TIMESTAMPTZ DEFAULT NOW()
)");
@pg_query($connection, "CREATE TABLE IF NOT EXISTS contact_content_audit (
    id SERIAL PRIMARY KEY,
    municipality_id INT NOT NULL DEFAULT 1,
    block_key VARCHAR(100) NOT NULL,
    html_snapshot TEXT,
    text_color VARCHAR(20),
    bg_color VARCHAR(20),
    changed_by VARCHAR(120),
    changed_at TIMESTAMPTZ DEFAULT NOW()
)");
@pg_query($connection, "CREATE UNIQUE INDEX IF NOT EXISTS contact_content_blocks_unique ON contact_content_blocks(municipality_id, block_key)");

$blocks = $payload['blocks'];
$keys = array_filter(array_map(fn($b) => trim($b['key'] ?? ''), $blocks));
$existing = [];
if ($keys) {
    $ph = [];
    $params = [];
    foreach ($keys as $i => $k) {
        $ph[] = '$' . ($i + 1);
        $params[] = $k;
    }
    $sql = "SELECT block_key, html, text_color, bg_color FROM contact_content_blocks WHERE municipality_id=1 AND block_key IN (" . implode(',', $ph) . ")";
    $res = @pg_query_params($connection, $sql, $params);
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $existing[$row['block_key']] = $row;
        }
        pg_free_result($res);
    }
}

@pg_prepare($connection, 'contact_upsert', "INSERT INTO contact_content_blocks (municipality_id, block_key, html, text_color, bg_color) VALUES (1,$1,$2,$3,$4)
    ON CONFLICT (municipality_id, block_key) DO UPDATE SET html=EXCLUDED.html, text_color=EXCLUDED.text_color, bg_color=EXCLUDED.bg_color, updated_at=NOW()");

$updated = 0;
$errors = [];
$changedBy = $_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'super_admin';

foreach ($blocks as $block) {
    $key = trim($block['key'] ?? '');
    $html = isset($block['html']) ? (string)$block['html'] : '';
    $styles = $block['styles'] ?? [];
    if ($key === '' || $html === '') {
        continue;
    }

    $html_clean = preg_replace('#<script[^>]*>.*?</script>#is', '', $html);
    $textColor = preg_match('/^#[0-9a-fA-F]{3,8}$/', $styles['color'] ?? '') ? $styles['color'] : null;
    $bgColor = preg_match('/^#[0-9a-fA-F]{3,8}$/', $styles['backgroundColor'] ?? '') ? $styles['backgroundColor'] : null;

    $result = @pg_execute($connection, 'contact_upsert', [$key, $html_clean, $textColor, $bgColor]);
    if ($result) {
        $updated++;
        @pg_query_params($connection, "INSERT INTO contact_content_audit (municipality_id, block_key, html_snapshot, text_color, bg_color, changed_by) VALUES (1,$1,$2,$3,$4,$5)", [$key, $html_clean, $textColor, $bgColor, $changedBy]);
    } else {
        $errors[] = $key;
    }
}

if ($updated === 0 && empty($errors)) {
    resp_contact(false, 'Nothing updated');
}

resp_contact(true, 'Updated ' . $updated . ' block(s)', ['errors' => $errors]);
