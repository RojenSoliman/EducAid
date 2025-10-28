<?php
/**
 * Get detailed information about a distribution archive
 * Returns JSON with student list, file manifest, and metadata
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_username'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$distribution_id = $_GET['id'] ?? '';

if (empty($distribution_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Distribution ID required']);
    exit;
}

try {
    // Get snapshot information
    $snapshot_query = "
        SELECT 
            snapshot_id,
            distribution_id,
            academic_year,
            semester,
            distribution_date,
            total_students_count,
            files_compressed,
            compression_date,
            original_total_size,
            compressed_size,
            compression_ratio,
            space_saved,
            total_files_count,
            archive_filename,
            location,
            notes,
            finalized_at,
            finalized_by
        FROM distribution_snapshots
        WHERE distribution_id = $1
    ";
    
    $snapshot_result = pg_query_params($connection, $snapshot_query, [$distribution_id]);
    
    if (!$snapshot_result || pg_num_rows($snapshot_result) === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Distribution not found']);
        exit;
    }
    
    $snapshot = pg_fetch_assoc($snapshot_result);
    
    // Get student snapshots
    $students_query = "
        SELECT 
            student_id,
            first_name,
            last_name,
            middle_name,
            email,
            mobile,
            year_level_name,
            university_name,
            barangay_name,
            payroll_number,
            amount_received,
            distribution_date
        FROM distribution_student_snapshot
        WHERE distribution_id = $1
        ORDER BY last_name, first_name
    ";
    
    $students_result = pg_query_params($connection, $students_query, [$distribution_id]);
    $students = [];
    
    if ($students_result) {
        while ($row = pg_fetch_assoc($students_result)) {
            $students[] = $row;
        }
    }
    
    // Get file manifest
    $files_query = "
        SELECT 
            student_id,
            document_type_code,
            original_file_path,
            file_size,
            file_hash,
            archived_path,
            created_at,
            deleted_at
        FROM distribution_file_manifest
        WHERE snapshot_id = $1
        ORDER BY student_id, document_type_code
    ";
    
    $files_result = pg_query_params($connection, $files_query, [$snapshot['snapshot_id']]);
    $files = [];
    
    if ($files_result) {
        while ($row = pg_fetch_assoc($files_result)) {
            $files[] = $row;
        }
    }
    
    // Get admin who finalized
    $admin_name = 'Unknown';
    if ($snapshot['finalized_by']) {
        $admin_query = "SELECT CONCAT(first_name, ' ', last_name) as name FROM admins WHERE admin_id = $1";
        $admin_result = pg_query_params($connection, $admin_query, [$snapshot['finalized_by']]);
        if ($admin_result && $admin_row = pg_fetch_assoc($admin_result)) {
            $admin_name = $admin_row['name'];
        }
    }
    
    // Get ZIP file info if it exists
    $zipFile = __DIR__ . '/../../assets/uploads/distributions/' . $distribution_id . '.zip';
    $zipInfo = null;
    
    if (file_exists($zipFile)) {
        $zipInfo = [
            'exists' => true,
            'size' => filesize($zipFile),
            'modified' => date('Y-m-d H:i:s', filemtime($zipFile)),
            'file_count' => 0
        ];
        
        // Try to count files in ZIP
        $zip = new ZipArchive();
        if ($zip->open($zipFile) === true) {
            $zipInfo['file_count'] = $zip->numFiles;
            $zip->close();
        }
    }
    
    // Build response
    $response = [
        'success' => true,
        'distribution' => [
            'id' => $snapshot['distribution_id'],
            'academic_year' => $snapshot['academic_year'],
            'semester' => $snapshot['semester'],
            'date' => $snapshot['distribution_date'],
            'location' => $snapshot['location'],
            'notes' => $snapshot['notes'],
            'finalized_at' => $snapshot['finalized_at'],
            'finalized_by' => $admin_name,
            'student_count' => $snapshot['total_students_count'],
            'file_count' => $snapshot['total_files_count']
        ],
        'compression' => [
            'compressed' => $snapshot['files_compressed'],
            'compression_date' => $snapshot['compression_date'],
            'original_size' => (int)$snapshot['original_total_size'],
            'compressed_size' => (int)$snapshot['compressed_size'],
            'compression_ratio' => (float)$snapshot['compression_ratio'],
            'space_saved' => (int)$snapshot['space_saved']
        ],
        'students' => $students,
        'files' => $files,
        'zip_file' => $zipInfo
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Error fetching distribution details: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
