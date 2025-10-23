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
     * @param string $filePath Full file path
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
            
            // Extract file information
            $fileName = basename($filePath);
            $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
            $fileSize = file_exists($filePath) ? filesize($filePath) : 0;
            
            // Prepare OCR paths
            $ocrTextPath = file_exists($filePath . '.ocr.txt') ? $filePath . '.ocr.txt' : null;
            $verificationDataPath = file_exists($filePath . '.verify.json') ? $filePath . '.verify.json' : null;
            
            // Extract OCR data
            $ocrConfidence = $ocrData['ocr_confidence'] ?? 0;
            $verificationScore = $ocrData['verification_score'] ?? 0;
            $verificationStatus = $ocrData['verification_status'] ?? 'pending';
            
            // Prepare verification details JSONB
            $verificationDetails = null;
            if (isset($ocrData['verification_details'])) {
                $verificationDetails = json_encode($ocrData['verification_details']);
            }
            
            // Prepare extracted grades JSONB (for grades documents)
            $extractedGrades = null;
            $averageGrade = null;
            $passingStatus = null;
            
            if ($docTypeName === 'academic_grades' && isset($ocrData['extracted_grades'])) {
                $extractedGrades = json_encode($ocrData['extracted_grades']);
                $averageGrade = $ocrData['average_grade'] ?? null;
                $passingStatus = $ocrData['passing_status'] ?? null;
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
                ocr_text_path,
                ocr_confidence,
                verification_data_path,
                verification_status,
                verification_score,
                verification_details,
                extracted_grades,
                average_grade,
                passing_status,
                status,
                upload_year
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, 
                $11, $12, $13, $14, $15, $16, $17, 'temp', $18
            )
            ON CONFLICT (document_id) 
            DO UPDATE SET
                file_path = EXCLUDED.file_path,
                file_name = EXCLUDED.file_name,
                file_size_bytes = EXCLUDED.file_size_bytes,
                ocr_text_path = EXCLUDED.ocr_text_path,
                ocr_confidence = EXCLUDED.ocr_confidence,
                verification_data_path = EXCLUDED.verification_data_path,
                verification_status = EXCLUDED.verification_status,
                verification_score = EXCLUDED.verification_score,
                verification_details = EXCLUDED.verification_details,
                extracted_grades = EXCLUDED.extracted_grades,
                average_grade = EXCLUDED.average_grade,
                passing_status = EXCLUDED.passing_status,
                last_modified = NOW()";
            
            $result = pg_query_params($this->db, $query, [
                $documentId,
                $studentId,
                $docInfo['code'],
                $docInfo['name'],
                $filePath,
                $fileName,
                $fileExtension,
                $fileSize,
                $ocrTextPath,
                $ocrConfidence,
                $verificationDataPath,
                $verificationStatus,
                $verificationScore,
                $verificationDetails,
                $extractedGrades,
                $averageGrade,
                $passingStatus ? 't' : 'f',
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
                
                // Determine new path (move from temp/ to student/)
                $newPath = str_replace('/temp/', '/student/', $oldPath);
                
                // Ensure target directory exists
                $targetDir = dirname($newPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                
                // Move main file
                if (!rename($oldPath, $newPath)) {
                    $errors[] = "Failed to move: {$oldPath}";
                    continue;
                }
                
                // Move associated OCR files
                $this->moveAssociatedFiles($oldPath, $newPath);
                
                // Update database
                $updateQuery = "UPDATE documents 
                               SET file_path = $1, 
                                   ocr_text_path = $2,
                                   verification_data_path = $3,
                                   status = 'approved',
                                   approved_date = NOW(),
                                   last_modified = NOW()
                               WHERE document_id = $4";
                
                $ocrPath = file_exists($newPath . '.ocr.txt') ? $newPath . '.ocr.txt' : null;
                $verifyPath = file_exists($newPath . '.verify.json') ? $newPath . '.verify.json' : null;
                
                $updateResult = pg_query_params($this->db, $updateQuery, [
                    $newPath,
                    $ocrPath,
                    $verifyPath,
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
     * Move associated OCR files (.ocr.txt, .verify.json, .tsv, .confidence.json)
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
            
            // Load OCR text if available
            if ($doc['ocr_text_path'] && file_exists($doc['ocr_text_path'])) {
                $doc['extracted_text'] = file_get_contents($doc['ocr_text_path']);
            }
            
            // Parse verification details from JSONB
            if ($doc['verification_details']) {
                $doc['identity_verification'] = json_decode($doc['verification_details'], true);
            }
            
            // Parse extracted grades from JSONB
            if ($doc['extracted_grades']) {
                $doc['extracted_grades_array'] = json_decode($doc['extracted_grades'], true);
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
