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
            // DO NOT start a new transaction here - caller should manage transactions
            // Removed: pg_query($this->conn, "BEGIN");
            
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
            
            // Get snapshot for this distribution
            $snapshotQuery = "SELECT snapshot_id FROM distribution_snapshots WHERE distribution_id = $1 LIMIT 1";
            $snapshotResult = pg_query_params($this->conn, $snapshotQuery, [$distributionId]);
            $snapshotId = null;
            
            if ($snapshotResult && pg_num_rows($snapshotResult) > 0) {
                $snapshotRow = pg_fetch_assoc($snapshotResult);
                $snapshotId = $snapshotRow['snapshot_id'];
            }
            
            if (!$snapshotId) {
                throw new Exception("No distribution snapshot found for distribution ID: $distributionId");
            }
            
            // CRITICAL FIX: Get students from distribution_student_records, not from current 'given' status
            // This allows compression to work even after students have been reset to 'applicant'
            $studentsQuery = "SELECT s.student_id, s.first_name, s.middle_name, s.last_name
                             FROM students s
                             INNER JOIN distribution_student_records dsr ON s.student_id = dsr.student_id
                             WHERE dsr.snapshot_id = $1
                             ORDER BY s.student_id";
            $studentsResult = pg_query_params($this->conn, $studentsQuery, [$snapshotId]);
            
            if (!$studentsResult || pg_num_rows($studentsResult) === 0) {
                throw new Exception("No students found in distribution snapshot $snapshotId");
            }
            
            $students = pg_fetch_all($studentsResult);
            
            error_log("=== Distribution Compression Started ===");
            error_log("Distribution ID: $distributionId");
            error_log("Snapshot ID: $snapshotId");
            error_log("Students in snapshot: " . count($students));
            foreach ($students as $student) {
                error_log("  - Student ID: " . $student['student_id'] . " | Name: " . $student['first_name'] . " " . $student['last_name']);
            }
            
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
                'student/letter_mayor' => 'letter_mayor' // Fixed: folder name is letter_mayor not letter_to_mayor
            ];
            
            $totalFilesFound = 0;
            $totalFilesMatched = 0;
            
            foreach ($folders as $folderPath => $folderType) {
                $fullPath = $uploadsPath . '/' . $folderPath;
                error_log("Scanning folder: $fullPath");
                
                if (is_dir($fullPath)) {
                    // Use scandir instead of glob to catch ALL files including those with multiple extensions
                    $allFiles = scandir($fullPath);
                    $files = [];
                    foreach ($allFiles as $file) {
                        if ($file !== '.' && $file !== '..' && is_file($fullPath . '/' . $file)) {
                            $files[] = $fullPath . '/' . $file;
                        }
                    }
                    error_log("  Found " . count($files) . " files in $folderType");
                    $totalFilesFound += count($files);
                    
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            $filename = basename($file);
                            $filenameLower = strtolower($filename);
                            $matched = false;
                            
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
                                    $matched = true;
                                    $totalFilesMatched++;
                                    error_log("  ✓ Matched: $filename -> Student $studentId");
                                    break; // Move to next file
                                }
                            }
                            
                            if (!$matched) {
                                error_log("  ✗ NO MATCH: $filename (file not linked to any student with 'given' status)");
                            }
                        }
                    }
                } else {
                    error_log("  Directory not found: $fullPath");
                }
            }
            
            error_log("=== File Scan Summary ===");
            error_log("Total files found: $totalFilesFound");
            error_log("Total files matched to students: $totalFilesMatched");
            error_log("Unmatched files: " . ($totalFilesFound - $totalFilesMatched));
            
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
                
                // Use just the student ID as the folder name
                $studentFolderName = $studentId;
                
                $studentOriginalSize = 0;
                
                // Add each file to ZIP under student's folder (no subfolders by type)
                foreach ($studentFilesList as $file) {
                    if (file_exists($file['path'])) {
                        // Create path inside ZIP: StudentID/filename
                        // All files go directly in the student's folder regardless of type
                        $zipEntryName = $studentFolderName . '/' . $file['name'];
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
                    "Student %s (%s %s): %d files, %.2f KB",
                    $studentId,
                    $studentInfo['first_name'],
                    $studentInfo['last_name'],
                    count($studentFilesList),
                    $studentOriginalSize / 1024
                );
            }
            
            // Close the ZIP file
            if (!$zip->close()) {
                throw new Exception("Failed to close ZIP archive - compression may have failed");
            }
            
            // CRITICAL: Verify ZIP file was created and has content before deleting originals
            if (!file_exists($zipPath)) {
                throw new Exception("ZIP file was not created at: $zipPath");
            }
            
            $totalCompressedSize = filesize($zipPath);
            
            if ($totalCompressedSize === 0) {
                throw new Exception("ZIP file is empty - aborting to preserve original files");
            }
            
            // Verify ZIP integrity
            $zipCheck = new ZipArchive();
            if ($zipCheck->open($zipPath, ZipArchive::CHECKCONS) !== TRUE) {
                throw new Exception("ZIP file integrity check failed - archive may be corrupted");
            }
            $zipCheck->close();
            
            // Only NOW is it safe to delete original files
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
            
            // DO NOT commit here - let the caller handle transaction management
            // Removed: pg_query($this->conn, "COMMIT");
            
            return [
                'success' => true,
                'message' => "Distribution compressed successfully. Student uploads have been archived and deleted.",
                'archive_path' => $zipPath,
                'size' => $totalCompressedSize,
                'file_count' => $filesCompressed,
                'compression_ratio' => round(($totalCompressedSize / $totalOriginalSize * 100), 2),
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
            
            // DO NOT rollback here - let the caller handle transaction management
            // Removed: pg_query($this->conn, "ROLLBACK");
            
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
