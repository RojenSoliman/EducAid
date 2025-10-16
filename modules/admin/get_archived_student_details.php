<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Unauthorized access</div>';
    exit;
}

include '../../config/database.php';

$studentId = $_GET['student_id'] ?? null;

if (!$studentId) {
    echo '<div class="alert alert-danger">Student ID is required</div>';
    exit;
}

// Get student details
$query = "
    SELECT 
        s.student_id,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.extension_name,
        s.email,
        s.mobile,
        s.bdate,
        s.sex,
        s.status,
        s.payroll_no,
        s.application_date,
        s.last_login,
        s.is_archived,
        s.archived_at,
        s.archived_by,
        s.archive_reason,
        s.expected_graduation_year,
        s.academic_year_registered,
        yl.name as year_level_name,
        u.name as university_name,
        b.name as barangay_name,
        m.name as municipality_name,
        CONCAT(a.first_name, ' ', a.last_name) as archived_by_name,
        a.email as archived_by_email,
        CASE 
            WHEN s.archived_by IS NULL THEN 'Automatic'
            ELSE 'Manual'
        END as archive_type
    FROM students s
    LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
    LEFT JOIN universities u ON s.university_id = u.university_id
    LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
    LEFT JOIN municipalities m ON s.municipality_id = m.municipality_id
    LEFT JOIN admins a ON s.archived_by = a.admin_id
    WHERE s.student_id = $1 AND s.is_archived = TRUE
";

$result = pg_query_params($connection, $query, [$studentId]);

if (!$result || pg_num_rows($result) === 0) {
    echo '<div class="alert alert-danger">Student not found or not archived</div>';
    exit;
}

$student = pg_fetch_assoc($result);
$fullName = trim($student['first_name'] . ' ' . 
          ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . 
          $student['last_name'] . ' ' . 
          ($student['extension_name'] ?? ''));

$age = null;
if ($student['bdate']) {
    $birthDate = new DateTime($student['bdate']);
    $today = new DateTime();
    $age = $birthDate->diff($today)->y;
}
?>

<div class="container-fluid">
    <div class="row">
        <!-- Personal Information -->
        <div class="col-md-6">
            <h6 class="fw-bold text-primary mb-3">
                <i class="bi bi-person-fill"></i> Personal Information
            </h6>
            
            <div class="info-row">
                <div class="info-label">Full Name:</div>
                <div class="info-value"><?php echo htmlspecialchars($fullName); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Student ID:</div>
                <div class="info-value"><?php echo htmlspecialchars($student['student_id']); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Email:</div>
                <div class="info-value"><?php echo htmlspecialchars($student['email']); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Mobile:</div>
                <div class="info-value"><?php echo htmlspecialchars($student['mobile']); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Sex:</div>
                <div class="info-value"><?php echo htmlspecialchars($student['sex']); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Birth Date:</div>
                <div class="info-value">
                    <?php 
                    echo $student['bdate'] ? date('F d, Y', strtotime($student['bdate'])) : 'N/A';
                    echo $age !== null ? " ({$age} years old)" : '';
                    ?>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Barangay:</div>
                <div class="info-value"><?php echo htmlspecialchars($student['barangay_name'] ?? 'N/A'); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Municipality:</div>
                <div class="info-value"><?php echo htmlspecialchars($student['municipality_name'] ?? 'N/A'); ?></div>
            </div>
        </div>

        <!-- Academic Information -->
        <div class="col-md-6">
            <h6 class="fw-bold text-primary mb-3">
                <i class="bi bi-mortarboard-fill"></i> Academic Information
            </h6>
            
            <div class="info-row">
                <div class="info-label">University:</div>
                <div class="info-value"><?php echo htmlspecialchars($student['university_name'] ?? 'N/A'); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Year Level:</div>
                <div class="info-value"><?php echo htmlspecialchars($student['year_level_name'] ?? 'N/A'); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Academic Year Registered:</div>
                <div class="info-value"><?php echo htmlspecialchars($student['academic_year_registered'] ?? 'N/A'); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Expected Graduation:</div>
                <div class="info-value">
                    <?php 
                    if ($student['expected_graduation_year']) {
                        echo htmlspecialchars($student['expected_graduation_year']);
                        $currentYear = date('Y');
                        if ($currentYear > $student['expected_graduation_year']) {
                            $yearsPast = $currentYear - $student['expected_graduation_year'];
                            echo " <span class='text-muted'>({$yearsPast} year" . ($yearsPast > 1 ? 's' : '') . " past)</span>";
                        }
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Payroll Number:</div>
                <div class="info-value"><?php echo $student['payroll_no'] ? htmlspecialchars($student['payroll_no']) : '<em>Not assigned</em>'; ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Application Date:</div>
                <div class="info-value"><?php echo date('F d, Y', strtotime($student['application_date'])); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Last Login:</div>
                <div class="info-value">
                    <?php 
                    if ($student['last_login']) {
                        echo date('F d, Y h:i A', strtotime($student['last_login']));
                        $lastLogin = new DateTime($student['last_login']);
                        $now = new DateTime();
                        $diff = $lastLogin->diff($now);
                        if ($diff->y > 0) {
                            echo " <span class='text-muted'>({$diff->y} year" . ($diff->y > 1 ? 's' : '') . " ago)</span>";
                        } elseif ($diff->m > 0) {
                            echo " <span class='text-muted'>({$diff->m} month" . ($diff->m > 1 ? 's' : '') . " ago)</span>";
                        } elseif ($diff->d > 0) {
                            echo " <span class='text-muted'>({$diff->d} day" . ($diff->d > 1 ? 's' : '') . " ago)</span>";
                        }
                    } else {
                        echo '<em>Never logged in</em>';
                    }
                    ?>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Status:</div>
                <div class="info-value">
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($student['status']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <hr class="my-4">

    <!-- Archive Information -->
    <div class="row">
        <div class="col-12">
            <h6 class="fw-bold text-danger mb-3">
                <i class="bi bi-archive-fill"></i> Archive Information
            </h6>
            
            <div class="info-row">
                <div class="info-label">Archive Type:</div>
                <div class="info-value">
                    <span class="badge <?php echo $student['archive_type'] === 'Automatic' ? 'bg-info' : 'bg-warning text-dark'; ?>">
                        <?php echo htmlspecialchars($student['archive_type']); ?>
                    </span>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Archived At:</div>
                <div class="info-value">
                    <?php echo date('F d, Y h:i A', strtotime($student['archived_at'])); ?>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Archived By:</div>
                <div class="info-value">
                    <?php 
                    if ($student['archived_by_name']) {
                        echo htmlspecialchars($student['archived_by_name']);
                        if ($student['archived_by_email']) {
                            echo " <span class='text-muted'>(" . htmlspecialchars($student['archived_by_email']) . ")</span>";
                        }
                    } else {
                        echo '<em>System (Automatic)</em>';
                    }
                    ?>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Archive Reason:</div>
                <div class="info-value">
                    <div class="alert alert-warning mb-0" style="padding: 10px;">
                        <?php echo htmlspecialchars($student['archive_reason'] ?? 'No reason provided'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.info-row {
    display: flex;
    padding: 10px 0;
    border-bottom: 1px solid #ecf0f1;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    width: 220px;
    color: #2c3e50;
}

.info-value {
    flex: 1;
    color: #555;
}
</style>
