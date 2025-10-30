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
            error_log("FileCompressionService: Looking for snapshot with distribution_id = '$distributionId'");
            $snapshotQuery = "SELECT snapshot_id, files_compressed FROM distribution_snapshots WHERE distribution_id = $1 LIMIT 1";
            $snapshotResult = pg_query_params($this->conn, $snapshotQuery, [$distributionId]);
            $snapshotId = null;
            $alreadyCompressed = false;
            
            if (!$snapshotResult) {
                error_log("FileCompressionService ERROR: Query failed - " . pg_last_error($this->conn));
                throw new Exception("Database error while looking for distribution snapshot");
            }
            
            $rowCount = pg_num_rows($snapshotResult);
            error_log("FileCompressionService: Query returned $rowCount rows");
            
            if ($rowCount > 0) {
                $snapshotRow = pg_fetch_assoc($snapshotResult);
                $snapshotId = $snapshotRow['snapshot_id'];
                $alreadyCompressed = ($snapshotRow['files_compressed'] === 't' || $snapshotRow['files_compressed'] === true);
                error_log("FileCompressionService: Found snapshot_id = $snapshotId, compressed = " . ($alreadyCompressed ? 'YES' : 'NO'));
            }
            
            if (!$snapshotId) {
                error_log("FileCompressionService ERROR: No snapshot found for distribution_id = '$distributionId'");
                throw new Exception("No distribution snapshot found for distribution ID: $distributionId");
            }
            
            // Prevent re-compression
            if ($alreadyCompressed) {
                return [
                    'success' => false,
                    'message' => 'This distribution has already been compressed and archived. Files have been deleted.',
                    'already_compressed' => true
                ];
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
            // UPDATED: Now scans student-organized folders (student/{doc_type}/{student_id}/)
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
                    // NEW: Check if this folder has student subfolders (new structure) or flat files (old structure)
                    $items = scandir($fullPath);
                    $hasStudentFolders = false;
                    
                    // Detect if we have student folders
                    foreach ($items as $item) {
                        if ($item !== '.' && $item !== '..' && is_dir($fullPath . '/' . $item)) {
                            $hasStudentFolders = true;
                            break;
                        }
                    }
                    
                    $files = [];
                    
                    if ($hasStudentFolders) {
                        // NEW STRUCTURE: student/{doc_type}/{student_id}/files
                        error_log("  Using new student-organized structure");
                        foreach ($items as $item) {
                            if ($item !== '.' && $item !== '..') {
                                $studentFolder = $fullPath . '/' . $item;
                                if (is_dir($studentFolder)) {
                                    // Scan files in student's folder
                                    $studentFiles_scan = scandir($studentFolder);
                                    foreach ($studentFiles_scan as $file) {
                                        if ($file !== '.' && $file !== '..' && is_file($studentFolder . '/' . $file)) {
                                            // Skip associated files (.ocr.txt, .verify.json, etc.)
                                            if (!preg_match('/\.(ocr\.txt|verify\.json|confidence\.json|tsv)$/', $file)) {
                                                $files[] = $studentFolder . '/' . $file;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        // OLD STRUCTURE: flat files in student/{doc_type}/
                        error_log("  Using legacy flat structure");
                        foreach ($items as $file) {
                            if ($file !== '.' && $file !== '..' && is_file($fullPath . '/' . $file)) {
                                // Skip associated files
                                if (!preg_match('/\.(ocr\.txt|verify\.json|confidence\.json|tsv)$/', $file)) {
                                    $files[] = $fullPath . '/' . $file;
                                }
                            }
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
                                
                                // Match by student ID in filename OR by parent folder name
                                $parentFolder = basename(dirname($file));
                                
                                if (strpos($filenameLower, $studentIdLower) !== false || 
                                    strtolower($parentFolder) === $studentIdLower) {
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
                
                // Create folder name: "LastName, FirstName MiddleInitial - STUDENT-ID"
                // Example: "Dela Cruz, Juan C. - GENERALTRIAS-2025-3-000000"
                $lastName = trim($studentInfo['last_name'] ?? '');
                $firstName = trim($studentInfo['first_name'] ?? '');
                $middleName = trim($studentInfo['middle_name'] ?? '');
                
                // Get middle initial (first character if exists)
                $middleInitial = !empty($middleName) ? strtoupper(substr($middleName, 0, 1)) . '.' : '';
                
                // Construct full name: "LastName, FirstName MiddleInitial"
                $fullName = $lastName;
                if (!empty($firstName)) {
                    $fullName .= ', ' . $firstName;
                    if (!empty($middleInitial)) {
                        $fullName .= ' ' . $middleInitial;
                    }
                }
                
                // Sanitize folder name (remove invalid characters for file systems)
                $fullName = preg_replace('/[<>:"\/\\|?*]/', '', $fullName);
                
                // Final folder name format: "LastName, FirstName M. - STUDENT-ID"
                $studentFolderName = $fullName . ' - ' . $studentId;
                
                $studentOriginalSize = 0;
                
                // Add each file to ZIP under student's named folder
                foreach ($studentFilesList as $file) {
                    if (file_exists($file['path'])) {
                        // Create path inside ZIP: "LastName, FirstName M. - STUDENT-ID/filename"
                        // All files go directly in the student's folder regardless of type
                        $zipEntryName = $studentFolderName . '/' . $file['name'];
                        $zip->addFile($file['path'], $zipEntryName);
                        $studentOriginalSize += $file['size'];
                        $totalOriginalSize += $file['size'];
                        $filesCompressed++;
                        
                        // Mark file for deletion after successful compression
                        $filesToDelete[] = [
                            'path' => $file['path'],
                            'student_id' => $studentId,
                            'type' => $file['type'],
                            'name' => $file['name'],
                            'size' => $file['size'],
                            'archived_path' => $zipEntryName
                        ];
                    }
                }
                
                $studentsProcessed++;
                $compressionLog[] = sprintf(
                    "Student %s (%s %s %s): %d files, %.2f KB → Folder: %s",
                    $studentId,
                    $studentInfo['first_name'],
                    $middleName ? substr($middleName, 0, 1) . '.' : '',
                    $studentInfo['last_name'],
                    count($studentFilesList),
                    $studentOriginalSize / 1024,
                    $studentFolderName
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
            
            // OPTION 2 IMPLEMENTATION: Insert file manifest records BEFORE deleting files
            error_log("=== Populating distribution_file_manifest ===");
            $manifestInserted = 0;
            
            foreach ($filesToDelete as $fileData) {
                // Calculate file hash for verification
                $fileHash = file_exists($fileData['path']) ? md5_file($fileData['path']) : null;
                
                $manifestInsert = @pg_query_params($this->conn,
                    "INSERT INTO distribution_file_manifest 
                     (snapshot_id, student_id, document_type_code, original_file_path, 
                      file_size, file_hash, archived_path)
                     VALUES ($1, $2, $3, $4, $5, $6, $7)",
                    [
                        $snapshotId,
                        $fileData['student_id'],
                        $fileData['type'],
                        $fileData['path'],
                        $fileData['size'],
                        $fileHash,
                        $fileData['archived_path']
                    ]
                );
                
                if ($manifestInsert) {
                    $manifestInserted++;
                } else {
                    error_log("Warning: Failed to insert manifest for file: " . $fileData['path']);
                }
            }
            
            error_log("Inserted $manifestInserted file manifest record(s)");
            $compressionLog[] = "Recorded $manifestInserted files in distribution_file_manifest";
            
            // Only NOW is it safe to delete original files AND their associated files
            $filesDeleted = 0;
            $associatedFilesDeleted = 0;
            foreach ($filesToDelete as $fileData) {
                $filePath = $fileData['path'];
                
                // Delete the main file
                if (file_exists($filePath) && unlink($filePath)) {
                    $filesDeleted++;
                    
                    // OPTION 2: Update manifest to mark file as deleted
                    @pg_query_params($this->conn,
                        "UPDATE distribution_file_manifest 
                         SET deleted_at = NOW()
                         WHERE snapshot_id = $1 
                         AND student_id = $2 
                         AND original_file_path = $3",
                        [$snapshotId, $fileData['student_id'], $fileData['path']]
                    );
                    
                    // Delete associated files (.ocr.txt, .verify.json, .confidence.json, .tsv, .ocr.json)
                    // For files like: file.jpg -> file.jpg.verify.json (NOT file.verify.json)
                    $pathInfo = pathinfo($filePath);
                    $fileDir = $pathInfo['dirname'];
                    $fileBasename = $pathInfo['basename']; // Includes extension
                    $fileWithoutExt = $pathInfo['filename']; // Without extension
                    
                    $associatedExtensions = ['.ocr.txt', '.verify.json', '.confidence.json', '.tsv', '.ocr.json'];
                    
                    foreach ($associatedExtensions as $ext) {
                        // Try both patterns:
                        // 1. file.jpg.verify.json (new style - preferred)
                        // 2. file.verify.json (old style - fallback)
                        $associatedFile1 = $fileDir . '/' . $fileBasename . $ext; // With extension
                        $associatedFile2 = $fileDir . '/' . $fileWithoutExt . $ext; // Without extension
                        
                        $deleted = false;
                        
                        // Try new style first (file.jpg.verify.json)
                        if (file_exists($associatedFile1)) {
                            if (unlink($associatedFile1)) {
                                $associatedFilesDeleted++;
                                $deleted = true;
                                error_log("  Deleted associated file: " . basename($associatedFile1));
                            } else {
                                error_log("  WARNING: Failed to delete associated file: " . basename($associatedFile1));
                            }
                        }
                        // Try old style if new style wasn't found (file.verify.json)
                        elseif (file_exists($associatedFile2)) {
                            if (unlink($associatedFile2)) {
                                $associatedFilesDeleted++;
                                $deleted = true;
                                error_log("  Deleted associated file: " . basename($associatedFile2));
                            } else {
                                error_log("  WARNING: Failed to delete associated file: " . basename($associatedFile2));
                            }
                        }
                        
                        if (!$deleted) {
                            error_log("  Associated file not found (tried both patterns): " . $fileBasename . $ext);
                        }
                    }
                } else {
                    error_log("  WARNING: Failed to delete main file: " . basename($filePath));
                }
            }
            $compressionLog[] = "Deleted $filesDeleted original files from uploads";
            $compressionLog[] = "Deleted $associatedFilesDeleted associated files (OCR/JSON data)";
            error_log("Deleted $filesDeleted main files and $associatedFilesDeleted associated files");

            
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
