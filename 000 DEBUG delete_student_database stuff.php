<?php
/**
 * Complete Student Deletion Tool
 * Access via browser: http://localhost/EducAid/delete_student.php
 * 
 * This will completely remove a student and ALL associated data:
 * - Student record
 * - Documents (database + files)
 * - Notifications
 * - Audit logs
 * - OTP records
 * - Distribution records
 */

require_once __DIR__ . '/config/database.php';

// Security: Only allow from localhost
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    die('Access denied. This tool can only be run from localhost.');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Delete Student - EducAid</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { padding: 40px; background: #f8f9fa; }
        .container { max-width: 900px; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .student-card { border: 1px solid #dee2e6; padding: 20px; margin: 10px 0; border-radius: 8px; }
        .student-card:hover { background: #f8f9fa; cursor: pointer; }
        .danger-zone { background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 5px; }
        .delete-confirmation { background: #f8d7da; border: 2px solid #dc3545; padding: 20px; margin: 20px 0; border-radius: 5px; }
        .file-list { max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4"><i class="bi bi-trash3"></i> Delete Student Tool</h1>
        
<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    $student_id = trim($_POST['student_id']);
    $confirmation = trim($_POST['confirmation']);
    
    if ($confirmation !== 'DELETE') {
        echo '<div class="alert alert-danger">❌ Confirmation failed. You must type DELETE exactly.</div>';
    } else {
        echo '<div class="alert alert-info">🗑️ Deleting student and all associated data...</div>';
        
        // Get student info first
        $studentQuery = pg_query_params($connection, 
            "SELECT first_name, last_name, email FROM students WHERE student_id = $1",
            [$student_id]
        );
        
        if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
            echo '<div class="alert alert-danger">❌ Student not found!</div>';
        } else {
            $student = pg_fetch_assoc($studentQuery);
            $studentName = $student['first_name'] . ' ' . $student['last_name'];
            
            // Delete files from filesystem
            $uploadsPath = dirname(__DIR__) . '/assets/uploads/student';
            $documentTypes = ['enrollment_forms', 'grades', 'id_pictures', 'indigency', 'letter_mayor'];
            $deletedFiles = 0;
            
            foreach ($documentTypes as $type) {
                // OLD STRUCTURE: Delete files in flat folder
                $files = glob($uploadsPath . '/' . $type . '/' . $student_id . '_*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                        $deletedFiles++;
                    }
                }
                
                // NEW STRUCTURE: Delete entire student folder
                $studentFolder = $uploadsPath . '/' . $type . '/' . $student_id;
                if (is_dir($studentFolder)) {
                    $studentFiles = glob($studentFolder . '/*');
                    foreach ($studentFiles as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                            $deletedFiles++;
                        }
                    }
                    @rmdir($studentFolder);
                }
            }
            
            // Also check temp folder
            $tempPath = dirname(__DIR__) . '/assets/uploads/temp';
            foreach ($documentTypes as $type) {
                $tempFiles = glob($tempPath . '/' . $type . '/' . $student_id . '_*');
                foreach ($tempFiles as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                        $deletedFiles++;
                    }
                }
            }
            
            echo '<div class="alert alert-success">✅ Deleted ' . $deletedFiles . ' file(s) from filesystem</div>';
            
            // Delete from database in correct order
            $deletions = [
                'student_notifications' => 'Student notifications',
                'notifications' => 'General notifications',
                'documents' => 'Document records',
                'audit_log' => 'Audit log entries',
                'audit_logs' => 'Audit logs',
                'student_otp' => 'OTP records',
                'distributions' => 'Distribution records'
            ];
            
            $deletedRecords = [];
            
            foreach ($deletions as $table => $description) {
                // Check if table exists
                $tableCheck = pg_query($connection, 
                    "SELECT 1 FROM information_schema.tables 
                     WHERE table_name = '$table' LIMIT 1"
                );
                
                if (pg_num_rows($tableCheck) > 0) {
                    $result = pg_query_params($connection, 
                        "DELETE FROM $table WHERE student_id = $1",
                        [$student_id]
                    );
                    
                    if ($result) {
                        $count = pg_affected_rows($result);
                        if ($count > 0) {
                            $deletedRecords[$description] = $count;
                        }
                    }
                }
            }
            
            // Finally, delete the student record
            $deleteStudent = pg_query_params($connection,
                "DELETE FROM students WHERE student_id = $1",
                [$student_id]
            );
            
            if ($deleteStudent && pg_affected_rows($deleteStudent) > 0) {
                echo '<div class="alert alert-success">';
                echo '<h4>✅ Student Deleted Successfully!</h4>';
                echo '<p><strong>Student:</strong> ' . htmlspecialchars($studentName) . ' (' . htmlspecialchars($student['email']) . ')</p>';
                echo '<p><strong>ID:</strong> ' . htmlspecialchars($student_id) . '</p>';
                echo '<hr>';
                echo '<h5>Deleted Records:</h5>';
                echo '<ul>';
                foreach ($deletedRecords as $description => $count) {
                    echo '<li>' . $description . ': ' . $count . ' record(s)</li>';
                }
                echo '<li>Files deleted: ' . $deletedFiles . '</li>';
                echo '</ul>';
                echo '</div>';
                
                echo '<div class="alert alert-info">';
                echo '<h5>Next Steps:</h5>';
                echo '<p>The student can now register again with a fresh account:</p>';
                echo '<ol>';
                echo '<li>Student goes to registration page</li>';
                echo '<li>Fills out the registration form</li>';
                echo '<li>Uploads all required documents</li>';
                echo '<li>Waits for admin approval</li>';
                echo '</ol>';
                echo '</div>';
                
            } else {
                echo '<div class="alert alert-danger">❌ Failed to delete student record: ' . pg_last_error($connection) . '</div>';
            }
        }
    }
    
} else {
    // Show student selection
    $studentsQuery = "SELECT 
        student_id,
        first_name,
        last_name,
        email,
        status,
        needs_document_upload,
        documents_submitted,
        application_date
    FROM students 
    WHERE status = 'applicant'
    ORDER BY application_date DESC
    LIMIT 50";
    
    $result = pg_query($connection, $studentsQuery);
    
    if (!$result) {
        echo '<div class="alert alert-danger">Database error: ' . pg_last_error($connection) . '</div>';
        exit;
    }
    
    echo '<div class="alert alert-warning">';
    echo '<h5><i class="bi bi-exclamation-triangle"></i> Warning: Permanent Deletion</h5>';
    echo '<p>This tool will <strong>permanently delete</strong> a student and ALL associated data:</p>';
    echo '<ul>';
    echo '<li>Student account</li>';
    echo '<li>All uploaded documents (database + files)</li>';
    echo '<li>Notifications</li>';
    echo '<li>Audit logs</li>';
    echo '<li>OTP records</li>';
    echo '<li>Distribution records (if any)</li>';
    echo '</ul>';
    echo '<p class="mb-0"><strong>This cannot be undone!</strong> The student will need to register again.</p>';
    echo '</div>';
    
    echo '<h4 class="mt-4">Select Student to Delete:</h4>';
    echo '<p class="text-muted">Showing recent applicants (newest first)</p>';
    
    if (pg_num_rows($result) === 0) {
        echo '<div class="alert alert-info">No applicants found.</div>';
    } else {
        while ($student = pg_fetch_assoc($result)) {
            $student_id = $student['student_id'];
            $name = $student['first_name'] . ' ' . $student['last_name'];
            $email = $student['email'];
            $status = $student['status'];
            $needsUpload = ($student['needs_document_upload'] === 't');
            $submitted = ($student['documents_submitted'] === 't');
            $created = date('M d, Y g:i A', strtotime($student['application_date']));
            
            echo '<div class="student-card" onclick="selectStudent(\'' . htmlspecialchars($student_id, ENT_QUOTES) . '\', \'' . htmlspecialchars($name, ENT_QUOTES) . '\', \'' . htmlspecialchars($email, ENT_QUOTES) . '\')">';
            echo '<div class="d-flex justify-content-between align-items-start">';
            echo '<div>';
            echo '<h5 class="mb-1">' . htmlspecialchars($name) . '</h5>';
            echo '<p class="mb-1"><small class="text-muted">ID: ' . htmlspecialchars($student_id) . '</small></p>';
            echo '<p class="mb-1"><small>' . htmlspecialchars($email) . '</small></p>';
            echo '<p class="mb-0"><small class="text-muted">Registered: ' . $created . '</small></p>';
            echo '</div>';
            echo '<div class="text-end">';
            echo '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($status)) . '</span><br>';
            if ($needsUpload) {
                echo '<span class="badge bg-warning mt-1">Needs Upload</span><br>';
            }
            if ($submitted) {
                echo '<span class="badge bg-info mt-1">Submitted</span>';
            }
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
    }
    
    echo '<div id="deleteForm" style="display: none;" class="mt-4">';
    echo '<div class="delete-confirmation">';
    echo '<h4><i class="bi bi-exclamation-triangle-fill"></i> Confirm Deletion</h4>';
    echo '<form method="POST" onsubmit="return confirmDeletion();">';
    echo '<input type="hidden" name="student_id" id="student_id_input">';
    echo '<div class="mb-3">';
    echo '<strong>Student:</strong> <span id="selected_name"></span><br>';
    echo '<strong>Email:</strong> <span id="selected_email"></span><br>';
    echo '<strong>ID:</strong> <span id="selected_id"></span>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label class="form-label"><strong>Type DELETE to confirm:</strong></label>';
    echo '<input type="text" name="confirmation" class="form-control" required placeholder="Type DELETE here">';
    echo '</div>';
    echo '<div class="d-flex gap-2">';
    echo '<button type="submit" name="delete_student" class="btn btn-danger"><i class="bi bi-trash3"></i> Delete Permanently</button>';
    echo '<button type="button" onclick="cancelDelete()" class="btn btn-secondary">Cancel</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
}

?>
        
        <hr class="my-4">
        <a href="modules/admin/manage_applicants.php" class="btn btn-secondary">← Back to Manage Applicants</a>
    </div>
    
    <script>
    function selectStudent(id, name, email) {
        document.getElementById('student_id_input').value = id;
        document.getElementById('selected_name').textContent = name;
        document.getElementById('selected_email').textContent = email;
        document.getElementById('selected_id').textContent = id;
        document.getElementById('deleteForm').style.display = 'block';
        
        // Scroll to form
        document.getElementById('deleteForm').scrollIntoView({ behavior: 'smooth' });
    }
    
    function cancelDelete() {
        document.getElementById('deleteForm').style.display = 'none';
        document.getElementById('student_id_input').value = '';
    }
    
    function confirmDeletion() {
        const confirmation = confirm('⚠️ FINAL WARNING\n\nThis will permanently delete the student and ALL associated data.\n\nThis action CANNOT be undone!\n\nAre you absolutely sure?');
        return confirmation;
    }
    </script>
</body>
</html>
