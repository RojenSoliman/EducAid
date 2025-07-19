<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

// Handle form submission to post new announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_announcement'])) {
    $title = $_POST['title'];
    $location = $_POST['location'];
    $announcement_date = $_POST['announcement_date'];
    $time = $_POST['time'];
    $reminder = $_POST['reminder'];
    $municipality_id = 1; // GenTri

    // Deactivate previous announcement
    pg_query_params($connection, "UPDATE announcements SET is_active = FALSE WHERE is_active = TRUE AND municipality_id = $1", [$municipality_id]);

    // Insert new
    $query = "INSERT INTO announcements (municipality_id, title, location, announcement_date, time, reminder, updated_at) 
              VALUES ($1, $2, $3, $4, $5, $6, NOW())";
    pg_query_params($connection, $query, [$municipality_id, $title, $location, $announcement_date, $time, $reminder]);
}

// Handle toggle active/inactive
if (isset($_POST['toggle_status'])) {
    $announcement_id = $_POST['announcement_id'];
    $new_status = $_POST['new_status'] === '1' ? 'FALSE' : 'TRUE';

    // If enabling this one, disable others first
    if ($new_status === 'TRUE') {
        pg_query_params($connection, "UPDATE announcements SET is_active = FALSE WHERE municipality_id = $1", [1]);
    }

    // Update the selected one
    pg_query_params($connection, "UPDATE announcements SET is_active = $new_status::BOOLEAN WHERE announcement_id = $1", [$announcement_id]);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Announcements</title>
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
        <div class="container py-4">
            <!-- New Announcement Form -->
            <h2>Post New Announcement</h2>
            <form method="POST" class="card p-4 mb-5">
                <input type="hidden" name="post_announcement" value="1">
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

            <!-- Previous Announcements -->
            <h3>Previous Announcements</h3>
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Title</th><th>Location</th><th>Date</th><th>Time</th><th>Reminder</th><th>Status</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $res = pg_query($connection, "SELECT * FROM announcements WHERE municipality_id = 1 ORDER BY created_at DESC LIMIT 5");
                    while ($row = pg_fetch_assoc($res)) {
                        $id = $row['announcement_id'];
                        $is_active = $row['is_active'] === 't' || $row['is_active'] === true;
                        echo "<tr>
                            <td>" . htmlspecialchars($row['title']) . "</td>
                            <td>" . htmlspecialchars($row['location']) . "</td>
                            <td>" . htmlspecialchars($row['announcement_date']) . "</td>
                            <td>" . htmlspecialchars($row['time']) . "</td>
                            <td>" . htmlspecialchars($row['reminder']) . "</td>
                            <td>";
                        echo $is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>';
                        echo "</td>
                            <td>
                                <form method='POST'>
                                    <input type='hidden' name='announcement_id' value='$id'>
                                    <input type='hidden' name='new_status' value='" . ($is_active ? "1" : "0") . "'>
                                    <button type='submit' name='toggle_status' class='btn btn-sm btn-outline-" . ($is_active ? "danger" : "success") . "'>
                                        " . ($is_active ? "Disable" : "Enable") . "
                                    </button>
                                </form>
                            </td>
                        </tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>


</body>
</html>
