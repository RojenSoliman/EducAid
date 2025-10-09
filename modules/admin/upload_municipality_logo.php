<?php
/**
 * Municipality Logo Upload Handler
 * Handles custom logo uploads for municipalities
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';

// Security checks
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$adminRole = getCurrentAdminRole($connection);
if ($adminRole !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

// Validate request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF validation
$token = $_POST['csrf_token'] ?? '';
if (!CSRFProtection::validateToken('municipality-logo-upload', $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$municipalityId = (int) ($_POST['municipality_id'] ?? 0);
if (!$municipalityId) {
    echo json_encode(['success' => false, 'message' => 'Municipality ID is required']);
    exit;
}

// Verify municipality exists and admin has access
$checkQuery = "SELECT municipality_id, name FROM municipalities WHERE municipality_id = $1";
$checkResult = pg_query_params($connection, $checkQuery, [$municipalityId]);
if (!$checkResult || pg_num_rows($checkResult) === 0) {
    echo json_encode(['success' => false, 'message' => 'Municipality not found']);
    exit;
}
$municipality = pg_fetch_assoc($checkResult);
pg_free_result($checkResult);

// Validate file upload
if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$file = $_FILES['logo_file'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds maximum size allowed by server',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum size specified in form',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'Upload stopped by PHP extension'
    ];
    $message = $errorMessages[$file['error']] ?? 'Unknown upload error';
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Validate file type
$allowedMimeTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/svg+xml'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedMimeTypes, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only images (PNG, JPG, GIF, WebP, SVG) are allowed']);
    exit;
}

// Validate file size (max 5MB)
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit']);
    exit;
}

// Validate image dimensions and create proper image resource
$imageInfo = getimagesize($file['tmp_name']);
if (!$imageInfo && $mimeType !== 'image/svg+xml') {
    echo json_encode(['success' => false, 'message' => 'Invalid image file']);
    exit;
}

// Create upload directory if it doesn't exist
$uploadDir = __DIR__ . '/../../assets/uploads/municipality_logos';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
        exit;
    }
}

// Generate safe filename
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (empty($extension)) {
    $extensionMap = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg'
    ];
    $extension = $extensionMap[$mimeType] ?? 'png';
}

// Create safe filename: municipality_slug_timestamp.ext
$municipalitySlug = $municipality['slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($municipality['name']));
$timestamp = time();
$filename = sprintf('%s_%d.%s', $municipalitySlug, $timestamp, $extension);
$filepath = $uploadDir . DIRECTORY_SEPARATOR . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
    exit;
}

// Store relative path in database (from web root)
$dbPath = '/assets/uploads/municipality_logos/' . $filename;

// Update database
$updateQuery = "UPDATE municipalities 
                SET custom_logo_image = $1, 
                    use_custom_logo = TRUE,
                    updated_at = NOW()
                WHERE municipality_id = $2";

$updateResult = pg_query_params($connection, $updateQuery, [$dbPath, $municipalityId]);

if (!$updateResult) {
    // Delete uploaded file if database update fails
    @unlink($filepath);
    echo json_encode(['success' => false, 'message' => 'Failed to update database: ' . pg_last_error($connection)]);
    exit;
}

// Success response
echo json_encode([
    'success' => true,
    'message' => 'Logo uploaded successfully',
    'data' => [
        'municipality_id' => $municipalityId,
        'municipality_name' => $municipality['name'],
        'logo_path' => $dbPath,
        'filename' => $filename,
        'file_size' => $file['size'],
        'mime_type' => $mimeType
    ]
]);

// Log the upload
error_log(sprintf(
    'Municipality logo uploaded: Municipality #%d (%s), File: %s, Size: %d bytes, Admin: #%d',
    $municipalityId,
    $municipality['name'],
    $filename,
    $file['size'],
    $_SESSION['admin_id']
));
?>
