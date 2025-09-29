<?php
session_start();
$_SESSION['admin_username'] = 'test_admin'; // Simulate admin login for testing
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification System Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8">
                <h2>Admin Notification System Test</h2>
                <div class="mb-4">
                    <h5>Notification Bell</h5>
                    <button type="button" class="btn btn-primary position-relative admin-icon-btn" title="Notifications">
                        <i class="bi bi-bell fs-5"></i>
                        <span class="badge rounded-pill bg-danger" style="font-size: .55rem; position: absolute; top: -6px; right: -6px; display: none;">0</span>
                    </button>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Test Controls</h6>
                    </div>
                    <div class="card-body">
                        <button id="test-get-count" class="btn btn-info me-2">Test Get Count</button>
                        <button id="test-mark-all" class="btn btn-warning me-2">Test Mark All Read</button>
                        <button id="create-notif" class="btn btn-success me-2">Create Test Notification</button>
                        <button id="refresh-count" class="btn btn-secondary">Refresh Count</button>
                    </div>
                </div>
                
                <div class="alert alert-info mt-4">
                    <h6>Test Results:</h6>
                    <div id="test-results">
                        <p class="mb-0">Ready to test...</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Session Info</h6>
                    </div>
                    <div class="card-body small">
                        <p><strong>Admin User:</strong> <?= $_SESSION['admin_username'] ?? 'Not logged in' ?></p>
                        <p><strong>Session ID:</strong> <?= session_id() ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../../assets/js/admin/notification_bell.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const results = document.getElementById('test-results');
            
            function addResult(message, success = null) {
                const p = document.createElement('p');
                p.textContent = `${new Date().toLocaleTimeString()}: ${message}`;
                p.className = success === true ? 'text-success mb-1' : success === false ? 'text-danger mb-1' : 'text-info mb-1';
                results.appendChild(p);
                results.scrollTop = results.scrollHeight;
            }
            
            // Test get count
            document.getElementById('test-get-count').onclick = function() {
                addResult('Testing get count API...');
                fetch('notifications_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=get_count'
                })
                .then(response => response.json())
                .then(data => {
                    addResult(`Get Count: ${data.success ? 'SUCCESS' : 'FAILED'} - Count: ${data.count}`, data.success);
                })
                .catch(error => {
                    addResult(`Get Count: ERROR - ${error.message}`, false);
                });
            };
            
            // Test mark all read
            document.getElementById('test-mark-all').onclick = function() {
                addResult('Testing mark all read API...');
                fetch('notifications_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=mark_all_read'
                })
                .then(response => response.json())
                .then(data => {
                    addResult(`Mark All Read: ${data.success ? 'SUCCESS' : 'FAILED'} - ${data.message}`, data.success);
                })
                .catch(error => {
                    addResult(`Mark All Read: ERROR - ${error.message}`, false);
                });
            };
            
            // Create test notification
            document.getElementById('create-notif').onclick = function() {
                addResult('Creating test notification...');
                // This would typically be done server-side, but for testing we'll simulate
                fetch('../../sql/create_test_notifications.php')
                .then(response => response.text())
                .then(data => {
                    addResult('Test notification created (check server output)', true);
                })
                .catch(error => {
                    addResult(`Create Notification: ERROR - ${error.message}`, false);
                });
            };
            
            // Refresh count manually
            document.getElementById('refresh-count').onclick = function() {
                addResult('Manually refreshing notification count...');
                // The notification bell script should handle this automatically
            };
            
            addResult('Test page loaded. Notification bell should auto-update every 30 seconds.');
        });
    </script>
</body>
</html>