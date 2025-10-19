<?php
/**
 * Test Access Control Implementation
 * 
 * Verifies that workflow-based access control is properly enforced for:
 * - Manage Applicants
 * - Verify Students
 * - Manage Slots
 * - Scheduling
 * - QR Scanner
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/workflow_control.php';

echo "<h2>🔒 Access Control Test</h2>";
echo "<hr>";

// Test 1: Get current workflow status
echo "<h3>Test 1: Workflow Status</h3>";
$workflow_status = getWorkflowStatus($connection);
echo "<pre>";
print_r($workflow_status);
echo "</pre>";

// Test 2: Check distribution status from config
echo "<h3>Test 2: Distribution Status from Config</h3>";
$config_query = "SELECT key, value FROM config WHERE key IN ('distribution_status', 'current_academic_year', 'current_semester')";
$config_result = pg_query($connection, $config_query);
$config_data = [];
while ($row = pg_fetch_assoc($config_result)) {
    $config_data[$row['key']] = $row['value'];
}
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Config Key</th><th>Value</th></tr>";
foreach ($config_data as $key => $value) {
    echo "<tr><td>$key</td><td><strong>$value</strong></td></tr>";
}
echo "</table>";

// Test 3: Page Access Control Matrix
echo "<h3>Test 3: Page Access Matrix</h3>";
echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr style='background-color: #f0f0f0;'>";
echo "<th>Page</th>";
echo "<th>Workflow Flag</th>";
echo "<th>Access Status</th>";
echo "<th>Expected Behavior</th>";
echo "</tr>";

$pages = [
    [
        'name' => 'Manage Applicants',
        'flag' => 'can_manage_applicants',
        'desc' => 'Should be locked unless distribution is preparing/active'
    ],
    [
        'name' => 'Verify Students',
        'flag' => 'can_verify_students',
        'desc' => 'Should be locked unless distribution is preparing/active'
    ],
    [
        'name' => 'Manage Slots',
        'flag' => 'can_manage_slots',
        'desc' => 'Should be locked unless distribution is preparing/active'
    ],
    [
        'name' => 'Scheduling',
        'flag' => 'can_schedule',
        'desc' => 'Should be locked unless payroll & QR codes generated'
    ],
    [
        'name' => 'Scan QR',
        'flag' => 'can_scan_qr',
        'desc' => 'Should be locked unless payroll & QR codes generated'
    ]
];

foreach ($pages as $page) {
    $flag = $page['flag'];
    $can_access = $workflow_status[$flag] ?? false;
    $status_color = $can_access ? 'green' : 'red';
    $status_text = $can_access ? '✅ ACCESSIBLE' : '🔒 LOCKED';
    
    echo "<tr>";
    echo "<td><strong>{$page['name']}</strong></td>";
    echo "<td><code>$flag</code></td>";
    echo "<td style='color: $status_color; font-weight: bold;'>$status_text</td>";
    echo "<td style='font-size: 0.9em;'>{$page['desc']}</td>";
    echo "</tr>";
}
echo "</table>";

// Test 4: Sidebar Workflow Variables
echo "<h3>Test 4: Sidebar Variables (Simulated)</h3>";
echo "<p>These variables would be used in <code>admin_sidebar.php</code>:</p>";
echo "<pre>";
echo "\$canManageApplicants = " . ($workflow_status['can_manage_applicants'] ? 'true' : 'false') . ";\n";
echo "\$canVerifyStudents = " . ($workflow_status['can_verify_students'] ? 'true' : 'false') . ";\n";
echo "\$canManageSlots = " . ($workflow_status['can_manage_slots'] ? 'true' : 'false') . ";\n";
echo "\$canSchedule = " . ($workflow_status['can_schedule'] ? 'true' : 'false') . ";\n";
echo "\$canScanQR = " . ($workflow_status['can_scan_qr'] ? 'true' : 'false') . ";\n";
echo "</pre>";

// Test 5: Distribution Requirements
echo "<h3>Test 5: Access Requirements</h3>";
$distribution_status = $config_data['distribution_status'] ?? 'unknown';
$has_active_distribution = in_array($distribution_status, ['preparing', 'active']);

echo "<table border='1' cellpadding='10'>";
echo "<tr style='background-color: #f0f0f0;'><th>Requirement</th><th>Status</th><th>Result</th></tr>";

// Check 1: Distribution Status
$dist_icon = $has_active_distribution ? '✅' : '❌';
echo "<tr>";
echo "<td><strong>Active Distribution</strong><br><small>Status must be 'preparing' or 'active'</small></td>";
echo "<td>Distribution Status: <strong>$distribution_status</strong></td>";
echo "<td style='font-size: 1.5em;'>$dist_icon</td>";
echo "</tr>";

// Check 2: Payroll & QR Codes
$has_payroll_qr = $workflow_status['has_payroll_and_qr'] ?? false;
$payroll_icon = $has_payroll_qr ? '✅' : '❌';
echo "<tr>";
echo "<td><strong>Payroll & QR Codes Generated</strong><br><small>Required for Scheduling and QR Scanner</small></td>";
echo "<td>Has Payroll/QR: <strong>" . ($has_payroll_qr ? 'Yes' : 'No') . "</strong></td>";
echo "<td style='font-size: 1.5em;'>$payroll_icon</td>";
echo "</tr>";

echo "</table>";

// Test 6: Action Items
echo "<h3>Test 6: Next Steps</h3>";
if (!$has_active_distribution) {
    echo "<div style='background-color: #fff3cd; padding: 15px; border-left: 4px solid #ffc107;'>";
    echo "<strong>⚠️ No Active Distribution</strong><br>";
    echo "To enable Manage Applicants, Verify Students, and Manage Slots pages:<br>";
    echo "<ol>";
    echo "<li>Go to <strong>Distribution Control</strong></li>";
    echo "<li>Click <strong>Start New Distribution</strong></li>";
    echo "<li>Set Academic Period and Municipality</li>";
    echo "<li>Click <strong>Start Distribution</strong></li>";
    echo "</ol>";
    echo "</div>";
} else {
    echo "<div style='background-color: #d1ecf1; padding: 15px; border-left: 4px solid #17a2b8;'>";
    echo "<strong>✅ Distribution Active</strong><br>";
    echo "Basic pages (Manage Applicants, Verify Students, Manage Slots) are now accessible!<br>";
    if (!$has_payroll_qr) {
        echo "<br><strong>Next Step:</strong> Generate payroll numbers and QR codes to enable Scheduling and QR Scanner.";
    } else {
        echo "<br><strong>All pages accessible!</strong> You can now use all distribution features.";
    }
    echo "</div>";
}

// Test 7: Summary
echo "<h3>Test 7: Summary</h3>";
$total_pages = count($pages);
$accessible_pages = 0;
foreach ($pages as $page) {
    if ($workflow_status[$page['flag']] ?? false) {
        $accessible_pages++;
    }
}
$locked_pages = $total_pages - $accessible_pages;

echo "<div style='background-color: #f8f9fa; padding: 20px; border-radius: 5px;'>";
echo "<h4>Access Control Status:</h4>";
echo "<ul style='font-size: 1.1em;'>";
echo "<li><strong style='color: green;'>✅ Accessible:</strong> $accessible_pages pages</li>";
echo "<li><strong style='color: red;'>🔒 Locked:</strong> $locked_pages pages</li>";
echo "</ul>";

if ($locked_pages == 0) {
    echo "<p style='color: green; font-weight: bold;'>🎉 All workflow controls passed! All pages are accessible.</p>";
} else {
    echo "<p style='color: orange; font-weight: bold;'>⚠️ Some pages are locked. Follow the steps above to enable them.</p>";
}
echo "</div>";

echo "<hr>";
echo "<p><small>Test completed at: " . date('Y-m-d H:i:s') . "</small></p>";
?>
