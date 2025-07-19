<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

// Handle form submission to post new announcement (date/time auto-filled)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_announcement'])) {
    $title = $_POST['title'];
    $location = $_POST['location'];
    $reminder = $_POST['reminder'];

    // Deactivate all previous announcements
    pg_query($connection, "UPDATE announcements SET is_active = FALSE");

    // Fetch all schedule entries to create new announcements
    $scheduleRes = pg_query($connection, "SELECT schedule_id FROM schedules");
    while ($sch = pg_fetch_assoc($scheduleRes)) {
        $schId = intval($sch['schedule_id']);
        pg_query_params(
            $connection,
            "INSERT INTO announcements (title, location, reminder, schedule_id) VALUES ($1, $2, $3, $4)",
            [$title, $location, $reminder, $schId]
        );
    }
    pg_free_result($scheduleRes);
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
            <!-- Previous announcements listing removed; only form is shown -->
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
