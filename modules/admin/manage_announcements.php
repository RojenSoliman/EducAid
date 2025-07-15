<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $location = $_POST['location'];
    $announcement_date = $_POST['announcement_date'];
    $time = $_POST['time'];
    $reminder = $_POST['reminder'];
    $municipality_id = 1; // hardcoded for GenTri

    // Deactivate old announcement
    pg_query_params($connection, "UPDATE announcements SET is_active = FALSE WHERE is_active = TRUE AND municipality_id = $1", [$municipality_id]);

    // Insert new
    $query = "INSERT INTO announcements (municipality_id, title, location, announcement_date, time, reminder, updated_at) 
              VALUES ($1, $2, $3, $4, $5, $6, NOW())";
    pg_query_params($connection, $query, [$municipality_id, $title, $location, $announcement_date, $time, $reminder]);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Announcements</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
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
                            <a class="nav-link" href="manage_announcements.php">Manage Announcements</a>
                        <li class="nav-item">
                            <a class="nav-link" href="">Manage Applicants</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php">Logout</a>
                        </li>
                    </ul>
                </div>
            </nav>
    <h2>Post New Announcement</h2>
    <form method="POST" class="card p-4 mb-5">
        <div class="mb-3">
            <label class="form-label">Title</label>
            <input type="text" name="title" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Location</label>
            <input type="text" name="location" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Date</label>
            <input type="date" name="announcement_date" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Time</label>
            <input type="time" name="time" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Reminder</label>
            <textarea name="reminder" class="form-control" rows="3"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Post Announcement</button>
    </form>

    <h3>Previous Announcements</h3>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Title</th><th>Location</th><th>Date</th><th>Time</th><th>Reminder</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $res = pg_query($connection, "SELECT * FROM announcements WHERE municipality_id = 1 ORDER BY created_at DESC LIMIT 5");
        while ($row = pg_fetch_assoc($res)) {
            echo "<tr>
                <td>" . htmlspecialchars($row['title']) . "</td>
                <td>" . htmlspecialchars($row['location']) . "</td>
                <td>" . htmlspecialchars($row['announcement_date']) . "</td>
                <td>" . htmlspecialchars($row['time']) . "</td>
                <td>" . htmlspecialchars($row['reminder']) . "</td>
                <td>";
            if ($row['is_active'] === 't' || $row['is_active'] === true || $row['is_active'] == 1) {
                echo '<span class="badge bg-success">Active</span>';
            } else {
                echo '<span class="badge bg-danger">Inactive</span>';
            }
            echo "</td>
            </tr>";
        }
        ?>
        </tbody>
    </table>
</div>
</body>
</html>