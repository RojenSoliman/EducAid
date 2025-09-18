<?php
include __DIR__ . '/../../config/database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get all pending registrations and recalculate their confidence scores
    $query = "SELECT student_id FROM students WHERE status = 'under_registration'";
    $result = pg_query($connection, $query);
    
    if (!$result) {
        throw new Exception("Database error: " . pg_last_error($connection));
    }
    
    $updated_count = 0;
    
    while ($row = pg_fetch_assoc($result)) {
        $student_id = $row['student_id'];
        
        // Calculate new confidence score
        $updateQuery = "UPDATE students SET confidence_score = calculate_confidence_score($1) WHERE student_id = $1";
        $updateResult = pg_query_params($connection, $updateQuery, [$student_id]);
        
        if ($updateResult) {
            $updated_count++;
        }
    }
    
    echo json_encode([
        'success' => true, 
        'count' => $updated_count,
        'message' => "Successfully updated confidence scores for $updated_count registrations"
    ]);
    
} catch (Exception $e) {
    error_log("Confidence score refresh error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error refreshing confidence scores'
    ]);
}

pg_close($connection);
?>