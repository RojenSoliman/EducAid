<?php
/**
 * File Management Service
 * Handles moving files between temp and permanent storage
 * Handles compression of archived student files
 */

class FileManagementService {
    private $conn;
    private $basePath;
    
    // Folder mappings
    private $folders = [
        'enrollment_forms' => 'enrollment_forms',
        'grades' => 'grades',
        'id_pictures' => 'id_pictures',
        'indigency' => 'indigency',
        'letter_mayor' => 'letter_to_mayor' // Note: temp uses letter_mayor, permanent uses letter_to_mayor
    ];
    
    public function __construct($connection = null) {
        global $connection;
        $this->conn = $connection ?? $connection;
        $this->basePath = __DIR__ . '/../assets/uploads';
    }
    
    /**
     * Move files from temp to permanent student storage
     * Called when admin approves a student application
     */
    public function moveTemporaryFilesToPermanent($studentId) {
        error_log("FileManagement: Moving temp files to permanent for student: $studentId");
        
        // Get student info for file naming
        $studentQuery = pg_query_params($this->conn,
            "SELECT first_name, last_name, middle_name FROM students WHERE student_id = $1",
            [$studentId]
        );
        
        if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
            error_log("FileManagement: Student not found: $studentId");
            return ['success' => false, 'message' => 'Student not found'];
        }
        
        $student = pg_fetch_assoc($studentQuery);
        $lastName = preg_replace('/[^a-zA-Z0-9]/', '', $student['last_name']);
        $firstName = preg_replace('/[^a-zA-Z0-9]/', '', $student['first_name']);
        
        $movedFiles = [];
        $errors = [];
        
        foreach ($this->folders as $tempFolder => $permanentFolder) {
            $tempPath = $this->basePath . '/temp/' . $tempFolder;
            $permanentPath = $this->basePath . '/student/' . $permanentFolder;
            
            if (!is_dir($tempPath)) {
                continue;
            }
            
            // Get all files in temp folder
            $files = glob($tempPath . '/*.*');
            
            foreach ($files as $file) {
                if (!is_file($file)) continue;
                
                // Skip OCR/verification files
                if (preg_match('/\.(verify\.json|ocr\.txt|confidence\.json)$/', $file)) {
                    continue;
                }
                
                $filename = basename($file);
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                
                // Determine document type for naming
                $docType = '';
                switch ($permanentFolder) {
                    case 'enrollment_forms':
                        $docType = 'EAF';
                        break;
                    case 'grades':
                        $docType = 'grades';
                        break;
                    case 'id_pictures':
                        $docType = 'id';
                        break;
                    case 'indigency':
                        $docType = 'indigency';
                        break;
                    case 'letter_to_mayor':
                        $docType = 'lettertomayor';
                        break;
                }
                
                // Create new filename: STUDENTID_Lastname_Firstname_TYPE.ext
                $newFilename = $studentId . '_' . $lastName . '_' . $firstName . '_' . $docType . '.' . $extension;
                $newPath = $permanentPath . '/' . $newFilename;
                
                // Move file
                if (rename($file, $newPath)) {
                    $movedFiles[] = $newFilename;
                    error_log("FileManagement: Moved $filename → $newFilename");
                    
                    // Also move associated files (.verify.json, .ocr.txt, etc)
                    $associatedFiles = glob($file . '.*');
                    foreach ($associatedFiles as $assocFile) {
                        $assocExt = substr($assocFile, strlen($file));
                        rename($assocFile, $newPath . $assocExt);
                    }
                } else {
                    $errors[] = "Failed to move: $filename";
                    error_log("FileManagement: Failed to move $filename");
                }
            }
        }
        
        // Clean up empty temp OCR folder
        $tempOcrPath = $this->basePath . '/temp/temp_ocr';
        if (is_dir($tempOcrPath)) {
            $ocrFiles = glob($tempOcrPath . '/*');
            foreach ($ocrFiles as $ocrFile) {
                @unlink($ocrFile);
            }
        }
        
        $result = [
            'success' => count($errors) === 0,
            'files_moved' => count($movedFiles),
            'files' => $movedFiles,
            'errors' => $errors
        ];
        
        error_log("FileManagement: Moved " . count($movedFiles) . " files for $studentId");
        
        return $result;
    }
    
    /**
     * Compress archived student's files into a ZIP
     * Called when student is archived
     */
    public function compressArchivedStudent($studentId) {
        error_log("FileManagement: Compressing files for archived student: $studentId");
        
        // Get student info
        $studentQuery = pg_query_params($this->conn,
            "SELECT first_name, last_name, middle_name FROM students WHERE student_id = $1",
            [$studentId]
        );
        
        if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
            return ['success' => false, 'message' => 'Student not found'];
        }
        
        $student = pg_fetch_assoc($studentQuery);
        $fullName = trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']);
        
        // Create ZIP file
        $archivePath = __DIR__ . '/../assets/uploads/archived_students';
        if (!is_dir($archivePath)) {
            mkdir($archivePath, 0755, true);
        }
        
        $zipFile = $archivePath . '/' . $studentId . '.zip';
        $zip = new ZipArchive();
        
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            return ['success' => false, 'message' => 'Failed to create ZIP file'];
        }
        
        $studentFolders = ['enrollment_forms', 'grades', 'id_pictures', 'indigency', 'letter_to_mayor'];
        $filesAdded = 0;
        $filesToDelete = [];
        $totalOriginalSize = 0;
        
        foreach ($studentFolders as $folder) {
            $folderPath = $this->basePath . '/student/' . $folder;
            
            if (!is_dir($folderPath)) {
                continue;
            }
            
            $files = glob($folderPath . '/*.*');
            
            foreach ($files as $file) {
                if (!is_file($file)) continue;
                
                $filename = basename($file);
                
                // Check if file belongs to this student
                if (strpos($filename, $studentId) === 0 || stripos($filename, strtolower($studentId)) === 0) {
                    // Add to ZIP in folder structure
                    $zipPath = $folder . '/' . $filename;
                    
                    if ($zip->addFile($file, $zipPath)) {
                        $filesAdded++;
                        $filesToDelete[] = $file;
                        $totalOriginalSize += filesize($file);
                        error_log("FileManagement: Added to ZIP: $zipPath");
                    }
                }
            }
        }
        
        $zip->close();
        
        if ($filesAdded === 0) {
            // No files found, remove empty ZIP
            @unlink($zipFile);
            error_log("FileManagement: No files found for $studentId");
            return [
                'success' => true,
                'files_archived' => 0,
                'message' => 'No files found to archive'
            ];
        }
        
        // Delete original files after successful ZIP creation
        $filesDeleted = 0;
        foreach ($filesToDelete as $file) {
            if (@unlink($file)) {
                $filesDeleted++;
                
                // Also delete associated files
                $associatedFiles = glob($file . '.*');
                foreach ($associatedFiles as $assocFile) {
                    @unlink($assocFile);
                }
            }
        }
        
        $compressedSize = filesize($zipFile);
        $spaceSaved = $totalOriginalSize - $compressedSize;
        $compressionRatio = $totalOriginalSize > 0 ? round(($spaceSaved / $totalOriginalSize) * 100, 1) : 0;
        
        error_log("FileManagement: Archived $filesAdded files for $studentId, saved " . ($spaceSaved / 1024 / 1024) . " MB");
        
        return [
            'success' => true,
            'files_archived' => $filesAdded,
            'files_deleted' => $filesDeleted,
            'original_size' => $totalOriginalSize,
            'compressed_size' => $compressedSize,
            'space_saved' => $spaceSaved,
            'compression_ratio' => $compressionRatio,
            'zip_file' => $zipFile
        ];
    }
    
    /**
     * Clean up old temporary files
     * Should be called periodically (cron job or manual trigger)
     */
    public function cleanupTemporaryFiles($olderThanDays = 7) {
        error_log("FileManagement: Cleaning up temp files older than $olderThanDays days");
        
        $cutoffTime = time() - ($olderThanDays * 24 * 60 * 60);
        $deletedCount = 0;
        $deletedSize = 0;
        
        $tempPath = $this->basePath . '/temp';
        $folders = ['enrollment_forms', 'grades', 'id_pictures', 'indigency', 'letter_mayor', 'temp_ocr'];
        
        foreach ($folders as $folder) {
            $folderPath = $tempPath . '/' . $folder;
            
            if (!is_dir($folderPath)) continue;
            
            $files = glob($folderPath . '/*');
            
            foreach ($files as $file) {
                if (!is_file($file)) continue;
                
                if (filemtime($file) < $cutoffTime) {
                    $size = filesize($file);
                    if (@unlink($file)) {
                        $deletedCount++;
                        $deletedSize += $size;
                    }
                }
            }
        }
        
        error_log("FileManagement: Deleted $deletedCount temp files, freed " . ($deletedSize / 1024 / 1024) . " MB");
        
        return [
            'success' => true,
            'files_deleted' => $deletedCount,
            'space_freed' => $deletedSize
        ];
    }
    
    /**
     * Get archived student ZIP file path
     */
    public function getArchivedStudentZip($studentId) {
        $zipFile = __DIR__ . '/../assets/uploads/archived_students/' . $studentId . '.zip';
        return file_exists($zipFile) ? $zipFile : null;
    }
    
    /**
     * Extract archived student files (for viewing or unarchiving)
     */
    public function extractArchivedStudent($studentId, $extractPath = null) {
        $zipFile = $this->getArchivedStudentZip($studentId);
        
        if (!$zipFile) {
            return ['success' => false, 'message' => 'Archive not found'];
        }
        
        if (!$extractPath) {
            $extractPath = $this->basePath . '/student';
        }
        
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== TRUE) {
            return ['success' => false, 'message' => 'Failed to open ZIP file'];
        }
        
        $extractedFiles = [];
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $fileInfo = pathinfo($filename);
            
            $targetDir = $extractPath . '/' . $fileInfo['dirname'];
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            
            $targetFile = $extractPath . '/' . $filename;
            
            if (copy("zip://$zipFile#$filename", $targetFile)) {
                $extractedFiles[] = $filename;
            }
        }
        
        $zip->close();
        
        return [
            'success' => true,
            'files_extracted' => count($extractedFiles),
            'files' => $extractedFiles
        ];
    }
}
