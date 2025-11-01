<?php
/**
 * Grade Validation Helper Functions
 * Extracted from student_register.php for reuse in document re-upload
 */

if (!function_exists('validateDeclaredYear')) {
    function validateDeclaredYear($ocrText, $declaredYearName, $adminSemester = '') {
        $match = false;
        $section = '';
        $confidence = 0;
        $error = '';

        $ocrTextLower = strtolower($ocrText);
        $declaredYearLower = strtolower($declaredYearName);

        // Enhanced year level patterns
        $yearPatterns = [
            '1st year' => ['1st year', 'first year', 'freshman', 'year 1', 'year i', 'level 1'],
            '2nd year' => ['2nd year', 'second year', 'sophomore', 'year 2', 'year ii', 'level 2'],
            '3rd year' => ['3rd year', 'third year', 'junior', 'year 3', 'year iii', 'level 3'],
            '4th year' => ['4th year', 'fourth year', 'senior', 'year 4', 'year iv', 'level 4'],
            '5th year' => ['5th year', 'fifth year', 'year 5', 'year v', 'level 5']
        ];

        $foundPattern = null;
        $foundPosition = PHP_INT_MAX;

        foreach ($yearPatterns as $year => $patterns) {
            if (stripos($declaredYearLower, $year) !== false) {
                foreach ($patterns as $pattern) {
                    $pos = stripos($ocrTextLower, $pattern);
                    if ($pos !== false && $pos < $foundPosition) {
                        $foundPattern = $pattern;
                        $foundPosition = $pos;
                        $match = true;
                        $confidence = 95;
                    }
                }
                break;
            }
        }

        if (!$match) {
            $error = "Year level '$declaredYearName' not found in document";
            return ['match' => false, 'section' => '', 'confidence' => 0, 'error' => $error];
        }

        // Extract section: text from found year pattern to end of document
        $section = substr($ocrText, $foundPosition);

        // If semester specified, try to narrow to that semester's section
        if (!empty($adminSemester)) {
            $semesterPatterns = [
                '1st semester' => 'first semester|1st semester|semester 1|sem 1',
                '2nd semester' => 'second semester|2nd semester|semester 2|sem 2',
                'summer' => 'summer|midyear'
            ];

            $adminSemLower = strtolower($adminSemester);
            if (isset($semesterPatterns[$adminSemLower])) {
                $regex = '/' . $semesterPatterns[$adminSemLower] . '/i';
                if (preg_match($regex, $section, $semMatch, PREG_OFFSET_CAPTURE)) {
                    $semStart = $semMatch[0][1];
                    $section = substr($section, $semStart);
                    $confidence = 98;
                }
            }
        }

        return [
            'match' => $match,
            'section' => $section,
            'confidence' => $confidence,
            'error' => $error
        ];
    }
}

if (!function_exists('normalize_and_extract_grade_student')) {
    function normalize_and_extract_grade_student(string $line): ?string {
        $line = trim($line);
        if (empty($line)) return null;

        // Remove common prefixes
        $line = preg_replace('/^(FINAL|MIDTERM|PRELIM|GRADE|RATING|SCORE)\s*[:=]?\s*/i', '', $line);

        // Extract numeric grade (1.0-5.0, 65-100, etc.)
        if (preg_match('/\b([1-5]\.\d{1,2})\b/', $line, $m)) {
            return $m[1]; // GWA format (1.00-5.00)
        }
        if (preg_match('/\b(100|[6-9]\d)\b/', $line, $m)) {
            return $m[1]; // Percentage format (65-100)
        }
        if (preg_match('/\b([1-5])\b/', $line, $m)) {
            return $m[1] . '.00'; // Integer grade -> decimal
        }

        return null;
    }
}

if (!function_exists('extractGradesFromTSV')) {
    /**
     * Extract grades from TSV file with structured data
     * TSV files have columns: level, page_num, block_num, par_num, line_num, word_num, left, top, width, height, conf, text
     * This is MUCH more accurate than regex parsing of OCR text
     * 
     * SECURITY: Validates university name and student name BEFORE extracting grades
     * to prevent students from uploading transcripts from other schools
     */
    function extractGradesFromTSV($tsvFilePath, $yearSection = '', $declaredYearName = '', $declaredTerm = '', $studentData = null) {
        if (!file_exists($tsvFilePath)) {
            return [
                'all_passing' => false,
                'grades' => [],
                'failing_grades' => [],
                'error' => 'TSV file not found - falling back to text parsing'
            ];
        }

        // Load TSV helper
        require_once __DIR__ . '/../../utils/TSVOCRHelper.php';
        
        $result = TSVOCRHelper::loadTSV($tsvFilePath);
        if (!$result['success']) {
            return [
                'all_passing' => false,
                'grades' => [],
                'failing_grades' => [],
                'error' => 'Failed to load TSV: ' . ($result['error'] ?? 'Unknown error')
            ];
        }

        $tsvData = $result['data'];
        $words = TSVOCRHelper::getWords($tsvData);
        
        // Reconstruct full OCR text from TSV for validation
        $fullOcrText = implode(' ', array_map(function($w) { return $w['text']; }, $words));
        
        // SECURITY CHECK 1: Validate University Name
        if (!empty($studentData['university_name'])) {
            $universityValidation = validateUniversity($fullOcrText, $studentData['university_name']);
            
            if (!$universityValidation['match']) {
                return [
                    'all_passing' => false,
                    'grades' => [],
                    'failing_grades' => [],
                    'error' => 'SECURITY: University name "' . $studentData['university_name'] . '" not found in document. This may be a fraudulent transcript.',
                    'security_failure' => 'university_mismatch',
                    'expected_university' => $studentData['university_name'],
                    'found_text' => $universityValidation['found_text']
                ];
            }
        }
        
        // SECURITY CHECK 2: Validate Student Name (First Name + Last Name)
        if (!empty($studentData['first_name']) && !empty($studentData['last_name'])) {
            $nameValidation = validateStudentName($fullOcrText, $studentData['first_name'], $studentData['last_name']);
            
            if (!$nameValidation) {
                return [
                    'all_passing' => false,
                    'grades' => [],
                    'failing_grades' => [],
                    'error' => 'SECURITY: Student name "' . $studentData['first_name'] . ' ' . $studentData['last_name'] . '" not found in document. This may be a fraudulent transcript.',
                    'security_failure' => 'name_mismatch',
                    'expected_name' => $studentData['first_name'] . ' ' . $studentData['last_name']
                ];
            }
        }
        
        // OPTIONAL CHECK 3: Validate Course (if provided from enrollment form)
        // This is NOT required - some universities don't show course on grades documents
        $courseValidation = [
            'checked' => false,
            'match' => false,
            'confidence' => 0,
            'found_text' => ''
        ];
        
        if (!empty($studentData['course_name'])) {
            $courseValidation = validateCourse($fullOcrText, $studentData['course_name']);
            
            // Log the result but DON'T fail validation if course not found
            // This is just for additional confidence/security when available
            if ($courseValidation['match']) {
                error_log("GRADES: Course validation PASSED - Found '{$courseValidation['found_text']}' matching '{$studentData['course_name']}'");
            } else {
                error_log("GRADES: Course validation SKIPPED - Course '{$studentData['course_name']}' not found (this is OK, some universities don't show it)");
            }
        }
        
        // Group words by line number to reconstruct lines
        $lines = [];
        foreach ($words as $word) {
            $lineKey = $word['page_num'] . '_' . $word['block_num'] . '_' . $word['par_num'] . '_' . $word['line_num'];
            if (!isset($lines[$lineKey])) {
                $lines[$lineKey] = [];
            }
            $lines[$lineKey][] = $word;
        }

        // Process each line to extract subject + grade pairs
        $validGrades = [];
        $failingGrades = [];
        
        // Two-column format detection:
        // Many transcripts have First Semester | Second Semester side-by-side
        // Grades appear at specific X positions (left column around x=50-150, right column around x=1090-1200)
        
        foreach ($lines as $lineKey => $lineWords) {
            // Sort words by position (left to right)
            usort($lineWords, function($a, $b) {
                return $a['left'] <=> $b['left'];
            });
            
            // Separate into left column (x < 900) and right column (x >= 900)
            $leftColumn = [];
            $rightColumn = [];
            
            foreach ($lineWords as $word) {
                if ($word['left'] < 900) {
                    $leftColumn[] = $word;
                } else {
                    $rightColumn[] = $word;
                }
            }
            
            // Process LEFT COLUMN (First Semester)
            if (!empty($leftColumn)) {
                $gradeData = extractGradeFromColumn($leftColumn);
                if ($gradeData) {
                    $validGrades[] = $gradeData;
                    if (!$gradeData['passing']) {
                        $failingGrades[] = $gradeData;
                    }
                }
            }
            
            // Process RIGHT COLUMN (Second Semester)
            if (!empty($rightColumn)) {
                $gradeData = extractGradeFromColumn($rightColumn);
                if ($gradeData) {
                    $validGrades[] = $gradeData;
                    if (!$gradeData['passing']) {
                        $failingGrades[] = $gradeData;
                    }
                }
            }
        }
        
        $allPassing = (count($validGrades) > 0 && count($failingGrades) === 0);
        
        return [
            'all_passing' => $allPassing,
            'grades' => $validGrades,
            'failing_grades' => $failingGrades,
            'course_validation' => $courseValidation  // Include course check results
        ];
    }
}

if (!function_exists('extractGradeFromColumn')) {
    /**
     * Extract grade and subject from a single column's words
     */
    function extractGradeFromColumn($columnWords) {
        $lineText = implode(' ', array_map(function($w) { return $w['text']; }, $columnWords));
        $lineTextLower = strtolower($lineText);
        
        // Skip header lines, empty lines, year/semester markers
        if (empty(trim($lineText)) || 
            strlen($lineText) < 5 ||
            preg_match('/^\s*(first|second|third|fourth|fifth|year|semester|page|student|total|earned|academic|non-academic|nothing|follows|credited|units)/i', $lineText)) {
            return null;
        }
        
        // Look for grade pattern: decimal (1.00-5.00)
        if (!preg_match('/\b([1-5]\.\d{1,2})\b/', $lineText, $gradeMatch)) {
            return null; // No valid grade found
        }
        
        $grade = $gradeMatch[1];
        $gradeFloat = floatval($grade);
        
        // Validate grade range (1.00-5.00 for GWA)
        if ($gradeFloat < 1.0 || $gradeFloat > 5.0) {
            return null;
        }
        
        // Extract subject name: look for substantial text AFTER the grade
        // Pattern: [grade] [year-code] [course-code] [subject name] [units]
        // Example: "1.25 A24-25 DCSNO6C Applications Development and 3"
        
        $gradePosInLine = strpos($lineText, $grade);
        $afterGrade = trim(substr($lineText, $gradePosInLine + strlen($grade)));
        
        // Remove year codes (A24-25, B23-24, a22-23, 822-23, etc.)
        $afterGrade = preg_replace('/\b[ABab]?\d{2,3}-\d{2}\b/', '', $afterGrade);
        
        // Remove course codes (DCSNO6C, ELECL4C, ITENO4C, etc.) - alphanumeric codes 4-12 chars
        $afterGrade = preg_replace('/\b[A-Z]{3,}[A-Z0-9]{1,}[A-Z*]?\b/', '', $afterGrade);
        
        // Remove standalone numbers (units, page numbers)
        $afterGrade = preg_replace('/\b\d+\b/', '', $afterGrade);
        
        // Remove special characters and extra whitespace
        $afterGrade = preg_replace('/[:\|]{1,}/', '', $afterGrade);
        $afterGrade = preg_replace('/\s+/', ' ', $afterGrade);
        $afterGrade = trim($afterGrade);
        
        // Need at least 3 characters for a valid subject name
        if (strlen($afterGrade) < 3) {
            return null;
        }
        
        // Determine if passing (1.00-3.00 is passing in GWA system)
        $isPassing = ($gradeFloat <= 3.0);
        
        return [
            'subject' => $afterGrade,
            'grade' => $grade,
            'passing' => $isPassing
        ];
    }
}

if (!function_exists('validateGradeThreshold')) {
    function validateGradeThreshold($yearSection, $declaredYearName, $debug = false, $declaredTerm = '', $tsvFilePath = null, $studentData = null) {
        // Try TSV parsing first if file is available
        if (!empty($tsvFilePath) && file_exists($tsvFilePath)) {
            $tsvResult = extractGradesFromTSV($tsvFilePath, $yearSection, $declaredYearName, $declaredTerm, $studentData);
            
            // Check for security failures
            if (isset($tsvResult['security_failure'])) {
                // Return the security error immediately - don't fall back to legacy parsing
                return $tsvResult;
            }
            
            // If TSV parsing succeeded, use those results
            if (count($tsvResult['grades']) > 0) {
                return $tsvResult;
            }
            // Otherwise fall through to legacy text parsing
        }
        
        // LEGACY: Fallback to text-based parsing
        $allPassing = false;
        $validGrades = [];
        $failingGrades = [];

        if (empty($yearSection)) {
            return [
                'all_passing' => false,
                'grades' => [],
                'failing_grades' => [],
                'error' => 'No year section provided for grade extraction'
            ];
        }

        $lines = explode("\n", $yearSection);
        $subjectPattern = '/^([A-Z][A-Za-z\s\-&,\.0-9]{3,60})\s+([\d\.]+)/';

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strlen($line) < 5) continue;

            if (preg_match($subjectPattern, $line, $matches)) {
                $subject = trim($matches[1]);
                $grade = trim($matches[2]);

                // Validate grade format
                $gradeFloat = floatval($grade);
                if ($gradeFloat >= 1.0 && $gradeFloat <= 5.0) {
                    // GWA format: 1.00-3.00 passing
                    $isPassing = ($gradeFloat <= 3.0);
                } elseif ($gradeFloat >= 65 && $gradeFloat <= 100) {
                    // Percentage: 75+ passing
                    $isPassing = ($gradeFloat >= 75);
                } else {
                    continue; // Invalid grade
                }

                $gradeData = [
                    'subject' => $subject,
                    'grade' => $grade,
                    'passing' => $isPassing
                ];

                $validGrades[] = $gradeData;
                if (!$isPassing) {
                    $failingGrades[] = $gradeData;
                }
            }
        }

        $allPassing = (count($validGrades) > 0 && count($failingGrades) === 0);

        return [
            'all_passing' => $allPassing,
            'grades' => $validGrades,
            'failing_grades' => $failingGrades
        ];
    }
}

if (!function_exists('validatePerSubjectGrades')) {
    function validatePerSubjectGrades($universityKey, $uploadedFile = null, $subjects = null) {
        global $connection;

        try {
            // Fetch grading system for this university
            $gradingQuery = pg_query_params($connection,
                "SELECT gs.*, u.name as university_name
                 FROM grading_systems gs
                 JOIN universities u ON gs.university_id = u.university_id
                 WHERE u.code = $1",
                [$universityKey]
            );

            if (!$gradingQuery || pg_num_rows($gradingQuery) === 0) {
                return [
                    'success' => false,
                    'eligible' => false,
                    'error' => 'No grading system found for university: ' . $universityKey
                ];
            }

            $gradingSystem = pg_fetch_assoc($gradingQuery);
            $passingGrade = floatval($gradingSystem['passing_grade']);
            $minGrade = floatval($gradingSystem['min_grade']);
            $maxGrade = floatval($gradingSystem['max_grade']);
            $isLowerBetter = ($gradingSystem['is_lower_better'] === 't' || $gradingSystem['is_lower_better'] === true);

            $failedSubjects = [];
            $totalSubjects = count($subjects);

            foreach ($subjects as $subject) {
                $rawGrade = $subject['rawGrade'] ?? $subject['grade'] ?? null;
                if ($rawGrade === null) continue;

                $numericGrade = floatval($rawGrade);

                // Check if grade is within valid range
                if ($numericGrade < $minGrade || $numericGrade > $maxGrade) {
                    $failedSubjects[] = $subject['name'] . ': ' . $rawGrade . ' (out of range)';
                    continue;
                }

                // Check passing status
                $isPassing = $isLowerBetter ? 
                    ($numericGrade <= $passingGrade) : 
                    ($numericGrade >= $passingGrade);

                if (!$isPassing) {
                    $failedSubjects[] = $subject['name'] . ': ' . $rawGrade;
                }
            }

            $eligible = (count($failedSubjects) === 0 && $totalSubjects > 0);

            return [
                'success' => true,
                'eligible' => $eligible,
                'failed_subjects' => $failedSubjects,
                'total_subjects' => $totalSubjects,
                'grading_system' => [
                    'university' => $gradingSystem['university_name'],
                    'passing_grade' => $passingGrade,
                    'range' => "$minGrade - $maxGrade",
                    'is_lower_better' => $isLowerBetter
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'eligible' => false,
                'error' => 'Validation error: ' . $e->getMessage()
            ];
        }
    }
}

if (!function_exists('validateUniversity')) {
    function validateUniversity($ocrText, $declaredUniversityName) {
        $match = false;
        $confidence = 0;
        $foundText = '';

        $ocrTextLower = strtolower($ocrText);
        $declaredLower = strtolower($declaredUniversityName);

        // Extract key words from university name
        $keyWords = preg_split('/\s+/', $declaredLower);
        $keyWords = array_filter($keyWords, function($word) {
            return strlen($word) > 3 && !in_array($word, ['the', 'and', 'of', 'for', 'university', 'college']);
        });

        $matchedWords = 0;
        foreach ($keyWords as $word) {
            if (stripos($ocrTextLower, $word) !== false) {
                $matchedWords++;
                $foundText .= $word . ' ';
            }
        }

        if ($matchedWords >= max(1, count($keyWords) * 0.6)) {
            $match = true;
            $confidence = min(95, 60 + ($matchedWords * 10));
        }

        return [
            'match' => $match,
            'confidence' => $confidence,
            'found_text' => trim($foundText)
        ];
    }
}

if (!function_exists('validateStudentName')) {
    /**
     * Validate student name in OCR text
     * Returns detailed match information for both first name and last name
     * 
     * @param string $ocrText Full OCR text to search in
     * @param string $firstName Expected first name
     * @param string $lastName Expected last name
     * @param bool $returnDetails If true, returns array with detailed info. If false, returns simple boolean
     * @return bool|array Boolean match result OR array with detailed confidence scores
     */
    function validateStudentName($ocrText, $firstName, $lastName, $returnDetails = false) {
        $ocrTextLower = strtolower($ocrText);
        $firstNameLower = strtolower($firstName);
        $lastNameLower = strtolower($lastName);

        // Check for exact matches
        $firstNameMatch = stripos($ocrTextLower, $firstNameLower) !== false;
        $lastNameMatch = stripos($ocrTextLower, $lastNameLower) !== false;
        
        // Calculate confidence scores (95% for match, 0% for no match)
        $firstNameConfidence = $firstNameMatch ? 95 : 0;
        $lastNameConfidence = $lastNameMatch ? 95 : 0;
        
        // Find matched text snippets
        $firstNameSnippet = '';
        $lastNameSnippet = '';
        
        if ($firstNameMatch) {
            $pattern = '/\b\w*' . preg_quote(substr($firstName, 0, min(3, strlen($firstName))), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $firstNameSnippet = $matches[0];
            }
        }
        
        if ($lastNameMatch) {
            $pattern = '/\b\w*' . preg_quote(substr($lastName, 0, min(3, strlen($lastName))), '/') . '\w*\b/i';
            if (preg_match($pattern, $ocrText, $matches)) {
                $lastNameSnippet = $matches[0];
            }
        }
        
        $overallMatch = ($firstNameMatch && $lastNameMatch);
        
        if ($returnDetails) {
            return [
                'match' => $overallMatch,
                'first_name_match' => $firstNameMatch,
                'last_name_match' => $lastNameMatch,
                'confidence_scores' => [
                    'first_name' => $firstNameConfidence,
                    'last_name' => $lastNameConfidence,
                    'name' => $overallMatch ? 95 : 0
                ],
                'found_text_snippets' => [
                    'first_name' => $firstNameSnippet,
                    'last_name' => $lastNameSnippet
                ]
            ];
        }
        
        return $overallMatch;
    }
}

if (!function_exists('validateCourse')) {
    /**
     * Validate course name in OCR text (OPTIONAL check - not required)
     * Aligns with course scanned from enrollment form
     * 
     * @param string $ocrText Full OCR text to search in
     * @param string $courseName Expected course name from enrollment form
     * @return array Match result with confidence and found text
     */
    function validateCourse($ocrText, $courseName) {
        $match = false;
        $confidence = 0;
        $foundText = '';

        if (empty($courseName)) {
            return [
                'checked' => false,
                'match' => false,
                'confidence' => 0,
                'found_text' => 'No course to validate'
            ];
        }

        $ocrTextLower = strtolower($ocrText);
        $courseNameLower = strtolower($courseName);

        // Extract key words from course name
        // Skip common words like "Bachelor", "Science", "of", "in", "the"
        $stopWords = ['bachelor', 'bachelors', 'master', 'masters', 'of', 'in', 'the', 'and', 'science', 'sciences', 'arts', 'art'];
        
        $courseWords = preg_split('/\s+/', $courseNameLower);
        $keyWords = array_filter($courseWords, function($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });

        // Look for exact full course name match first
        if (stripos($ocrTextLower, $courseNameLower) !== false) {
            $match = true;
            $confidence = 95;
            $foundText = $courseName;
        } else {
            // Look for key words from course name (e.g., "Computer", "Engineering", "Nursing")
            $matchedWords = 0;
            $foundWords = [];
            
            foreach ($keyWords as $word) {
                if (stripos($ocrTextLower, $word) !== false) {
                    $matchedWords++;
                    $foundWords[] = $word;
                }
            }

            // Calculate match percentage
            $totalKeyWords = count($keyWords);
            if ($totalKeyWords > 0 && $matchedWords > 0) {
                $matchRatio = $matchedWords / $totalKeyWords;
                
                // Require at least 60% of key words for a match
                if ($matchRatio >= 0.6) {
                    $match = true;
                    $confidence = round(60 + ($matchRatio * 35)); // 60-95% confidence
                    $foundText = implode(' ', $foundWords);
                }
            }
        }

        return [
            'checked' => true,
            'match' => $match,
            'confidence' => $confidence,
            'found_text' => $foundText,
            'expected_course' => $courseName
        ];
    }
}

if (!function_exists('validateSchoolStudentId')) {
    function validateSchoolStudentId($ocrText, $schoolStudentId) {
        $match = false;
        $confidence = 0;
        $foundText = '';

        if (empty($schoolStudentId)) {
            return ['match' => true, 'confidence' => 100, 'found_text' => 'No ID to validate'];
        }

        // Remove common separators for comparison
        $normalizedId = preg_replace('/[\s\-_]/', '', $schoolStudentId);
        $normalizedOcr = preg_replace('/[\s\-_]/', '', $ocrText);

        // Try exact match
        if (stripos($normalizedOcr, $normalizedId) !== false) {
            $match = true;
            $confidence = 100;
            $foundText = $schoolStudentId;
            return ['match' => $match, 'confidence' => $confidence, 'found_text' => $foundText];
        }

        // Try partial match (at least 70% of digits match)
        $idDigits = preg_replace('/\D/', '', $schoolStudentId);
        if (strlen($idDigits) >= 4) {
            $ocrDigits = preg_replace('/\D/', '', $ocrText);
            
            // Check if most ID digits appear in order
            $matchCount = 0;
            $lastPos = 0;
            for ($i = 0; $i < strlen($idDigits); $i++) {
                $digit = $idDigits[$i];
                $pos = strpos($ocrDigits, $digit, $lastPos);
                if ($pos !== false) {
                    $matchCount++;
                    $lastPos = $pos + 1;
                }
            }
            
            $matchRatio = $matchCount / strlen($idDigits);
            if ($matchRatio >= 0.7) {
                $match = true;
                $confidence = round($matchRatio * 90);
                $foundText = "Partial match: " . $matchCount . "/" . strlen($idDigits) . " digits";
            }
        }

        return [
            'match' => $match,
            'confidence' => $confidence,
            'found_text' => $foundText
        ];
    }
}

if (!function_exists('validateAdminSemester')) {
    function validateAdminSemester($ocrText, $adminSemester) {
        $match = false;
        $confidence = 0;
        $foundText = '';

        if (empty($adminSemester)) {
            return ['match' => true, 'confidence' => 100, 'found_text' => 'No semester requirement'];
        }

        $ocrTextLower = strtolower($ocrText);
        $adminSemLower = strtolower($adminSemester);

        $semesterPatterns = [
            '1st semester' => ['first semester', '1st semester', 'semester 1', 'sem 1', '1st sem'],
            '2nd semester' => ['second semester', '2nd semester', 'semester 2', 'sem 2', '2nd sem'],
            'summer' => ['summer', 'midyear', 'summer term', 'summer semester']
        ];

        if (isset($semesterPatterns[$adminSemLower])) {
            foreach ($semesterPatterns[$adminSemLower] as $pattern) {
                if (stripos($ocrTextLower, $pattern) !== false) {
                    $match = true;
                    $confidence = 95;
                    $foundText = $pattern;
                    break;
                }
            }
        }

        return [
            'match' => $match,
            'confidence' => $confidence,
            'found_text' => $foundText
        ];
    }
}

if (!function_exists('validateAdminSchoolYear')) {
    function validateAdminSchoolYear($ocrText, $adminSchoolYear) {
        $match = false;
        $confidence = 0;
        $foundText = '';

        if (empty($adminSchoolYear)) {
            return ['match' => true, 'confidence' => 100, 'found_text' => 'No school year requirement'];
        }

        // Extract years from admin requirement (e.g., "2024-2025" -> [2024, 2025])
        if (preg_match('/(\d{4})\s*-\s*(\d{4})/', $adminSchoolYear, $matches)) {
            $year1 = $matches[1];
            $year2 = $matches[2];

            // Check if both years appear in OCR text
            if (stripos($ocrText, $year1) !== false && stripos($ocrText, $year2) !== false) {
                $match = true;
                $confidence = 95;
                $foundText = "$year1-$year2";
            } elseif (stripos($ocrText, $year1) !== false || stripos($ocrText, $year2) !== false) {
                $match = true;
                $confidence = 75;
                $foundText = "Partial: found " . (stripos($ocrText, $year1) !== false ? $year1 : $year2);
            }
        }

        return [
            'match' => $match,
            'confidence' => $confidence,
            'found_text' => $foundText
        ];
    }
}
