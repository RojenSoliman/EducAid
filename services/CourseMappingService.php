<?php
/**
 * Course Mapping Service
 * Handles course name normalization using fuzzy matching
 * Maps raw OCR course text to standardized course names
 */

class CourseMappingService {
    private $connection;
    
    public function __construct($dbConnection) {
        $this->connection = $dbConnection;
    }
    
    /**
     * Find matching course from courses_mapping table
     * Uses pg_trgm for fuzzy matching
     * 
     * @param string $rawCourseName - Course name from OCR or user input
     * @return array|null - Matched course data or null if not found
     */
    public function findMatchingCourse($rawCourseName) {
        if (empty($rawCourseName)) {
            return null;
        }
        
        try {
            // First: Try exact match
            $exactQuery = "
                SELECT 
                    id,
                    normalized_course,
                    course_category,
                    program_duration_years,
                    occurrence_count,
                    similarity(normalized_course, $1) as similarity_score
                FROM courses_mapping
                WHERE LOWER(normalized_course) = LOWER($1)
                LIMIT 1
            ";
            
            $stmt = pg_prepare($this->connection, "exact_course_match", $exactQuery);
            $result = pg_execute($this->connection, "exact_course_match", [$rawCourseName]);
            
            if ($result && pg_num_rows($result) > 0) {
                $match = pg_fetch_assoc($result);
                $match['match_type'] = 'exact';
                $match['confidence'] = 100;
                
                // Update occurrence count
                $this->incrementOccurrenceCount($match['id']);
                
                return $match;
            }
            
            // Second: Fuzzy match using pg_trgm
            $fuzzyQuery = "
                SELECT 
                    id,
                    normalized_course,
                    course_category,
                    program_duration_years,
                    occurrence_count,
                    similarity(normalized_course, $1) as similarity_score
                FROM courses_mapping
                WHERE similarity(normalized_course, $1) > 0.3
                ORDER BY similarity_score DESC
                LIMIT 1
            ";
            
            $stmt = pg_prepare($this->connection, "fuzzy_course_match", $fuzzyQuery);
            $result = pg_execute($this->connection, "fuzzy_course_match", [$rawCourseName]);
            
            if ($result && pg_num_rows($result) > 0) {
                $match = pg_fetch_assoc($result);
                $match['match_type'] = 'fuzzy';
                $match['confidence'] = round($match['similarity_score'] * 100, 1);
                
                // Only accept if confidence >= 70%
                if ($match['confidence'] >= 70) {
                    $this->incrementOccurrenceCount($match['id']);
                    return $match;
                }
            }
            
            // Third: Try partial keyword matching
            $keywordMatch = $this->keywordMatch($rawCourseName);
            if ($keywordMatch) {
                $this->incrementOccurrenceCount($keywordMatch['id']);
                return $keywordMatch;
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Course mapping error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Keyword-based matching for common abbreviations
     */
    private function keywordMatch($rawCourseName) {
        $keywords = [
            'Computer Science' => ['CS', 'CompSci', 'Comp Sci', 'Computer Sci'],
            'Information Technology' => ['IT', 'InfoTech', 'Info Tech'],
            'Civil Engineering' => ['CE', 'CivEng'],
            'Electrical Engineering' => ['EE', 'ElecEng'],
            'Mechanical Engineering' => ['ME', 'MechEng'],
            'Electronics and Communications Engineering' => ['ECE', 'Electronics', 'Communications'],
            'Architecture' => ['Archi', 'Arch'],
            'Business Administration' => ['BA', 'BusAd', 'Business Ad'],
            'Accountancy' => ['Accounting', 'Acctg'],
            'Nursing' => ['Nurse', 'BSN']
        ];
        
        $rawLower = strtolower($rawCourseName);
        
        foreach ($keywords as $fullName => $abbrs) {
            foreach ($abbrs as $abbr) {
                if (stripos($rawLower, strtolower($abbr)) !== false) {
                    // Found keyword match, lookup full course
                    $query = "
                        SELECT 
                            id,
                            normalized_course,
                            course_category,
                            program_duration_years,
                            occurrence_count,
                            1.0 as similarity_score
                        FROM courses_mapping
                        WHERE normalized_course ILIKE $1
                        LIMIT 1
                    ";
                    
                    $stmt = pg_prepare($this->connection, "keyword_match_" . md5($fullName), $query);
                    $result = pg_execute($this->connection, "keyword_match_" . md5($fullName), ['%' . $fullName . '%']);
                    
                    if ($result && pg_num_rows($result) > 0) {
                        $match = pg_fetch_assoc($result);
                        $match['match_type'] = 'keyword';
                        $match['confidence'] = 80;
                        return $match;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Increment occurrence count for matched course
     */
    private function incrementOccurrenceCount($courseId) {
        try {
            $query = "
                UPDATE courses_mapping 
                SET occurrence_count = occurrence_count + 1,
                    last_used_at = CURRENT_TIMESTAMP
                WHERE id = $1
            ";
            
            $stmt = pg_prepare($this->connection, "increment_occurrence", $query);
            pg_execute($this->connection, "increment_occurrence", [$courseId]);
            
        } catch (Exception $e) {
            error_log("Failed to increment occurrence count: " . $e->getMessage());
        }
    }
    
    /**
     * Get all available courses
     */
    public function getAllCourses() {
        try {
            $query = "
                SELECT 
                    id,
                    normalized_course,
                    course_category,
                    program_duration_years,
                    occurrence_count
                FROM courses_mapping
                ORDER BY course_category, normalized_course
            ";
            
            $result = pg_query($this->connection, $query);
            
            if ($result) {
                $courses = [];
                while ($row = pg_fetch_assoc($result)) {
                    $courses[] = $row;
                }
                return $courses;
            }
            
            return [];
            
        } catch (Exception $e) {
            error_log("Failed to get courses: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get courses grouped by category
     */
    public function getCoursesByCategory() {
        try {
            $query = "
                SELECT 
                    course_category,
                    json_agg(
                        json_build_object(
                            'id', id,
                            'normalized_course', normalized_course,
                            'program_duration_years', program_duration_years,
                            'occurrence_count', occurrence_count
                        ) ORDER BY normalized_course
                    ) as courses
                FROM courses_mapping
                GROUP BY course_category
                ORDER BY course_category
            ";
            
            $result = pg_query($this->connection, $query);
            
            if ($result) {
                $grouped = [];
                while ($row = pg_fetch_assoc($result)) {
                    $grouped[$row['course_category']] = json_decode($row['courses'], true);
                }
                return $grouped;
            }
            
            return [];
            
        } catch (Exception $e) {
            error_log("Failed to get grouped courses: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Add new course mapping
     */
    public function addCourseMapping($normalizedCourse, $category, $durationYears) {
        try {
            $query = "
                INSERT INTO courses_mapping 
                    (normalized_course, course_category, program_duration_years)
                VALUES ($1, $2, $3)
                RETURNING id, normalized_course
            ";
            
            $stmt = pg_prepare($this->connection, "add_course", $query);
            $result = pg_execute($this->connection, "add_course", [
                $normalizedCourse,
                $category,
                $durationYears
            ]);
            
            if ($result && pg_num_rows($result) > 0) {
                return pg_fetch_assoc($result);
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Failed to add course: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get most commonly used courses
     */
    public function getMostUsedCourses($limit = 10) {
        try {
            $query = "
                SELECT 
                    id,
                    normalized_course,
                    course_category,
                    program_duration_years,
                    occurrence_count,
                    last_used_at
                FROM courses_mapping
                WHERE occurrence_count > 0
                ORDER BY occurrence_count DESC, last_used_at DESC
                LIMIT $1
            ";
            
            $stmt = pg_prepare($this->connection, "most_used_courses", $query);
            $result = pg_execute($this->connection, "most_used_courses", [$limit]);
            
            if ($result) {
                $courses = [];
                while ($row = pg_fetch_assoc($result)) {
                    $courses[] = $row;
                }
                return $courses;
            }
            
            return [];
            
        } catch (Exception $e) {
            error_log("Failed to get most used courses: " . $e->getMessage());
            return [];
        }
    }
}
?>
