<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }
    
    // Validate required fields
    $required_fields = ['student_id', 'grading_system', 'grades'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field])) {
            echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
            exit;
        }
    }
    
    $student_id = filter_var($input['student_id'], FILTER_SANITIZE_STRING);
    $grading_system = filter_var($input['grading_system'], FILTER_SANITIZE_STRING);
    $grades = $input['grades'];
    
    // Validate grading system
    $valid_systems = ['percentage', 'gpa', 'dlsu_gpa', 'letter'];
    if (!in_array($grading_system, $valid_systems)) {
        echo json_encode(['success' => false, 'message' => 'Invalid grading system']);
        exit;
    }
    
    // Validate grades array
    if (!is_array($grades) || empty($grades)) {
        echo json_encode(['success' => false, 'message' => 'No grades provided']);
        exit;
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // First, delete any existing grades for this student during registration
        $delete_sql = "DELETE FROM student_grades WHERE student_id = ? AND source = 'registration'";
        $delete_stmt = $pdo->prepare($delete_sql);
        $delete_stmt->execute([$student_id]);
        
        // Insert new grades
        $insert_sql = "INSERT INTO student_grades (
            student_id, 
            subject_name, 
            grade_value, 
            grade_system, 
            units, 
            source, 
            semester,
            academic_year,
            created_at
        ) VALUES (?, ?, ?, ?, ?, 'registration', ?, ?, NOW())";
        
        $insert_stmt = $pdo->prepare($insert_sql);
        
        $current_year = date('Y');
        $current_month = date('n');
        
        // Determine semester based on current date
        if ($current_month >= 6 && $current_month <= 10) {
            $semester = '1st Semester';
            $academic_year = $current_year . '-' . ($current_year + 1);
        } else if ($current_month >= 11 || $current_month <= 3) {
            if ($current_month >= 11) {
                $academic_year = $current_year . '-' . ($current_year + 1);
            } else {
                $academic_year = ($current_year - 1) . '-' . $current_year;
            }
            $semester = '2nd Semester';
        } else {
            $semester = 'Summer';
            $academic_year = $current_year . '-' . ($current_year + 1);
        }
        
        $total_units = 0;
        $total_grade_points = 0;
        $grade_count = 0;
        
        foreach ($grades as $grade_data) {
            // Validate individual grade data
            if (!isset($grade_data['subject']) || !isset($grade_data['grade']) || !isset($grade_data['units'])) {
                throw new Exception('Invalid grade data structure');
            }
            
            $subject = filter_var($grade_data['subject'], FILTER_SANITIZE_STRING);
            $grade = filter_var($grade_data['grade'], FILTER_SANITIZE_STRING);
            $units = filter_var($grade_data['units'], FILTER_VALIDATE_INT);
            
            if (empty($subject) || empty($grade) || $units <= 0 || $units > 6) {
                throw new Exception('Invalid grade values');
            }
            
            // Convert grade to standard 4.0 GPA for calculation
            $grade_point = convertToGPA($grade, $grading_system);
            
            // Insert grade record
            $insert_stmt->execute([
                $student_id,
                $subject,
                $grade,
                $grading_system,
                $units,
                $semester,
                $academic_year
            ]);
            
            // Calculate totals for GPA
            $total_units += $units;
            $total_grade_points += ($grade_point * $units);
            $grade_count++;
        }
        
        // Calculate and store overall GPA
        $gpa = $total_units > 0 ? $total_grade_points / $total_units : 0;
        
        // Update or insert GPA summary
        $gpa_sql = "INSERT INTO student_gpa_summary (
            student_id, 
            semester, 
            academic_year, 
            total_units, 
            gpa, 
            grading_system,
            source,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'registration', NOW())
        ON CONFLICT (student_id, semester, academic_year, source) 
        DO UPDATE SET 
            total_units = EXCLUDED.total_units,
            gpa = EXCLUDED.gpa,
            grading_system = EXCLUDED.grading_system,
            updated_at = NOW()";
        
        $gpa_stmt = $pdo->prepare($gpa_sql);
        $gpa_stmt->execute([
            $student_id,
            $semester,
            $academic_year,
            $total_units,
            round($gpa, 2),
            $grading_system
        ]);
        
        // Commit transaction
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Grades saved successfully',
            'data' => [
                'grades_count' => $grade_count,
                'total_units' => $total_units,
                'calculated_gpa' => round($gpa, 2),
                'semester' => $semester,
                'academic_year' => $academic_year
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Save Registration Grades Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save grades: ' . $e->getMessage()
    ]);
}

/**
 * Convert different grading systems to 4.0 GPA scale
 */
function convertToGPA($grade, $system) {
    switch ($system) {
        case 'gpa':
            // Traditional 1.0-5.0 scale (1.0 = highest), convert to 4.0 scale
            $gpa_5_scale = min(max(floatval($grade), 1.0), 5.0);
            return max(0, 5.0 - $gpa_5_scale); // Convert to 4.0 scale
            
        case 'dlsu_gpa':
            // DLSU 4.0 scale (4.0 = 100%, 0.0 = failed)
            return min(max(floatval($grade), 0), 4.0);
            
        case 'letter':
            $letterToGPA = [
                'A+' => 4.0, 'A' => 4.0, 'A-' => 3.7,
                'B+' => 3.3, 'B' => 3.0, 'B-' => 2.7,
                'C+' => 2.3, 'C' => 2.0, 'C-' => 1.7,
                'D+' => 1.3, 'D' => 1.0, 'D-' => 0.7,
                'F' => 0.0
            ];
            return $letterToGPA[strtoupper($grade)] ?? 0.0;
            
        case 'percentage':
        default:
            $percentage = floatval($grade);
            if ($percentage >= 97) return 4.0;
            else if ($percentage >= 93) return 3.7;
            else if ($percentage >= 90) return 3.3;
            else if ($percentage >= 87) return 3.0;
            else if ($percentage >= 83) return 2.7;
            else if ($percentage >= 80) return 2.3;
            else if ($percentage >= 77) return 2.0;
            else if ($percentage >= 73) return 1.7;
            else if ($percentage >= 70) return 1.3;
            else if ($percentage >= 65) return 1.0;
            else if ($percentage >= 60) return 0.7;
            else return 0.0;
    }
}
?>