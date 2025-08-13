<?php
// Reset Auto-Generated Payroll Numbers
// This script cleans up any payroll numbers that were auto-generated due to the previous bug

include __DIR__ . '/../config/database.php';

echo "<h2>Reset Auto-Generated Payroll Numbers</h2>";
echo "<pre>";

try {
    // Check if the list is finalized
    $configResult = pg_query($connection, "SELECT value FROM config WHERE key = 'student_list_finalized'");
    $configData = pg_fetch_assoc($configResult);
    $isFinalized = ($configData && $configData['value'] === '1');
    
    if ($isFinalized) {
        echo "⚠️  Student list is already finalized. This script should only be run if payroll was auto-generated accidentally.\n";
        echo "Do you want to proceed? (This will reset all payroll numbers and QR codes)\n\n";
        
        // For safety, require manual confirmation
        if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
            echo "❌ Reset cancelled for safety.\n";
            echo "To proceed, add ?confirm=yes to the URL.\n";
            echo "WARNING: This will reset ALL payroll numbers and QR codes!\n";
            exit;
        }
    }
    
    echo "🔄 Starting cleanup...\n\n";
    
    // Count current payroll assignments
    $countResult = pg_query($connection, "SELECT COUNT(*) as count FROM students WHERE payroll_no > 0");
    $countData = pg_fetch_assoc($countResult);
    $currentCount = $countData['count'];
    
    echo "Current students with payroll numbers: $currentCount\n";
    
    // Count QR codes
    $qrCountResult = pg_query($connection, "SELECT COUNT(*) as count FROM qr_codes");
    $qrCountData = pg_fetch_assoc($qrCountResult);
    $qrCount = $qrCountData['count'];
    
    echo "Current QR code records: $qrCount\n\n";
    
    if ($currentCount > 0 || $qrCount > 0) {
        // Reset payroll numbers
        echo "Resetting payroll numbers...\n";
        $resetPayroll = pg_query($connection, "UPDATE students SET payroll_no = 0 WHERE status = 'active'");
        if ($resetPayroll) {
            echo "✅ Payroll numbers reset successfully\n";
        } else {
            echo "❌ Error resetting payroll numbers: " . pg_last_error($connection) . "\n";
        }
        
        // Delete QR codes
        echo "Deleting QR code records...\n";
        $deleteQR = pg_query($connection, "DELETE FROM qr_codes");
        if ($deleteQR) {
            echo "✅ QR code records deleted successfully\n";
        } else {
            echo "❌ Error deleting QR codes: " . pg_last_error($connection) . "\n";
        }
        
        // Reset finalized status if it was set
        if ($isFinalized) {
            echo "Resetting finalized status...\n";
            $resetFinalized = pg_query($connection, "UPDATE config SET value = '0' WHERE key = 'student_list_finalized'");
            if ($resetFinalized) {
                echo "✅ Finalized status reset\n";
            } else {
                echo "❌ Error resetting finalized status: " . pg_last_error($connection) . "\n";
            }
        }
        
        // Add notification
        $notification = "System reset: Auto-generated payroll numbers and QR codes have been cleared. Please finalize list and generate payroll manually.";
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification]);
        
        echo "\n🎉 Cleanup completed successfully!\n";
        echo "The system is now ready for proper workflow:\n";
        echo "1. Finalize the student list\n";
        echo "2. Click 'Generate Payroll Numbers' when ready\n";
        echo "3. Access scheduling and QR scanning features\n";
        
    } else {
        echo "✅ No cleanup needed - no payroll numbers or QR codes found\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<p><a href='modules/admin/verify_students.php'>Go to Verify Students</a> | <a href='modules/admin/homepage.php'>Go to Dashboard</a></p>";

pg_close($connection);
?>
