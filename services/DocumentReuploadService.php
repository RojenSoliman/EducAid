<?php
/**
 * DocumentReuploadService - Handles document re-upload for rejected applicants
 * 
 * Key differences from registration:
 * - Uploads directly to permanent storage (skips temp folder)
 * - Uses DocumentService for database operations
 * - Minimal OCR processing (only for grades)
 */

class DocumentReuploadService {
    private $db;
    private $baseDir;
    private $docService;
    
    const DOCUMENT_TYPES = [
        '04' => ['name' => 'id_picture', 'folder' => 'id_pictures'],
        '00' => ['name' => 'eaf', 'folder' => 'enrollment_forms'],
        '01' => ['name' => 'academic_grades', 'folder' => 'grades'],
        '02' => ['name' => 'letter_to_mayor', 'folder' => 'letter_mayor'],
        '03' => ['name' => 'certificate_of_indigency', 'folder' => 'indigency']
    ];
    
    public function __construct($dbConnection) {
        $this->db = $dbConnection;
        $this->baseDir = dirname(__DIR__) . '/assets/uploads/';
        
        // Initialize DocumentService for database operations
        require_once __DIR__ . '/DocumentService.php';
        $this->docService = new DocumentService($dbConnection);
    }
    
    
    /**
     * Upload document directly to permanent storage for re-upload
     * Follows same pattern as student_register.php but skips temp folder
     */
    public function uploadDocument($studentId, $docTypeCode, $tmpPath, $originalName, $studentData = []) {
        try {
            // Validate document type
            if (!isset(self::DOCUMENT_TYPES[$docTypeCode])) {
                return ['success' => false, 'message' => 'Invalid document type'];
            }
            
            $docInfo = self::DOCUMENT_TYPES[$docTypeCode];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            // Validate file extension
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
            if (!in_array($extension, $allowedExtensions)) {
                return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and PDF allowed.'];
            }
            
            // Generate filename: STUDENTID_doctype_timestamp.ext
            $timestamp = time();
            $newFilename = "{$studentId}_{$docInfo['name']}_{$timestamp}.{$extension}";
            
            // Create permanent path
            $permanentFolder = $this->baseDir . 'student/' . $docInfo['folder'] . '/';
            if (!is_dir($permanentFolder)) {
                mkdir($permanentFolder, 0755, true);
            }
            
            $permanentPath = $permanentFolder . $newFilename;
            
            // Move file to permanent storage
            if (!move_uploaded_file($tmpPath, $permanentPath)) {
                return ['success' => false, 'message' => 'Failed to move uploaded file'];
            }
            
            error_log("DocumentReuploadService: File uploaded to $permanentPath");
            
            // Prepare OCR data structure (similar to registration)
            $ocrData = [
                'ocr_confidence' => 0,
                'verification_score' => 0,
                'verification_status' => 'pending',
                'verification_details' => null
            ];
            
            // Only process OCR for grades (like registration does)
            if ($docTypeCode === '01') {
                $ocrData = $this->processGradesOCR($permanentPath, $studentData);
            }
            
            // Use DocumentService to save to database (same as registration)
            $saveResult = $this->docService->saveDocument(
                $studentId,
                $docInfo['name'],  // Use document type name (e.g., 'academic_grades')
                $permanentPath,
                $ocrData
            );
            
            if (!$saveResult['success']) {
                // Cleanup file if database save failed
                @unlink($permanentPath);
                return [
                    'success' => false,
                    'message' => $saveResult['error'] ?? 'Failed to save to database'
                ];
            }
            
            error_log("DocumentReuploadService: Saved to database - " . ($saveResult['document_id'] ?? 'unknown ID'));
            
            // Log audit trail
            $this->logAudit($studentId, $docInfo['name'], $ocrData);
            
            // Check if all rejected documents are uploaded
            $this->checkAndClearRejectionStatus($studentId);
            
            return [
                'success' => true,
                'message' => 'Document uploaded successfully',
                'document_id' => $saveResult['document_id'] ?? null,
                'file_path' => $permanentPath,
                'ocr_confidence' => $ocrData['ocr_confidence'] ?? 0,
                'verification_score' => $ocrData['verification_score'] ?? 0
            ];
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::uploadDocument error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Process OCR for grades document only (minimal processing)
     */
    private function processGradesOCR($filePath, $studentData) {
        $ocrData = [
            'ocr_confidence' => 0,
            'verification_score' => 0,
            'verification_status' => 'pending',
            'verification_details' => null
        ];
        
        try {
            // Use OCRProcessingService for grade extraction
            require_once __DIR__ . '/OCRProcessingService.php';
            $ocrService = new OCRProcessingService([
                'tesseract_path' => 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                'temp_dir' => dirname(__DIR__) . '/temp',
                'max_file_size' => 10 * 1024 * 1024,
                'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'bmp']
            ]);
            
            $result = $ocrService->processGradeDocument($filePath);
            
            if ($result['success']) {
                $ocrData['ocr_confidence'] = $result['confidence'] ?? 0;
                $ocrData['verification_score'] = $result['confidence'] ?? 0;
                $ocrData['verification_status'] = ($result['confidence'] ?? 0) >= 70 ? 'passed' : 'manual_review';
                $ocrData['verification_details'] = $result;
                
                // Save OCR text file
                if (!empty($result['raw_text'])) {
                    file_put_contents($filePath . '.ocr.txt', $result['raw_text']);
                }
            }
        } catch (Exception $e) {
            error_log("DocumentReuploadService::processGradesOCR error: " . $e->getMessage());
        }
        
        return $ocrData;
    }
    
    /**
     * Log audit trail for document upload
     */
    private function logAudit($studentId, $docTypeName, $ocrData) {
        try {
            $description = "Student re-uploaded {$docTypeName} (Confidence: " . ($ocrData['ocr_confidence'] ?? 0) . "%)";
            
            $query = "INSERT INTO audit_logs (user_id, student_id, action, description, ip_address, created_at)
                      VALUES ($1, $2, $3, $4, $5, NOW())";
            
            pg_query_params($this->db, $query, [
                null,
                $studentId,
                'student_document_reupload',
                $description,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("DocumentReuploadService::logAudit error: " . $e->getMessage());
        }
    }
    
    /**
     * Check if all rejected documents are uploaded and clear rejection status
     */
    private function checkAndClearRejectionStatus($studentId) {
        try {
            $studentQuery = pg_query_params($this->db,
                "SELECT documents_to_reupload FROM students WHERE student_id = $1",
                [$studentId]
            );
            
            if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
                return;
            }
            
            $student = pg_fetch_assoc($studentQuery);
            $documentsToReupload = json_decode($student['documents_to_reupload'], true) ?: [];
            
            if (empty($documentsToReupload)) {
                return;
            }
            
            // Check if all required documents are now uploaded
            $query = "SELECT document_type_code FROM documents 
                      WHERE student_id = $1 AND document_type_code = ANY($2::text[])";
            
            $result = pg_query_params($this->db, $query, [
                $studentId,
                '{' . implode(',', $documentsToReupload) . '}'
            ]);
            
            if ($result) {
                $uploadedDocs = [];
                while ($row = pg_fetch_assoc($result)) {
                    $uploadedDocs[] = $row['document_type_code'];
                }
                
                // If all documents are uploaded, clear the rejection status
                if (count($uploadedDocs) >= count($documentsToReupload)) {
                    pg_query_params($this->db,
                        "UPDATE students 
                         SET documents_to_reupload = NULL,
                             needs_document_upload = FALSE
                         WHERE student_id = $1",
                        [$studentId]
                    );
                    
                    pg_query_params($this->db,
                        "INSERT INTO notifications (student_id, message, is_read, created_at) 
                         VALUES ($1, $2, FALSE, NOW())",
                        [$studentId, 'All required documents have been re-uploaded successfully. An admin will review them shortly.']
                    );
                }
            }
        } catch (Exception $e) {
            error_log("DocumentReuploadService::checkAndClearRejectionStatus error: " . $e->getMessage());
        }
    }
}
