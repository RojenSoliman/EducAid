<?php
/* filepath: c:\xampp\htdocs\EducAid\modules\student\upload_handler.php */
include '../../config/database.php';
require '../../services/DocumentUploadService.php';

session_start();

if (!isset($_SESSION['student_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

$studentId = $_SESSION['student_id'];
$studentName = $_SESSION['student_username'];
$documentService = new DocumentUploadService($connection);

// Handle file uploads
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES['documents'])) {
    $uploadResults = [];
    $hasErrors = false;
    
    foreach ($_FILES['documents']['name'] as $index => $fileName) {
        if (empty($fileName)) continue;
        
        $file = [
            'name' => $_FILES['documents']['name'][$index],
            'tmp_name' => $_FILES['documents']['tmp_name'][$index],
            'size' => $_FILES['documents']['size'][$index],
            'error' => $_FILES['documents']['error'][$index]
        ];
        
        $type = $_POST['document_type'][$index] ?? '';
        
        $result = $documentService->uploadDocument($studentId, $studentName, $file, $type);
        $uploadResults[$type] = $result;
        
        if (!$result['success']) {
            $hasErrors = true;
        }
    }
    
    // Set session messages
    if ($hasErrors) {
        $_SESSION['upload_errors'] = $uploadResults;
    } else {
        $_SESSION['upload_success'] = 'Documents uploaded successfully!';
    }
    
    header("Location: upload_document.php");
    exit;
}

// Handle AJAX requests for file preview
if (isset($_GET['ajax']) && $_GET['ajax'] === 'preview' && isset($_GET['file'])) {
    $filePath = $_GET['file'];
    
    // Security check - ensure file belongs to current student
    $query = "SELECT file_path FROM documents WHERE student_id = $1 AND file_path = $2";
    $result = pg_query_params($connection, $query, [$studentId, $filePath]);
    
    if (pg_num_rows($result) > 0 && file_exists($filePath)) {
        $mimeType = mime_content_type($filePath);
        header("Content-Type: $mimeType");
        readfile($filePath);
    } else {
        http_response_code(404);
    }
    exit;
}
?>