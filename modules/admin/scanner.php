<?php

session_start();

// Ensure the admin is logged in, otherwise redirect to the login page
if (!isset($_SESSION['admin_username'])) {
    header("Location: admin_login.php");
    exit;
}

// Include the database connection to establish the connection
include __DIR__ . '/../../config/database.php';

// Query to get active applicants with a QR code and payroll number
// Ensure only students who are active and have both QR codes and payroll numbers are selected
$qr_res = pg_query($connection, "
    SELECT 
        qr_codes.qr_id, 
        qr_codes.payroll_number, 
        qr_codes.student_id, 
        qr_codes.status AS qr_status, 
        students.first_name, 
        students.last_name, 
        students.status AS student_status
    FROM 
        qr_codes
    JOIN 
        students ON students.student_id = qr_codes.student_id
    WHERE 
        students.status = 'active'  -- Only active students
        AND qr_codes.payroll_number IS NOT NULL  -- Students with a payroll number
        AND qr_codes.unique_id IS NOT NULL  -- Students with a QR code
    ORDER BY qr_codes.created_at DESC
");

// Check if the query is successful
if (!$qr_res) {
    echo "Error executing query: " . pg_last_error($connection);
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Scan QR - Admin</title>
    <link href="https://fonts.googleapis.com/css?family=Poppins:400,500,600&display=swap" rel="stylesheet">
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/admin/homepage.css" rel="stylesheet">
    <link href="../../assets/css/admin/sidebar.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .qr-center-viewport {
            min-height: 100vh;
            width: 100vw;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f4f7f6;
        }
        .qr-box {
            max-width: 420px;
            width: 100%;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            padding: 36px 28px 32px 28px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .controls {
            margin: 1rem 0;
        }
        button, select {
            padding: 0.5rem 1rem;
            margin: 0.5rem 0.5rem;
            font-size: 1rem;
            cursor: pointer;
        }
        #result {
            font-family: monospace;
            color: #333;
        }
    </style>
</head>
<body>
    <div id="wrapper">
        <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
        <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
        <section class="home-section" id="page-content-wrapper">
            <nav>
                <div class="sidebar-toggle px-4 py-3">
                    <i class="bi bi-list" id="menu-toggle" aria-label="Toggle Sidebar"></i>
                </div>
            </nav>

            <div class="container py-5">
                <h2>Scan QR</h2>

                <!-- QR Code Scanner -->
                <h3>QR Code Scanner</h3>
                <div class="qr-center-viewport">
                    <div class="qr-box">
                        <div id="reader"></div>
                        <div class="controls">
                            <select id="camera-select">
                                <option value="">Select Camera</option>
                            </select>
                            <br />
                            <button id="start-button">Start Scanner</button>
                            <button id="stop-button" disabled>Stop Scanner</button>
                        </div>

                        <p><strong>Result:</strong> <span id="result">—</span></p>
                    </div>
                </div>

                <script src="https://unpkg.com/html5-qrcode"></script>
                <script>
                    const startButton = document.getElementById('start-button');
                    const stopButton = document.getElementById('stop-button');
                    const resultSpan = document.getElementById('result');
                    const cameraSelect = document.getElementById('camera-select');
                    const html5QrCode = new Html5Qrcode("reader");

                    let currentCameraId = null;

                    // Populate camera dropdown on load
                    Html5Qrcode.getCameras().then(cameras => {
                        if (!cameras.length) {
                            alert("No cameras found.");
                            return;
                        }

                        cameras.forEach(camera => {
                            const option = document.createElement('option');
                            option.value = camera.id;
                            option.text = camera.label || `Camera ${camera.id}`;
                            cameraSelect.appendChild(option);
                        });

                        const backCam = cameras.find(cam => cam.label.toLowerCase().includes('back'));
                        if (backCam) {
                            cameraSelect.value = backCam.id;
                            currentCameraId = backCam.id;
                        } else {
                            cameraSelect.selectedIndex = 1; // first available camera
                            currentCameraId = cameras[0].id;
                        }
                    });

                    cameraSelect.addEventListener('change', () => {
                        currentCameraId = cameraSelect.value;
                    });

                    startButton.addEventListener('click', () => {
                        if (!currentCameraId) {
                            alert("Please select a camera.");
                            return;
                        }

                        html5QrCode.start(
                            currentCameraId,
                            { fps: 10, qrbox: { width: 250, height: 250 } },
                            decodedText => {
                                resultSpan.textContent = decodedText;
                                html5QrCode.stop();
                                startButton.disabled = false;
                                stopButton.disabled = true;
                            },
                            error => {
                                // decode errors ignored
                            }
                        ).then(() => {
                            startButton.disabled = true;
                            stopButton.disabled = false;
                        }).catch(err => {
                            console.error("Failed to start scanning:", err);
                        });
                    });

                    stopButton.addEventListener('click', () => {
                        html5QrCode.stop()
                            .then(() => {
                                startButton.disabled = false;
                                stopButton.disabled = true;
                            })
                            .catch(err => console.error("Failed to stop scanning:", err));
                    });
                </script>

                <!-- Table displaying the QR code status and students info -->
                <h3>QR Code and Student Status</h3>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>Payroll Number</th>
                            <th>QR Code Status</th>
                            <th>Student Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Fetch all QR codes and display them
                        if ($qr_count > 0) {
                            while ($row = pg_fetch_assoc($qr_res)) {
                                // Determine QR code status: 'Given' if status is 'Done', otherwise 'Pending'
                                $qr_status = ($row['qr_status'] === 'Done') ? 'Given' : 'Pending';
                                
                                // Fetch student status (already fetched as part of the query)
                                $student_status = $row['student_status'];
                                
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($row['qr_id']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['first_name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['last_name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['payroll_number']) . "</td>";
                                echo "<td>" . htmlspecialchars($qr_status) . "</td>";
                                echo "<td>" . htmlspecialchars($student_status) . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='6' class='text-center'>No active students with QR codes and payroll numbers found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script src="../../assets/js/admin/sidebar.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
