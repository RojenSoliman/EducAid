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

if (!function_exists('validateGradeThreshold')) {
    function validateGradeThreshold($yearSection, $declaredYearName, $debug = false, $declaredTerm = '') {
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
    function validateStudentName($ocrText, $firstName, $lastName) {
        $ocrTextLower = strtolower($ocrText);
        $firstNameLower = strtolower($firstName);
        $lastNameLower = strtolower($lastName);

        $firstNameMatch = stripos($ocrTextLower, $firstNameLower) !== false;
        $lastNameMatch = stripos($ocrTextLower, $lastNameLower) !== false;

        return ($firstNameMatch && $lastNameMatch);
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
