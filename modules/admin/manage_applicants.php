<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

include '../../config/database.php';

// Function to check if a student has uploaded all required documents
function check_documents($connection, $student_id) {
    $required_docs = ['id_picture', 'letter_to_mayor', 'certificate_of_indigency'];
    $uploaded_docs = [];
    $query = "SELECT type FROM documents WHERE student_id = $1";
    $result = pg_query_params($connection, $query, [$student_id]);

    while ($row = pg_fetch_assoc($result)) {
        $uploaded_docs[] = $row['type'];
    }

    return count(array_diff($required_docs, $uploaded_docs)) == 0;
}

// Handle form submissions to mark students as active
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_verified']) && isset($_POST['student_id'])) {
        $student_id = $_POST['student_id'];

        // Mark student as 'active' if all documents are uploaded
        pg_query_params($connection, "UPDATE students SET status = 'active' WHERE student_id = $1", [$student_id]);

        echo "<script>alert('Student marked as verified and active.'); window.location.href = 'manage_applicants.php';</script>";
    }
}

// Fetch applicants
$applicants = pg_query($connection, "SELECT * FROM students WHERE status = 'applicant'");

?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Applicants</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <!-- Sidebar -->
    <nav class="col-md-2 d-flex flex-column bg-light sidebar mb-4">
        <div class="sidebar-sticky">
            <h4 class="text-center mt-3">Admin Dashboard</h4>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="homepage.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="manage_applicants.php">Manage Applicants</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">Logout</a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-5">
        <h1>Manage Applicants</h1>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Contact Number</th>
                        <th>Email</th>
                        <th>Documents Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (pg_num_rows($applicants) === 0): ?>
                        <tr><td colspan="5" class="text-center">No applicants found.</td></tr>
                    <?php else: ?>
                        <?php while ($applicant = pg_fetch_assoc($applicants)) { 
                            $student_id = $applicant['student_id'];
                            $isComplete = check_documents($connection, $student_id); // Check if the student uploaded all required documents
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($applicant['first_name']) . ' ' . htmlspecialchars($applicant['last_name']) ?></td>
                                <td><?= htmlspecialchars($applicant['mobile']) ?></td>
                                <td><?= htmlspecialchars($applicant['email']) ?></td>
                                <td><?= $isComplete ? 'Complete' : 'Incomplete' ?></td>
                                <td>
                                    <!-- Button to view documents -->
                                    <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#viewDocumentsModal<?= $student_id ?>">View Documents</button>
                                </td>
                            </tr>

                            <!-- Modal for Viewing Documents -->
                            <div class="modal fade" id="viewDocumentsModal<?= $student_id ?>" tabindex="-1" aria-labelledby="viewDocumentsModalLabel" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="viewDocumentsModalLabel">Documents for <?= htmlspecialchars($applicant['first_name']) ?> <?= htmlspecialchars($applicant['last_name']) ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <?php
                                            $documents = pg_query_params($connection, "SELECT * FROM documents WHERE student_id = $1", [$student_id]);
                                            if (pg_num_rows($documents) > 0) {
                                                while ($doc = pg_fetch_assoc($documents)) {
                                                    echo "<p><strong>" . ucfirst(str_replace("_", " ", $doc['type'])) . ":</strong> <a href='" . htmlspecialchars($doc['file_path']) . "' target='_blank'>View</a></p>";
                                                }
                                            } else {
                                                echo "<p>No documents available.</p>";
                                            }
                                            ?>
                                        </div>
                                        <div class="modal-footer">
                                            <?php if ($isComplete): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="student_id" value="<?= $student_id ?>" />
                                                    <button type="submit" name="mark_verified" class="btn btn-success">Mark as Verified</button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn btn-secondary" disabled>Not all documents uploaded</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
