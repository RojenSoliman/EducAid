<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

include '../../config/database.php';

// Fetch applicants
$applicants = pg_query($connection, "SELECT * FROM students WHERE status = 'applicant'");

?>


<!DOCTYPE html>
<html>
<head>
    <title>Manage Announcements</title>
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
                    <a class="nav-link" href="verify_students.php">Verify Students</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="manage_announcements.php">Manage Announcements</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="">Manage Applicants</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">Logout</a>
                </li>
            </ul>
        </div>
    </nav>
    <div class="container mt-5">
    <h1>Manage Applicants</h1>
    <div class="table-responsive">
      <table class="table table-bordered">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($applicant = pg_fetch_assoc($applicants)) { ?>
            <tr>
              <td><?= htmlspecialchars($applicant['first_name']) . ' ' . htmlspecialchars($applicant['last_name']) ?></td>
              <td><?= htmlspecialchars($applicant['email']) ?></td>
              <td><?= htmlspecialchars($applicant['status']) ?></td>
              <td>
                <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#viewDocumentsModal<?= $applicant['student_id'] ?>">View Documents</button>
              </td>
            </tr>

            <!-- Modal for Viewing Documents -->
            <div class="modal fade" id="viewDocumentsModal<?= $applicant['student_id'] ?>" tabindex="-1" aria-labelledby="viewDocumentsModalLabel" aria-hidden="true">
              <div class="modal-dialog modal-lg">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title" id="viewDocumentsModalLabel">Documents for <?= htmlspecialchars($applicant['first_name']) ?> <?= htmlspecialchars($applicant['last_name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <?php
                    $documents = pg_query_params($connection, "SELECT * FROM documents WHERE student_id = $1", [$applicant['student_id']]);
                    while ($doc = pg_fetch_assoc($documents)) {
                      echo "<p><strong>" . ucfirst(str_replace("_", " ", $doc['type'])) . ":</strong> <a href='" . htmlspecialchars($doc['file_path']) . "' target='_blank'>View</a></p>";
                    }
                    ?>
                  </div>
                  <div class="modal-footer">
                    <form action="verify_document.php" method="POST">
                      <input type="hidden" name="student_id" value="<?= $applicant['student_id'] ?>" />
                      <button type="submit" class="btn btn-success">Mark as Verified</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          <?php } ?>
        </tbody>
      </table>
    </div>
  </div>
    
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</div>
</body>
</html>

