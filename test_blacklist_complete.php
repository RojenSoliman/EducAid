<?php
session_start();
require_once 'config/database.php';

// Simulate admin session for testing
if (!isset($_SESSION['admin_id'])) {
    $admin_query = "SELECT admin_id, email, first_name, last_name FROM admins LIMIT 1";
    $admin_result = pg_query($connection, $admin_query);
    
    if ($admin = pg_fetch_assoc($admin_result)) {
        $_SESSION['admin_id'] = $admin['admin_id'];
        $_SESSION['admin_username'] = trim($admin['first_name'] . ' ' . $admin['last_name']);
        $_SESSION['admin_role'] = 'admin';
    }
}

echo "<h2>🧪 Blacklist OTP Verification Test</h2>";

// Test the complete_blacklist action directly
if (isset($_POST['test_complete'])) {
    echo "<h3>Testing Complete Blacklist API...</h3>";
    
    $student_id = intval($_POST['student_id']);
    $otp = $_POST['otp'];
    
    // Make a POST request to the blacklist service
    $postData = [
        'action' => 'complete_blacklist',
        'student_id' => $student_id,
        'otp' => $otp
    ];
    
    echo "<p><strong>Sending data:</strong></p>";
    echo "<pre>" . print_r($postData, true) . "</pre>";
    
    // Using cURL to test the service
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/EducAid/modules/admin/blacklist_service.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "<p><strong>HTTP Code:</strong> $http_code</p>";
    echo "<p><strong>Response:</strong></p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
}

// Get existing verification records
echo "<h3>Current Verification Records:</h3>";
if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $verify_query = "SELECT * FROM admin_blacklist_verifications WHERE admin_id = $1 ORDER BY created_at DESC LIMIT 5";
    $verify_result = pg_query_params($connection, $verify_query, [$admin_id]);
    
    if ($verify_result && pg_num_rows($verify_result) > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f8f9fa;'><th>ID</th><th>Student ID</th><th>OTP</th><th>Email</th><th>Expires</th><th>Used</th><th>Created</th></tr>";
        
        while ($record = pg_fetch_assoc($verify_result)) {
            $expired = strtotime($record['expires_at']) < time() ? 'EXPIRED' : 'VALID';
            $used = $record['used'] ? 'YES' : 'NO';
            
            echo "<tr>";
            echo "<td>" . $record['id'] . "</td>";
            echo "<td>" . $record['student_id'] . "</td>";
            echo "<td style='font-weight: bold; color: #007bff;'>" . $record['otp'] . "</td>";
            echo "<td>" . $record['email'] . "</td>";
            echo "<td style='color: " . ($expired === 'EXPIRED' ? 'red' : 'green') . ";'>" . $record['expires_at'] . "<br><small>($expired)</small></td>";
            echo "<td style='color: " . ($used === 'YES' ? 'red' : 'green') . ";'>" . $used . "</td>";
            echo "<td>" . $record['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No verification records found for admin ID: $admin_id</p>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Blacklist OTP Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        table { margin: 15px 0; }
        th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        .test-form { background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #007bff; }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; }
        input { padding: 8px; margin: 5px; border: 1px solid #ddd; border-radius: 3px; }
        pre { background: #f1f1f1; padding: 10px; border-radius: 3px; overflow-x: auto; }
    </style>
</head>
<body>

<div class="test-form">
    <h3>🧪 Test Complete Blacklist Action</h3>
    <form method="POST">
        <label><strong>Student ID:</strong></label><br>
        <input type="number" name="student_id" value="1" required><br><br>
        
        <label><strong>OTP Code:</strong></label><br>
        <input type="text" name="otp" placeholder="Enter OTP from table above" required maxlength="6"><br><br>
        
        <button type="submit" name="test_complete">Test Complete Blacklist</button>
    </form>
    <p><em>Use an OTP from the table above that is VALID and not USED.</em></p>
</div>

<div style="margin-top: 30px; padding: 15px; background: #e9ecef; border-radius: 5px;">
    <h4>📋 Instructions:</h4>
    <ol>
        <li>First, use the blacklist modal to initiate a blacklist and get an OTP</li>
        <li>Find the OTP in the table above (make sure it's VALID and not USED)</li>
        <li>Use this form to test the complete_blacklist action directly</li>
        <li>Check the response to see what's happening</li>
    </ol>
</div>

<p><a href="test_blacklist_modal.php">← Back to Modal Test</a> | <a href="blacklist_debug.php">Debug Tool</a></p>

</body>
</html>