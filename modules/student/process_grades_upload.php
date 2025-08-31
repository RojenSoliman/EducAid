<?php
session_start();
include '../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_id']) || !isset($_FILES['grades_file'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_username'];
$file = $_FILES['grades_file'];

try {
    // Validate file
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only PDF and images are allowed.');
    }
    
    if ($file['size'] > 10 * 1024 * 1024) { // 10MB limit
        throw new Exception('File size exceeds 10MB limit.');
    }
    
    // Create upload directory
    $uploadDir = "../../assets/uploads/students/{$student_name}/grades/";
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique filename
    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = 'grades_' . time() . '_' . uniqid() . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Failed to upload file.');
    }
    
    // Determine file type
    $fileType = (strtolower($fileExtension) === 'pdf') ? 'pdf' : 'image';
    
    // Insert into database
    $query = "INSERT INTO grade_uploads (student_id, file_path, file_type) VALUES ($1, $2, $3) RETURNING upload_id";
    $result = pg_query_params($connection, $query, [$student_id, $filePath, $fileType]);
    
    if (!$result) {
        throw new Exception('Database error: ' . pg_last_error($connection));
    }
    
    $row = pg_fetch_assoc($result);
    $uploadId = $row['upload_id'];
    
    echo json_encode([
        'success' => true,
        'upload_id' => $uploadId,
        'file_path' => $filePath,
        'message' => 'File uploaded successfully'
    ]);
    
} catch (Exception $e) {
    // Clean up file if it was uploaded
    if (isset($filePath) && file_exists($filePath)) {
        unlink($filePath);
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
