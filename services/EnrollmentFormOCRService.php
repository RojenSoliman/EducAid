<?php
/**
 * Enrollment Form OCR Service - TSV-based processing
 * Extracts structured data from enrollment/assessment forms
 * Uses Tesseract TSV output for higher accuracy
 */

class EnrollmentFormOCRService {
    private $connection;
    private $tesseractPath;
    private $tempDir;
    
    public function __construct($dbConnection) {
        $this->connection = $dbConnection;
        $this->tesseractPath = 'tesseract'; // Adjust if needed
        $this->tempDir = __DIR__ . '/../assets/uploads/temp/';
    }
    
    /**
     * Process enrollment form and extract key information
     * Returns structured data with confidence scores
     */
    public function processEnrollmentForm($filePath, $studentData = []) {
        try {
            // Validate file
            if (!file_exists($filePath)) {
                return $this->errorResponse('File not found');
            }
            
            // Generate TSV output
            $tsvData = $this->runTesseractTSV($filePath);
            
            if (!$tsvData['success']) {
                return $this->errorResponse($tsvData['error']);
            }
            
            // Extract structured information
            $extracted = $this->extractEnrollmentData($tsvData['words'], $studentData);
            
            // Calculate overall confidence
            $overallConfidence = $this->calculateOverallConfidence($extracted);
            
            // Determine if verification passed
            $verificationPassed = $this->verifyExtractedData($extracted, $studentData);
            
            return [
                'success' => true,
                'data' => $extracted,
                'overall_confidence' => $overallConfidence,
                'verification_passed' => $verificationPassed,
                'tsv_quality' => $tsvData['quality']
            ];
            
        } catch (Exception $e) {
            error_log("Enrollment OCR Error: " . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }
    
    /**
     * Run Tesseract with TSV output
     */
    private function runTesseractTSV($filePath) {
        try {
            // Handle PDF conversion if needed
            $processedFile = $this->preprocessFile($filePath);
            
            // Generate unique output filename
            $outputBase = $this->tempDir . 'enrollment_ocr_' . uniqid();
            $tsvFile = $outputBase . '.tsv';
            
            // Run Tesseract for TSV output
            $command = sprintf(
                '"%s" "%s" "%s" -l eng --oem 1 --psm 6 tsv 2>&1',
                $this->tesseractPath,
                $processedFile,
                $outputBase
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0 || !file_exists($tsvFile)) {
                throw new Exception("Tesseract execution failed");
            }
            
            // Parse TSV data
            $tsvContent = file_get_contents($tsvFile);
            $words = $this->parseTSV($tsvContent);
            
            // DEBUG: Save TSV file for inspection (DON'T delete yet)
            // Keep it in the enrollment_forms folder alongside the uploaded file
            $debugTsvPath = str_replace('.tsv', '_debug.tsv', str_replace('enrollment_ocr_', '', $tsvFile));
            $debugTsvPath = dirname($filePath) . '/' . basename($filePath) . '.tsv';
            @copy($tsvFile, $debugTsvPath);
            
            // Also save extracted text for easy reading
            $extractedText = implode(' ', array_column($words, 'text'));
            $debugTxtPath = $filePath . '.ocr.txt';
            @file_put_contents($debugTxtPath, $extractedText);
            
            // Clean up temporary TSV
            @unlink($tsvFile);
            if ($processedFile !== $filePath) {
                @unlink($processedFile);
            }
            
            // Calculate quality metrics
            $quality = $this->calculateQuality($words);
            
            return [
                'success' => true,
                'words' => $words,
                'quality' => $quality,
                'extracted_text' => $extractedText // Include for debugging
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Preprocess file for OCR (handle PDFs, enhance images)
     */
    private function preprocessFile($filePath) {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        // If PDF, convert first page to image
        if ($extension === 'pdf') {
            if (extension_loaded('imagick') && class_exists('Imagick')) {
                try {
                    $imagick = new Imagick();
                    $imagick->setResolution(300, 300);
                    $imagick->readImage($filePath . '[0]'); // First page only
                    $imagick->setImageFormat('png');
                    
                    $tempFile = $this->tempDir . 'enrollment_temp_' . uniqid() . '.png';
                    $imagick->writeImage($tempFile);
                    $imagick->clear();
                    
                    return $tempFile;
                } catch (Exception $e) {
                    error_log("PDF conversion failed: " . $e->getMessage());
                    return $filePath;
                }
            }
        }
        
        return $filePath;
    }
    
    /**
     * Parse TSV content into structured word data
     */
    private function parseTSV($tsvContent) {
        $lines = explode("\n", $tsvContent);
        array_shift($lines); // Remove header
        
        $words = [];
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $cols = explode("\t", $line);
            if (count($cols) < 12) continue;
            
            $level = (int)$cols[0];
            $conf = is_numeric($cols[10]) ? (float)$cols[10] : 0;
            $text = trim($cols[11]);
            
            // Only keep word-level entries with text
            if ($level === 5 && !empty($text) && $conf > 30) {
                $words[] = [
                    'page_num' => (int)$cols[1],
                    'block_num' => (int)$cols[2],
                    'par_num' => (int)$cols[3],
                    'line_num' => (int)$cols[4],
                    'word_num' => (int)$cols[5],
                    'left' => (int)$cols[6],
                    'top' => (int)$cols[7],
                    'width' => (int)$cols[8],
                    'height' => (int)$cols[9],
                    'conf' => $conf,
                    'text' => $text
                ];
            }
        }
        
        return $words;
    }
    
    /**
     * Calculate OCR quality metrics
     */
    private function calculateQuality($words) {
        if (empty($words)) {
            return [
                'total_words' => 0,
                'avg_confidence' => 0,
                'quality_score' => 0
            ];
        }
        
        $totalConf = 0;
        $lowConfCount = 0;
        
        foreach ($words as $word) {
            $totalConf += $word['conf'];
            if ($word['conf'] < 70) {
                $lowConfCount++;
            }
        }
        
        $avgConf = round($totalConf / count($words), 2);
        $qualityScore = 100 - (($lowConfCount / count($words)) * 100);
        
        return [
            'total_words' => count($words),
            'avg_confidence' => $avgConf,
            'quality_score' => round($qualityScore, 1)
        ];
    }
    
    /**
     * Extract enrollment data from TSV words
     */
    private function extractEnrollmentData($words, $studentData) {
        $fullText = implode(' ', array_column($words, 'text'));
        $fullTextLower = strtolower($fullText);
        
        $extracted = [
            'student_name' => $this->extractStudentName($words, $studentData),
            'course' => $this->extractCourse($words),
            'year_level' => $this->extractYearLevel($words),
            'university' => $this->extractUniversity($words, $studentData),
            'academic_year' => $this->extractAcademicYear($words),
            'student_id' => $this->extractStudentId($words),
            'document_type' => $this->verifyDocumentType($fullTextLower)
        ];
        
        return $extracted;
    }
    
    /**
     * Extract student name and verify
     */
    private function extractStudentName($words, $studentData) {
        $firstName = $studentData['first_name'] ?? '';
        $middleName = $studentData['middle_name'] ?? '';
        $lastName = $studentData['last_name'] ?? '';
        
        $result = [
            'first_name_found' => false,
            'middle_name_found' => false,
            'last_name_found' => false,
            'confidence' => 0
        ];
        
        $fullText = strtolower(implode(' ', array_column($words, 'text')));
        
        // Check first name
        if (!empty($firstName)) {
            $similarity = $this->fuzzyMatch($firstName, $fullText);
            $result['first_name_found'] = $similarity >= 70;
            $result['first_name_similarity'] = $similarity;
        }
        
        // Check middle name (optional)
        if (!empty($middleName)) {
            $similarity = $this->fuzzyMatch($middleName, $fullText);
            $result['middle_name_found'] = $similarity >= 60;
            $result['middle_name_similarity'] = $similarity;
        } else {
            $result['middle_name_found'] = true; // Not required
            $result['middle_name_similarity'] = 100;
        }
        
        // Check last name
        if (!empty($lastName)) {
            $similarity = $this->fuzzyMatch($lastName, $fullText);
            $result['last_name_found'] = $similarity >= 75;
            $result['last_name_similarity'] = $similarity;
        }
        
        // Calculate overall confidence
        $confidences = [
            $result['first_name_similarity'] ?? 0,
            $result['middle_name_similarity'] ?? 0,
            $result['last_name_similarity'] ?? 0
        ];
        $result['confidence'] = round(array_sum($confidences) / 3, 1);
        
        return $result;
    }
    
    /**
     * Extract course/program from enrollment form
     */
    private function extractCourse($words) {
        $fullText = implode(' ', array_column($words, 'text'));
        
        // DEBUG: Log what text we're searching
        error_log("=== ENROLLMENT COURSE EXTRACTION DEBUG ===");
        error_log("Full OCR Text: " . substr($fullText, 0, 500));
        
        // PRIORITY 1: Look for "PROGRAM:" field (most reliable)
        // Pattern: "PROGRAM: IT", "PROGRAM: BSCS", etc.
        if (preg_match('/PROGRAM\s*[:;]\s*([A-Z]{2,6})\b/i', $fullText, $matches)) {
            $programCode = strtoupper(trim($matches[1]));
            error_log("Found PROGRAM field: '{$programCode}'");
            
            // Map common program codes
            $programCodeMap = [
                'IT' => 'BS Information Technology',
                'CS' => 'BS Computer Science',
                'BSIT' => 'BS Information Technology',
                'BSCS' => 'BS Computer Science',
                'CE' => 'BS Civil Engineering',
                'EE' => 'BS Electrical Engineering',
                'ME' => 'BS Mechanical Engineering',
                'ECE' => 'BS Electronics and Communications Engineering',
                'CPE' => 'BS Computer Engineering',
                'BSCE' => 'BS Civil Engineering',
                'BSEE' => 'BS Electrical Engineering',
                'BSME' => 'BS Mechanical Engineering',
                'BSECE' => 'BS Electronics and Communications Engineering',
                'ARCH' => 'BS Architecture',
                'ARCHI' => 'BS Architecture',
                'BSA' => 'BS Accountancy',
                'BSBA' => 'BS Business Administration',
                'BSN' => 'BS Nursing',
                'BSPSYCH' => 'BS Psychology',
                'ABPSYCH' => 'AB Psychology',
                'ABCOMM' => 'AB Communication',
                'BEED' => 'Bachelor of Elementary Education',
                'BSED' => 'Bachelor of Secondary Education'
            ];
            
            if (isset($programCodeMap[$programCode])) {
                $extractedCourse = $programCodeMap[$programCode];
                error_log("Mapped '{$programCode}' to '{$extractedCourse}'");
                $normalized = $this->normalizeCourse($extractedCourse);
                return [
                    'raw' => $programCode,
                    'normalized' => $normalized,
                    'confidence' => 95, // Very high confidence for PROGRAM field
                    'found' => true
                ];
            }
        }
        
        // PRIORITY 2: Full course patterns (if PROGRAM field not found)
        error_log("PROGRAM field not found, trying full patterns...");
        
        // IMPROVED: More specific patterns with word boundaries and better matching
        $coursePatterns = [
            // Information Technology (check this FIRST as it's very common)
            '/\b(?:BS|B\.S\.|Bachelor.*?Science.*?in)?\s*Information\s+Technology\b/i',
            '/\b(?:BS|B\.S\.)\s*IT\b/i',
            '/\bBSIT\b/i',
            
            // Computer Science (check AFTER IT to avoid confusion)
            '/\b(?:BS|B\.S\.|Bachelor.*?Science.*?in)?\s*Computer\s+Science\b/i',
            '/\b(?:BS|B\.S\.)\s*CompSci\b/i',
            '/\b(?:BS|B\.S\.)\s*CS\b(?!\w)/i', // Negative lookahead to avoid matching "CSomething"
            '/\bBSCS\b/i',
            
            // Engineering courses
            '/\b(?:BS|B\.S\.)\s*Civil\s+Engineering\b/i',
            '/\b(?:BS|B\.S\.)\s*Electrical\s+Engineering\b/i',
            '/\b(?:BS|B\.S\.)\s*Mechanical\s+Engineering\b/i',
            '/\b(?:BS|B\.S\.)\s*Electronics?\s+(?:and\s+)?Communications?\s+Engineering\b/i',
            '/\b(?:BS|B\.S\.)\s*Chemical\s+Engineering\b/i',
            '/\b(?:BS|B\.S\.)\s*Industrial\s+Engineering\b/i',
            '/\bBSCE\b/i',
            '/\bBSEE\b/i',
            '/\bBSME\b/i',
            '/\bBSECE\b/i',
            
            // Architecture
            '/\b(?:BS|B\.S\.)\s*Architecture\b/i',
            '/\bBSArch\b/i',
            
            // Business courses
            '/\b(?:BS|B\.S\.)\s*Accountancy\b/i',
            '/\b(?:BS|B\.S\.)\s*Accounting\b/i',
            '/\b(?:BS|B\.S\.)\s*Business\s+Administration\b/i',
            '/\b(?:BS|B\.S\.)\s*Management\b/i',
            '/\bBSA\b(?!\w)/i', // Accountancy
            '/\bBSBA\b/i',
            '/\bBSBM\b/i',
            
            // Medical/Health
            '/\b(?:BS|B\.S\.)\s*Nursing\b/i',
            '/\b(?:BS|B\.S\.)\s*Pharmacy\b/i',
            '/\b(?:BS|B\.S\.)\s*Physical\s+Therapy\b/i',
            '/\b(?:BS|B\.S\.)\s*Medical\s+Technology\b/i',
            '/\bBSN\b(?!\w)/i',
            
            // Psychology
            '/\b(?:BS|B\.S\.)\s*Psychology\b/i',
            '/\b(?:AB|A\.B\.)\s*Psychology\b/i',
            '/\bBSPsych\b/i',
            '/\bABPsych\b/i',
            
            // Communication
            '/\b(?:AB|A\.B\.)\s*Communication\b/i',
            '/\b(?:AB|A\.B\.)\s*Mass\s+Communication\b/i',
            
            // Political Science
            '/\b(?:AB|A\.B\.)\s*Political\s+Science\b/i',
            '/\bABPolSci\b/i',
            
            // Education
            '/\b(?:B\.?Ed|Bachelor.*?Education)\s*(?:Elementary|Secondary)?\b/i',
            '/\bBEED\b/i',
            '/\bBSED\b/i',
            '/\bBECEd\b/i'
        ];
        
        $extractedCourse = null;
        $confidence = 0;
        $bestMatch = '';
        
        // Try each pattern and find the BEST match (longest match wins)
        foreach ($coursePatterns as $idx => $pattern) {
            if (preg_match($pattern, $fullText, $matches)) {
                $match = trim($matches[0]);
                error_log("Pattern #{$idx} MATCHED: '{$match}' using pattern: {$pattern}");
                // Prefer longer, more specific matches
                if (strlen($match) > strlen($bestMatch)) {
                    $bestMatch = $match;
                    $extractedCourse = $match;
                    $confidence = 85; // High confidence for pattern match
                    error_log("  -> New best match (length " . strlen($match) . ")");
                }
            }
        }
        
        error_log("Final extracted course: " . ($extractedCourse ?? 'NONE'));
        error_log("==========================================");
        
        // If no pattern match, try to find course keywords with context
        if (!$extractedCourse) {
            $courseKeywords = [
                'Information Technology' => 'BS Information Technology',
                'Computer Science' => 'BS Computer Science',
                'Civil Engineering' => 'BS Civil Engineering',
                'Electrical Engineering' => 'BS Electrical Engineering',
                'Mechanical Engineering' => 'BS Mechanical Engineering',
                'Electronics and Communications Engineering' => 'BS Electronics and Communications Engineering',
                'Architecture' => 'BS Architecture',
                'Accountancy' => 'BS Accountancy',
                'Business Administration' => 'BS Business Administration',
                'Nursing' => 'BS Nursing',
                'Psychology' => 'BS Psychology',
                'Education' => 'Bachelor of Education',
                'Communication' => 'AB Communication',
                'Political Science' => 'AB Political Science'
            ];
            
            $bestSimilarity = 0;
            foreach ($courseKeywords as $keyword => $fullCourseName) {
                $similarity = $this->fuzzyMatch($keyword, $fullText);
                if ($similarity >= 70 && $similarity > $bestSimilarity) {
                    $bestSimilarity = $similarity;
                    $extractedCourse = $keyword;
                    $confidence = $similarity;
                }
            }
        }
        
        // Normalize course name
        if ($extractedCourse) {
            $normalized = $this->normalizeCourse($extractedCourse);
            return [
                'raw' => $extractedCourse,
                'normalized' => $normalized,
                'confidence' => $confidence,
                'found' => true
            ];
        }
        
        return [
            'raw' => null,
            'normalized' => null,
            'confidence' => 0,
            'found' => false
        ];
    }
    
    /**
     * Normalize course name for database lookup
     */
    private function normalizeCourse($rawCourse) {
        // Remove degree type prefixes (BS, AB, etc.)
        $normalized = preg_replace('/^(BS|B\.S\.|AB|A\.B\.|Bachelor\s+of\s+Science\s+in|Bachelor\s+of\s+Arts\s+in)\s+/i', '', $rawCourse);
        
        // Common abbreviation expansions - ONLY for STANDALONE abbreviations
        // Use word boundaries to avoid matching abbreviations inside words
        $expansions = [
            '/\bCS\b/i' => 'Computer Science',
            '/\bIT\b/i' => 'Information Technology',
            '/\bCE\b/i' => 'Civil Engineering',
            '/\bEE\b/i' => 'Electrical Engineering',
            '/\bME\b/i' => 'Mechanical Engineering',
            '/\bECE\b/i' => 'Electronics and Communications Engineering',
            '/\bArchi\b/i' => 'Architecture',
            '/\bBA\b/i' => 'Business Administration',
            '/\bCompSci\b/i' => 'Computer Science'
        ];
        
        foreach ($expansions as $pattern => $fullName) {
            $normalized = preg_replace($pattern, $fullName, $normalized);
        }
        
        // Add BS prefix back for consistency (if not already present)
        if (!preg_match('/^(BS|AB|B\.Ed)/i', $normalized)) {
            $normalized = 'BS ' . $normalized;
        }
        
        return trim($normalized);
    }
    
    /**
     * Extract year level
     */
    private function extractYearLevel($words) {
        $fullText = implode(' ', array_column($words, 'text'));
        
        $yearPatterns = [
            '/\b(1st|First|I)\s*Year\b/i' => '1st Year College',
            '/\b(2nd|Second|II)\s*Year\b/i' => '2nd Year College',
            '/\b(3rd|Third|III)\s*Year\b/i' => '3rd Year College',
            '/\b(4th|Fourth|IV)\s*Year\b/i' => '4th Year College',
            '/\b(5th|Fifth|V)\s*Year\b/i' => '5th Year College'
        ];
        
        foreach ($yearPatterns as $pattern => $yearLevel) {
            if (preg_match($pattern, $fullText)) {
                return [
                    'raw' => $yearLevel,
                    'normalized' => $yearLevel,
                    'confidence' => 85,
                    'found' => true
                ];
            }
        }
        
        return [
            'raw' => null,
            'normalized' => null,
            'confidence' => 0,
            'found' => false
        ];
    }
    
    /**
     * Extract university name
     */
    private function extractUniversity($words, $studentData) {
        $declaredUniversity = $studentData['university_name'] ?? '';
        $fullText = implode(' ', array_column($words, 'text'));
        
        if (empty($declaredUniversity)) {
            return [
                'found' => false,
                'confidence' => 0,
                'match' => false
            ];
        }
        
        // Check if university name appears in OCR text
        $similarity = $this->fuzzyMatch($declaredUniversity, $fullText);
        
        return [
            'found' => $similarity >= 60,
            'confidence' => $similarity,
            'match' => $similarity >= 70
        ];
    }
    
    /**
     * Extract academic year
     */
    private function extractAcademicYear($words) {
        $fullText = implode(' ', array_column($words, 'text'));
        
        // Pattern: 2024-2025, SY 2024-2025, A.Y. 2024-2025
        if (preg_match('/\b(\d{4})[-–]\s*(\d{4})\b/', $fullText, $matches)) {
            $year1 = $matches[1];
            $year2 = $matches[2];
            
            return [
                'raw' => "$year1-$year2",
                'confidence' => 90,
                'found' => true
            ];
        }
        
        return [
            'raw' => null,
            'confidence' => 0,
            'found' => false
        ];
    }
    
    /**
     * Extract student ID number
     */
    private function extractStudentId($words) {
        $fullText = implode(' ', array_column($words, 'text'));
        
        // Common patterns: ID No., Student No., etc.
        $patterns = [
            '/(?:ID|Student|Stud\.)\s*(?:No\.?|Number|#)?\s*:?\s*([A-Z0-9]{8,15})/i',
            '/\b([0-9]{4,6}[-\s]?[0-9]{4,6})\b/', // Dash or space separated
            '/\b(\d{8,15})\b/' // Pure numeric
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $fullText, $matches)) {
                return [
                    'raw' => trim($matches[1]),
                    'confidence' => 75,
                    'found' => true
                ];
            }
        }
        
        return [
            'raw' => null,
            'confidence' => 0,
            'found' => false
        ];
    }
    
    /**
     * Verify document type is enrollment/assessment form
     */
    private function verifyDocumentType($fullTextLower) {
        $keywords = [
            'enrollment', 'assessment', 'eaf', 'form', 'billing',
            'statement', 'certificate', 'registration', 'tuition'
        ];
        
        $foundCount = 0;
        foreach ($keywords as $keyword) {
            if (strpos($fullTextLower, $keyword) !== false) {
                $foundCount++;
            }
        }
        
        return [
            'is_enrollment_form' => $foundCount >= 2,
            'keywords_found' => $foundCount,
            'confidence' => min(100, $foundCount * 25)
        ];
    }
    
    /**
     * Fuzzy text matching with similarity score
     */
    private function fuzzyMatch($needle, $haystack) {
        $needle = strtolower(trim($needle));
        $haystack = strtolower($haystack);
        
        // Exact match
        if (strpos($haystack, $needle) !== false) {
            return 100;
        }
        
        // Split into words and check each
        $needleWords = preg_split('/\s+/', $needle);
        $matchedWords = 0;
        
        foreach ($needleWords as $word) {
            if (strlen($word) >= 3 && strpos($haystack, $word) !== false) {
                $matchedWords++;
            }
        }
        
        $wordMatchPercent = count($needleWords) > 0 ? ($matchedWords / count($needleWords)) * 100 : 0;
        
        // Levenshtein for short strings
        if (strlen($needle) <= 255) {
            $minDistance = PHP_INT_MAX;
            $haystackWords = preg_split('/\s+/', $haystack);
            
            foreach ($haystackWords as $hWord) {
                if (strlen($hWord) >= 3) {
                    $distance = levenshtein($needle, $hWord);
                    $minDistance = min($minDistance, $distance);
                }
            }
            
            if ($minDistance !== PHP_INT_MAX) {
                $levSimilarity = max(0, 100 - ($minDistance / strlen($needle)) * 100);
                return max($wordMatchPercent, $levSimilarity);
            }
        }
        
        return $wordMatchPercent;
    }
    
    /**
     * Calculate overall confidence score
     */
    private function calculateOverallConfidence($extracted) {
        $scores = [];
        
        if (isset($extracted['student_name']['confidence'])) {
            $scores[] = $extracted['student_name']['confidence'];
        }
        
        if (isset($extracted['course']['confidence'])) {
            $scores[] = $extracted['course']['confidence'];
        }
        
        if (isset($extracted['year_level']['confidence'])) {
            $scores[] = $extracted['year_level']['confidence'];
        }
        
        if (isset($extracted['university']['confidence'])) {
            $scores[] = $extracted['university']['confidence'];
        }
        
        if (isset($extracted['document_type']['confidence'])) {
            $scores[] = $extracted['document_type']['confidence'];
        }
        
        return empty($scores) ? 0 : round(array_sum($scores) / count($scores), 1);
    }
    
    /**
     * Verify if extracted data passes validation
     */
    private function verifyExtractedData($extracted, $studentData) {
        $checks = [
            $extracted['student_name']['first_name_found'] ?? false,
            $extracted['student_name']['last_name_found'] ?? false,
            $extracted['course']['found'] ?? false,
            $extracted['year_level']['found'] ?? false,
            $extracted['document_type']['is_enrollment_form'] ?? false
        ];
        
        $passedChecks = count(array_filter($checks));
        
        // Need at least 4 out of 5 checks to pass
        return $passedChecks >= 4;
    }
    
    /**
     * Error response helper
     */
    private function errorResponse($message) {
        return [
            'success' => false,
            'error' => $message,
            'data' => null
        ];
    }
}
?>
