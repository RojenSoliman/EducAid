<?php
require_once __DIR__ . '/../config/database.php';

class FileCompressionService {
    private $conn;
    private $fileArchiveSupportsDistribution = null;
    
    public function __construct() {
        global $connection;
        $this->conn = $connection;
    }
    
    public function compressDistribution($distributionId, $adminId) {
        try {
            pg_query($this->conn, "BEGIN");
            
            $distribution = null;
            $distQuery = "SELECT d.*
                         FROM distributions d
                         WHERE d.distribution_id = $1";
            $distResult = @pg_query_params($this->conn, $distQuery, [$distributionId]);
            
            if ($distResult && pg_num_rows($distResult) > 0) {
                $distribution = pg_fetch_assoc($distResult);
            } else {
                // Fallback for config-driven distributions: synthesize basic info
                $distribution = [
                    'distribution_id' => $distributionId,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }
            
            // Get all students with 'given' status (they received aid in this distribution)
            $studentsQuery = "SELECT s.student_id, s.first_name, s.middle_name, s.last_name
                             FROM students s
                             WHERE s.status = 'given'
                             ORDER BY s.student_id";
            $studentsResult = pg_query($this->conn, $studentsQuery);
            
            if (!$studentsResult || pg_num_rows($studentsResult) === 0) {
                throw new Exception("No students found with 'given' status");
            }
            
            $students = pg_fetch_all($studentsResult);
            
            // Prepare to scan actual files from shared upload folders
            $uploadsPath = __DIR__ . '/../assets/uploads';
            $studentFiles = [];
            
            // Initialize array for each student
            foreach ($students as $student) {
                $studentId = $student['student_id'];
                $studentFiles[$studentId] = [
                    'info' => $student,
                    'files' => []
                ];
            }
            
            // Scan shared folders for files belonging to our students
            // Include ALL document types: enrollment, grades, ID, indigency, letter
            $folders = [
                'student/enrollment_forms' => 'enrollment_forms',
                'student/grades' => 'grades',
                'student/id_pictures' => 'id_pictures',
                'student/indigency' => 'indigency',
                'student/letter_to_mayor' => 'letter_to_mayor'
            ];
            
            foreach ($folders as $folderPath => $folderType) {
                $fullPath = $uploadsPath . '/' . $folderPath;
                if (is_dir($fullPath)) {
                    $files = glob($fullPath . '/*.*');
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            $filename = basename($file);
                            $filenameLower = strtolower($filename);
                            // Check which student this file belongs to
                            foreach ($students as $student) {
                                $studentId = $student['student_id'];
                                $studentIdLower = strtolower($studentId);
                                // Files are named like: GENERALTRIAS-2025-3-9YW3ST_Soliman_Rojen_...
                                // Use case-insensitive matching
                                if (strpos($filenameLower, $studentIdLower) !== false) {
                                    $studentFiles[$studentId]['files'][] = [
                                        'path' => $file,
                                        'type' => $folderType,
                                        'size' => filesize($file),
                                        'name' => $filename
                                    ];
                                    break; // Move to next file
                                }
                            }
                        }
                    }
                }
            }
            
            // Filter out students with no files
            $studentFiles = array_filter($studentFiles, function($data) {
                return !empty($data['files']);
            });
            
            if (empty($studentFiles)) {
                throw new Exception("No files found to compress");
            }
            
            // Create distribution archive directory named after distribution ID
            $archiveBaseDir = __DIR__ . '/../assets/uploads/distributions/';
            if (!file_exists($archiveBaseDir)) {
                mkdir($archiveBaseDir, 0755, true);
            }
            
            // Create the main ZIP file named with distribution ID
            $zipFilename = $distributionId . '.zip';
            $zipPath = $archiveBaseDir . $zipFilename;
            
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception("Cannot create ZIP file: $zipPath");
            }
            
            $totalOriginalSize = 0;
            $totalCompressedSize = 0;
            $filesCompressed = 0;
            $studentsProcessed = 0;
            $compressionLog = [];
            $filesToDelete = [];
            
            // Add files for each student to the main ZIP
            foreach ($studentFiles as $studentId => $data) {
                $studentInfo = $data['info'];
                $studentFilesList = $data['files'];
                
                if (empty($studentFilesList)) {
                    continue; // Skip students with no files
                }
                
                $studentName = sprintf(
                    "%s_%s_%s",
                    $studentId,
                    preg_replace('/[^A-Za-z0-9]/', '_', $studentInfo['last_name']),
                    preg_replace('/[^A-Za-z0-9]/', '_', $studentInfo['first_name'])
                );
                
                $studentOriginalSize = 0;
                
                // Add each file to ZIP under student's folder
                foreach ($studentFilesList as $file) {
                    if (file_exists($file['path'])) {
                        // Create path inside ZIP: StudentName/folder_type/filename
                        $zipEntryName = $studentName . '/' . $file['type'] . '/' . $file['name'];
                        $zip->addFile($file['path'], $zipEntryName);
                        $studentOriginalSize += $file['size'];
                        $totalOriginalSize += $file['size'];
                        $filesCompressed++;
                        
                        // Mark file for deletion after successful compression
                        $filesToDelete[] = $file['path'];
                    }
                }
                
                $studentsProcessed++;
                $compressionLog[] = sprintf(
                    "Student %s: %d files, %.2f KB",
                    $studentName,
                    count($studentFilesList),
                    $studentOriginalSize / 1024
                );
            }
            
            $zip->close();
            
            $totalCompressedSize = filesize($zipPath);
            
            // Delete original files after successful compression
            $filesDeleted = 0;
            foreach ($filesToDelete as $filePath) {
                if (file_exists($filePath) && unlink($filePath)) {
                    $filesDeleted++;
                }
            }
            $compressionLog[] = "Deleted $filesDeleted original files from uploads";

            
            // Update distributions table
            @pg_query_params($this->conn,
                "UPDATE distributions 
                 SET files_compressed = true, compression_date = NOW()
                 WHERE distribution_id = $1",
                [$distributionId]);
            
            // Update distribution_snapshots if exists
            // Match by distribution_id or archive_filename
            @pg_query_params($this->conn,
                "UPDATE distribution_snapshots 
                 SET files_compressed = true, 
                     compression_date = NOW(),
                     archive_filename = $2
                 WHERE distribution_id = $1 OR archive_filename = $2",
                [$distributionId, $zipFilename]);
            
            // Log the operation
            $spaceSaved = $totalOriginalSize - $totalCompressedSize;
            $this->logOperation(
                'compress_distribution', $adminId, $distributionId, null,
                $filesCompressed, $totalOriginalSize, $totalCompressedSize, $spaceSaved,
                'success', null
            );
            
            pg_query($this->conn, "COMMIT");
            
            $spaceSaved = $totalOriginalSize - $totalCompressedSize;
            
            // Log the operation (optional, may not have file_archive_log table)
            try {
                $this->logOperation(
                    'compress_distribution', $adminId, $distributionId, null,
                    $filesCompressed, $totalOriginalSize, $totalCompressedSize, $spaceSaved,
                    'success', null
                );
            } catch (Exception $e) {
                error_log("Failed to log operation: " . $e->getMessage());
            }
            
            pg_query($this->conn, "COMMIT");
            
            return [
                'success' => true,
                'message' => "Distribution compressed successfully. Student uploads have been archived and deleted.",
                'statistics' => [
                    'students_processed' => $studentsProcessed,
                    'files_compressed' => $filesCompressed,
                    'original_size' => $totalOriginalSize,
                    'compressed_size' => $totalCompressedSize,
                    'space_saved' => $spaceSaved,
                    'compression_ratio' => round(($totalCompressedSize / $totalOriginalSize * 100), 2),
                    'archive_location' => 'assets/uploads/distributions/' . $zipFilename
                ],
                'log' => $compressionLog
            ];
            
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            
            try {
                $this->logOperation(
                    'compress_distribution', $adminId, $distributionId, null,
                    0, 0, 0, 0, 'failed', $e->getMessage()
                );
            } catch (Exception $e2) {
                error_log("Failed to log error: " . $e2->getMessage());
            }
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Recursively delete a directory and all its contents
     */
    private function deleteDirectory($dir) {
        if (!file_exists($dir)) {
            return true;
        }
        
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            if (!$this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        
        return rmdir($dir);
    }
    
    private function logOperation($operationType, $adminId, $distributionId, $studentId, 
                                  $fileCount, $originalSize, $compressedSize, $spaceSaved, 
                                  $status, $errorMessage) {
        try {
            $logQuery = "INSERT INTO file_archive_log 
                        (operation, performed_by, distribution_id, student_id, 
                         file_count, total_size_before, total_size_after, space_saved, 
                         operation_status, error_message)
                        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)";
            
            if (!$this->fileArchiveLogSupportsDistributionId()) {
                return; // Table not compatible, skip logging
            }

            $result = @pg_query_params($this->conn, $logQuery, [
                $operationType, $adminId, $distributionId, $studentId,
                $fileCount, $originalSize, $compressedSize, $spaceSaved,
                $status, $errorMessage
            ]);
            if (!$result) {
                throw new Exception(pg_last_error($this->conn) ?: 'Failed to log operation');
            }
        } catch (Exception $e) {
            error_log("Failed to log file operation: " . $e->getMessage());
        }
    }

    private function fileArchiveLogSupportsDistributionId() {
        if ($this->fileArchiveSupportsDistribution !== null) {
            return $this->fileArchiveSupportsDistribution;
        }
        $query = "SELECT 1 FROM information_schema.columns WHERE table_name = 'file_archive_log' AND column_name = 'distribution_id'";
        $result = @pg_query($this->conn, $query);
        if ($result && pg_num_rows($result) > 0) {
            $this->fileArchiveSupportsDistribution = true;
        } else {
            $this->fileArchiveSupportsDistribution = false;
        }
        return $this->fileArchiveSupportsDistribution;
    }
}
