<?php
include __DIR__ . '/../../config/database.php';
session_start();

// Function to convert absolute server paths to web-accessible relative paths
function convertToWebPath($absolutePath) {
    // Get the document root (EducAid folder)
    $docRoot = realpath(__DIR__ . '/../../');
    
    // Normalize path separators
    $absolutePath = str_replace('\\', '/', $absolutePath);
    $docRoot = str_replace('\\', '/', $docRoot);
    
    // If the path is already relative, just clean it up
    if (strpos($absolutePath, '../../') === 0) {
        return ltrim($absolutePath, './');
    }
    
    // If it's an absolute path, convert it to relative
    if (strpos($absolutePath, $docRoot) === 0) {
        $relativePath = substr($absolutePath, strlen($docRoot));
        return ltrim($relativePath, '/');
    }
    
    // If nothing else works, just return as-is
    return $absolutePath;
}

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

// Debug logging
error_log("Looking for document: student_id=$student_id, type=$document_type");

if ($result && pg_num_rows($result) > 0) {
    $document = pg_fetch_assoc($result);
    $file_path = $document['file_path'];
    
    // Debug logging
    error_log("Found document in DB: file_path=" . $file_path);
    
    // Check if the file exists at the stored path
    if (!file_exists($file_path)) {
        error_log("File does not exist at stored path: " . $file_path);
        
        // Try to find the file with the new naming convention
        $temp_dirs = [
            __DIR__ . '/../../assets/uploads/temp/enrollment_forms/',
            __DIR__ . '/../../assets/uploads/temp/letter_mayor/',
            __DIR__ . '/../../assets/uploads/temp/indigency/',
            __DIR__ . '/../../assets/uploads/student/enrollment_forms/',
            __DIR__ . '/../../assets/uploads/student/letter_to_mayor/',
            __DIR__ . '/../../assets/uploads/student/indigency/'
        ];
        
        $found_file = null;
        foreach ($temp_dirs as $dir) {
            if (is_dir($dir)) {
                // Look for files with student_id prefix and document type
                $pattern_mappings = [
                    'eaf' => ['*eaf*', '*EAF*', '*enrollment*'],
                    'letter_to_mayor' => ['*lettertomayor*', '*letter_to_mayor*', '*letter*mayor*'],
                    'certificate_of_indigency' => ['*indigency*', '*certificate*']
                ];
                
                if (isset($pattern_mappings[$document_type])) {
                    foreach ($pattern_mappings[$document_type] as $pattern) {
                        $search_pattern = $dir . $student_id . '_' . $pattern;
                        $files = glob($search_pattern);
                        if (!empty($files)) {
                            $found_file = $files[0]; // Take the first match
                            error_log("Found file using pattern search: " . $found_file);
                            break 2;
                        }
                    }
                }
            }
        }
        
        if ($found_file && file_exists($found_file)) {
            $file_path = $found_file;
            
            // Update the database with the correct path
            $update_query = "UPDATE documents SET file_path = $1 WHERE student_id = $2 AND type = $3";
            pg_query_params($connection, $update_query, [$file_path, $student_id, $document_type]);
            error_log("Updated database with correct file path: " . $file_path);
        } else {
            // Try alternative paths if the stored path doesn't work
            $alternative_paths = [
                // If path is relative, try absolute
                __DIR__ . '/../../' . ltrim($file_path, './'),
                // If it's in the temp folder, try with correct prefix
                str_replace('../../assets/uploads/temp/', __DIR__ . '/../../assets/uploads/temp/', $file_path),
                // Try the direct path from root
                str_replace('../../', __DIR__ . '/../../', $file_path)
            ];
            
            foreach ($alternative_paths as $alt_path) {
                if (file_exists($alt_path)) {
                    $file_path = $alt_path;
                    error_log("Found file at alternative path: " . $alt_path);
                    break;
                }
            }
        }
        
        // If still not found, log the issue
        if (!file_exists($file_path)) {
            error_log("File not found for student $student_id, type $document_type. Checked paths: " . implode(', ', array_merge([$document['file_path']], $alternative_paths)));
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'File not found on server']);
            exit;
        }
    }
    
    // Final debug logging
    error_log("Final file path: " . $file_path);
    error_log("Web path will be: " . convertToWebPath($file_path));
    
    // Generate a user-friendly filename based on document type
    $type_names = [
        'certificate_of_indigency' => 'Certificate of Indigency',
        'letter_to_mayor' => 'Letter to Mayor', 
        'eaf' => 'Enrollment Assessment Form'
    ];
    
    $filename = $type_names[$document_type] . ' - Student ' . $student_id;
    
    // Add appropriate extension based on file
    $path_info = pathinfo($file_path);
    if (isset($path_info['extension'])) {
        $filename .= '.' . $path_info['extension'];
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'file_path' => convertToWebPath($file_path),
        'filename' => $filename
    ]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Document not found']);
}

pg_close($connection);
?>