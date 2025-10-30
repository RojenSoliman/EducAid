<?php
/**
 * Web-accessible page to create active session for currently logged-in student
 * Visit this page in your browser while logged in
 */
session_start();
include '../../config/database.php';
require_once '../../includes/SessionManager.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Session - EducAid</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-body p-4">
                        <h3 class="card-title mb-4">Create Active Session</h3>
                        
                        <?php
                        // Check if student is logged in
                        if (!isset($_SESSION['student_id'])) {
                            echo '<div class="alert alert-danger">';
                            echo '<i class="bi bi-x-circle me-2"></i>';
                            echo 'Error: No student logged in. Please <a href="../../unified_login.php">login first</a>.';
                            echo '</div>';
                        } else {
                            $student_id = $_SESSION['student_id'];
                            $session_id = session_id();
                            
                            echo '<div class="alert alert-info mb-3">';
                            echo '<strong>Student ID:</strong> ' . htmlspecialchars($student_id) . '<br>';
                            echo '<strong>Session ID:</strong> ' . htmlspecialchars(substr($session_id, 0, 20)) . '...<br>';
                            echo '</div>';
                            
                            // Check if session already exists
                            $checkQuery = pg_query_params($connection,
                                "SELECT * FROM student_active_sessions WHERE session_id = $1",
                                [$session_id]
                            );
                            
                            if (pg_num_rows($checkQuery) > 0) {
                                echo '<div class="alert alert-success">';
                                echo '<i class="bi bi-check-circle me-2"></i>';
                                echo 'Your session is already tracked!';
                                echo '</div>';
                                echo '<a href="student_settings.php#sessions" class="btn btn-primary w-100">';
                                echo '<i class="bi bi-gear me-2"></i>Go to Settings';
                                echo '</a>';
                            } else {
                                // Create the session
                                try {
                                    $sessionManager = new SessionManager($connection);
                                    $sessionManager->logLogin($student_id, $session_id, 'manual_create');
                                    
                                    echo '<div class="alert alert-success">';
                                    echo '<i class="bi bi-check-circle me-2"></i>';
                                    echo '<strong>Success!</strong> Your active session has been created.';
                                    echo '</div>';
                                    
                                    echo '<a href="student_settings.php#sessions" class="btn btn-primary w-100">';
                                    echo '<i class="bi bi-gear me-2"></i>View Active Sessions';
                                    echo '</a>';
                                } catch (Exception $e) {
                                    echo '<div class="alert alert-danger">';
                                    echo '<i class="bi bi-x-circle me-2"></i>';
                                    echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage());
                                    echo '</div>';
                                }
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</body>
</html>
