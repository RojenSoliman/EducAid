<?php
/**
 * UnifiedFileService - Comprehensive File Management
 * 
 * Combines functionality from:
 * - DocumentService (upload, temp to permanent, OCR handling)
 * - FileManagementService (approval workflow, archiving)
 * - FileCompressionService (distribution compression)
 * 
 * Features:
 * - Single source of truth for all file operations
 * - Student-specific subfolders (consistent organization)
 * - Transaction support (data integrity)
 * - Comprehensive audit logging
 * - Tracks approved_by for accountability
 * - Handles OCR/verification files
 * - ZIP compression for archives and distributions
 * - Consistent error handling
 */

class UnifiedFileService {
    private $db;
    private $baseDir;
    private $basePath;
    
    // Document type mapping
    const DOCUMENT_TYPES = [
        'eaf' => ['code' => '00', 'name' => 'eaf', 'folder' => 'enrollment_forms'],
        'academic_grades' => ['code' => '01', 'name' => 'academic_grades', 'folder' => 'grades'],
        'letter_to_mayor' => ['code' => '02', 'name' => 'letter_to_mayor', 'folder' => 'letter_mayor'],
        'certificate_of_indigency' => ['code' => '03', 'name' => 'certificate_of_indigency', 'folder' => 'indigency'],
        'id_picture' => ['code' => '04', 'name' => 'id_picture', 'folder' => 'id_pictures']
    ];
    
    // Folder mappings for legacy compatibility
    private $folders = [
        'enrollment_forms' => 'enrollment_forms',
        'grades' => 'grades',
        'id_pictures' => 'id_pictures',
        'indigency' => 'indigency',
        'letter_mayor' => 'letter_mayor'
    ];
    
    public function __construct($dbConnection) {
        $this->db = $dbConnection;
        $this->baseDir = __DIR__ . '/../assets/uploads/';
        $this->basePath = __DIR__ . '/../assets/uploads';
    }
    
    // ============================================================================
    // CORE FILE OPERATIONS
    // ============================================================================
    
    /**
     * Move documents from temp to permanent storage after approval
     * Supports both registration approval (under_registration → applicant)
     * and applicant approval (applicant → active)
     * 
     * @param string $studentId Student ID
     * @param int|null $adminId Admin who approved (optional)
     * @param array $options Additional options (naming scheme, etc.)
     * @return array ['success' => bool, 'moved_count' => int, 'errors' => array]
     */
    public function moveToPermStorage($studentId, $adminId = null, $options = []) {
        try {
            pg_query($this->db, "BEGIN");
            error_log("UnifiedFileService::moveToPermStorage - START for student: $studentId");
            
            // Get admin ID from session if not provided
            if ($adminId === null && isset($_SESSION['admin_id'])) {
                $adminId = $_SESSION['admin_id'];
            }
            error_log("UnifiedFileService::moveToPermStorage - Admin ID: " . ($adminId ?? 'NULL'));
            
            // Get all temp documents for this student
            $query = "SELECT * FROM documents WHERE student_id = $1 AND status = 'temp'";
            $result = pg_query_params($this->db, $query, [$studentId]);
            
            if (!$result) {
                throw new Exception('Failed to fetch documents: ' . pg_last_error($this->db));
            }
            
            $docCount = pg_num_rows($result);
            error_log("UnifiedFileService::moveToPermStorage - Found $docCount temp documents");
            
            $movedCount = 0;
            $errors = [];
            
            while ($doc = pg_fetch_assoc($result)) {
                $oldPath = $doc['file_path'];
                error_log("UnifiedFileService::moveToPermStorage - Processing doc type: " . $doc['document_type_code'] . ", Original path: $oldPath");
                
                // Handle both absolute and relative paths
                if (!file_exists($oldPath)) {
                    // Try prepending base directory (EducAid root)
                    $oldPath = dirname(dirname(__FILE__)) . '/' . $oldPath;
                    error_log("UnifiedFileService::moveToPermStorage - Adjusted path: $oldPath, Exists: " . (file_exists($oldPath) ? 'YES' : 'NO'));
                    
                    if (!file_exists($oldPath)) {
                        $errors[] = "File not found: {$doc['file_path']}";
                        continue;
                    }
                }
                
                // Build student-organized path
                // Pattern: assets/uploads/temp/{doc_type}/filename → assets/uploads/student/{doc_type}/{student_id}/filename
                // Handles both /temp/ and temp/ (with or without leading slash)
                
                // Initialize variables for associated file handling
                $originalFilename = null;
                $baseName = null;
                $timestamp = date('YmdHis');
                
                if (preg_match('#/?temp/([^/]+)/([^/]+)$#', $oldPath, $matches)) {
                    $docTypeFolder = $matches[1];
                    $originalFilename = $matches[2];
                    
                    // Generate timestamped filename to prevent overwrites
                    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                    $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);
                    $newFilename = $baseName . '_' . $timestamp . '.' . $extension;
                    
                    // Build absolute path to student folder (within EducAid/assets/uploads directory)
                    // __FILE__ = C:\xampp\htdocs\EducAid\services\UnifiedFileService.php
                    // dirname(__FILE__) = C:\xampp\htdocs\EducAid\services
                    // dirname(dirname(__FILE__)) = C:\xampp\htdocs\EducAid
                    // CORRECT STRUCTURE: assets/uploads/student/{doc_type}/{student_id}/filename.ext
                    $baseRoot = dirname(dirname(__FILE__));
                    $targetDir = $baseRoot . '/assets/uploads/student/' . $docTypeFolder . '/' . $studentId . '/';
                    $newPath = $targetDir . $newFilename;
                    
                    error_log("UnifiedFileService::moveToPermStorage - Moving to student folder: {$newPath}");
                } else {
                    // Fallback to simple replacement if pattern doesn't match
                    $newPath = str_replace('/temp/', '/student/', $oldPath);
                    $newPath = str_replace('temp/', 'student/', $newPath); // Handle both
                    $targetDir = dirname($newPath);
                    
                    // Extract filename info for associated files even in fallback
                    $originalFilename = basename($oldPath);
                    $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);
                    
                    error_log("UnifiedFileService::moveToPermStorage - Using fallback path: {$newPath}");
                }
                
                // Ensure target directory exists
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                    error_log("UnifiedFileService::moveToPermStorage - Created directory: {$targetDir}");
                }
                
                // Move associated OCR files BEFORE moving the main file
                // This ensures we can find them using the original $oldPath
                // Pass ORIGINAL FILENAME (with extension) because associated files use: originalfile.ext.ocr.txt pattern
                if (isset($originalFilename) && isset($baseName) && isset($timestamp) && isset($targetDir)) {
                    $this->moveAssociatedFilesWithTimestamp($oldPath, $targetDir, $originalFilename, $baseName, $timestamp);
                } else {
                    $this->moveAssociatedFiles($oldPath, $newPath);
                }
                
                // Move main file AFTER moving associated files
                if (!rename($oldPath, $newPath)) {
                    $errors[] = "Failed to move: {$oldPath}";
                    continue;
                }
                
                // Convert new path to web-accessible relative path for database storage
                $webPath = $this->convertToWebPath($newPath);
                
                // Update database with approved_by tracking
                $updateQuery = "UPDATE documents 
                               SET file_path = $1, 
                                   status = 'approved',
                                   approved_by = $2,
                                   approved_date = NOW(),
                                   last_modified = NOW()
                               WHERE document_id = $3";
                
                $updateResult = pg_query_params($this->db, $updateQuery, [
                    $webPath,
                    $adminId,
                    $doc['document_id']
                ]);
                
                if (!$updateResult) {
                    $errors[] = "Database update failed for {$doc['document_id']}";
                    error_log("UnifiedFileService::moveToPermStorage - DB UPDATE FAILED for doc: " . $doc['document_id']);
                    continue;
                }
                
                error_log("UnifiedFileService::moveToPermStorage - Successfully moved and updated DB for doc: " . $doc['document_id']);
                $movedCount++;
            }
            
            error_log("UnifiedFileService::moveToPermStorage - COMPLETE - Moved: $movedCount, Errors: " . count($errors));
            pg_query($this->db, "COMMIT");
            
            // Log approval to audit trail
            if ($movedCount > 0 && isset($_SESSION['admin_id'])) {
                $this->logAudit(
                    $_SESSION['admin_id'],
                    'admin',
                    $_SESSION['admin_username'] ?? 'admin',
                    'applicant_approved',
                    'applicant_management',
                    "Student documents approved and moved to permanent storage",
                    [
                        'student_id' => $studentId,
                        'documents_moved' => $movedCount,
                        'errors' => count($errors),
                        'approved_by' => $adminId
                    ]
                );
            }
            
            return [
                'success' => true,
                'moved_count' => $movedCount,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            pg_query($this->db, "ROLLBACK");
            error_log("UnifiedFileService::moveToPermStorage - Error: " . $e->getMessage());
            return [
                'success' => false,
                'moved_count' => 0,
                'errors' => [$e->getMessage()]
            ];
        }
    }
    
    /**
     * Compress archived student's files into a ZIP
     * Called when student is archived
     * Handles both temp folders (for rejected applicants) and permanent folders (for archived students)
     * 
     * @param string $studentId Student ID
     * @return array ['success' => bool, 'zip_file' => string, 'files_added' => int, ...]
     */
    public function compressArchivedStudent($studentId) {
        error_log("UnifiedFileService: Compressing files for archived student: $studentId");
        
        try {
            // Get student info
            $studentQuery = pg_query_params($this->db,
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
            
            $filesAdded = 0;
            $filesToDelete = [];
            $totalOriginalSize = 0;
            
            // Define folders to check - both temp and permanent
            $folderPairs = [
                ['temp' => 'enrollment_forms', 'permanent' => 'enrollment_forms'],
                ['temp' => 'grades', 'permanent' => 'grades'],
                ['temp' => 'id_pictures', 'permanent' => 'id_pictures'],
                ['temp' => 'indigency', 'permanent' => 'indigency'],
                ['temp' => 'letter_mayor', 'permanent' => 'letter_mayor']
            ];
            
            // Check temp folders first (for rejected applicants who never got approved)
            foreach ($folderPairs as $folderPair) {
                $tempPath = $this->basePath . '/temp/' . $folderPair['temp'];
                
                if (!is_dir($tempPath)) continue;
                
                $files = glob($tempPath . '/' . $studentId . '_*');
                foreach ($files as $file) {
                    if (!is_file($file)) continue;
                    
                    $filename = basename($file);
                    $zip->addFile($file, $folderPair['temp'] . '/' . $filename);
                    $filesAdded++;
                    $totalOriginalSize += filesize($file);
                    $filesToDelete[] = $file;
                    
                    error_log("UnifiedFileService: Added temp file: {$filename}");
                }
            }
            
            // Check permanent student-specific folders (2 levels up from services/)
            $projectRoot = dirname(dirname(__FILE__));
            foreach ($folderPairs as $folderPair) {
                $permanentPath = $projectRoot . '/student/' . $folderPair['permanent'] . '/' . $studentId;
                
                if (!is_dir($permanentPath)) {
                    error_log("UnifiedFileService: Checking permanent path (not found): {$permanentPath}");
                    continue;
                }
                
                error_log("UnifiedFileService: Found permanent directory: {$permanentPath}");
                $files = glob($permanentPath . '/*');
                foreach ($files as $file) {
                    if (!is_file($file)) continue;
                    
                    $filename = basename($file);
                    $zip->addFile($file, $folderPair['permanent'] . '/' . $filename);
                    $filesAdded++;
                    $totalOriginalSize += filesize($file);
                    $filesToDelete[] = $file;
                    
                    error_log("UnifiedFileService: Added permanent file: {$filename}");
                }
            }
            
            $zip->close();
            
            if ($filesAdded === 0) {
                if (file_exists($zipFile)) {
                    unlink($zipFile);
                }
                error_log("UnifiedFileService: No files found for student $studentId");
                return [
                    'success' => false,
                    'message' => 'No files found for this student'
                ];
            }
            
            // Delete original files after successful ZIP creation
            $filesDeleted = 0;
            foreach ($filesToDelete as $file) {
                if (unlink($file)) {
                    $filesDeleted++;
                    
                    // Try to remove empty parent directory
                    $parentDir = dirname($file);
                    if (is_dir($parentDir) && count(scandir($parentDir)) === 2) { // Only . and ..
                        @rmdir($parentDir);
                    }
                }
            }
            
            $compressedSize = filesize($zipFile);
            $spaceSaved = $totalOriginalSize - $compressedSize;
            $compressionRatio = $totalOriginalSize > 0 ? round(($spaceSaved / $totalOriginalSize) * 100, 1) : 0;
            
            error_log("UnifiedFileService: Archived $filesAdded files for $studentId, saved " . ($spaceSaved / 1024 / 1024) . " MB");
            
            return [
                'success' => true,
                'zip_file' => $zipFile,
                'files_added' => $filesAdded,
                'files_deleted' => $filesDeleted,
                'original_size' => $totalOriginalSize,
                'compressed_size' => $compressedSize,
                'space_saved' => $spaceSaved,
                'compression_ratio' => $compressionRatio
            ];
            
        } catch (Exception $e) {
            error_log("UnifiedFileService::compressArchivedStudent - Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error during compression: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Compress blacklisted student's files into a ZIP
     * Similar to archiving, but stores in separate blacklisted_students folder
     * Called when student is blacklisted
     * 
     * @param string $studentId Student ID
     * @param string $reasonCategory Blacklist reason category
     * @param string $detailedReason Detailed blacklist reason
     * @return array ['success' => bool, 'zip_file' => string, 'files_added' => int, ...]
     */
    public function compressBlacklistedStudent($studentId, $reasonCategory = '', $detailedReason = '') {
        error_log("UnifiedFileService: Compressing files for blacklisted student: $studentId");
        
        try {
            // Get student info
            $studentQuery = pg_query_params($this->db,
                "SELECT first_name, last_name, middle_name FROM students WHERE student_id = $1",
                [$studentId]
            );
            
            if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
                return ['success' => false, 'message' => 'Student not found'];
            }
            
            $student = pg_fetch_assoc($studentQuery);
            $fullName = trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']);
            
            // Create ZIP file in blacklisted_students folder
            $blacklistPath = __DIR__ . '/../assets/uploads/blacklisted_students';
            if (!is_dir($blacklistPath)) {
                mkdir($blacklistPath, 0755, true);
                error_log("UnifiedFileService: Created blacklisted_students directory");
            }
            
            $zipFile = $blacklistPath . '/' . $studentId . '.zip';
            $zip = new ZipArchive();
            
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                return ['success' => false, 'message' => 'Failed to create ZIP file'];
            }
            
            // Add metadata file with blacklist info
            $metadata = [
                'student_id' => $studentId,
                'student_name' => $fullName,
                'blacklisted_date' => date('Y-m-d H:i:s'),
                'reason_category' => $reasonCategory,
                'detailed_reason' => $detailedReason
            ];
            $zip->addFromString('BLACKLIST_INFO.txt', 
                "BLACKLISTED STUDENT RECORD\n" .
                "==========================\n" .
                "Student ID: {$studentId}\n" .
                "Name: {$fullName}\n" .
                "Blacklisted: " . date('Y-m-d H:i:s') . "\n" .
                "Category: {$reasonCategory}\n" .
                "Reason: {$detailedReason}\n"
            );
            
            $filesAdded = 1; // Count metadata file
            $filesToDelete = [];
            $totalOriginalSize = 0;
            
            // Define folders to check - both temp and permanent
            $folderPairs = [
                ['temp' => 'enrollment_forms', 'permanent' => 'enrollment_forms'],
                ['temp' => 'grades', 'permanent' => 'grades'],
                ['temp' => 'id_pictures', 'permanent' => 'id_pictures'],
                ['temp' => 'indigency', 'permanent' => 'indigency'],
                ['temp' => 'letter_mayor', 'permanent' => 'letter_mayor']
            ];
            
            //Check temp folders first (for students blacklisted during registration)
            // Check both assets/uploads/temp/ AND project root temp/
            $projectRoot = dirname(dirname(__FILE__));
            $tempLocations = [
                $this->basePath . '/temp',  // assets/uploads/temp/
                $projectRoot . '/temp'      // project root temp/
            ];
            
            foreach ($tempLocations as $tempBaseDir) {
                foreach ($folderPairs as $folderPair) {
                    $tempPath = $tempBaseDir . '/' . $folderPair['temp'];
                    
                    error_log("UnifiedFileService: Checking temp path: {$tempPath}");
                    
                    if (!is_dir($tempPath)) {
                        error_log("UnifiedFileService: Temp directory not found: {$tempPath}");
                        continue;
                    }
                    
                    $pattern = $tempPath . '/' . $studentId . '_*';
                    error_log("UnifiedFileService: Searching pattern: {$pattern}");
                    $files = glob($pattern);
                    error_log("UnifiedFileService: Found " . count($files) . " files in {$tempPath}");
                    
                    foreach ($files as $file) {
                        if (!is_file($file)) continue;
                        
                        $filename = basename($file);
                        $zip->addFile($file, 'temp/' . $folderPair['temp'] . '/' . $filename);
                        $filesAdded++;
                        $totalOriginalSize += filesize($file);
                        $filesToDelete[] = $file;
                        
                        error_log("UnifiedFileService: Added temp file to blacklist archive: {$filename}");
                    }
                }
            }
            
            // Check permanent student-specific folders (for students blacklisted after approval)
            $projectRoot = dirname(dirname(__FILE__));
            foreach ($folderPairs as $folderPair) {
                $permanentPath = $projectRoot . '/student/' . $folderPair['permanent'] . '/' . $studentId;
                
                if (!is_dir($permanentPath)) continue;
                
                error_log("UnifiedFileService: Found permanent directory for blacklisted student: {$permanentPath}");
                $files = glob($permanentPath . '/*');
                foreach ($files as $file) {
                    if (!is_file($file)) continue;
                    
                    $filename = basename($file);
                    $zip->addFile($file, 'permanent/' . $folderPair['permanent'] . '/' . $filename);
                    $filesAdded++;
                    $totalOriginalSize += filesize($file);
                    $filesToDelete[] = $file;
                    
                    error_log("UnifiedFileService: Added permanent file to blacklist archive: {$filename}");
                }
            }
            
            $zip->close();
            
            if ($filesAdded <= 1) { // Only metadata, no actual files
                if (file_exists($zipFile)) {
                    unlink($zipFile);
                }
                error_log("UnifiedFileService: No student files found for blacklisted student $studentId");
                return [
                    'success' => false,
                    'message' => 'No student files found to archive'
                ];
            }
            
            // Delete original files after successful ZIP creation
            $filesDeleted = 0;
            foreach ($filesToDelete as $file) {
                if (unlink($file)) {
                    $filesDeleted++;
                    
                    // Try to remove empty parent directory
                    $parentDir = dirname($file);
                    if (is_dir($parentDir) && count(scandir($parentDir)) === 2) { // Only . and ..
                        @rmdir($parentDir);
                    }
                }
            }
            
            $compressedSize = filesize($zipFile);
            $spaceSaved = $totalOriginalSize - $compressedSize;
            $compressionRatio = $totalOriginalSize > 0 ? round(($spaceSaved / $totalOriginalSize) * 100, 1) : 0;
            
            error_log("UnifiedFileService: Blacklist archive created - $filesAdded files, saved " . round($spaceSaved / 1024, 2) . " KB");
            
            return [
                'success' => true,
                'zip_file' => $zipFile,
                'files_added' => $filesAdded,
                'files_deleted' => $filesDeleted,
                'original_size' => $totalOriginalSize,
                'compressed_size' => $compressedSize,
                'space_saved' => $spaceSaved,
                'compression_ratio' => $compressionRatio
            ];
            
        } catch (Exception $e) {
            error_log("UnifiedFileService::compressBlacklistedStudent - Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error during compression: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete student files (for cleanup or account deletion)
     * 
     * @param string $studentId Student ID
     * @param bool $includePermanent Also delete permanent files (default: false)
     * @return array ['success' => bool, 'deleted_count' => int]
     */
    public function deleteStudentFiles($studentId, $includePermanent = false) {
        $deletedCount = 0;
        $errors = [];
        
        try {
            // Delete temp files
            foreach ($this->folders as $tempFolder => $permanentFolder) {
                $tempPath = $this->basePath . '/temp/' . $tempFolder;
                
                if (is_dir($tempPath)) {
                    $files = glob($tempPath . '/' . $studentId . '_*');
                    foreach ($files as $file) {
                        if (is_file($file) && unlink($file)) {
                            $deletedCount++;
                        }
                    }
                }
            }
            
            // Delete permanent files if requested
            if ($includePermanent) {
                foreach ($this->folders as $folder) {
                    $permanentPath = $this->basePath . '/student/' . $folder . '/' . $studentId;
                    
                    if (is_dir($permanentPath)) {
                        $files = glob($permanentPath . '/*');
                        foreach ($files as $file) {
                            if (is_file($file) && unlink($file)) {
                                $deletedCount++;
                            }
                        }
                        // Remove the directory
                        @rmdir($permanentPath);
                    }
                }
            }
            
            return [
                'success' => true,
                'deleted_count' => $deletedCount
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'deleted_count' => $deletedCount,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // ============================================================================
    // DOCUMENT DATABASE OPERATIONS
    // ============================================================================
    
    /**
     * Save document to database after upload during registration
     * 
     * @param string $studentId Student ID
     * @param string $docTypeName Document type name (eaf, academic_grades, etc.)
     * @param string $filePath Full file path (absolute or relative)
     * @param array $ocrData OCR and verification results
     * @return array ['success' => bool, 'document_id' => string, 'message' => string]
     */
    public function saveDocument($studentId, $docTypeName, $filePath, $ocrData = []) {
        try {
            if (!isset(self::DOCUMENT_TYPES[$docTypeName])) {
                throw new Exception("Invalid document type: {$docTypeName}");
            }
            
            $docInfo = self::DOCUMENT_TYPES[$docTypeName];
            $currentYear = date('Y');
            
            // Generate document ID
            $documentId = $this->generateDocumentId($studentId, $docInfo['code'], $currentYear);
            
            // Convert absolute path to web-accessible relative path for storage
            $webPath = $this->convertToWebPath($filePath);
            
            // Extract file information
            $fileName = basename($filePath);
            $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
            $fileSize = file_exists($filePath) ? filesize($filePath) : 0;
            
            // Extract verification data
            $ocrConfidence = $ocrData['ocr_confidence'] ?? 0;
            $verificationScore = $ocrData['verification_score'] ?? 0;
            $verificationStatus = $ocrData['verification_status'] ?? 'pending';
            
            error_log("UnifiedFileService::saveDocument - DocType: {$docTypeName}, OCR: {$ocrConfidence}%, Verification: {$verificationScore}%");
            
            // Prepare verification details JSONB
            $verificationDetails = null;
            if (isset($ocrData['verification_details'])) {
                $verificationDetails = json_encode($ocrData['verification_details']);
            }
            
            // Insert into documents table
            $query = "INSERT INTO documents (
                document_id,
                student_id,
                document_type_code,
                document_type_name,
                file_path,
                file_name,
                file_extension,
                file_size_bytes,
                ocr_confidence,
                verification_score,
                verification_status,
                verification_details,
                status,
                upload_date,
                upload_year,
                last_modified
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, 'temp', NOW(), $13, NOW()
            )
            ON CONFLICT (document_id) 
            DO UPDATE SET
                file_path = EXCLUDED.file_path,
                file_name = EXCLUDED.file_name,
                file_extension = EXCLUDED.file_extension,
                file_size_bytes = EXCLUDED.file_size_bytes,
                ocr_confidence = EXCLUDED.ocr_confidence,
                verification_score = EXCLUDED.verification_score,
                verification_status = EXCLUDED.verification_status,
                verification_details = EXCLUDED.verification_details,
                last_modified = NOW()";
            
            $result = pg_query_params($this->db, $query, [
                $documentId,
                $studentId,
                $docInfo['code'],
                $docInfo['name'],
                $webPath,
                $fileName,
                $fileExtension,
                $fileSize,
                $ocrConfidence,
                $verificationScore,
                $verificationStatus,
                $verificationDetails,
                $currentYear
            ]);
            
            if (!$result) {
                throw new Exception("Database insert failed: " . pg_last_error($this->db));
            }
            
            return [
                'success' => true,
                'document_id' => $documentId,
                'message' => 'Document saved successfully'
            ];
            
        } catch (Exception $e) {
            error_log("UnifiedFileService::saveDocument - Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update existing document
     * 
     * @param string $documentId Document ID
     * @param array $updates Key-value pairs to update
     * @return array ['success' => bool, 'message' => string]
     */
    public function updateDocument($documentId, $updates = []) {
        try {
            if (empty($updates)) {
                return ['success' => false, 'message' => 'No updates provided'];
            }
            
            $setClauses = [];
            $params = [];
            $paramCount = 1;
            
            foreach ($updates as $key => $value) {
                $setClauses[] = "$key = $" . $paramCount;
                $params[] = $value;
                $paramCount++;
            }
            
            $setClauses[] = "last_modified = NOW()";
            
            $query = "UPDATE documents SET " . implode(', ', $setClauses) . " WHERE document_id = $" . $paramCount;
            $params[] = $documentId;
            
            $result = pg_query_params($this->db, $query, $params);
            
            if (!$result) {
                throw new Exception("Update failed: " . pg_last_error($this->db));
            }
            
            return [
                'success' => true,
                'message' => 'Document updated successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get all documents for a student
     * 
     * @param string $studentId Student ID
     * @return array|false Array of documents or false on error
     */
    public function getStudentDocuments($studentId) {
        try {
            $query = "SELECT * FROM documents WHERE student_id = $1 ORDER BY upload_date DESC";
            $result = pg_query_params($this->db, $query, [$studentId]);
            
            if (!$result) {
                throw new Exception("Query failed: " . pg_last_error($this->db));
            }
            
            $documents = [];
            while ($row = pg_fetch_assoc($result)) {
                $documents[] = $row;
            }
            
            return $documents;
            
        } catch (Exception $e) {
            error_log("UnifiedFileService::getStudentDocuments - Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete document and associated files
     * 
     * @param string $documentId Document ID
     * @return array ['success' => bool, 'message' => string]
     */
    public function deleteDocument($documentId) {
        try {
            pg_query($this->db, "BEGIN");
            
            // Get document info
            $query = "SELECT * FROM documents WHERE document_id = $1";
            $result = pg_query_params($this->db, $query, [$documentId]);
            
            if (!$result || pg_num_rows($result) === 0) {
                throw new Exception("Document not found");
            }
            
            $doc = pg_fetch_assoc($result);
            
            // Delete physical file and associated files
            $filePath = $doc['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
                $this->deleteAssociatedFiles($filePath);
            }
            
            // Delete database record
            $deleteQuery = "DELETE FROM documents WHERE document_id = $1";
            $deleteResult = pg_query_params($this->db, $deleteQuery, [$documentId]);
            
            if (!$deleteResult) {
                throw new Exception("Database delete failed");
            }
            
            pg_query($this->db, "COMMIT");
            
            return [
                'success' => true,
                'message' => 'Document deleted successfully'
            ];
            
        } catch (Exception $e) {
            pg_query($this->db, "ROLLBACK");
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // ============================================================================
    // ARCHIVE MANAGEMENT
    // ============================================================================
    
    /**
     * Get archived student ZIP file path
     * 
     * @param string $studentId Student ID
     * @return string|null ZIP file path or null if not found
     */
    public function getArchivedStudentZip($studentId) {
        $zipFile = __DIR__ . '/../assets/uploads/archived_students/' . $studentId . '.zip';
        return file_exists($zipFile) ? $zipFile : null;
    }
    
    /**
     * Extract archived student files (for viewing or unarchiving)
     * 
     * @param string $studentId Student ID
     * @param string|null $extractPath Custom extract path (optional)
     * @return array ['success' => bool, 'extracted_files' => array, ...]
     */
    public function extractArchivedStudent($studentId, $extractPath = null) {
        $zipFile = $this->getArchivedStudentZip($studentId);
        
        if (!$zipFile) {
            return ['success' => false, 'message' => 'Archive ZIP not found'];
        }
        
        if (!$extractPath) {
            $extractPath = __DIR__ . '/../assets/uploads/temp/extracted_' . $studentId;
        }
        
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== TRUE) {
            return ['success' => false, 'message' => 'Failed to open ZIP file'];
        }
        
        $extractedFiles = [];
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $zip->extractTo($extractPath, $filename);
            $extractedFiles[] = $filename;
        }
        
        $zip->close();
        
        return [
            'success' => true,
            'extracted_files' => $extractedFiles,
            'extract_path' => $extractPath
        ];
    }
    
    /**
     * Delete archived ZIP file for a student
     * Used after successful unarchival
     * 
     * @param string $studentId Student ID
     * @return bool Success status
     */
    public function deleteArchivedZip($studentId) {
        $zipFile = $this->getArchivedStudentZip($studentId);
        
        if ($zipFile && file_exists($zipFile)) {
            if (unlink($zipFile)) {
                error_log("UnifiedFileService: Deleted archive ZIP for student: $studentId");
                return true;
            }
        }
        
        error_log("UnifiedFileService: No archive ZIP found for student: $studentId");
        return false;
    }
    
    /**
     * Clean up old temporary files
     * Should be called periodically (cron job or manual trigger)
     * 
     * @param int $olderThanDays Files older than this many days will be deleted
     * @return array ['success' => bool, 'deleted_count' => int, 'space_freed' => int]
     */
    public function cleanupTemporaryFiles($olderThanDays = 7) {
        error_log("UnifiedFileService: Cleaning up temp files older than $olderThanDays days");
        
        $cutoffTime = time() - ($olderThanDays * 24 * 60 * 60);
        $deletedCount = 0;
        $deletedSize = 0;
        
        $tempPath = $this->basePath . '/temp';
        $folders = ['enrollment_forms', 'grades', 'id_pictures', 'indigency', 'letter_mayor'];
        
        foreach ($folders as $folder) {
            $folderPath = $tempPath . '/' . $folder;
            
            if (!is_dir($folderPath)) continue;
            
            $files = scandir($folderPath);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                
                $filePath = $folderPath . '/' . $file;
                if (!is_file($filePath)) continue;
                
                if (filemtime($filePath) < $cutoffTime) {
                    $deletedSize += filesize($filePath);
                    if (unlink($filePath)) {
                        $deletedCount++;
                    }
                }
            }
        }
        
        error_log("UnifiedFileService: Deleted $deletedCount temp files, freed " . ($deletedSize / 1024 / 1024) . " MB");
        
        return [
            'success' => true,
            'deleted_count' => $deletedCount,
            'space_freed' => $deletedSize
        ];
    }
    
    // ============================================================================
    // HELPER FUNCTIONS
    // ============================================================================
    
    /**
     * Convert absolute file path to web-accessible relative path
     * 
     * @param string $filePath Absolute file path
     * @return string Relative web path
     */
    private function convertToWebPath($filePath) {
        // Normalize path separators
        $filePath = str_replace('\\', '/', $filePath);
        $baseDir = str_replace('\\', '/', $this->baseDir);
        
        // Get project root (2 levels up from services/)
        $projectRoot = str_replace('\\', '/', dirname(dirname(__FILE__)));
        
        // Remove drive letter if present (C:/, D:/, etc.) - for Windows paths
        if (preg_match('/^[A-Za-z]:\//', $filePath)) {
            // Check if this is the project path
            if (stripos($filePath, $projectRoot) !== false) {
                // Extract everything after project root
                $pattern = preg_quote($projectRoot, '/');
                if (preg_match('/' . $pattern . '(.*)$/i', $filePath, $matches)) {
                    return ltrim($matches[1], '/');
                }
            }
            
            // Check if path contains /assets/uploads/
            if (preg_match('/\/assets\/uploads\/(.*)$/', $filePath, $matches)) {
                return 'assets/uploads/' . $matches[1];
            }
        }
        
        // If path starts with project root, make it relative
        if (strpos($filePath, $projectRoot) === 0) {
            $relativePath = substr($filePath, strlen($projectRoot));
            return ltrim($relativePath, '/');
        }
        
        // If path starts with base directory (temp folder), make it relative
        if (strpos($filePath, $baseDir) === 0) {
            $relativePath = substr($filePath, strlen($baseDir));
            return 'assets/uploads/' . ltrim($relativePath, '/');
        }
        
        // If it's already a relative path starting with assets/
        if (strpos($filePath, 'assets/uploads/') === 0) {
            return $filePath;
        }
        
        // If it's already a relative path starting with student/
        if (strpos($filePath, 'student/') === 0) {
            return $filePath;
        }
        
        // If it's a relative path with ../../
        if (strpos($filePath, '../../assets/uploads/') === 0) {
            return str_replace('../../', '', $filePath);
        }
        
        // Fallback: return as-is
        return $filePath;
    }
    
    /**
     * Generate standardized document ID
     * Format: STUDENTID-DOCU-YEAR-TYPE
     * 
     * @param string $studentId Student ID
     * @param string $typeCode Document type code (00-04)
     * @param string $year Year
     * @return string Document ID
     */
    private function generateDocumentId($studentId, $typeCode, $year) {
        return "{$studentId}-DOCU-{$year}-{$typeCode}";
    }
    
    /**
     * Move associated OCR files with timestamped naming
     * 
     * @param string $oldPath Old file path (full path to main file)
     * @param string $targetDir Target directory
     * @param string $originalFilename Original filename WITH extension (e.g., file.png)
     * @param string $baseName Base filename WITHOUT extension (e.g., file)
     * @param string $timestamp Timestamp string
     */
    private function moveAssociatedFilesWithTimestamp($oldPath, $targetDir, $originalFilename, $baseName, $timestamp) {
        $extensions = ['.ocr.txt', '.verify.json', '.tsv', '.confidence.json'];
        $oldDir = dirname($oldPath);
        
        error_log("UnifiedFileService::moveAssociatedFiles - oldPath: $oldPath");
        error_log("UnifiedFileService::moveAssociatedFiles - oldDir: $oldDir");
        error_log("UnifiedFileService::moveAssociatedFiles - targetDir: $targetDir");
        error_log("UnifiedFileService::moveAssociatedFiles - originalFilename: $originalFilename");
        error_log("UnifiedFileService::moveAssociatedFiles - baseName: $baseName");
        error_log("UnifiedFileService::moveAssociatedFiles - timestamp: $timestamp");
        
        // IMPORTANT: Associated files are named using the FULL original filename with extension
        // Pattern: originalfile.ext.ocr.txt (e.g., studentid_name.png.ocr.txt)
        // We need to use $originalFilename (WITH extension), not $baseName
        foreach ($extensions as $ext) {
            // Use original filename WITH extension to find associated files
            $oldFile = $oldDir . '/' . $originalFilename . $ext;
            error_log("UnifiedFileService::moveAssociatedFiles - Checking: $oldFile, Exists: " . (file_exists($oldFile) ? 'YES' : 'NO'));
            
            if (file_exists($oldFile)) {
                // New filename: basename_timestamp.originalext.associatedext
                // Example: studentid_name_eaf_20251031134227.png.ocr.txt
                $mainExtension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                $newFile = $targetDir . $baseName . '_' . $timestamp . '.' . $mainExtension . $ext;
                error_log("UnifiedFileService::moveAssociatedFiles - Moving to: $newFile");
                
                if (rename($oldFile, $newFile)) {
                    error_log("UnifiedFileService::moveAssociatedFiles - ✓ Moved: " . basename($newFile));
                } else {
                    error_log("UnifiedFileService::moveAssociatedFiles - ✗ FAILED to move: $oldFile to $newFile");
                }
            }
        }
        
        // Note: Confidence files are already handled above in the extensions array
        // No need for special session-based confidence file handling during approval
        // because registration already renamed them to student ID-based format
    }
    
    /**
     * Move associated OCR files
     * Legacy method for backward compatibility
     * 
     * @param string $oldPath Old file path
     * @param string $newPath New file path
     */
    private function moveAssociatedFiles($oldPath, $newPath) {
        $extensions = ['.ocr.txt', '.verify.json', '.tsv', '.confidence.json'];
        
        foreach ($extensions as $ext) {
            $oldFile = $oldPath . $ext;
            if (file_exists($oldFile)) {
                rename($oldFile, $newPath . $ext);
            }
        }
    }
    
    /**
     * Delete associated files
     * 
     * @param string $filePath Main file path
     */
    private function deleteAssociatedFiles($filePath) {
        $extensions = ['.ocr.txt', '.verify.json', '.tsv', '.confidence.json'];
        
        foreach ($extensions as $ext) {
            $file = $filePath . $ext;
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
    
    /**
     * Log action to audit_logs table
     * 
     * @param int $userId User ID
     * @param string $userType User type (admin, student)
     * @param string $username Username
     * @param string $eventType Event type
     * @param string $eventCategory Event category
     * @param string $description Action description
     * @param array|null $metadata Additional metadata
     * @param string $status Status (success, failure)
     */
    private function logAudit($userId, $userType, $username, $eventType, $eventCategory, $description, $metadata = null, $status = 'success') {
        try {
            $query = "INSERT INTO audit_logs (
                user_id, user_type, username, event_type, event_category,
                action_description, status, ip_address, user_agent,
                affected_table, metadata
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8, $9, 'documents', $10
            )";
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            pg_query_params($this->db, $query, [
                $userId,
                $userType,
                $username,
                $eventType,
                $eventCategory,
                $description,
                $status,
                $ipAddress,
                $userAgent,
                $metadata ? json_encode($metadata) : null
            ]);
        } catch (Exception $e) {
            error_log("Audit log failed: " . $e->getMessage());
        }
    }
}
?>
