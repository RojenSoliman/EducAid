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

    private function buildOcrService(array $overrides = []) {
        if (!defined('TESSERACT_PATH')) {
            $configPath = __DIR__ . '/../config/ocr_config.php';
            if (file_exists($configPath)) {
                require_once $configPath;
            }
        }

        $config = [
            'tesseract_path' => defined('TESSERACT_PATH') ? TESSERACT_PATH : 'tesseract',
            'temp_dir' => dirname(__DIR__) . '/temp',
            'max_file_size' => 10 * 1024 * 1024,
            'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'bmp']
        ];

        require_once __DIR__ . '/OCRProcessingService.php';
        return new OCRProcessingService(array_merge($config, $overrides));
    }
    
    public function __construct($dbConnection) {
        $this->db = $dbConnection;
        $this->baseDir = dirname(__DIR__) . '/assets/uploads/';
        
        // Initialize DocumentService for database operations
        require_once __DIR__ . '/DocumentService.php';
        $this->docService = new DocumentService($dbConnection);
    }
    
    
    /**
     * STAGE 1: Upload document to TEMPORARY storage for preview (like registration)
     * User can see OCR results before confirming
     */
    public function uploadToTemp($studentId, $docTypeCode, $tmpPath, $originalName, $studentData = []) {
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
            
            // Create TEMP path (like registration)
            $tempFolder = $this->baseDir . 'temp/' . $docInfo['folder'] . '/';
            if (!is_dir($tempFolder)) {
                mkdir($tempFolder, 0755, true);
            }
            
            $tempPath = $tempFolder . $newFilename;
            
            // Move file to TEMP storage
            if (!move_uploaded_file($tmpPath, $tempPath)) {
                return ['success' => false, 'message' => 'Failed to move uploaded file'];
            }
            
            error_log("DocumentReuploadService: File uploaded to TEMP: $tempPath");
            
            // DO NOT process OCR automatically - let user trigger it manually
            // This prevents creating null JSON files before OCR button is clicked
            $ocrData = [
                'ocr_confidence' => 0,
                'verification_score' => 0,
                'verification_status' => 'pending',
                'verification_details' => null
            ];
            
            return [
                'success' => true,
                'message' => 'Document uploaded to preview',
                'temp_path' => $tempPath,
                'filename' => $newFilename,
                'ocr_confidence' => 0,
                'verification_score' => 0,
                'verification_status' => 'pending'
            ];
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::uploadToTemp error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * STAGE 2: Confirm upload - Move from TEMP to PERMANENT storage (like registration)
     * Called when user clicks "Confirm Upload" button
     */
    public function confirmUpload($studentId, $docTypeCode, $tempPath) {
        try {
            // Validate document type
            if (!isset(self::DOCUMENT_TYPES[$docTypeCode])) {
                return ['success' => false, 'message' => 'Invalid document type'];
            }
            
            // Validate temp file exists
            if (!file_exists($tempPath)) {
                return ['success' => false, 'message' => 'Temporary file not found. Please upload again.'];
            }
            
            $docInfo = self::DOCUMENT_TYPES[$docTypeCode];
            $filename = basename($tempPath);
            
            // Create permanent path
            $permanentFolder = $this->baseDir . 'student/' . $docInfo['folder'] . '/';
            if (!is_dir($permanentFolder)) {
                mkdir($permanentFolder, 0755, true);
            }
            
            $permanentPath = $permanentFolder . $filename;
            
            // Collect associated OCR files BEFORE moving main file
            $associatedFiles = ['.ocr.txt', '.verify.json', '.confidence.json'];
            $tempAssociatedFiles = [];
            foreach ($associatedFiles as $ext) {
                $tempAssocPath = $tempPath . $ext;
                if (file_exists($tempAssocPath)) {
                    $tempAssociatedFiles[$ext] = $tempAssocPath;
                }
            }
            
            // Move file from TEMP to PERMANENT (using rename for same filesystem)
            $moveSuccess = @rename($tempPath, $permanentPath);
            if (!$moveSuccess) {
                // Fallback to copy if rename fails (different filesystems)
                if (!copy($tempPath, $permanentPath)) {
                    return ['success' => false, 'message' => 'Failed to move file to permanent storage'];
                }
                @unlink($tempPath); // Delete temp file after successful copy
            }
            
            error_log("DocumentReuploadService: Moved from TEMP to PERMANENT: $permanentPath");
            
            // Move associated OCR files (.ocr.txt, .verify.json, .confidence.json)
            foreach ($tempAssociatedFiles as $ext => $tempAssocPath) {
                $permAssocPath = $permanentPath . $ext;
                $assocMoveSuccess = @rename($tempAssocPath, $permAssocPath);
                if (!$assocMoveSuccess) {
                    // Fallback to copy+delete
                    if (@copy($tempAssocPath, $permAssocPath)) {
                        @unlink($tempAssocPath);
                        error_log("DocumentReuploadService: Copied and deleted associated file: $ext");
                    } else {
                        error_log("DocumentReuploadService: Failed to move associated file: $ext");
                    }
                } else {
                    error_log("DocumentReuploadService: Moved associated file: $ext");
                }
            }
            
            // Read OCR data from .verify.json if exists
            $ocrData = [
                'ocr_confidence' => 0,
                'verification_score' => 0,
                'verification_status' => 'pending',
                'verification_details' => null
            ];
            
            if (file_exists($permanentPath . '.verify.json')) {
                $verifyJson = json_decode(file_get_contents($permanentPath . '.verify.json'), true);
                $ocrData['ocr_confidence'] = $verifyJson['ocr_confidence'] ?? 0;
                $ocrData['verification_score'] = $verifyJson['verification_score'] ?? 0;
                $ocrData['verification_status'] = $verifyJson['verification_status'] ?? 'pending';
                $ocrData['verification_details'] = $verifyJson;
            }
            
            // Use DocumentService to save to database
            $saveResult = $this->docService->saveDocument(
                $studentId,
                $docInfo['name'],
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
            error_log("DocumentReuploadService::confirmUpload error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Confirmation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process OCR for a temporary upload and produce artifacts for preview.
     * Uses the SAME proven OCR approach as registration for consistent quality
     */
    public function processTempOcr($studentId, $docTypeCode, $tempPath, $studentData = []) {
        try {
            if (!isset(self::DOCUMENT_TYPES[$docTypeCode])) {
                return ['success' => false, 'message' => 'Invalid document type'];
            }

            if (!file_exists($tempPath)) {
                return ['success' => false, 'message' => 'Temporary file missing. Please re-upload.'];
            }

            $docInfo = self::DOCUMENT_TYPES[$docTypeCode];
            $extension = strtolower(pathinfo($tempPath, PATHINFO_EXTENSION));

            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'pdf', 'tiff', 'bmp'])) {
                return ['success' => false, 'message' => 'Unsupported file type for OCR'];
            }

            // Grades keep existing specialised flow
            if ($docTypeCode === '01') {
                $ocrData = $this->processGradesOCR($tempPath, $studentData);

                if (($ocrData['ocr_confidence'] ?? 0) <= 0) {
                    return ['success' => false, 'message' => 'Unable to extract text from grades document'];
                }

                return [
                    'success' => true,
                    'ocr_confidence' => $ocrData['ocr_confidence'] ?? 0,
                    'verification_score' => $ocrData['verification_score'] ?? 0,
                    'verification_status' => $ocrData['verification_status'] ?? 'manual_review'
                ];
            }

            // Use DIRECT Tesseract approach (same as registration) for better quality
            $ocrResult = $this->runDirectTesseractOCR($tempPath, $docTypeCode);
            
            if (empty($ocrResult['text'])) {
                return ['success' => false, 'message' => 'No readable text detected'];
            }

            $confidence = $ocrResult['confidence'] ?? 0;
            $status = $confidence >= 75 ? 'passed' : ($confidence >= 50 ? 'manual_review' : 'failed');

            // Persist OCR artifacts beside the temp file
            @file_put_contents($tempPath . '.ocr.txt', $ocrResult['text'] ?? '');

            $verificationPayload = [
                'timestamp' => date('Y-m-d H:i:s'),
                'student_id' => $studentData['student_id'] ?? $studentId,
                'document_type' => $docInfo['name'],
                'ocr_confidence' => $confidence,
                'verification_score' => $confidence,
                'verification_status' => $status,
                'word_count' => str_word_count($ocrResult['text'] ?? ''),
                'ocr_text_preview' => substr($ocrResult['text'] ?? '', 0, 500)
            ];

            @file_put_contents($tempPath . '.verify.json', json_encode($verificationPayload, JSON_PRETTY_PRINT));

            return [
                'success' => true,
                'ocr_confidence' => $confidence,
                'verification_score' => $confidence,
                'verification_status' => $status
            ];

        } catch (Exception $e) {
            error_log('DocumentReuploadService::processTempOcr error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'OCR failed: ' . $e->getMessage()];
        }
    }

    /**
     * Run direct Tesseract OCR with multiple passes (same as registration)
     * This provides much better quality than the generic OCRProcessingService
     */
    private function runDirectTesseractOCR($filePath, $docTypeCode) {
        $result = ['text' => '', 'confidence' => 0];
        
        try {
            // Different PSM modes for different document types
            $psmMode = $this->getPSMForDocType($docTypeCode);
            
            // Primary OCR pass with appropriate PSM
            $cmd = "tesseract " . escapeshellarg($filePath) . " stdout --oem 1 --psm $psmMode -l eng 2>&1";
            $tessOut = @shell_exec($cmd);
            
            if (!empty($tessOut)) {
                $result['text'] = $tessOut;
            }
            
            // Additional passes for better text extraction (like registration does)
            if ($docTypeCode === '04') {
                // ID Picture: Multiple passes for better name extraction
                $passA = @shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 11 2>&1");
                $passB = @shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 7 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz,.- 2>&1");
                $result['text'] = trim($result['text'] . "\n" . $passA . "\n" . $passB);
            } elseif ($docTypeCode === '02') {
                // Letter to Mayor: Try multiple passes for better text extraction
                $passA = @shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm 3 2>&1"); // Fully automatic
                $result['text'] = trim($result['text'] . "\n" . $passA);
            }
            
            // Get confidence from TSV (same as registration)
            $tsv = @shell_exec("tesseract " . escapeshellarg($filePath) . " stdout -l eng --oem 1 --psm $psmMode tsv 2>&1");
            if (!empty($tsv)) {
                $lines = explode("\n", $tsv);
                if (count($lines) > 1) {
                    array_shift($lines); // Remove header
                    $sum = 0;
                    $cnt = 0;
                    foreach ($lines as $line) {
                        if (!trim($line)) continue;
                        $cols = explode("\t", $line);
                        if (count($cols) >= 12) {
                            $conf = floatval($cols[10] ?? 0);
                            if ($conf > 0) {
                                $sum += $conf;
                                $cnt++;
                            }
                        }
                    }
                    if ($cnt > 0) {
                        $result['confidence'] = round($sum / $cnt, 2);
                    }
                }
            }
            
            error_log("DirectTesseractOCR for $docTypeCode: " . strlen($result['text']) . " chars, confidence: " . $result['confidence']);
            
        } catch (Exception $e) {
            error_log("DirectTesseractOCR error: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Get optimal PSM mode for each document type
     */
    private function getPSMForDocType($docTypeCode) {
        switch ($docTypeCode) {
            case '04': // ID Picture
                return 6; // Uniform block of text
            case '00': // EAF
                return 6; // Uniform block of text
            case '02': // Letter to Mayor
                return 4; // Single column text
            case '03': // Certificate of Indigency
                return 6; // Uniform block of text
            default:
                return 6; // Default
        }
    }

    private function getOcrOptionsForType($docTypeCode) {
        // This function is now deprecated - using direct Tesseract instead
        // Kept for backwards compatibility
        switch ($docTypeCode) {
            case '04':
                return ['psm' => 6];
            case '02':
                return ['psm' => 4];
            case '03':
                return ['psm' => 6];
            case '00':
                return ['psm' => 6];
            default:
                return ['psm' => 6];
        }
    }
    
    /**
     * Cancel preview - Delete temp file
     */
    public function cancelPreview($tempPath) {
        try {
            $deleted = [];
            $failed = [];
            
            // Delete main file
            if (file_exists($tempPath)) {
                if (@unlink($tempPath)) {
                    $deleted[] = basename($tempPath);
                } else {
                    $failed[] = basename($tempPath);
                }
            }
            
            // Delete all associated OCR/verification files
            $associatedFiles = [
                '.ocr.txt',
                '.verify.json', 
                '.confidence.json',
                '.preprocessed.png',
                '.preprocessed.jpg',
                '.tsv'  // Tesseract output
            ];
            
            foreach ($associatedFiles as $ext) {
                $fullPath = $tempPath . $ext;
                if (file_exists($fullPath)) {
                    if (@unlink($fullPath)) {
                        $deleted[] = basename($fullPath);
                    } else {
                        $failed[] = basename($fullPath);
                    }
                }
            }
            
            // Also check for files without extension prefix (e.g., filename.tsv)
            $basePath = pathinfo($tempPath, PATHINFO_DIRNAME);
            $baseFilename = pathinfo($tempPath, PATHINFO_FILENAME);
            $tsvPath = $basePath . '/' . $baseFilename . '.tsv';
            if (file_exists($tsvPath)) {
                if (@unlink($tsvPath)) {
                    $deleted[] = basename($tsvPath);
                } else {
                    $failed[] = basename($tsvPath);
                }
            }
            
            error_log("CancelPreview - Deleted: " . implode(', ', $deleted) . 
                     ($failed ? " | Failed: " . implode(', ', $failed) : ''));
            
            return [
                'success' => true, 
                'message' => 'Preview cancelled',
                'deleted' => $deleted,
                'failed' => $failed
            ];
            
        } catch (Exception $e) {
            error_log("DocumentReuploadService::cancelPreview error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to cancel preview: ' . $e->getMessage()];
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
            $ocrService = $this->buildOcrService();
            
            $result = $ocrService->processGradeDocument($filePath);
            
            if ($result['success']) {
                $confidence = $result['confidence'] ?? 0;
                $ocrData['ocr_confidence'] = $confidence;
                $ocrData['verification_score'] = $confidence;

                $inferredStatus = $result['passing_status'];
                if ($inferredStatus === true) {
                    $ocrData['verification_status'] = 'passed';
                } elseif ($inferredStatus === false) {
                    $ocrData['verification_status'] = 'failed';
                } else {
                    $ocrData['verification_status'] = $confidence >= 70 ? 'passed' : 'manual_review';
                }

                $ocrData['extracted_grades'] = $result['subjects'] ?? [];
                $ocrData['average_grade'] = $result['average_grade'] ?? null;
                $ocrData['passing_status'] = ($inferredStatus === true);

                $verificationData = [
                    'timestamp' => date('c'),
                    'student_id' => $studentData['student_id'] ?? 'unknown',
                    'document_type' => 'academic_grades',
                    'ocr_confidence' => $confidence,
                    'verification_score' => $confidence,
                    'verification_status' => $ocrData['verification_status'],
                    'summary' => [
                        'total_subjects' => $result['totalSubjects'] ?? 0,
                        'average_grade' => $result['average_grade'] ?? null,
                        'grade_scale' => $result['grade_scale'] ?? null,
                        'semester' => $result['semester'] ?? null,
                        'school_year' => $result['school_year'] ?? null,
                        'year_level' => $result['year_level'] ?? null,
                        'passing_status' => $ocrData['verification_status']
                    ],
                    'extracted_subjects' => $result['subjects'] ?? [],
                    'ocr_text_preview' => substr($result['raw_text'] ?? '', 0, 500),
                    'processing_notes' => $result['notes'] ?? []
                ];

                $ocrData['verification_details'] = $verificationData;

                if (!empty($result['raw_text'])) {
                    file_put_contents($filePath . '.ocr.txt', $result['raw_text']);
                    error_log("DocumentReuploadService: Saved OCR text to " . $filePath . '.ocr.txt');
                }

                file_put_contents($filePath . '.verify.json', json_encode($verificationData, JSON_PRETTY_PRINT));
                error_log("DocumentReuploadService: Saved verification JSON to " . $filePath . '.verify.json');
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
