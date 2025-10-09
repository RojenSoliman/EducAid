<?php
/**
 * AJAX Get Contact History
 * Returns edit history for a specific block
 * Super Admin only
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function resp_history($ok, $msg = '', $extra = []) {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    resp_history(false, 'Invalid method');
}

if (!isset($connection)) {
    resp_history(false, 'Database unavailable');
}

$is_super_admin = false;
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
    $role = @getCurrentAdminRole($connection);
    if ($role === 'super_admin') {
        $is_super_admin = true;
    }
}

if (!$is_super_admin) {
    resp_history(false, 'Unauthorized');
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$blockKey = trim($data['block'] ?? '');
$limit = (int)($data['limit'] ?? 50);
if ($limit < 1) { $limit = 25; }
if ($limit > 200) { $limit = 200; }

$params = [1];
$clauses = ['municipality_id=$1'];

if ($blockKey !== '') {
    $params[] = $blockKey;
    $clauses[] = 'block_key=$' . count($params);
}

$sql = 'SELECT id, block_key, html_snapshot, text_color, bg_color, changed_by, changed_at FROM contact_content_audit WHERE ' . implode(' AND ', $clauses) . ' ORDER BY changed_at DESC LIMIT ' . $limit;
$result = @pg_query_params($connection, $sql, $params);

if ($result === false) {
    resp_history(false, 'Query failed', ['error' => pg_last_error($connection)]);
}

$records = [];
while ($row = pg_fetch_assoc($result)) {
    $records[] = [
        'audit_id' => (int)$row['id'],
        'block_key' => $row['block_key'],
        'html' => $row['html_snapshot'],
        'text_color' => $row['text_color'],
        'bg_color' => $row['bg_color'],
        'changed_by' => $row['changed_by'],
        'changed_at' => $row['changed_at']
    ];
}
pg_free_result($result);

resp_history(true, 'OK', ['records' => $records, 'count' => count($records), 'block' => $blockKey]);
