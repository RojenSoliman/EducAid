<?php
/**
 * Secure image serving endpoint for student profile pictures.
 * Expects ?sid=STUDENT_ID . Validates session ownership (student) or future admin role.
 * Reads stored path from DB, decrypts if encrypted, sets proper headers, streams bytes.
 */
session_start();
if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo 'Unauthorized (no active session)';
    exit;
}

// Accept provided sid if in valid format; enforce ownership (only own picture for now)
$sessionId = (string)$_SESSION['student_id'];
$requestedId = isset($_GET['sid']) ? trim($_GET['sid']) : '';
// Allow alphanumeric, dash, underscore up to 40 chars
if ($requestedId === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $requestedId)) {
    $requestedId = $sessionId; // invalid format fallback
}
if ($requestedId !== $sessionId) {
    // In future: permit admin override; for now block cross-student access explicitly
    http_response_code(403);
    echo 'Forbidden (cannot access another student\'s image)';
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$res = pg_query_params($connection, 'SELECT student_picture FROM students WHERE student_id = $1', [$requestedId]);
$row = $res ? pg_fetch_assoc($res) : null;
if (!$row || empty($row['student_picture'])) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$relative = $row['student_picture'];
$path = realpath(__DIR__ . '/../../' . $relative);
if (!$path || !is_file($path)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// Optional debug mode: only for localhost and when ?debug=1 provided
if (isset($_GET['debug']) && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1') {
    $info = [
        'session_student_id' => $sessionId,
        'requested_sid_param' => isset($_GET['sid']) ? $_GET['sid'] : null,
        'effective_student_id' => $requestedId,
        'db_relative_path' => $relative,
        'filesystem_exists' => file_exists($path),
        'size_bytes' => @filesize($path),
        'enc_extension' => str_ends_with($path, '.enc'),
    ];
    $fh = fopen($path, 'rb');
    $first = fread($fh, 12);
    fclose($fh);
    if (substr($first,0,4) === 'MED1') {
        $info['magic'] = 'MED1';
        $info['version'] = ord($first[4]);
        if ($info['version'] === 2) {
            $info['key_id'] = ord($first[5]);
            $info['flags'] = ord($first[6]);
            $info['iv_len'] = ord($first[7]);
        }
    } else {
        $info['magic'] = 'PLAINTEXT_OR_UNKNOWN';
    }
    header('Content-Type: application/json');
    echo json_encode($info, JSON_PRETTY_PRINT);
    exit;
}

// Plaintext serving mode (encryption removed)
$data = file_get_contents($path);
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $path) ?: 'image/png';
finfo_close($finfo);

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . strlen($data));
echo $data;
