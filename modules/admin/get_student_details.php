<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username']) || !isset($_GET['id'])) {
    http_response_code(403);
    exit;
}

$student_id = intval($_GET['id']);

$query = "SELECT s.*, b.name as barangay_name, u.name as university_name, yl.name as year_level_name,
                 ef.file_path as enrollment_form_path, ef.original_filename
          FROM students s
          LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
          LEFT JOIN universities u ON s.university_id = u.university_id
          LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
          LEFT JOIN enrollment_forms ef ON s.student_id = ef.student_id
          WHERE s.student_id = $1 AND s.status = 'under_registration'";

$result = pg_query_params($connection, $query, [$student_id]);
$student = pg_fetch_assoc($result);

if (!$student) {
    echo '<div class="alert alert-warning">Student not found or already processed.</div>';
    exit;
}
?>

<div class="row">
    <div class="col-md-6">
        <h6 class="fw-bold text-primary mb-3">Personal Information</h6>
        <table class="table table-sm">
            <tr>
                <td class="fw-semibold">Full Name:</td>
                <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Email:</td>
                <td><?php echo htmlspecialchars($student['email']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Mobile:</td>
                <td><?php echo htmlspecialchars($student['mobile']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Gender:</td>
                <td><?php echo htmlspecialchars($student['sex']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Birth Date:</td>
                <td><?php echo date('M d, Y', strtotime($student['bdate'])); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Age:</td>
                <td><?php echo date_diff(date_create($student['bdate']), date_create('today'))->y; ?> years old</td>
            </tr>
        </table>
    </div>
    <div class="col-md-6">
        <h6 class="fw-bold text-primary mb-3">Academic Information</h6>
        <table class="table table-sm">
            <tr>
                <td class="fw-semibold">Student ID:</td>
                <td><?php echo htmlspecialchars($student['unique_student_id']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">University:</td>
                <td><?php echo htmlspecialchars($student['university_name']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Year Level:</td>
                <td><?php echo htmlspecialchars($student['year_level_name']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Barangay:</td>
                <td><?php echo htmlspecialchars($student['barangay_name']); ?></td>
            </tr>
            <tr>
                <td class="fw-semibold">Application Date:</td>
                <td><?php echo date('M d, Y g:i A', strtotime($student['application_date'])); ?></td>
            </tr>
        </table>
    </div>
</div>

<?php if ($student['enrollment_form_path']): ?>
    <div class="mt-4">
        <h6 class="fw-bold text-primary mb-3">Documents</h6>
        <div class="border rounded p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-file-earmark-pdf"></i>
                    <strong>Enrollment Assessment Form</strong>
                    <br>
                    <small class="text-muted"><?php echo htmlspecialchars($student['original_filename']); ?></small>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm" 
                        onclick="bootstrap.Modal.getInstance(document.getElementById('studentDetailsModal')).hide(); setTimeout(() => viewDocument('<?php echo htmlspecialchars($student['enrollment_form_path']); ?>', '<?php echo htmlspecialchars($student['original_filename']); ?>'), 300);">
                    <i class="bi bi-eye"></i> View
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="mt-4 d-flex gap-2">
    <button type="button" class="btn btn-success" 
            onclick="bootstrap.Modal.getInstance(document.getElementById('studentDetailsModal')).hide(); showActionModal(<?php echo $student['student_id']; ?>, 'approve', '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>')">
        <i class="bi bi-check-circle"></i> Approve Registration
    </button>
    <button type="button" class="btn btn-danger" 
            onclick="bootstrap.Modal.getInstance(document.getElementById('studentDetailsModal')).hide(); showActionModal(<?php echo $student['student_id']; ?>, 'reject', '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>')">
        <i class="bi bi-x-circle"></i> Reject Registration
    </button>
</div>

<?php pg_close($connection); ?>
