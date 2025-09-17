<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username']) || !isset($_GET['student_id'])) {
    exit('Unauthorized');
}

$student_id = trim($_GET['student_id']); // Remove intval for TEXT student_id

// Fetch detailed blacklist information
$query = "SELECT s.*, bl.*, 
                 CONCAT(a.first_name, ' ', a.last_name) as blacklisted_by_name,
                 b.name as barangay_name,
                 u.name as university_name,
                 yl.name as year_level_name
          FROM students s
          JOIN blacklisted_students bl ON s.student_id = bl.student_id
          LEFT JOIN admins a ON bl.blacklisted_by = a.admin_id
          LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
          LEFT JOIN universities u ON s.university_id = u.university_id
          LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
          WHERE s.student_id = $1";

$result = pg_query_params($connection, $query, [$student_id]);
$student = pg_fetch_assoc($result);

if (!$student) {
    echo '<div class="alert alert-danger">Student not found.</div>';
    exit;
}

$reasonCategories = [
    'fraudulent_activity' => 'Fraudulent Activity',
    'academic_misconduct' => 'Academic Misconduct',
    'system_abuse' => 'System Abuse',
    'other' => 'Other'
];
?>

<div class="row">
    <div class="col-md-6">
        <h6 class="fw-bold text-danger mb-3">Student Information</h6>
        <table class="table table-borderless">
            <tr>
                <td><strong>Full Name:</strong></td>
                <td><?= htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']) ?></td>
            </tr>
            <tr>
                <td><strong>Email:</strong></td>
                <td><?= htmlspecialchars($student['email']) ?></td>
            </tr>
            <tr>
                <td><strong>Mobile:</strong></td>
                <td><?= htmlspecialchars($student['mobile'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <td><strong>Barangay:</strong></td>
                <td><?= htmlspecialchars($student['barangay_name'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <td><strong>University:</strong></td>
                <td><?= htmlspecialchars($student['university_name'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <td><strong>Year Level:</strong></td>
                <td><?= htmlspecialchars($student['year_level_name'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <td><strong>Application Date:</strong></td>
                <td><?= $student['application_date'] ? date('M j, Y g:i A', strtotime($student['application_date'])) : 'N/A' ?></td>
            </tr>
        </table>
    </div>
    
    <div class="col-md-6">
        <h6 class="fw-bold text-danger mb-3">Blacklist Information</h6>
        <table class="table table-borderless">
            <tr>
                <td><strong>Reason Category:</strong></td>
                <td>
                    <?php
                    $reasonClass = 'badge bg-danger';
                    switch($student['reason_category']) {
                        case 'fraudulent_activity': $reasonClass = 'badge bg-danger'; break;
                        case 'academic_misconduct': $reasonClass = 'badge bg-warning'; break;
                        case 'system_abuse': $reasonClass = 'badge bg-dark'; break;
                        case 'other': $reasonClass = 'badge bg-secondary'; break;
                    }
                    ?>
                    <span class="<?= $reasonClass ?>">
                        <?= $reasonCategories[$student['reason_category']] ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td><strong>Blacklisted By:</strong></td>
                <td><?= htmlspecialchars($student['blacklisted_by_name'] ?? 'System') ?></td>
            </tr>
            <tr>
                <td><strong>Admin Email:</strong></td>
                <td><?= htmlspecialchars($student['admin_email']) ?></td>
            </tr>
            <tr>
                <td><strong>Date Blacklisted:</strong></td>
                <td><?= date('M j, Y g:i A', strtotime($student['blacklisted_at'])) ?></td>
            </tr>
        </table>
    </div>
</div>

<?php if (!empty($student['detailed_reason'])): ?>
<div class="row mt-3">
    <div class="col-12">
        <h6 class="fw-bold text-danger mb-2">Detailed Reason</h6>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?= nl2br(htmlspecialchars($student['detailed_reason'])) ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($student['admin_notes'])): ?>
<div class="row mt-3">
    <div class="col-12">
        <h6 class="fw-bold text-danger mb-2">Admin Notes</h6>
        <div class="alert alert-warning">
            <i class="bi bi-sticky-fill"></i>
            <?= nl2br(htmlspecialchars($student['admin_notes'])) ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row mt-4">
    <div class="col-12">
        <div class="alert alert-info">
            <i class="bi bi-info-circle-fill"></i>
            <strong>Note:</strong> This student's account is permanently disabled. 
            They cannot register, login, or access any system features. 
            Any attempt to access the system will show an appropriate message 
            directing them to contact the Office of the Mayor.
        </div>
    </div>
</div>

<?php pg_close($connection); ?>