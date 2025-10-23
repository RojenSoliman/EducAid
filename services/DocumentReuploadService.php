<?php
/**
 * DocumentReuploadService - Handles document re-upload for rejected applicants
 * 
 * For applicants who had documents rejected:
 * - Uploads directly to permanent storage (not temp)
 * - Processes OCR and verification
 * - Updates database with new document
 * - Maintains audit trail
 */

class DocumentReuploadService {
    private $db;
    private $baseDir;
    
    // Document type mapping
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
    }
    
    /**
     * Upload document directly to permanent storage for re-upload
     * 
     * @param string $studentId Student ID
     * @param string $docTypeCode Document type code (00-04)
     * @param string $tmpPath Temporary file path from $_FILES
     * @param string $originalName Original filename
     * @param array $studentData Student information for OCR validation
     * @return array ['success' => bool, 'message' => string, 'document_id' => string]
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
            
            // Generate unique filename
            $timestamp = time();
            $uniqueId = uniqid();
            $newFilename = "{$studentId}_{$docTypeCode}_{$timestamp}_{$uniqueId}.{$extension}";
            
            // Determine permanent storage path
            $targetFolder = $this->baseDir . 'student/' . $docInfo['folder'] . '/';
            if (!is_dir($targetFolder)) {
                mkdir($targetFolder, 0755, true);
            }
            
            $targetPath = $targetFolder . $newFilename;
            
            // Move uploaded file to permanent storage
            if (!move_uploaded_file($tmpPath, $targetPath)) {
                return ['success' => false, 'message' => 'Failed to move uploaded file'];
            }
            
            // Process OCR and verification
            $ocrData = $this->processOCRAndVerification($targetPath, $docTypeCode, $studentData);
            
            // Generate document ID
            $currentYear = date('Y');
            $documentId = "{$studentId}-DOCU-{$currentYear}-{$docTypeCode}";
            
            // Save to database
            $saveResult = $this->saveToDatabase(
                $documentId,
                $studentId,
                $docTypeCode,
                $targetPath,
                $newFilename,
                $extension,
                filesize($targetPath),
                $ocrData
            );
            
            if (!$saveResult['success']) {
                // Cleanup file if database save failed
                @unlink($targetPath);
                return $saveResult;
            }
            
            // Log to audit trail
            $this->logAudit($studentId, $docTypeCode, $documentId, $ocrData);
            
            // Check if all rejected documents are now uploaded
            $this->checkAndClearRejectionStatus($studentId);
            
            return [
                'success' => true,
                'message' => 'Document uploaded successfully',
                'document_id' => $documentId,
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
     * Process OCR and verification for uploaded document
     */
    private function processOCRAndVerification($filePath, $docTypeCode, $studentData) {
        $ocrData = [
            'ocr_confidence' => 0,
            'verification_score' => 0,
            'verification_status' => 'pending',
            'verification_details' => null,
            'extracted_text' => '',
            'extracted_grades' => null,
            'average_grade' => null,
            'passing_status' => false
        ];
        
        try {
            // Initialize OCR service
            require_once __DIR__ . '/OCRProcessingService.php';
            $ocrService = new OCRProcessingService($this->db);
            
            // Process based on document type
            if ($docTypeCode === '01') {
                // Academic Grades - extract grades
                $result = $ocrService->processGradesDocument($filePath, $studentData['student_id'] ?? null);
                
                if ($result['success']) {
                    $ocrData['ocr_confidence'] = $result['confidence'] ?? 0;
                    $ocrData['extracted_text'] = $result['extracted_text'] ?? '';
                    $ocrData['extracted_grades'] = $result['grades'] ?? [];
                    $ocrData['average_grade'] = $result['average_grade'] ?? null;
                    $ocrData['passing_status'] = $result['passing_status'] ?? false;
                    $ocrData['verification_status'] = 'completed';
                    
                    // Save OCR text file
                    if (!empty($ocrData['extracted_text'])) {
                        file_put_contents($filePath . '.ocr.txt', $ocrData['extracted_text']);
                    }
                }
            } elseif (in_array($docTypeCode, ['00', '04'])) {
                // EAF or ID Picture - verify identity
                $result = $ocrService->processIdentityDocument(
                    $filePath,
                    $studentData['first_name'] ?? '',
                    $studentData['middle_name'] ?? '',
                    $studentData['last_name'] ?? '',
                    $studentData['year_level_name'] ?? '',
                    $studentData['student_id'] ?? ''
                );
                
                if ($result['success']) {
                    $ocrData['ocr_confidence'] = $result['confidence'] ?? 0;
                    $ocrData['extracted_text'] = $result['extracted_text'] ?? '';
                    $ocrData['verification_score'] = $result['verification_score'] ?? 0;
                    $ocrData['verification_details'] = $result['verification_details'] ?? null;
                    $ocrData['verification_status'] = 'completed';
                    
                    // Save OCR text file
                    if (!empty($ocrData['extracted_text'])) {
                        file_put_contents($filePath . '.ocr.txt', $ocrData['extracted_text']);
                    }
                    
                    // Save verification details
                    if ($ocrData['verification_details']) {
                        file_put_contents($filePath . '.verify.json', json_encode($ocrData['verification_details']));
                    }
                }
            } elseif (in_array($docTypeCode, ['02', '03'])) {
                // Letter or Certificate - verify barangay
                $result = $ocrService->processBarangayDocument(
                    $filePath,
                    $studentData['first_name'] ?? '',
                    $studentData['last_name'] ?? '',
                    $studentData['barangay_name'] ?? ''
                );
                
                if ($result['success']) {
                    $ocrData['ocr_confidence'] = $result['confidence'] ?? 0;
                    $ocrData['extracted_text'] = $result['extracted_text'] ?? '';
                    $ocrData['verification_score'] = $result['verification_score'] ?? 0;
                    $ocrData['verification_details'] = $result['verification_details'] ?? null;
                    $ocrData['verification_status'] = 'completed';
                    
                    // Save OCR text file
                    if (!empty($ocrData['extracted_text'])) {
                        file_put_contents($filePath . '.ocr.txt', $ocrData['extracted_text']);
                    }
                    
                    // Save verification details
                    if ($ocrData['verification_details']) {
                        file_put_contents($filePath . '.verify.json', json_encode($ocrData['verification_details']));
                    }
                }
            }
        } catch (Exception $e) {
            error_log("OCR processing error: " . $e->getMessage());
            // Continue without OCR - admin can manually verify
        }
        
        return $ocrData;
    }
    
    /**
     * Save document to database
     */
    private function saveToDatabase($documentId, $studentId, $docTypeCode, $filePath, $fileName, $extension, $fileSize, $ocrData) {
        try {
            $docInfo = self::DOCUMENT_TYPES[$docTypeCode];
            $currentYear = date('Y');
            
            // Prepare OCR paths (relative to document root for portability)
            $relativeFilePath = str_replace($this->baseDir, 'assets/uploads/', $filePath);
            $ocrTextPath = file_exists($filePath . '.ocr.txt') ? $relativeFilePath . '.ocr.txt' : null;
            $verificationDataPath = file_exists($filePath . '.verify.json') ? $relativeFilePath . '.verify.json' : null;
            
            // Prepare verification details JSONB
            $verificationDetails = $ocrData['verification_details'] ? json_encode($ocrData['verification_details']) : null;
            
            // Prepare extracted grades JSONB
            $extractedGrades = !empty($ocrData['extracted_grades']) ? json_encode($ocrData['extracted_grades']) : null;
            
            // Insert or update document
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
                upload_year,
                upload_date
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, 
                $11, $12, $13, $14, $15, $16, $17, 'pending', $18, NOW()
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
                status = 'pending',
                upload_date = NOW(),
                last_modified = NOW()
            RETURNING document_id";
            
            $result = pg_query_params($this->db, $query, [
                $documentId,
                $studentId,
                $docTypeCode,
                $docInfo['name'],
                $relativeFilePath,
                $fileName,
                $extension,
                $fileSize,
                $ocrTextPath,
                $ocrData['ocr_confidence'],
                $verificationDataPath,
                $ocrData['verification_status'],
                $ocrData['verification_score'],
                $verificationDetails,
                $extractedGrades,
                $ocrData['average_grade'],
                $ocrData['passing_status'] ? 't' : 'f',
                $currentYear
            ]);
            
            if (!$result) {
                throw new Exception('Database insert failed: ' . pg_last_error($this->db));
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::saveToDatabase error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Log document upload to audit trail
     */
    private function logAudit($studentId, $docTypeCode, $documentId, $ocrData) {
        try {
            $query = "INSERT INTO audit_logs (
                user_id, user_type, username, event_type, event_category,
                action_description, status, ip_address, user_agent,
                affected_table, metadata
            ) VALUES (
                $1, 'student', $2, 'document_reuploaded', 'applicant_management',
                $3, 'success', $4, $5, 'documents', $6
            )";
            
            $description = "Student re-uploaded document after rejection";
            $metadata = json_encode([
                'document_id' => $documentId,
                'document_type_code' => $docTypeCode,
                'ocr_confidence' => $ocrData['ocr_confidence'] ?? 0,
                'verification_score' => $ocrData['verification_score'] ?? 0
            ]);
            
            pg_query_params($this->db, $query, [
                null, // user_id is NULL for student actions
                $studentId,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $metadata
            ]);
        } catch (Exception $e) {
            error_log("Audit log failed: " . $e->getMessage());
        }
    }
    
    /**
     * Check if all rejected documents are uploaded and clear rejection status
     */
    private function checkAndClearRejectionStatus($studentId) {
        try {
            // Get list of documents that need re-upload
            $studentQuery = pg_query_params($this->db,
                "SELECT documents_to_reupload FROM students WHERE student_id = $1",
                [$studentId]
            );
            
            if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
                return;
            }
            
            $student = pg_fetch_assoc($studentQuery);
            $documentsToReupload = json_decode($student['documents_to_reupload'] ?? '[]', true);
            
            if (empty($documentsToReupload)) {
                return;
            }
            
            // Check if all rejected documents are now uploaded
            $placeholders = implode(',', array_fill(0, count($documentsToReupload), '?'));
            $query = "SELECT COUNT(*) as count FROM documents 
                      WHERE student_id = $1 
                      AND document_type_code = ANY($2)
                      AND status != 'rejected'";
            
            $result = pg_query_params($this->db, $query, [
                $studentId,
                '{' . implode(',', $documentsToReupload) . '}'
            ]);
            
            if ($result) {
                $row = pg_fetch_assoc($result);
                $uploadedCount = $row['count'];
                
                // If all rejected documents are re-uploaded, clear rejection status
                if ($uploadedCount >= count($documentsToReupload)) {
                    pg_query_params($this->db,
                        "UPDATE students 
                         SET documents_to_reupload = NULL,
                             needs_document_upload = FALSE
                         WHERE student_id = $1",
                        [$studentId]
                    );
                    
                    // Create notification for student
                    pg_query_params($this->db,
                        "INSERT INTO notifications (student_id, message, is_read) 
                         VALUES ($1, $2, FALSE)",
                        [
                            $studentId,
                            'All rejected documents have been re-uploaded successfully. Your application is now being reviewed by the admin.'
                        ]
                    );
                }
            }
        } catch (Exception $e) {
            error_log("checkAndClearRejectionStatus error: " . $e->getMessage());
        }
    }
}
?>
