<?php
/**
 * Secure Distribution Archive Download Handler
 * Prevents direct access to files and ensures proper MIME type
 */
session_start();
if (!isset($_SESSION['admin_username'])) {
    http_response_code(403);
    die('Access denied');
}

require_once __DIR__ . '/../../config/database.php';

// Get distribution ID from request
$distribution_id = $_GET['id'] ?? '';

if (empty($distribution_id)) {
    http_response_code(400);
    die('Distribution ID required');
}

// Sanitize the distribution ID (remove any directory traversal attempts)
$distribution_id = basename($distribution_id);

// Construct file path
$zipFile = __DIR__ . '/../../assets/uploads/distributions/' . $distribution_id . '.zip';

// Verify file exists
if (!file_exists($zipFile)) {
    http_response_code(404);
    die('Distribution archive not found');
}

// Verify it's actually a ZIP file
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $zipFile);
finfo_close($finfo);

if ($mimeType !== 'application/zip') {
    http_response_code(400);
    die('Invalid file type');
}

// Log the download
error_log("[Distribution Archive] Admin {$_SESSION['admin_username']} downloading: $distribution_id");

// Set headers for download
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $distribution_id . '.zip"');
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

// Clear output buffer
if (ob_get_level()) {
    ob_end_clean();
}

// Read and output file
readfile($zipFile);
exit;
