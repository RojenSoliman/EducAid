<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-2 d-flex flex-column bg-light sidebar">
                <div class="sidebar-sticky">
                    <h4 class="text-center mt-3">Admin Dashboard</h4>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="homepage.php">Home</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="verify_students.php">Verify Students</a>
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
            <main class="col-md-9 ms-sm-auto col-lg-10 px-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Welcome, Admin</h1>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <h3>Dashboard Overview</h3>
                        <p>Here you can manage student registrations, verify applicants, and more.</p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="card mb-4">
                            <div class="card-body">
                                <h5 class="card-title">Total Students</h5>
                                <p class="card-text">
                                    <?php
                                    $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students");
                                    $row = pg_fetch_assoc($result);
                                    echo $row['total'];
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card mb-4">
                            <div class="card-body">
                                <h5 class="card-title">Pending Applications</h5>
                                <p class="card-text">
                                    <?php
                                    $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'applicant'");
                                    $row = pg_fetch_assoc($result);
                                    echo $row['total'];
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card mb-4">
                            <div class="card-body">
                                <h5 class="card-title">Verified Students</h5>
                                <p class="card-text">
                                    <?php
                                    $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'active'");
                                    $row = pg_fetch_assoc($result);
                                    echo $row['total'];
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin_homepage.js"></script>
</body>
</html>

<?php
pg_close($connection);
?>
