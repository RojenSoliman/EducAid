<?php 
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
include_once __DIR__ . '/../../includes/workflow_control.php';

// Check admin authentication
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Check workflow prerequisites
$workflow_status = getWorkflowStatus($connection);
if (!$workflow_status['has_payroll_qr']) {
    $_SESSION['error_message'] = "Cannot access QR scanner. Please generate payroll numbers and QR codes first in the Verify Students page.";
    header("Location: verify_students.php?error=no_payroll");
    exit;
}

// ADDITIONAL CHECK: Ensure there are actual students with payroll and QR codes
$student_check_query = "
    SELECT COUNT(*) as count 
    FROM students s 
    INNER JOIN qr_codes q ON s.student_id = q.student_id 
    WHERE s.status IN ('active', 'given') AND s.payroll_no IS NOT NULL
";
$student_check_result = pg_query($connection, $student_check_query);
$student_count = 0;
if ($student_check_result) {
    $student_data = pg_fetch_assoc($student_check_result);
    $student_count = intval($student_data['count']);
}

if ($student_count === 0) {
    $_SESSION['error_message'] = "No students found with payroll numbers and QR codes. Please verify students and generate payroll/QR codes first.";
    header("Location: verify_students.php");
    exit;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_distribution_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Payroll Number', 'Student Name', 'Student ID', 'Status', 'Distribution Date']);
    
    $csv_query = "
        SELECT s.payroll_no, s.first_name, s.middle_name, s.last_name, 
               s.student_id, s.status, dsr.scanned_at as date_given
        FROM students s
        LEFT JOIN distribution_student_records dsr ON s.student_id = dsr.student_id
        WHERE s.status IN ('active', 'given') AND s.payroll_no IS NOT NULL
        ORDER BY s.payroll_no ASC
    ";
    
    $csv_result = pg_query($connection, $csv_query);
    while ($row = pg_fetch_assoc($csv_result)) {
        $full_name = trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']);
        $distribution_date = $row['date_given'] ? date('Y-m-d', strtotime($row['date_given'])) : '';
        fputcsv($output, [
            $row['payroll_no'],
            $full_name,
            $row['student_id'],
            ucfirst($row['status']),
            $distribution_date
        ]);
    }
    
    fclose($output);
    exit;
}

// Handle Complete Distribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_distribution'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('complete_distribution', $token)) {
        $_SESSION['error_message'] = 'Security validation failed. Please refresh and try again.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $password = $_POST['admin_password'] ?? '';
    $location = $_POST['distribution_location'] ?? '';
    $notes = $_POST['distribution_notes'] ?? '';
    $academic_year = trim($_POST['academic_year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    
    if (empty($password) || empty($location)) {
        $_SESSION['error_message'] = 'Password and location are required.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Verify admin password
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id) {
        $username = $_SESSION['admin_username'] ?? null;
        if ($username) {
            $admin_lookup = pg_query_params($connection, "SELECT admin_id FROM admins WHERE username = $1", [$username]);
            if ($admin_lookup && pg_num_rows($admin_lookup) > 0) {
                $admin_data_lookup = pg_fetch_assoc($admin_lookup);
                $admin_id = $admin_data_lookup['admin_id'];
                $_SESSION['admin_id'] = $admin_id;
            }
        }
        if (!$admin_id) {
            $_SESSION['error_message'] = 'Admin session invalid.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    
    $password_check = pg_query_params($connection, "SELECT password FROM admins WHERE admin_id = $1", [$admin_id]);
    if (!$password_check || pg_num_rows($password_check) === 0) {
        $_SESSION['error_message'] = 'Admin not found.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $admin_data = pg_fetch_assoc($password_check);
    if (!password_verify($password, $admin_data['password'])) {
        $_SESSION['error_message'] = 'Incorrect password.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // CRITICAL: Check if any students have been scanned (status = 'given')
    $scanned_check = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE status = 'given'");
    $scanned_count = 0;
    if ($scanned_check) {
        $scanned_data = pg_fetch_assoc($scanned_check);
        $scanned_count = intval($scanned_data['count']);
    }
    
    if ($scanned_count === 0) {
        $_SESSION['error_message'] = 'Cannot complete distribution. You must scan at least one student QR code before completing the distribution.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    try {
        pg_query($connection, "BEGIN");
        
        error_log("=== Complete Distribution Transaction Started ===");
        error_log("Admin ID: $admin_id");
        error_log("Location: $location");
        error_log("Academic Year: $academic_year");
        error_log("Semester: $semester");
        
        // Get distribution data
        $students_query = "
            SELECT s.student_id, s.payroll_no, s.first_name, s.last_name, s.email, s.mobile,
                   b.name as barangay, u.name as university, yl.name as year_level,
                   dsr.scanned_at as date_given
            FROM students s
            LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
            LEFT JOIN universities u ON s.university_id = u.university_id
            LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
            LEFT JOIN distribution_student_records dsr ON s.student_id = dsr.student_id
            WHERE s.status = 'given'
            ORDER BY s.payroll_no
        ";
        
        error_log("Executing students query...");
        $students_result = pg_query($connection, $students_query);
        if (!$students_result) {
            $error = pg_last_error($connection);
            error_log("Students query failed: " . $error);
            throw new Exception("Failed to query students data: " . $error);
        }
        error_log("Students query succeeded");
        
        $schedules_query = "SELECT schedule_id, student_id, payroll_no, batch_no, distribution_date, time_slot, location, status FROM schedules";
        
        error_log("Executing schedules query...");
        $schedules_result = pg_query($connection, $schedules_query);
        if (!$schedules_result) {
            $error = pg_last_error($connection);
            error_log("Schedules query failed: " . $error);
            throw new Exception("Failed to query schedules data: " . $error);
        }
        error_log("Schedules query succeeded");
        
        $students_data = [];
        $schedules_data = [];
        $total_students = 0;
        
        while ($row = pg_fetch_assoc($students_result)) {
            $students_data[] = $row;
            $total_students++;
        }
        
        while ($row = pg_fetch_assoc($schedules_result)) {
            $schedules_data[] = $row;
        }
        
        error_log("Total students collected: $total_students");
        
        // Get active slot info
        error_log("Querying signup slots...");
        $slot_query = "SELECT slot_id, academic_year, semester FROM signup_slots WHERE is_active = true LIMIT 1";
        $slot_result = pg_query($connection, $slot_query);
        if (!$slot_result) {
            $error = pg_last_error($connection);
            error_log("Signup slots query failed: " . $error);
            throw new Exception("Failed to query signup slots: " . $error);
        }
        $slot_data = pg_fetch_assoc($slot_result);
        error_log("Signup slots query succeeded. Active slot: " . ($slot_data ? "Yes" : "No"));
        
        if (empty($academic_year) && $slot_data) $academic_year = $slot_data['academic_year'] ?? '';
        if (empty($semester) && $slot_data) $semester = $slot_data['semester'] ?? '';
        
        // Fallback to config
        if (empty($academic_year) || empty($semester)) {
            error_log("Querying config for academic year/semester...");
            $cfg_result = pg_query($connection, "SELECT key, value FROM config WHERE key IN ('current_academic_year','current_semester')");
            if (!$cfg_result) {
                $error = pg_last_error($connection);
                error_log("Config query failed: " . $error);
                throw new Exception("Failed to query config: " . $error);
            }
            while ($cfg = pg_fetch_assoc($cfg_result)) {
                if ($cfg['key'] === 'current_academic_year' && empty($academic_year)) $academic_year = $cfg['value'];
                if ($cfg['key'] === 'current_semester' && empty($semester)) $semester = $cfg['value'];
            }
            error_log("Config query succeeded");
        }
        
        error_log("Final academic period: $academic_year $semester");
        
        // Check if snapshot already exists for this academic period
        error_log("Checking for existing snapshot...");
        $check_snapshot = pg_query_params($connection, 
            "SELECT snapshot_id FROM distribution_snapshots WHERE academic_year = $1 AND semester = $2",
            [$academic_year, $semester]
        );
        
        if (!$check_snapshot) {
            $error = pg_last_error($connection);
            error_log("Snapshot check query failed: " . $error);
            throw new Exception("Failed to check existing snapshot: " . $error);
        }
        
        $snapshot_exists = pg_num_rows($check_snapshot) > 0;
        error_log("Snapshot exists: " . ($snapshot_exists ? "Yes" : "No"));
        
        // Generate distribution ID for archive linking
        $municipality_name = 'GENERALTRIAS'; // Can be fetched from config
        $distribution_id = $municipality_name . '-DISTR-' . date('Y-m-d-His');
        $archive_filename = $distribution_id . '.zip';
        
        error_log("Distribution ID: $distribution_id");
        
        if ($snapshot_exists) {
            // Update existing snapshot instead of creating a new one
            error_log("Updating existing snapshot...");
            $existing_snapshot = pg_fetch_assoc($check_snapshot);
            $snapshot_query = "
                UPDATE distribution_snapshots 
                SET distribution_date = $1, 
                    location = $2, 
                    total_students_count = $3, 
                    active_slot_id = $4,
                    finalized_by = $5, 
                    notes = $6,
                    schedules_data = $7, 
                    students_data = $8,
                    distribution_id = $9,
                    archive_filename = $10
                WHERE snapshot_id = $11
            ";
            
            $snapshot_result = pg_query_params($connection, $snapshot_query, [
                date('Y-m-d'), $location, $total_students, $slot_data['slot_id'] ?? null,
                $admin_id, $notes,
                json_encode($schedules_data), json_encode($students_data),
                $distribution_id, $archive_filename,
                $existing_snapshot['snapshot_id']
            ]);
            
            if (!$snapshot_result) {
                $error = pg_last_error($connection);
                error_log("Snapshot update failed: " . $error);
                throw new Exception("Failed to update distribution snapshot: " . $error);
            }
            error_log("Snapshot update succeeded");
        } else {
            // Create new snapshot
            error_log("Creating new snapshot...");
            $snapshot_query = "
                INSERT INTO distribution_snapshots 
                (distribution_date, location, total_students_count, active_slot_id, academic_year, semester, 
                 finalized_by, notes, schedules_data, students_data, distribution_id, archive_filename)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)
            ";
            
            $snapshot_result = pg_query_params($connection, $snapshot_query, [
                date('Y-m-d'), $location, $total_students, $slot_data['slot_id'] ?? null,
                $academic_year, $semester, $admin_id, $notes,
                json_encode($schedules_data), json_encode($students_data),
                $distribution_id, $archive_filename
            ]);
            
            if (!$snapshot_result) {
                $error = pg_last_error($connection);
                error_log("Snapshot insert failed: " . $error);
                throw new Exception("Failed to create distribution snapshot: " . $error);
            }
            error_log("Snapshot insert succeeded");
        }
        
        // Get the snapshot_id we just created/updated
        $snapshot_id_query = "SELECT snapshot_id FROM distribution_snapshots WHERE academic_year = $1 AND semester = $2 LIMIT 1";
        $snapshot_id_result = pg_query_params($connection, $snapshot_id_query, [$academic_year, $semester]);
        $final_snapshot_id = null;
        if ($snapshot_id_result && pg_num_rows($snapshot_id_result) > 0) {
            $snapshot_row = pg_fetch_assoc($snapshot_id_result);
            $final_snapshot_id = $snapshot_row['snapshot_id'];
            error_log("Final snapshot ID: $final_snapshot_id");
        }
        
        // CRITICAL: Backfill distribution_student_records for students with 'given' status
        // This ensures the snapshot has student records even if QR codes weren't scanned yet
        if ($final_snapshot_id) {
            error_log("Backfilling distribution_student_records...");
            
            // For each student with 'given' status, ensure they have a record
            $backfill_query = "
                INSERT INTO distribution_student_records 
                (snapshot_id, student_id, scanned_by, verification_method, notes)
                SELECT $1, student_id, $2, 'manual_completion', 'Added during Complete Distribution'
                FROM students
                WHERE status = 'given'
                ON CONFLICT (snapshot_id, student_id) DO NOTHING
            ";
            
            $backfill_result = pg_query_params($connection, $backfill_query, [$final_snapshot_id, $admin_id]);
            
            if (!$backfill_result) {
                error_log("Warning: Failed to backfill distribution records: " . pg_last_error($connection));
            } else {
                $backfilled_count = pg_affected_rows($backfill_result);
                error_log("Backfilled $backfilled_count student record(s) into distribution_student_records");
            }
        }
        
        // AUTO-CLOSE ACTIVE SLOTS when distribution is completed
        // This prevents new registrations after distribution is finalized
        $close_slots_query = "UPDATE signup_slots SET is_active = FALSE WHERE is_active = TRUE";
        $close_slots_result = pg_query($connection, $close_slots_query);
        
        $closed_slots_count = 0;
        if (!$close_slots_result) {
            error_log("Warning: Failed to auto-close slots: " . pg_last_error($connection));
        } else {
            $closed_slots_count = pg_affected_rows($close_slots_result);
            error_log("Auto-closed $closed_slots_count active slot(s) after distribution completion");
        }
        
        pg_query($connection, "COMMIT");
        $action_type = $snapshot_exists ? 'updated' : 'created';
        $slot_message = ($closed_slots_count > 0) ? " Active signup slots have been automatically closed." : "";
        $_SESSION['success_message'] = "Distribution snapshot $action_type successfully! Recorded $total_students students for " . 
            trim($academic_year . ' ' . ($semester ?? '')) . ". You can now proceed to End Distribution when ready." . $slot_message;
    } catch (Exception $e) {
        pg_query($connection, "ROLLBACK");
        $error_details = $e->getMessage() . " | Line: " . $e->getLine();
        error_log("Complete Distribution Error: " . $error_details);
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    }
    
    // Log additional debug info if failed
    if (isset($_SESSION['error_message']) && strpos($_SESSION['error_message'], 'Failed to complete') !== false) {
        $pg_error = pg_last_error($connection);
        error_log("PostgreSQL Error: " . $pg_error);
        $_SESSION['error_message'] .= " (Check logs for details)";
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle QR scan confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_distribution'])) {
  $token = $_POST['csrf_token'] ?? '';
  if (!CSRFProtection::validateToken('confirm_distribution', $token)) {
    http_response_code(400);
    echo json_encode([
      'success' => false,
      'message' => 'Security validation failed. Please refresh the page and try again.',
      'next_token' => CSRFProtection::generateToken('confirm_distribution')
    ]);
    exit;
  }

  $student_id = $_POST['student_id'];
  $admin_id = $_SESSION['admin_id'] ?? 1;
    
    try {
        // Start transaction
        pg_query($connection, "BEGIN");
        
        // Get current active snapshot (or create a temporary one)
        $snapshot_query = "
            SELECT snapshot_id, academic_year, semester 
            FROM distribution_snapshots 
            WHERE finalized_at IS NULL OR finalized_at >= CURRENT_DATE - INTERVAL '7 days'
            ORDER BY finalized_at DESC NULLS FIRST
            LIMIT 1
        ";
        $snapshot_result = pg_query($connection, $snapshot_query);
        $snapshot_id = null;
        
        if ($snapshot_result && pg_num_rows($snapshot_result) > 0) {
            $snapshot = pg_fetch_assoc($snapshot_result);
            $snapshot_id = $snapshot['snapshot_id'];
        } else {
            // Create a temporary snapshot for ongoing distribution
            $temp_snapshot_query = "
                INSERT INTO distribution_snapshots 
                (distribution_date, location, total_students_count, academic_year, semester, finalized_by, notes, distribution_id)
                VALUES (CURRENT_DATE, 'Ongoing', 0, 
                    (SELECT value FROM config WHERE key = 'current_academic_year'),
                    (SELECT value FROM config WHERE key = 'current_semester'),
                    $1, 'Auto-created during QR scanning', 
                    'TEMP-' || TO_CHAR(NOW(), 'YYYY-MM-DD-HH24MISS'))
                RETURNING snapshot_id
            ";
            $temp_result = pg_query_params($connection, $temp_snapshot_query, [$admin_id]);
            if ($temp_result) {
                $temp_row = pg_fetch_assoc($temp_result);
                $snapshot_id = $temp_row['snapshot_id'];
                error_log("Created temporary distribution snapshot: $snapshot_id");
            }
        }
        
        // Update student status to 'given'
        $update_query = "UPDATE students SET status = 'given' WHERE student_id = $1";
        $update_result = pg_query_params($connection, $update_query, [$student_id]);
        
        if (!$update_result) {
            throw new Exception('Failed to update student status');
        }
        
        // Get QR code for this student
        $qr_query = "SELECT unique_id FROM qr_codes WHERE student_id = $1";
        $qr_result = pg_query_params($connection, $qr_query, [$student_id]);
        $qr_data = $qr_result ? pg_fetch_assoc($qr_result) : null;
        $qr_code_used = $qr_data['unique_id'] ?? null;
        
        // Update QR code status to 'Done' (must match CHECK constraint: 'Pending' or 'Done')
        $qr_update_query = "UPDATE qr_codes SET status = 'Done' WHERE student_id = $1";
        $qr_update_result = pg_query_params($connection, $qr_update_query, [$student_id]);
        
        if (!$qr_update_result) {
            throw new Exception('Failed to update QR code status');
        }
        
        // Create distribution record linking student to snapshot
        if ($snapshot_id) {
            $record_query = "
                INSERT INTO distribution_student_records 
                (snapshot_id, student_id, qr_code_used, scanned_by, verification_method, notes)
                VALUES ($1, $2, $3, $4, 'qr_scan', 'Scanned via QR code scanner')
                ON CONFLICT (snapshot_id, student_id) DO UPDATE 
                SET scanned_at = NOW(), scanned_by = EXCLUDED.scanned_by
            ";
            $record_result = pg_query_params($connection, $record_query, [
                $snapshot_id, $student_id, $qr_code_used, $admin_id
            ]);
            
            if (!$record_result) {
                error_log("Warning: Failed to create distribution record for student $student_id in snapshot $snapshot_id");
            } else {
                error_log("Created distribution record: Student $student_id linked to snapshot $snapshot_id");
            }
        }
        
        // Log QR scan to qr_logs table for tracking
        $log_query = "INSERT INTO qr_logs (student_id, scanned_at, scanned_by) VALUES ($1, NOW(), $2)";
        $log_result = pg_query_params($connection, $log_query, [$student_id, $admin_id]);
        
        if (!$log_result) {
            error_log("Warning: Failed to log QR scan for student $student_id: " . pg_last_error($connection));
        }
        
        // Add notification to student
        $notif_query = "INSERT INTO notifications (student_id, message) VALUES ($1, $2)";
        $notif_message = "Your scholarship aid has been successfully distributed. Thank you for participating in the EducAid program.";
        $notif_result = pg_query_params($connection, $notif_query, [$student_id, $notif_message]);
        
        if (!$notif_result) {
            error_log("Warning: Failed to create notification for student $student_id");
        }
        
        // Commit transaction
        pg_query($connection, "COMMIT");
        
        echo json_encode([
            'success' => true,
            'message' => 'Distribution confirmed successfully',
            'next_token' => CSRFProtection::generateToken('confirm_distribution')
        ]);
    } catch (Exception $e) {
        // Rollback on any error
        pg_query($connection, "ROLLBACK");
        error_log("Distribution confirmation error: " . $e->getMessage());
        
        echo json_encode([
            'success' => false,
            'message' => 'Failed to confirm distribution: ' . $e->getMessage(),
            'next_token' => CSRFProtection::generateToken('confirm_distribution')
        ]);
    }
    exit;
}

// Handle QR code lookup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup_qr'])) {
  $token = $_POST['csrf_token'] ?? '';
  if (!CSRFProtection::validateToken('lookup_qr', $token)) {
    http_response_code(400);
    echo json_encode([
      'success' => false,
      'message' => 'Security validation failed. Please refresh the page and try again.',
      'next_token' => CSRFProtection::generateToken('lookup_qr')
    ]);
    exit;
  }

  error_log("QR Lookup started for: " . $_POST['qr_code']);
    
  $qr_unique_id = $_POST['qr_code'];
    
    $lookup_query = "
        SELECT s.student_id, s.first_name, s.middle_name, s.last_name, 
               s.payroll_no, s.status,
               b.name as barangay_name, u.name as university_name, yl.name as year_level_name
        FROM students s
        LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
        LEFT JOIN universities u ON s.university_id = u.university_id
        LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
        JOIN qr_codes q ON s.student_id = q.student_id AND s.payroll_no = q.payroll_number
        WHERE q.unique_id = $1 AND s.status = 'active'
    ";
    
    $lookup_result = pg_query_params($connection, $lookup_query, [$qr_unique_id]);
    
    if (!$lookup_result) {
        error_log("Database query failed: " . pg_last_error($connection));
    echo json_encode([
      'success' => false,
      'message' => 'Database error occurred',
      'next_token' => CSRFProtection::generateToken('lookup_qr')
    ]);
        exit;
    }
    
    if (pg_num_rows($lookup_result) > 0) {
        $student = pg_fetch_assoc($lookup_result);
        error_log("Student found: " . $student['student_id']);
    echo json_encode([
      'success' => true,
      'student' => $student,
      'next_token' => CSRFProtection::generateToken('lookup_qr')
    ]);
    } else {
        error_log("No student found for QR: " . $qr_unique_id);
    echo json_encode([
      'success' => false,
      'message' => 'QR code not found or student not eligible for distribution',
      'next_token' => CSRFProtection::generateToken('lookup_qr')
    ]);
    }
    exit;
}

// Fetch all students with payroll numbers for the table
$students_query = "
    SELECT s.student_id, s.payroll_no, s.first_name, s.middle_name, s.last_name, 
           s.status, q.unique_id as qr_unique_id, q.status as qr_status,
           COALESCE(qr_log.scanned_at, dsr.scanned_at) as distribution_date,
           dsr.snapshot_id,
           COALESCE(
               TRIM(qr_admin.first_name || ' ' || qr_admin.last_name),
               TRIM(a.first_name || ' ' || a.last_name)
           ) as scanned_by_name,
           a.username as distributed_by_username
    FROM students s
    LEFT JOIN qr_codes q ON s.student_id = q.student_id AND s.payroll_no = q.payroll_number
    LEFT JOIN distribution_student_records dsr ON s.student_id = dsr.student_id
    LEFT JOIN admins a ON dsr.scanned_by = a.admin_id
    LEFT JOIN LATERAL (
        SELECT scanned_at, scanned_by
        FROM qr_logs 
        WHERE qr_logs.student_id = s.student_id
        ORDER BY scanned_at DESC 
        LIMIT 1
    ) qr_log ON true
    LEFT JOIN admins qr_admin ON qr_log.scanned_by = qr_admin.admin_id
    WHERE s.status IN ('active', 'given') AND s.payroll_no IS NOT NULL
    ORDER BY s.payroll_no ASC
";

$students_result = pg_query($connection, $students_query);
$students = [];
if ($students_result) {
    while ($row = pg_fetch_assoc($students_result)) {
        $students[] = $row;
    }
}

// Count distributed students
$count_query = "SELECT COUNT(*) as total FROM students WHERE status = 'given'";
$count_result = pg_query($connection, $count_query);
$total_distributed = 0;
if ($count_result) {
    $row = pg_fetch_assoc($count_result);
    $total_distributed = intval($row['total']);
}

// Debug logging
error_log("Total distributed students: " . $total_distributed);

// Get config for modal prefill
$config_academic_year = '';
$config_semester = '';
$cfg_result = pg_query($connection, "SELECT key, value FROM config WHERE key IN ('current_academic_year','current_semester')");
if ($cfg_result) {
    while ($cfg = pg_fetch_assoc($cfg_result)) {
        if ($cfg['key'] === 'current_academic_year') $config_academic_year = $cfg['value'];
        if ($cfg['key'] === 'current_semester') $config_semester = $cfg['value'];
    }
}

// Get active slot
$slot_query = "SELECT academic_year, semester FROM signup_slots WHERE is_active = true LIMIT 1";
$slot_result = pg_query($connection, $slot_query);
$slot_data = $slot_result ? pg_fetch_assoc($slot_result) : null;
$prefill_academic_year = $slot_data['academic_year'] ?? $config_academic_year;
$prefill_semester = $slot_data['semester'] ?? $config_semester;

// Load settings for location
$settingsPath = __DIR__ . '/../../data/municipal_settings.json';
$settings = file_exists($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : [];
$distribution_location = $settings['schedule_meta']['location'] ?? '';

$csrf_lookup_token = CSRFProtection::generateToken('lookup_qr');
$csrf_confirm_token = CSRFProtection::generateToken('confirm_distribution');
$csrf_complete_token = CSRFProtection::generateToken('complete_distribution');
?>

<?php $page_title='QR Code Scanner'; include '../../includes/admin/admin_head.php'; ?>
  <style>
    body { font-family: 'Poppins', sans-serif; }
    #reader { 
      width: 100%; 
      max-width: 500px; 
      margin: 0 auto 20px auto;
      border: 2px solid #007bff;
      border-radius: 10px;
    }
    .controls { 
      text-align: center; 
      margin: 20px 0; 
    }
    .status-active { background-color: #d4edda; color: #155724; }
    .status-given { background-color: #f8d7da; color: #721c24; }
    .table-container {
      max-height: 600px;
      overflow-y: auto;
      border: 1px solid #dee2e6;
      border-radius: 5px;
    }
    .scanner-section {
      background: white;
      padding: 30px;
      border-radius: 10px;
      box-shadow: 0 5px 15px rgba(0,0,0,0.1);
      margin-bottom: 30px;
    }
    /* Ensure loading modal doesn't interfere with other modals */
    #loadingModal {
      z-index: 1040;
    }
    #qrConfirmModal {
      z-index: 1050;
    }
  </style>
  </head>
<body>
  <?php include '../../includes/admin/admin_topbar.php'; ?>
  <div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>
    <section class="home-section" id="page-content-wrapper">
      <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <h1><i class="bi bi-qr-code-scan me-2"></i>QR Code Scanner & Distribution</h1>
        </div>

        <!-- Flash Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
          <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
          <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
          <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
          <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Distribution Statistics Card -->
        <div class="card mb-4" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border: none;">
          <div class="card-body p-4">
            <div class="row align-items-center">
              <div class="col-md-8">
                <h3 class="mb-2"><i class="bi bi-box-seam me-2"></i>Distribution Progress</h3>
                <p class="mb-0 opacity-75">Students who have received their aid packages</p>
              </div>
              <div class="col-md-4 text-end">
                <h1 class="display-3 mb-0 fw-bold"><?php echo $total_distributed; ?></h1>
                <p class="mb-0">Distributed</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Action Buttons Row -->
        <div class="d-flex justify-content-end align-items-center mb-4">
          <button type="button" class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#completeDistributionModal">
            <i class="bi bi-check-circle me-2"></i>Complete Distribution
          </button>
        </div>

        <!-- Scanner Section -->
        <div class="scanner-section">
          <h3 class="text-center mb-4"><i class="bi bi-camera me-2"></i>Scan Student QR Code</h3>
          <div id="reader"></div>
          <div class="controls">
            <select id="camera-select" class="form-select w-auto d-inline-block me-2">
              <option value="">Select Camera</option>
            </select>
            <button id="start-button" class="btn btn-success me-2">
              <i class="bi bi-play-fill me-1"></i>Start Scanner
            </button>
            <button id="stop-button" class="btn btn-danger me-2" disabled>
              <i class="bi bi-stop-fill me-1"></i>Stop Scanner
            </button>
          </div>
          <div class="alert alert-info mt-3">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Instructions:</strong> Point the camera at a student's QR code to identify them and confirm aid distribution.
          </div>
        </div>

        <!-- Students Table -->
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <div>
              <h3 class="mb-0"><i class="bi bi-people-fill me-2"></i>Students with Payroll Numbers</h3>
              <small class="text-muted">Total: <?= count($students) ?> students</small>
            </div>
            <a href="?export=csv" class="btn btn-success">
              <i class="bi bi-download me-2"></i>Export to CSV
            </a>
          </div>
          <div class="card-body p-0">
            <div class="table-container">
              <table class="table table-striped table-hover mb-0" id="studentsTable">
                <thead class="table-dark sticky-top">
                  <tr>
                    <th>#</th>
                    <th></th>Payroll #</th>
                    <th>Student Name</th>
                    <th>Student ID</th>
                    <th>Status</th>
                    <th>Date & Time</th>
                    <th>Scanned By</th>
                    <th>QR Code</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $counter = 1;
                  foreach ($students as $student): 
                  ?>
                    <tr id="student-<?= $student['student_id'] ?>">
                      <td class="fw-semibold text-muted"><?= $counter++ ?></td>
                      <td>
                        <code class="bg-dark text-white px-2 py-1 rounded">#<?= htmlspecialchars($student['payroll_no']) ?></code>
                      </td>
                      <td class="fw-semibold">
                        <?= htmlspecialchars(trim($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name'])) ?>
                      </td>
                      <td>
                        <small class="text-muted"><?= htmlspecialchars($student['student_id']) ?></small>
                      </td>
                      <td>
                        <?php if ($student['status'] === 'given'): ?>
                          <span class="badge bg-success">
                            <i class="bi bi-check-circle me-1"></i>Given
                          </span>
                        <?php else: ?>
                          <span class="badge bg-warning">
                            <i class="bi bi-hourglass-split me-1"></i>Active
                          </span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!empty($student['distribution_date'])): ?>
                          <div class="small">
                            <i class="bi bi-calendar-check text-primary me-1"></i>
                            <strong><?= date('M d, Y', strtotime($student['distribution_date'])) ?></strong>
                          </div>
                          <div class="small text-muted">
                            <i class="bi bi-clock me-1"></i>
                            <?= date('g:i A', strtotime($student['distribution_date'])) ?>
                          </div>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!empty($student['scanned_by_name'])): ?>
                          <div class="small">
                            <i class="bi bi-person-circle text-success me-1"></i>
                            <strong><?= htmlspecialchars($student['scanned_by_name']) ?></strong>
                          </div>
                          <?php if (!empty($student['distributed_by_username'])): ?>
                          <div class="small text-muted">
                            <i class="bi bi-at"></i><?= htmlspecialchars($student['distributed_by_username']) ?>
                          </div>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($student['qr_unique_id']): ?>
                          <span class="badge bg-info">
                            <i class="bi bi-qr-code me-1"></i>Has QR
                          </span>
                        <?php else: ?>
                          <span class="badge bg-secondary">
                            <i class="bi bi-x-circle me-1"></i>No QR
                          </span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- QR Code Confirmation Modal -->
  <div class="modal fade" id="qrConfirmModal" tabindex="-1" aria-labelledby="qrConfirmModalLabel" aria-hidden="true" data-bs-backdrop="false">
    <div class="modal-dialog modal-lg">
      <div class="modal-content border border-primary" style="box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title" id="qrConfirmModalLabel">
            <i class="bi bi-qr-code-scan me-2"></i>Confirm Aid Distribution
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="studentInfo">
            <!-- Student information will be loaded here -->
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </button>
          <button type="button" class="btn btn-success" id="confirmDistribution">
            <i class="bi bi-check-circle me-1"></i>Confirm Distribution
          </button>
          <button type="button" class="btn btn-warning btn-sm ms-2" id="resetButton" style="display: none;" onclick="resetConfirmButton()">
            <i class="bi bi-arrow-clockwise me-1"></i>Reset
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Loading Modal -->
  <div class="modal fade" id="loadingModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="false" data-bs-keyboard="false">
    <div class="modal-dialog modal-sm">
      <div class="modal-content border border-info" style="box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
        <div class="modal-body text-center">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="mt-3 mb-0">Processing QR Code...</p>
          <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="forceCloseLoading" style="display: none;" onclick="clearModalIssues()">
            <i class="bi bi-x-circle me-1"></i>Close
          </button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/html5-qrcode"></script>
  <script src="../../assets/js/admin/sidebar.js"></script>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script>
    const startButton = document.getElementById('start-button');
    const stopButton = document.getElementById('stop-button');
    const cameraSelect = document.getElementById('camera-select');
    const html5QrCode = new Html5Qrcode("reader");
    let currentCameraId = null;
    let currentStudentData = null;
    const csrfTokens = {
      lookup: <?= json_encode($csrf_lookup_token) ?>,
      confirm: <?= json_encode($csrf_confirm_token) ?>
    };

    function updateCsrfToken(action, nextToken) {
      if (nextToken) {
        csrfTokens[action] = nextToken;
      }
    }

    function buildFormBody(params) {
      return new URLSearchParams(params).toString();
    }
    
    // Initialize camera selection
    Html5Qrcode.getCameras().then(cameras => {
      if (!cameras.length) {
        alert("No cameras found.");
        return;
      }
      cameras.forEach(camera => {
        const option = document.createElement('option');
        option.value = camera.id;
        option.text = camera.label || `Camera ${camera.id}`;
        cameraSelect.appendChild(option);
      });
      
      // Prefer back camera
      const backCam = cameras.find(cam => cam.label.toLowerCase().includes('back'));
      if (backCam) {
        cameraSelect.value = backCam.id;
        currentCameraId = backCam.id;
      } else if (cameras.length > 0) {
        cameraSelect.value = cameras[0].id;
        currentCameraId = cameras[0].id;
      }
    }).catch(err => {
      console.error("Error getting cameras:", err);
    });
    
    cameraSelect.addEventListener('change', () => {
      currentCameraId = cameraSelect.value;
    });

    // Start scanner
    startButton.addEventListener('click', () => {
      if (!currentCameraId) {
        alert("Please select a camera.");
        return;
      }
      
      html5QrCode.start(
        currentCameraId,
        { 
          fps: 10, 
          qrbox: { width: 300, height: 300 },
          aspectRatio: 1.0
        },
        decodedText => {
          // QR code detected
          console.log("QR Code detected:", decodedText);
          
          // Immediately disable scanner to prevent multiple scans
          startButton.disabled = true;
          stopButton.disabled = true;
          
          // Stop scanner first
          html5QrCode.stop().then(() => {
            // Reset buttons after stopping
            startButton.disabled = false;
            stopButton.disabled = true;
            
            // Show loading modal with slight delay to ensure scanner is stopped
            setTimeout(() => {
              const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'), {
                backdrop: false,  // NO BACKDROP!
                keyboard: false
              });
              loadingModal.show();
              
              // Show force close button after 3 seconds
              setTimeout(() => {
                const forceCloseBtn = document.getElementById('forceCloseLoading');
                if (forceCloseBtn) {
                  forceCloseBtn.style.display = 'inline-block';
                }
              }, 3000);
              
              // Lookup student info
              lookupQRCode(decodedText);
            }, 100);
            
          }).catch(err => {
            console.error("Error stopping scanner:", err);
            startButton.disabled = false;
            stopButton.disabled = true;
            
            // Still try to lookup even if stop failed
            const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'), {
              backdrop: false,  // NO BACKDROP!
              keyboard: false
            });
            loadingModal.show();
            
            // Show force close button after 3 seconds
            setTimeout(() => {
              const forceCloseBtn = document.getElementById('forceCloseLoading');
              if (forceCloseBtn) {
                forceCloseBtn.style.display = 'inline-block';
              }
            }, 3000);
            
            lookupQRCode(decodedText);
          });
        },
        error => {
          // Ignore decode errors (happens frequently during scanning)
          // Only log if it's not a common decode error
          if (!error.includes('NotFoundException') && !error.includes('No MultiFormat Readers')) {
            console.log("Scanner error:", error);
          }
        }
      ).then(() => {
        startButton.disabled = true;
        stopButton.disabled = false;
        console.log("Scanner started successfully");
      }).catch(err => {
        console.error("Failed to start scanning:", err);
        alert("Failed to start camera. Please check permissions and try again.");
        startButton.disabled = false;
        stopButton.disabled = true;
      });
    });

    // Stop scanner
    stopButton.addEventListener('click', () => {
      html5QrCode.stop()
        .then(() => {
          startButton.disabled = false;
          stopButton.disabled = true;
        })
        .catch(err => console.error("Failed to stop scanning:", err));
    });

    // Lookup QR code
    function lookupQRCode(qrCode) {
      console.log('Looking up QR code:', qrCode);
      
      // Set a timeout to hide loading modal if it takes too long
      const timeoutId = setTimeout(() => {
        clearModalIssues(); // Use our emergency clear function
        alert('Request timed out. Please try again.');
      }, 10000); // 10 second timeout
      
      fetch('scan_qr.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: buildFormBody({
          lookup_qr: '1',
          qr_code: qrCode,
          csrf_token: csrfTokens.lookup
        })
      })
      .then(response => {
        clearTimeout(timeoutId);
        console.log('Response status:', response.status);
        
        return response.text().then(text => {
          console.log('Raw response:', text);
          let data;
          try {
            data = JSON.parse(text);
          } catch (e) {
            console.error('JSON parse error:', e);
            const parseError = new Error('Invalid JSON response');
            throw parseError;
          }

          updateCsrfToken('lookup', data.next_token);

          if (!response.ok || !data.success) {
            const error = new Error(data.message || `Request failed with status ${response.status}`);
            error.responseData = data;
            throw error;
          }

          return data;
        });
      })
      .then(data => {
        // Hide loading modal properly
        const loadingModal = bootstrap.Modal.getInstance(document.getElementById('loadingModal'));
        if (loadingModal) {
          loadingModal.hide();
        }
        
        console.log('Parsed data:', data);
        
        currentStudentData = data.student;
        showStudentModal(data.student);
      })
      .catch(error => {
        clearTimeout(timeoutId);
        clearModalIssues(); // Clear any modal issues on error
        console.error('Fetch error:', error);
        const serverMessage = error.responseData && error.responseData.message;
        if (error.responseData) {
          updateCsrfToken('lookup', error.responseData.next_token);
        }
        alert(serverMessage || ('Error processing QR code: ' + error.message));
      });
    }

    // Show student confirmation modal
    function showStudentModal(student) {
      // Force hide loading modal first
      const loadingModal = bootstrap.Modal.getInstance(document.getElementById('loadingModal'));
      if (loadingModal) {
        loadingModal.hide();
      }
      
      // Remove any leftover backdrops just in case
      setTimeout(() => {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
      }, 100);
      
      // Wait a moment for cleanup
      setTimeout(() => {
        const modalBody = document.getElementById('studentInfo');
        modalBody.innerHTML = `
          <div class="row">
            <div class="col-md-6">
              <h6 class="text-primary">Student Information</h6>
              <table class="table table-sm">
                <tr><td><strong>Name:</strong></td><td>${student.first_name} ${student.middle_name || ''} ${student.last_name}</td></tr>
                <tr><td><strong>Student ID:</strong></td><td><code>${student.student_id}</code></td></tr>
                <tr><td><strong>Payroll Number:</strong></td><td><span class="badge bg-primary">${student.payroll_no}</span></td></tr>
              </table>
            </div>
            <div class="col-md-6">
              <h6 class="text-primary">Additional Details</h6>
              <table class="table table-sm">
                <tr><td><strong>Status:</strong></td><td><span class="badge bg-success">${student.status.toUpperCase()}</span></td></tr>
                <tr><td><strong>Barangay:</strong></td><td>${student.barangay_name || 'N/A'}</td></tr>
                <tr><td><strong>University:</strong></td><td>${student.university_name || 'N/A'}</td></tr>
                <tr><td><strong>Year Level:</strong></td><td>${student.year_level_name || 'N/A'}</td></tr>
              </table>
            </div>
          </div>
          <div class="alert alert-warning mt-3">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Confirm Distribution:</strong> Are you sure you want to mark this student's aid as distributed? 
            This action will change their status to "Given" and cannot be easily undone.
          </div>
        `;
        
        // Create modal WITHOUT backdrop
        const modal = new bootstrap.Modal(document.getElementById('qrConfirmModal'), {
          backdrop: false,  // NO BACKDROP!
          keyboard: true,
          focus: true
        });
        modal.show();
      }, 300);
    }

    // Emergency function to clear all modal issues
    function clearModalIssues() {
      console.log('Clearing all modal issues...');
      
      // Hide force close button
      const forceCloseBtn = document.getElementById('forceCloseLoading');
      if (forceCloseBtn) {
        forceCloseBtn.style.display = 'none';
      }
      
      // Hide all modals immediately
      const allModals = document.querySelectorAll('.modal');
      allModals.forEach(modal => {
        const modalInstance = bootstrap.Modal.getInstance(modal);
        if (modalInstance) {
          modalInstance.hide();
        }
        // Force hide the modal element directly
        modal.style.display = 'none';
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
      });
      
      // Remove all backdrops aggressively
      setTimeout(() => {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
      }, 50);
      
      setTimeout(() => {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
      }, 200);
      
      // Reset body styles
      document.body.classList.remove('modal-open');
      document.body.style.overflow = '';
      document.body.style.paddingRight = '';
      document.body.style.marginRight = '';
      
      console.log('All modal issues cleared');
    }
    
    // Add emergency key combination (Ctrl+Shift+C) to clear modal issues
    document.addEventListener('keydown', (e) => {
      if (e.ctrlKey && e.shiftKey && e.key === 'C') {
        clearModalIssues();
        alert('Modal issues cleared! You can now use the interface normally.');
      }
    });

    // Reset confirm button function
    function resetConfirmButton() {
      const button = document.getElementById('confirmDistribution');
      const resetBtn = document.getElementById('resetButton');
      
      button.disabled = false;
      button.innerHTML = '<i class="bi bi-check-circle me-1"></i>Confirm Distribution';
      resetBtn.style.display = 'none';
      
      console.log('Confirm button has been reset');
    }

    // Confirm distribution
    document.getElementById('confirmDistribution').addEventListener('click', () => {
      if (!currentStudentData) {
        alert('No student data available. Please scan a QR code first.');
        return;
      }
      
      const button = document.getElementById('confirmDistribution');
      const resetBtn = document.getElementById('resetButton');
      const originalText = button.innerHTML;
      
      console.log('Confirming distribution for student:', currentStudentData.student_id);
      
      button.disabled = true;
      button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';
      resetBtn.style.display = 'inline-block'; // Show reset button
      
      // Add timeout for the confirmation request
      const confirmTimeoutId = setTimeout(() => {
        button.disabled = false;
        button.innerHTML = originalText;
        resetBtn.style.display = 'none';
        alert('Confirmation request timed out. Please try again.');
      }, 15000); // 15 second timeout
      
      fetch('scan_qr.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: buildFormBody({
          confirm_distribution: '1',
          student_id: currentStudentData.student_id,
          csrf_token: csrfTokens.confirm
        })
      })
      .then(response => {
        clearTimeout(confirmTimeoutId);
        console.log('Confirmation response status:', response.status);
        
        return response.text().then(text => {
          console.log('Confirmation raw response:', text);
          let data;
          try {
            data = JSON.parse(text);
          } catch (e) {
            console.error('JSON parse error in confirmation:', e);
            const parseError = new Error('Invalid JSON response');
            throw parseError;
          }

          updateCsrfToken('confirm', data.next_token);

          if (!response.ok || !data.success) {
            const error = new Error(data.message || `Request failed with status ${response.status}`);
            error.responseData = data;
            throw error;
          }

          return data;
        });
      })
      .then(data => {
        console.log('Confirmation parsed data:', data);

        // Force hide ALL modals immediately
        clearModalIssues();
        
        // Update table row
        updateStudentRow(currentStudentData.student_id);
        
        // Update distributed count in real-time
        updateDistributedCount();
        
        // Show success message
        showSuccessMessage('Distribution confirmed successfully!');
        
        // Reset current student data
        currentStudentData = null;
      })
      .catch(error => {
        clearTimeout(confirmTimeoutId);
        console.error('Confirmation error:', error);
        if (error.responseData) {
          updateCsrfToken('confirm', error.responseData.next_token);
        }
        const serverMessage = error.responseData && error.responseData.message;
        alert(serverMessage || ('Error confirming distribution: ' + error.message));
      })
      .finally(() => {
        // Always re-enable the button and hide reset button
        const resetBtn = document.getElementById('resetButton');
        button.disabled = false;
        button.innerHTML = originalText;
        resetBtn.style.display = 'none';
      });
    });

    // Update student row in table
    function updateStudentRow(studentId) {
      const row = document.getElementById(`student-${studentId}`);
      if (row) {
        // Update status badge (column 4)
        const statusCell = row.cells[4];
        statusCell.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Given</span>';
        
        // Update distribution date (column 5)
        const dateCell = row.cells[5];
        const now = new Date();
        const dateStr = now.toLocaleDateString('en-US', { 
          year: 'numeric', 
          month: 'short', 
          day: 'numeric' 
        });
        const timeStr = now.toLocaleTimeString('en-US', {
          hour: 'numeric',
          minute: '2-digit',
          hour12: true
        });
        dateCell.innerHTML = `
          <div class="small">
            <i class="bi bi-calendar-check text-primary me-1"></i>
            <strong>${dateStr}</strong>
          </div>
          <div class="small text-muted">
            <i class="bi bi-clock me-1"></i>
            ${timeStr}
          </div>
        `;
        
        // Update scanned by (column 6) - will be shown after page reload
        const scannedByCell = row.cells[6];
        scannedByCell.innerHTML = '<span class="text-muted"><i class="bi bi-arrow-clockwise"></i> Refresh to see</span>';
        
        // Highlight row briefly
        row.classList.add('table-success');
        setTimeout(() => {
          row.classList.remove('table-success');
        }, 3000);
      }
    }

    // Update distributed count in the progress card
    function updateDistributedCount() {
      // Count all rows with "Given" status
      const givenBadges = document.querySelectorAll('.badge.bg-success');
      const count = givenBadges.length;
      
      // Update the distribution progress card
      const progressNumber = document.querySelector('.display-3');
      if (progressNumber) {
        progressNumber.textContent = count;
      }
      
      // Update "Complete Distribution" modal count when it opens
      console.log('Updated distributed count to:', count);
    }

    // Show success message
    function showSuccessMessage(message) {
      const alertDiv = document.createElement('div');
      alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
      alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
      alertDiv.innerHTML = `
        <i class="bi bi-check-circle me-2"></i>${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      `;
      
      document.body.appendChild(alertDiv);
      
      setTimeout(() => {
        if (alertDiv.parentNode) {
          alertDiv.remove();
        }
      }, 5000);
    }
  </script>

  <!-- Complete Distribution Modal -->
  <div class="modal fade" id="completeDistributionModal" tabindex="-1" aria-labelledby="completeDistributionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" id="completeDistributionModalLabel">
            <i class="bi bi-check-circle me-2"></i>Complete Distribution
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" id="completeDistributionForm">
          <input type="hidden" name="csrf_token" value="<?php echo $csrf_complete_token; ?>">
          <input type="hidden" name="complete_distribution" value="1">
          
          <div class="modal-body">
            <div class="alert alert-warning border-warning" id="distributionWarningBox">
              <i class="bi bi-exclamation-triangle me-2"></i>
              <strong>REQUIRED: Create Distribution Snapshot</strong>
              <p class="mb-2" id="distributionCountText">
                You have distributed aid to <strong id="currentDistributedCount"><?php echo $total_distributed; ?></strong> student<span id="studentPlural"><?php echo $total_distributed != 1 ? 's' : ''; ?></span>.
              </p>
              
              <div class="mt-2 p-2 bg-light rounded">
                <small class="text-dark">
                  <i class="bi bi-arrow-right me-1"></i><strong>Important:</strong> You MUST complete this step before you can access "End Distribution".<br>
                  <i class="bi bi-arrow-right me-1"></i>After creating the snapshot, you can continue scanning or proceed to end the distribution cycle.
                </small>
              </div>
            </div>

            <div class="mb-3">
              <label for="distribution_location" class="form-label fw-bold">
                <i class="bi bi-geo-alt me-1"></i>Distribution Location *
                <i class="bi bi-lock text-muted ms-1" title="Locked from settings"></i>
              </label>
              <input type="text" class="form-control" id="distribution_location" name="distribution_location" 
                     value="<?php echo htmlspecialchars($distribution_location); ?>" readonly required>
              <small class="text-muted">Location is set in Municipal Settings</small>
            </div>

            <div class="row">
              <div class="col-md-6 mb-3">
                <label for="academic_year" class="form-label fw-bold">
                  <i class="bi bi-calendar me-1"></i>Academic Year
                  <?php if (!empty($prefill_academic_year)): ?>
                    <i class="bi bi-lock text-muted ms-1" title="Locked from config"></i>
                  <?php endif; ?>
                </label>
                <input type="text" class="form-control" id="academic_year" name="academic_year" 
                       value="<?php echo htmlspecialchars($prefill_academic_year); ?>" 
                       <?php echo !empty($prefill_academic_year) ? 'readonly' : ''; ?>
                       placeholder="e.g., 2025-2026">
                <small class="text-muted">Optional - auto-filled from active slot</small>
              </div>

              <div class="col-md-6 mb-3">
                <label for="semester" class="form-label fw-bold">
                  <i class="bi bi-calendar-check me-1"></i>Semester
                  <?php if (!empty($prefill_semester)): ?>
                    <i class="bi bi-lock text-muted ms-1" title="Locked from config"></i>
                  <?php endif; ?>
                </label>
                <select class="form-select" id="semester" name="semester"
                        <?php echo !empty($prefill_semester) ? 'disabled' : ''; ?>>
                  <option value="">Select Semester</option>
                  <option value="1st Semester" <?php echo $prefill_semester === '1st Semester' ? 'selected' : ''; ?>>1st Semester</option>
                  <option value="2nd Semester" <?php echo $prefill_semester === '2nd Semester' ? 'selected' : ''; ?>>2nd Semester</option>
                  <option value="Summer" <?php echo $prefill_semester === 'Summer' ? 'selected' : ''; ?>>Summer</option>
                </select>
                <?php if (!empty($prefill_semester)): ?>
                <input type="hidden" name="semester" value="<?php echo htmlspecialchars($prefill_semester); ?>">
                <?php endif; ?>
                <small class="text-muted">Optional - auto-filled from active slot</small>
              </div>
            </div>

            <div class="mb-3">
              <label for="distribution_notes" class="form-label fw-bold">
                <i class="bi bi-pencil me-1"></i>Notes (Optional)
              </label>
              <textarea class="form-control" id="distribution_notes" name="distribution_notes" rows="3" 
                        placeholder="Any additional notes about this distribution..."></textarea>
            </div>

            <div class="mb-3">
              <label for="admin_password" class="form-label fw-bold">
                <i class="bi bi-shield-lock me-1"></i>Your Password *
              </label>
              <div class="input-group">
                <input type="password" class="form-control" id="admin_password" name="admin_password" required>
                <button class="btn btn-outline-secondary" type="button" id="toggleCompletePassword">
                  <i class="bi bi-eye" id="completePasswordIcon"></i>
                </button>
              </div>
              <small class="text-muted">Enter your admin password to confirm</small>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success">
              <i class="bi bi-check-circle me-1"></i>Complete Distribution
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    // Password toggle for complete distribution modal
    document.addEventListener('DOMContentLoaded', function() {
      const toggleBtn = document.getElementById('toggleCompletePassword');
      const passwordInput = document.getElementById('admin_password');
      const passwordIcon = document.getElementById('completePasswordIcon');
      
      if (toggleBtn && passwordInput && passwordIcon) {
        toggleBtn.addEventListener('click', function() {
          const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
          passwordInput.setAttribute('type', type);
          passwordIcon.className = type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
        });
      }

      // Update distributed count when modal opens
      const completeModal = document.getElementById('completeDistributionModal');
      if (completeModal) {
        completeModal.addEventListener('show.bs.modal', function() {
          // Count students with status='given' from the current table
          const givenRows = document.querySelectorAll('tr[id^="student-"]');
          let distributedCount = 0;
          
          givenRows.forEach(row => {
            const statusBadge = row.querySelector('.badge');
            if (statusBadge && statusBadge.textContent.trim().toLowerCase() === 'given') {
              distributedCount++;
            }
          });
          
          console.log('Distributed count from table:', distributedCount);
          
          // Update the modal display
          const countElement = document.getElementById('currentDistributedCount');
          const pluralElement = document.getElementById('studentPlural');
          const warningBox = document.getElementById('distributionWarningBox');
          
          if (countElement) {
            countElement.textContent = distributedCount;
          }
          if (pluralElement) {
            pluralElement.textContent = distributedCount !== 1 ? 's' : '';
          }
          
          // Add warning styling if no students distributed
          if (distributedCount === 0 && warningBox) {
            warningBox.classList.remove('alert-warning');
            warningBox.classList.add('alert-danger');
            const existingWarning = warningBox.querySelector('.no-students-warning');
            if (!existingWarning) {
              const warningText = document.createElement('div');
              warningText.className = 'alert alert-danger mt-2 no-students-warning';
              warningText.innerHTML = '<i class="bi bi-x-circle me-2"></i><strong>WARNING:</strong> No students have been distributed yet! Please scan at least one student QR code before creating a snapshot.';
              warningBox.appendChild(warningText);
            }
          } else if (warningBox) {
            warningBox.classList.remove('alert-danger');
            warningBox.classList.add('alert-warning');
            const existingWarning = warningBox.querySelector('.no-students-warning');
            if (existingWarning) {
              existingWarning.remove();
            }
          }
        });
      }

      // Form validation
      const form = document.getElementById('completeDistributionForm');
      if (form) {
        form.addEventListener('submit', function(e) {
          const location = document.getElementById('distribution_location').value.trim();
          const password = document.getElementById('admin_password').value.trim();
          const totalDistributed = <?php echo (int)$total_distributed; ?>;
          
          if (!location || !password) {
            e.preventDefault();
            alert('Location and password are required.');
            return false;
          }
          
          let confirmMsg = '📸 CREATE DISTRIBUTION SNAPSHOT\n\n';
          confirmMsg += 'You are about to save a permanent record of this distribution session.\n\n';
          confirmMsg += '• Total students distributed: ' + totalDistributed + '\n';
          confirmMsg += '• Location: ' + location + '\n\n';
          if (totalDistributed === 0) {
            confirmMsg += '⚠️ WARNING: No students have been distributed yet!\n\n';
          }
          confirmMsg += 'This will save the snapshot but keep the distribution active.\n';
          confirmMsg += 'You can continue scanning or go to "End Distribution" when completely done.\n\n';
          confirmMsg += 'Continue?';
          
          if (!confirm(confirmMsg)) {
            e.preventDefault();
            return false;
          }
        });
      }
    });
  </script>
</body>
</html>
