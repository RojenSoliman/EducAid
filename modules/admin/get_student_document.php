<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$student_id = trim($_GET['student_id']); // Remove intval for TEXT student_id
$document_type = $_GET['type'];

// Valid document types
$valid_types = ['certificate_of_indigency', 'letter_to_mayor', 'eaf'];

if (!in_array($document_type, $valid_types)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid document type']);
    exit;
}

// Get document information
$query = "SELECT file_path FROM documents WHERE student_id = $1 AND type = $2 ORDER BY upload_date DESC LIMIT 1";
$result = pg_query_params($connection, $query, [$student_id, $document_type]);

if ($result && pg_num_rows($result) > 0) {
    $document = pg_fetch_assoc($result);
    
    // Generate a user-friendly filename based on document type
    $type_names = [
        'certificate_of_indigency' => 'Certificate of Indigency',
        'letter_to_mayor' => 'Letter to Mayor', 
        'eaf' => 'Enrollment Assessment Form'
    ];
    
    $filename = $type_names[$document_type] . ' - Student ' . $student_id;
    
    // Add appropriate extension based on file
    $path_info = pathinfo($document['file_path']);
    if (isset($path_info['extension'])) {
        $filename .= '.' . $path_info['extension'];
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'file_path' => $document['file_path'],
        'filename' => $filename
    ]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Document not found']);
}

pg_close($connection);
?>