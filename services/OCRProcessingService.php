<?php
/**
 * OCR Processing Service
 * Handles document processing using Tesseract OCR with TSV output
 * Safe version that handles missing Imagick extension gracefully
 */

class OCRProcessingService {
    private $tesseractPath;
    private $tempDir;
    private $maxFileSize;
    private $allowedExtensions;
    
    public function __construct($config = []) {
        $this->tesseractPath = $config['tesseract_path'] ?? 'tesseract';
        $this->tempDir = $config['temp_dir'] ?? sys_get_temp_dir();
        $this->maxFileSize = $config['max_file_size'] ?? 10 * 1024 * 1024; // 10MB
        $this->allowedExtensions = $config['allowed_extensions'] ?? ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'bmp'];
    }
    
    /**
     * Process uploaded grade document and extract subjects with grades
     */
    public function processGradeDocument($filePath) {
        try {
            // Validate file
            if (!$this->validateFile($filePath)) {
                throw new Exception("Invalid file format or size");
            }
            
            // Preprocess the document
            $preprocessedFiles = $this->preprocessDocument($filePath);
            
            $allSubjects = [];
            
            // Process each page/file
            foreach ($preprocessedFiles as $processedFile) {
                $tsvData = $this->runTesseract($processedFile);
                $subjects = $this->parseTSVData($tsvData);
                $allSubjects = array_merge($allSubjects, $subjects);
                
                // Clean up temporary processed file
                if (file_exists($processedFile) && $processedFile !== $filePath) {
                    unlink($processedFile);
                }
            }
            
            return [
                'success' => true,
                'subjects' => $allSubjects,
                'totalSubjects' => count($allSubjects)
            ];
            
        } catch (Exception $e) {
            error_log("OCR Processing error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'subjects' => []
            ];
        }
    }
    
    /**
     * Validate uploaded file
     */
    private function validateFile($filePath) {
        if (!file_exists($filePath)) {
            return false;
        }
        
        // Check file size
        if (filesize($filePath) > $this->maxFileSize) {
            return false;
        }
        
        // Check file extension
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, $this->allowedExtensions);
    }
    
    /**
     * Preprocess document for better OCR results
     */
    private function preprocessDocument($filePath) {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $processedFiles = [];
        
        if ($extension === 'pdf') {
            // Handle PDF - split pages and process each
            $processedFiles = $this->processPDF($filePath);
        } else {
            // Handle image files
            $processedFile = $this->processImage($filePath);
            $processedFiles[] = $processedFile;
        }
        
        return $processedFiles;
    }
    
    /**
     * Process PDF document - split into pages
     */
    private function processPDF($pdfPath) {
        $processedFiles = [];
        
        try {
            // Check if Imagick extension is available
            if (extension_loaded('imagick') && class_exists('Imagick')) {
                // Try Imagick processing in a safe way
                $processedFiles = $this->processPDFWithImagick($pdfPath);
            } else {
                // Fallback: use original file if Imagick not available
                error_log("Imagick not available, processing PDF as single file");
                $processedFiles[] = $pdfPath;
            }
            
        } catch (Exception $e) {
            error_log("PDF processing error: " . $e->getMessage());
            $processedFiles[] = $pdfPath; // Fallback to original
        }
        
        return $processedFiles;
    }
    
    /**
     * Process PDF with Imagick in a safe manner
     */
    private function processPDFWithImagick($pdfPath) {
        $processedFiles = [];
        
        try {
            // Dynamic creation to avoid type errors
            $imagickClass = '\Imagick';
            $imagick = new $imagickClass();
            $imagick->setResolution(300, 300);
            $imagick->readImage($pdfPath);
            
            $pageCount = $imagick->getNumberImages();
            
            for ($i = 0; $i < $pageCount; $i++) {
                $imagick->setIteratorIndex($i);
                $image = clone $imagick;
                
                // Basic enhancement
                $this->enhanceImageForOCR($image);
                
                // Save to temporary file
                $tempFile = $this->tempDir . '/page_' . $i . '_' . uniqid() . '.png';
                $image->writeImage($tempFile);
                $processedFiles[] = $tempFile;
                
                $image->clear();
            }
            
            $imagick->clear();
            
        } catch (Exception $e) {
            error_log("Imagick PDF processing failed: " . $e->getMessage());
            throw $e;
        }
        
        return $processedFiles;
    }
    
    /**
     * Process image file for better OCR
     */
    private function processImage($imagePath) {
        try {
            if (extension_loaded('imagick') && class_exists('Imagick')) {
                return $this->processImageWithImagick($imagePath);
            } else {
                error_log("Imagick not available, using original image");
            }
        } catch (Exception $e) {
            error_log("Image processing error: " . $e->getMessage());
        }
        
        return $imagePath; // Return original if processing fails
    }
    
    /**
     * Process image with Imagick safely
     */
    private function processImageWithImagick($imagePath) {
        try {
            // Dynamic creation to avoid type errors
            $imagickClass = '\Imagick';
            $imagick = new $imagickClass($imagePath);
            
            $this->enhanceImageForOCR($imagick);
            
            // Save enhanced image
            $tempFile = $this->tempDir . '/enhanced_' . uniqid() . '.png';
            $imagick->writeImage($tempFile);
            $imagick->clear();
            
            return $tempFile;
            
        } catch (Exception $e) {
            error_log("Imagick image processing failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Enhance image for better OCR results
     */
    private function enhanceImageForOCR($imagick) {
        try {
            // Only proceed if we have a valid Imagick object
            if (!$imagick || !is_object($imagick)) {
                return $imagick;
            }
            
            // Convert to grayscale
            $imagick->transformImageColorspace(1); // COLORSPACE_GRAY = 1
            
            // Set resolution for better OCR (300-400 DPI)
            $imagick->setImageResolution(350, 350);
            $imagick->setResolution(350, 350);
            
            // Deskew if needed (auto-rotate)
            $imagick->deskewImage(40);
            
            // Enhance contrast and brightness
            $imagick->normalizeImage();
            $imagick->contrastImage(1);
            
            // Apply unsharp mask to improve text clarity
            $imagick->unsharpMaskImage(0, 0.5, 1, 0.05);
            
            // Binarize (convert to black and white)
            // Use numeric value instead of constant to avoid undefined constant error
            $quantum = method_exists($imagick, 'getQuantum') ? $imagick->getQuantum() : 65535;
            $imagick->thresholdImage(0.5 * $quantum);
            
            return $imagick;
            
        } catch (Exception $e) {
            error_log("Image enhancement error: " . $e->getMessage());
            return $imagick; // Return original if enhancement fails
        }
    }
    
    /**
     * Run Tesseract OCR on processed file
     */
    private function runTesseract($filePath) {
        // Clean up temp directory path to avoid double slashes
        $cleanTempDir = rtrim($this->tempDir, '/\\');
        $tsvFile = $cleanTempDir . '/ocr_' . uniqid() . '.tsv';
        
        // Build Tesseract command for TSV output
        $outputFile = pathinfo($tsvFile, PATHINFO_FILENAME);
        $command = sprintf(
            '"%s" "%s" "%s" -l eng --oem 1 --psm 6 tsv 2>&1',
            $this->tesseractPath,
            $filePath,
            $outputFile
        );
        
        // Execute Tesseract
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            error_log("Tesseract execution failed: " . implode("\n", $output));
            throw new Exception("OCR processing failed");
        }
        
        // Read TSV output
        $tsvContent = file_get_contents($tsvFile);
        
        // Clean up
        if (file_exists($tsvFile)) {
            unlink($tsvFile);
        }
        
        return $tsvContent;
    }
    
    /**
     * Parse TSV data and extract subjects with grades
     */
    private function parseTSVData($tsvData) {
        $subjects = [];
        $lines = explode("\n", $tsvData);
        
        error_log("OCR TSV Data - Total lines: " . count($lines));
        
        // Skip header line
        array_shift($lines);
        
        $currentLine = null;
        $lineData = [];
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $columns = explode("\t", $line);
            if (count($columns) < 12) continue; // TSV should have 12 columns
            
            $pageNum = $columns[1];
            $blockNum = $columns[2];
            $parNum = $columns[3];
            $lineNum = $columns[4];
            $wordNum = $columns[5];
            $left = intval($columns[6]);
            $top = intval($columns[7]);
            $width = intval($columns[8]);
            $height = intval($columns[9]);
            $conf = intval($columns[10]);
            $text = trim($columns[11]);
            
            if (empty($text) || $conf < 30) continue; // Skip low confidence or empty text
            
            $lineKey = "{$pageNum}-{$blockNum}-{$parNum}-{$lineNum}";
            
            // Group words by line
            if (!isset($lineData[$lineKey])) {
                $lineData[$lineKey] = [
                    'words' => [],
                    'top' => $top,
                    'height' => $height
                ];
            }
            
            $lineData[$lineKey]['words'][] = [
                'text' => $text,
                'left' => $left,
                'width' => $width,
                'conf' => $conf
            ];
        }
        
        // Process lines to extract subject-grade pairs
        error_log("OCR Processing - Lines grouped: " . count($lineData));
        
        foreach ($lineData as $lineKey => $line) {
            $extracted = $this->extractSubjectGradeFromLine($line);
            if ($extracted) {
                $subjects[] = $extracted;
                error_log("OCR Found subject: " . $extracted['subject'] . " Grade: " . $extracted['grade']);
            }
        }
        
        error_log("OCR Final subjects found: " . count($subjects));
        return $this->consolidateSubjects($subjects);
    }
    
    /**
     * Extract subject and grade from a line of words
     */
    private function extractSubjectGradeFromLine($line) {
        // Sort words by horizontal position (left coordinate)
        usort($line['words'], function($a, $b) {
            return $a['left'] - $b['left'];
        });
        
        $lineText = '';
        $grades = [];
        $avgConfidence = 0;
        $wordCount = 0;
        
        foreach ($line['words'] as $word) {
            $lineText .= $word['text'] . ' ';
            $avgConfidence += $word['conf'];
            $wordCount++;
            
            // Check if word looks like a grade
            if ($this->looksLikeGrade($word['text'])) {
                $grades[] = [
                    'grade' => $this->normalizeGrade($word['text']),
                    'conf' => $word['conf'],
                    'position' => $word['left']
                ];
            }
        }
        
        $avgConfidence = $wordCount > 0 ? $avgConfidence / $wordCount : 0;
        $lineText = trim($lineText);
        
        // Skip header-like lines
        if ($this->isHeaderLine($lineText)) {
            return null;
        }
        
        // Look for subject name and grade
        if (!empty($grades) && !empty($lineText)) {
            // Use the rightmost grade (usually final grade)
            $grade = end($grades);
            
            // Extract subject name (remove the grade from line text)
            $subjectName = trim(str_replace($grade['grade'], '', $lineText));
            $subjectName = $this->cleanSubjectName($subjectName);
            
            if (!empty($subjectName) && strlen($subjectName) > 2) {
                return [
                    'name' => $subjectName,
                    'rawGrade' => $grade['grade'],
                    'confidence' => min($avgConfidence, $grade['conf']),
                    'originalLine' => $lineText
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Check if text looks like a grade
     */
    private function looksLikeGrade($text) {
        $normalized = $this->normalizeGrade($text);
        
        // Numeric patterns for common grading systems
        $patterns = [
            '/^[1-5](\.\d{1,2})?$/',           // 1-5 scale
            '/^[0-4](\.\d{1,3})?$/',           // 0-4 scale  
            '/^\d{2,3}(\.\d{1,2})?$/',         // Percentage
            '/^[A-D][+-]?$/i',                 // Letter grades
            '/^(INC|DRP|W|NG|P|F)$/i'          // Special grades
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if line is a header
     */
    private function isHeaderLine($text) {
        $text = strtolower($text);
        $headerKeywords = [
            'subject', 'course', 'grade', 'final', 'units', 'credit',
            'semester', 'year', 'name', 'student', 'transcript'
        ];
        
        foreach ($headerKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Clean and normalize subject name
     */
    private function cleanSubjectName($name) {
        // Remove course codes (like "CS101", "MATH201")
        $name = preg_replace('/\b[A-Z]{2,4}\s*\d{3,4}\b/i', '', $name);
        
        // Remove common prefixes/suffixes
        $name = preg_replace('/\b(lecture|lab|laboratory|lec|unit|units|credit|credits)\b/i', '', $name);
        
        // Remove extra whitespace and punctuation
        $name = preg_replace('/[^\w\s&()-]/', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        
        return trim($name);
    }
    
    /**
     * Normalize grade text
     */
    private function normalizeGrade($grade) {
        $grade = trim($grade);
        
        // Common OCR fixes
        $grade = str_replace(',', '.', $grade);
        
        // Fix O to 0 - handle cases like "2O5" -> "2.05"  
        if (strpos($grade, 'O') !== false) {
            // If it looks like a decimal with O instead of 0
            if (preg_match('/^(\d+)O(\d+)$/', $grade, $matches)) {
                $grade = $matches[1] . '.0' . $matches[2];
            } else {
                // General O to 0 replacement
                $grade = str_replace('O', '0', $grade);
            }
        }
        
        // Fix S to 5 - handle cases like "S.00" -> "5.00"
        if (strpos($grade, 'S') !== false) {
            // If it starts with S and looks like a grade
            if (preg_match('/^S\.?\d*$/', $grade)) {
                $grade = str_replace('S', '5', $grade);
            }
        }
        
        // Remove degree symbol
        $grade = rtrim($grade, '°');
        
        return $grade;
    }
    
    /**
     * Consolidate duplicate subjects
     */
    private function consolidateSubjects($subjects) {
        $consolidated = [];
        $seen = [];
        
        foreach ($subjects as $subject) {
            $nameKey = strtolower(trim($subject['name']));
            
            // Skip very short or generic names
            if (strlen($nameKey) < 3 || in_array($nameKey, ['', 'total', 'gpa', 'average'])) {
                continue;
            }
            
            if (!isset($seen[$nameKey])) {
                $consolidated[] = $subject;
                $seen[$nameKey] = count($consolidated) - 1;
            } else {
                // Keep the one with higher confidence
                $existingIndex = $seen[$nameKey];
                if ($subject['confidence'] > $consolidated[$existingIndex]['confidence']) {
                    $consolidated[$existingIndex] = $subject;
                }
            }
        }
        
        return $consolidated;
    }
}
?>