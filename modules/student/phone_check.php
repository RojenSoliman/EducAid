<?php
include '../../db/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['phone'])) {
    $phone = htmlspecialchars(trim($_POST['phone']));
    
    // Validate phone format (should be 09XXXXXXXXX)
    if (!preg_match('/^09[0-9]{9}$/', $phone)) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Invalid phone number format.',
            'exists' => false
        ]);
        exit;
    }
    
    try {
        // Check if phone exists in database
        $checkPhone = pg_query_params($connection, "SELECT 1 FROM students WHERE mobile = $1", [$phone]);
        
        if ($checkPhone === false) {
            echo json_encode([
                'status' => 'error', 
                'message' => 'Database error occurred.',
                'exists' => false
            ]);
            exit;
        }
        
        $exists = pg_num_rows($checkPhone) > 0;
        
        echo json_encode([
            'status' => 'success',
            'exists' => $exists,
            'message' => $exists ? 'Phone number already registered' : 'Phone number available'
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Failed to validate phone number.',
            'exists' => false
        ]);
        exit;
    }
} else {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Invalid request.',
        'exists' => false
    ]);
}
?>