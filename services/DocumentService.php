<?php
/**
 * DocumentService - Unified Document Management
 * 
 * Handles all document operations:
 * - Upload to temp folder during registration
 * - Move to permanent storage after approval
 * - OCR processing and verification
 * - Database operations with new unified schema
 */

class DocumentService {
    private $db;
    private $baseDir = __DIR__ . '/../assets/uploads/';
    
    // Document type mapping
    const DOCUMENT_TYPES = [
        'eaf' => ['code' => '00', 'name' => 'eaf', 'folder' => 'enrollment_forms'],
        'academic_grades' => ['code' => '01', 'name' => 'academic_grades', 'folder' => 'grades'],
        'letter_to_mayor' => ['code' => '02', 'name' => 'letter_to_mayor', 'folder' => 'letter_mayor'],
        'certificate_of_indigency' => ['code' => '03', 'name' => 'certificate_of_indigency', 'folder' => 'indigency'],
        'id_picture' => ['code' => '04', 'name' => 'id_picture', 'folder' => 'id_pictures']
    ];
    
    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }
    
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
        
        // If path starts with base directory, make it relative
        if (strpos($filePath, $baseDir) === 0) {
            $relativePath = substr($filePath, strlen($baseDir));
            return 'assets/uploads/' . ltrim($relativePath, '/');
        }
        
        // If it's already a relative path starting with assets/
        if (strpos($filePath, 'assets/uploads/') === 0) {
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
     * Log action to existing audit_logs table
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
    
    /**
     * Save document to database after upload during registration
     * 
     * @param string $studentId Student ID (e.g., GENERALTRIAS-2025-3-DWXA3N)
     * @param string $docTypeName Document type name (eaf, academic_grades, etc.)
     * @param string $filePath Full file path (absolute or relative)
     * @param array $ocrData OCR and verification results
     * @return array ['success' => bool, 'document_id' => string, 'message' => string]
     */
    public function saveDocument($studentId, $docTypeName, $filePath, $ocrData = []) {
        try {
            if (!isset(self::DOCUMENT_TYPES[$docTypeName])) {
                throw new Exception("Invalid document type: $docTypeName");
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
            
            // Debug logging
            error_log("DocumentService::saveDocument - DocType: {$docTypeName}, OCR: {$ocrConfidence}%, Verification: {$verificationScore}%");
            error_log("DocumentService::saveDocument - Storing web path: {$webPath}");
            
            // Prepare verification details JSONB (this contains ALL OCR and verification data)
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
                upload_year
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, 'temp', $13
            )
            ON CONFLICT (document_id) 
            DO UPDATE SET
                file_path = EXCLUDED.file_path,
                file_name = EXCLUDED.file_name,
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
                $webPath,  // Store web-accessible path instead of absolute path
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
                throw new Exception('Database insert failed: ' . pg_last_error($this->db));
            }
            
            // Log to audit trail
            $this->logAudit(
                null,  // user_id (NULL for self-registration)
                'student',
                $studentId,
                'document_uploaded',
                'applicant_management',
                "Document uploaded: {$docInfo['name']}",
                [
                    'document_id' => $documentId,
                    'document_type' => $docTypeName,
                    'file_name' => $fileName,
                    'ocr_confidence' => $ocrConfidence,
                    'verification_score' => $verificationScore,
                    'verification_status' => $verificationStatus
                ]
            );
            
            return [
                'success' => true,
                'document_id' => $documentId,
                'message' => 'Document saved successfully'
            ];
            
        } catch (Exception $e) {
            error_log("DocumentService::saveDocument error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate standardized document ID
     * Format: STUDENTID-DOCU-YEAR-TYPE
     * Example: GENERALTRIAS-2025-3-DWXA3N-DOCU-2025-01
     */
    private function generateDocumentId($studentId, $typeCode, $year) {
        return "{$studentId}-DOCU-{$year}-{$typeCode}";
    }
    
    /**
     * Move documents from temp to permanent storage after approval
     * Updates database paths and status
     * 
     * @param string $studentId Student ID
     * @return array ['success' => bool, 'moved_count' => int, 'errors' => array]
     */
    public function moveToPermStorage($studentId) {
        try {
            pg_query($this->db, "BEGIN");
            
            // Get all temp documents for this student
            $query = "SELECT * FROM documents WHERE student_id = $1 AND status = 'temp'";
            $result = pg_query_params($this->db, $query, [$studentId]);
            
            if (!$result) {
                throw new Exception('Failed to fetch documents: ' . pg_last_error($this->db));
            }
            
            $movedCount = 0;
            $errors = [];
            
            while ($doc = pg_fetch_assoc($result)) {
                $oldPath = $doc['file_path'];
                
                if (!file_exists($oldPath)) {
                    $errors[] = "File not found: {$oldPath}";
                    continue;
                }
                
                // NEW: Build student-organized path
                // Extract document type folder from old path (e.g., 'eaf', 'academic_grades')
                // Pattern: temp/{doc_type}/filename → student/{doc_type}/{student_id}/filename
                
                if (preg_match('#/temp/([^/]+)/([^/]+)$#', $oldPath, $matches)) {
                    $docTypeFolder = $matches[1];
                    $originalFilename = $matches[2];
                    
                    // Generate timestamped filename to prevent overwrites
                    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                    $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);
                    $timestamp = date('YmdHis');
                    $newFilename = $baseName . '_' . $timestamp . '.' . $extension;
                    
                    // Create student-specific folder
                    $targetDir = dirname(dirname(dirname($oldPath))) . '/student/' . $docTypeFolder . '/' . $studentId . '/';
                    $newPath = $targetDir . $newFilename;
                    
                    error_log("DocumentService::moveToPermStorage - Moving to student folder: {$newPath}");
                } else {
                    // Fallback to old behavior if path doesn't match expected pattern
                    $newPath = str_replace('/temp/', '/student/', $oldPath);
                    $targetDir = dirname($newPath);
                    error_log("DocumentService::moveToPermStorage - Using fallback path: {$newPath}");
                }
                
                // Ensure target directory exists
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                    error_log("DocumentService::moveToPermStorage - Created directory: {$targetDir}");
                }
                
                // Move main file
                if (!rename($oldPath, $newPath)) {
                    $errors[] = "Failed to move: {$oldPath}";
                    continue;
                }
                
                // Move associated OCR files with timestamped naming
                if (isset($baseName) && isset($timestamp) && isset($targetDir)) {
                    $this->moveAssociatedFilesWithTimestamp($oldPath, $targetDir, $baseName, $timestamp);
                } else {
                    $this->moveAssociatedFiles($oldPath, $newPath);
                }
                
                // Convert new path to web-accessible relative path for database storage
                $webPath = $this->convertToWebPath($newPath);
                
                // Update database
                $updateQuery = "UPDATE documents 
                               SET file_path = $1, 
                                   status = 'approved',
                                   approved_date = NOW(),
                                   last_modified = NOW()
                               WHERE document_id = $2";
                
                $updateResult = pg_query_params($this->db, $updateQuery, [
                    $webPath,  // Store web-accessible path
                    $doc['document_id']
                ]);
                
                if (!$updateResult) {
                    $errors[] = "Database update failed for {$doc['document_id']}";
                    continue;
                }
                
                $movedCount++;
            }
            
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
                        'errors' => count($errors)
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
            error_log("DocumentService::moveToPermStorage error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'errors' => []
            ];
        }
    }
    
    /**
     * Move associated OCR files with timestamped naming
     * Used when moving to student-specific folders
     */
    private function moveAssociatedFilesWithTimestamp($oldPath, $targetDir, $baseName, $timestamp) {
        $extensions = ['.ocr.txt', '.verify.json', '.tsv', '.confidence.json'];
        
        foreach ($extensions as $ext) {
            $oldFile = $oldPath . $ext;
            $newFile = $targetDir . $baseName . '_' . $timestamp . $ext;
            
            if (file_exists($oldFile)) {
                if (@rename($oldFile, $newFile)) {
                    error_log("DocumentService: Moved associated file: {$ext}");
                } else {
                    error_log("DocumentService: Failed to move associated file: {$ext}");
                }
            }
        }
    }
    
    /**
     * Move associated OCR files (.ocr.txt, .verify.json, .tsv, .confidence.json)
     * Legacy method for backward compatibility
     */
    private function moveAssociatedFiles($oldPath, $newPath) {
        $extensions = ['.ocr.txt', '.verify.json', '.tsv', '.confidence.json'];
        
        foreach ($extensions as $ext) {
            $oldFile = $oldPath . $ext;
            $newFile = $newPath . $ext;
            
            if (file_exists($oldFile)) {
                @rename($oldFile, $newFile);
            }
        }
    }
    
    /**
     * Get document with full validation data for admin view
     */
    public function getDocumentWithValidation($studentId, $docTypeName) {
        try {
            if (!isset(self::DOCUMENT_TYPES[$docTypeName])) {
                return ['success' => false, 'message' => 'Invalid document type'];
            }
            
            $docInfo = self::DOCUMENT_TYPES[$docTypeName];
            
            // Get document from database
            $query = "SELECT * FROM documents 
                      WHERE student_id = $1 AND document_type_name = $2 
                      ORDER BY upload_date DESC 
                      LIMIT 1";
            
            $result = pg_query_params($this->db, $query, [$studentId, $docTypeName]);
            
            if (!$result || pg_num_rows($result) === 0) {
                return ['success' => false, 'message' => 'Document not found'];
            }
            
            $doc = pg_fetch_assoc($result);
            
            // Parse verification details from JSONB (contains ALL OCR and verification data)
            if ($doc['verification_details']) {
                $verificationData = json_decode($doc['verification_details'], true);
                $doc['identity_verification'] = $verificationData;
                
                // Extract grades from verification_details if it's a grades document
                if (isset($verificationData['extracted_grades'])) {
                    $doc['extracted_grades_array'] = $verificationData['extracted_grades'];
                }
            }
            
            return [
                'success' => true,
                'validation' => $doc
            ];
            
        } catch (Exception $e) {
            error_log("getDocumentWithValidation error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get all documents for a student
     */
    public function getStudentDocuments($studentId) {
        try {
            $query = "SELECT * FROM documents 
                      WHERE student_id = $1 
                      ORDER BY document_type_code, upload_date DESC";
            
            $result = pg_query_params($this->db, $query, [$studentId]);
            
            if (!$result) {
                throw new Exception('Failed to fetch documents: ' . pg_last_error($this->db));
            }
            
            $documents = [];
            while ($doc = pg_fetch_assoc($result)) {
                $documents[] = $doc;
            }
            
            return [
                'success' => true,
                'documents' => $documents
            ];
            
        } catch (Exception $e) {
            error_log("getStudentDocuments error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete document and associated files
     */
    public function deleteDocument($documentId) {
        try {
            // Get file path first
            $query = "SELECT * FROM documents WHERE document_id = $1";
            $result = pg_query_params($this->db, $query, [$documentId]);
            
            if ($result && pg_num_rows($result) > 0) {
                $doc = pg_fetch_assoc($result);
                $filePath = $doc['file_path'];
                
                // Delete physical files
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                if (file_exists($filePath . '.ocr.txt')) {
                    unlink($filePath . '.ocr.txt');
                }
                if (file_exists($filePath . '.verify.json')) {
                    unlink($filePath . '.verify.json');
                }
                
                // Delete from database
                pg_query_params($this->db, 
                    "DELETE FROM documents WHERE document_id = $1", 
                    [$documentId]
                );
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log("deleteDocument error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
?>
