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
        exit;
    } elseif (isset($_POST['reject_applicant']) && isset($_POST['student_id'])) {
        $student_id = $_POST['student_id'];
        // Delete uploaded document files and records so student can re-upload
        $docsToDelete = pg_query_params($connection, "SELECT file_path FROM documents WHERE student_id = $1", [$student_id]);
        while ($d = pg_fetch_assoc($docsToDelete)) {
            $path = $d['file_path'];
            if ($path && file_exists($path)) {
                @unlink($path);
            }
        }
        pg_query_params($connection, "DELETE FROM documents WHERE student_id = $1", [$student_id]);
        // Record rejection notification for student
        $msg = 'Your uploaded documents were rejected on ' . date('F j, Y, g:i a') . '. Please re-upload.';
        pg_query_params($connection, "INSERT INTO notifications (student_id, message) VALUES ($1, $2)", [$student_id, $msg]);
        echo "<script>alert('Applicant has been rejected; documents have been reset and they can re-upload.'); window.location.href = 'manage_applicants.php';</script>";
        exit;
    }
}

// Get filter and sort parameters
$sort = $_GET['sort'] ?? 'asc';
$searchSurname = trim($_GET['search_surname'] ?? '');

// Base query
$query = "SELECT * FROM students WHERE status = 'applicant'";
$params = [];

// If searching by surname
if (!empty($searchSurname)) {
    $query .= " AND last_name ILIKE $1";
    $params[] = "%$searchSurname%";
}

// Add sorting
$query .= " ORDER BY last_name " . ($sort === 'desc' ? 'DESC' : 'ASC');

// Run the correct query function
if (!empty($params)) {
    $applicants = pg_query_params($connection, $query, $params);
} else {
    $applicants = pg_query($connection, $query);
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Applicants</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
    <link rel="stylesheet" href="../../assets/css/admin_homepage.css">
    <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
</head>
<body class="bg-light">

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar -->
    <?php include '../../includes/admin/admin_sidebar.php'; ?>

    <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>

    <!-- Main -->
    <main class="col-md-10 ms-sm-auto px-4 py-4">
        <div class="container mt-5">
        <h1>Manage Applicants</h1>
        <div class="row mb-4">
            <!-- Sort and Search Form -->
            <form class="d-flex" method="GET">
                <div class="col-md-4">
                    <label for="sort" class="form-label">Sort by Surname</label>
                    <select name="sort" id="sort" class="form-select">
                        <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>A to Z</option>
                        <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="search_surname" class="form-label">Search by Surname</label>
                    <input type="text" name="search_surname" class="form-control" value="<?= htmlspecialchars($searchSurname) ?>" />
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                </div>
            </form>
        </div>

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
                                <td><?= htmlspecialchars($applicant['last_name'] . ', ' . $applicant['first_name'] . ' ' . $applicant['middle_name']); ?></td>
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
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="student_id" value="<?= $student_id ?>" />
                                                    <button type="submit" name="mark_verified" class="btn btn-success">Mark as Verified</button>
                                                    <button type="submit" name="reject_applicant" class="btn btn-danger ms-2">Reject</button>
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
    </main>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
