<?php
/**
 * Grade Validation Service
 * Handles per-subject grade validation using university-specific grading policies
 */

class GradeValidationService {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    /**
     * Check if a subject grade is passing for a specific university
     * 
     * For 1–5 grading scale: Subject passes if grade ≤ 3.00, fails if grade > 3.00
     * For 0–4 grading scale: Subject passes if grade ≥ 2.0 (varies by university)
     * For percentage scale: Subject passes if grade ≥ passing_percentage (e.g., 75%)
     * For letter grades: Uses letter_order array to determine passing threshold
     */
    public function isSubjectPassing($universityKey, $rawGrade) {
        try {
            // First try the PostgreSQL function
            $sql = "SELECT grading_is_passing(CAST(? AS TEXT), CAST(? AS TEXT)) AS is_passing";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$universityKey, $rawGrade]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['is_passing'] ?? false;
        } catch (Exception $e) {
            error_log("Grade validation error (trying fallback): " . $e->getMessage());
            
            // Fallback: Get policy manually and validate in PHP
            try {
                $sql = "SELECT scale_type, higher_is_better, passing_value FROM university_passing_policy WHERE university_key = ? AND is_active = TRUE";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$universityKey]);
                $policy = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$policy) {
                    error_log("No policy found for university: " . $universityKey);
                    return false;
                }
                
                // Convert grades to numbers
                $gradeNum = floatval($rawGrade);
                $passingNum = floatval($policy['passing_value']);
                
                // Apply logic based on scale type
                if ($policy['scale_type'] === 'NUMERIC_1_TO_5') {
                    // For 1-5 scale: pass if grade <= 3.00
                    $result = $gradeNum <= $passingNum;
                    error_log("Fallback validation: {$gradeNum} <= {$passingNum} = " . ($result ? 'PASS' : 'FAIL'));
                    return $result;
                } elseif ($policy['scale_type'] === 'NUMERIC_0_TO_4') {
                    // For 0-4 scale: pass if grade >= passing value
                    return $gradeNum >= $passingNum;
                }
                
                return false;
            } catch (Exception $e2) {
                error_log("Fallback validation also failed: " . $e2->getMessage());
                return false;
            }
        }
    }
    
    /**
     * Validate all subjects for an applicant
     * Returns eligibility status and list of failed subjects
     */
    public function validateApplicant($universityKey, $subjects) {
        $failedSubjects = [];
        $eligible = true;
        
        foreach ($subjects as $subject) {
            $subjectName = $subject['name'] ?? 'Unknown Subject';
            $rawGrade = $subject['rawGrade'] ?? '';
            $units = $subject['units'] ?? '';
            $confidence = $subject['confidence'] ?? 100;
            
            // Treat empty, low-confidence, or unrecognized grades as failing
            if (empty($rawGrade) || $confidence < 85) {
                $eligible = false;
                $confidenceNote = $confidence < 85 ? " (low OCR confidence: {$confidence}%)" : " (empty grade)";
                $failedSubjects[] = $subjectName . ': ' . $rawGrade . $confidenceNote;
                continue;
            }
            
            // Check if subject is passing
            if (!$this->isSubjectPassing($universityKey, $rawGrade)) {
                $eligible = false;
                $failedSubjects[] = $subjectName . ': ' . $rawGrade;
            }
        }
        
        return [
            'eligible' => $eligible,
            'failedSubjects' => $failedSubjects,
            'totalSubjects' => count($subjects),
            'passedSubjects' => count($subjects) - count($failedSubjects)
        ];
    }
    
    /**
     * Get university grading policy details
     */
    public function getUniversityGradingPolicy($universityKey) {
        try {
            $sql = "SELECT * FROM university_passing_policy WHERE university_key = ? AND is_active = TRUE";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$universityKey]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching grading policy: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Normalize grade string to handle common OCR artifacts
     */
    public function normalizeGrade($rawGrade) {
        if (empty($rawGrade)) {
            return '';
        }
        
        $grade = trim($rawGrade);
        
        // Common OCR fixes
        $grade = str_replace(',', '.', $grade); // 3,00 → 3.00
        $grade = preg_replace('/O(?=\d)/', '0', $grade); // O3 → 03
        $grade = preg_replace('/(?<=\d)O(?=\d|$)/', '0', $grade); // 3O → 30
        $grade = str_replace('S', '5', $grade); // S → 5 in numeric context
        $grade = rtrim($grade, '°'); // Remove trailing degree symbol
        
        // Remove extra whitespace
        $grade = preg_replace('/\s+/', ' ', $grade);
        
        return $grade;
    }
    
    /**
     * Validate grade format based on expected patterns
     */
    public function isValidGradeFormat($grade, $scaleType = 'NUMERIC_1_TO_5') {
        $grade = $this->normalizeGrade($grade);
        
        switch ($scaleType) {
            case 'NUMERIC_1_TO_5':
                return preg_match('/^[1-5](\.\d{1,2})?$/', $grade);
                
            case 'NUMERIC_0_TO_4':
                return preg_match('/^[0-4](\.\d{1,3})?$/', $grade);
                
            case 'PERCENT':
                return preg_match('/^\d{2,3}(\.\d{1,2})?$/', $grade) && 
                       floatval($grade) >= 0 && floatval($grade) <= 100;
                
            case 'LETTER':
                return preg_match('/^[A-D][+-]?|F|INC|DRP|W|NG|P$/i', $grade);
                
            default:
                return false;
        }
    }
}
?>