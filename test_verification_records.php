<?php
session_start();
require_once 'config/database.php';

echo "<h2>🔍 Current Blacklist Verification Records</h2>";

if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    
    echo "<p><strong>Admin ID from session:</strong> $admin_id</p>";
    
    // Get all verification records for this admin
    $query = "SELECT *, 
                     (expires_at > NOW()) as not_expired,
                     NOW() as current_time,
                     (NOW() - created_at) as age_interval
              FROM admin_blacklist_verifications 
              WHERE admin_id = $1 
              ORDER BY created_at DESC 
              LIMIT 10";
              
    $result = pg_query_params($connection, $query, [$admin_id]);
    
    if ($result && pg_num_rows($result) > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th>ID</th><th>Student ID</th><th>OTP</th><th>Email</th><th>Created</th><th>Expires</th><th>Used</th><th>Valid</th><th>Session Data</th>";
        echo "</tr>";
        
        while ($record = pg_fetch_assoc($result)) {
            $is_valid = ($record['not_expired'] === 't' && $record['used'] === 'f');
            $validity_color = $is_valid ? 'green' : 'red';
            $validity_text = $is_valid ? 'VALID' : 'INVALID';
            
            if ($record['used'] === 't') {
                $validity_text = 'USED';
            } elseif ($record['not_expired'] === 'f') {
                $validity_text = 'EXPIRED';
            }
            
            echo "<tr>";
            echo "<td>" . $record['id'] . "</td>";
            echo "<td>" . $record['student_id'] . "</td>";
            echo "<td style='font-weight: bold; color: blue;'>" . $record['otp'] . "</td>";
            echo "<td>" . htmlspecialchars($record['email']) . "</td>";
            echo "<td>" . $record['created_at'] . "</td>";
            echo "<td>" . $record['expires_at'] . "</td>";
            echo "<td style='color: " . ($record['used'] === 't' ? 'red' : 'green') . ";'>" . ($record['used'] === 't' ? 'YES' : 'NO') . "</td>";
            echo "<td style='color: $validity_color; font-weight: bold;'>$validity_text</td>";
            
            // Show session data
            if ($record['session_data']) {
                $session_data = json_decode($record['session_data'], true);
                echo "<td><small>" . htmlspecialchars($session_data['student_name'] ?? 'N/A') . "</small></td>";
            } else {
                echo "<td>N/A</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<h3>Current Server Time:</h3>";
        $time_result = pg_query($connection, "SELECT NOW() as current_time");
        $time_data = pg_fetch_assoc($time_result);
        echo "<p><strong>Database NOW():</strong> " . $time_data['current_time'] . "</p>";
        echo "<p><strong>PHP time():</strong> " . date('Y-m-d H:i:s') . "</p>";
        
    } else {
        echo "<p>No verification records found for admin ID: $admin_id</p>";
    }
} else {
    echo "<p style='color: red;'>No admin session found. Please log in first.</p>";
}

// Manual OTP test
if (isset($_POST['test_otp'])) {
    $test_student_id = intval($_POST['student_id']);
    $test_otp = trim($_POST['otp']);
    $admin_id = $_SESSION['admin_id'];
    
    echo "<h3>🧪 Testing OTP: $test_otp for Student: $test_student_id</h3>";
    
    // Same query as in the service
    $test_query = "SELECT * FROM admin_blacklist_verifications 
                   WHERE admin_id = $1 AND student_id = $2 AND otp = $3 AND expires_at > NOW() AND used = false";
    
    $test_result = pg_query_params($connection, $test_query, [$admin_id, $test_student_id, $test_otp]);
    
    if ($test_result && pg_num_rows($test_result) > 0) {
        echo "<div style='color: green; background: #d4edda; padding: 10px; border-radius: 5px;'>";
        echo "✅ OTP is VALID and can be used for blacklist completion";
        echo "</div>";
    } else {
        echo "<div style='color: red; background: #f8d7da; padding: 10px; border-radius: 5px;'>";
        echo "❌ OTP is INVALID or expired";
        
        // Check why it failed
        $debug_query = "SELECT *, 
                              (expires_at > NOW()) as not_expired, 
                              used
                       FROM admin_blacklist_verifications 
                       WHERE admin_id = $1 AND student_id = $2 AND otp = $3";
        
        $debug_result = pg_query_params($connection, $debug_query, [$admin_id, $test_student_id, $test_otp]);
        if ($debug_result && $debug_record = pg_fetch_assoc($debug_result)) {
            echo "<br><strong>Debug info:</strong><br>";
            echo "- Record exists: YES<br>";
            echo "- Not expired: " . ($debug_record['not_expired'] === 't' ? 'YES' : 'NO') . "<br>";
            echo "- Not used: " . ($debug_record['used'] === 'f' ? 'YES' : 'NO') . "<br>";
        } else {
            echo "<br>No matching record found for this admin/student/OTP combination.";
        }
        echo "</div>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verification Records Check</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        table { margin: 15px 0; width: 100%; }
        th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .test-form { background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 5px; }
        button { background: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 3px; cursor: pointer; }
        input { padding: 8px; margin: 5px; border: 1px solid #ddd; border-radius: 3px; }
    </style>
</head>
<body>

<div class="test-form">
    <h3>🧪 Test Specific OTP</h3>
    <form method="POST">
        <label><strong>Student ID:</strong></label><br>
        <input type="number" name="student_id" value="1" required><br><br>
        
        <label><strong>OTP to Test:</strong></label><br>
        <input type="text" name="otp" placeholder="Enter 6-digit OTP" required maxlength="6"><br><br>
        
        <button type="submit" name="test_otp">Test This OTP</button>
    </form>
    <p><em>Use an OTP from the table above to test if it's valid.</em></p>
</div>

<p><a href="test_blacklist_modal.php">← Back to Modal Test</a></p>

</body>
</html>