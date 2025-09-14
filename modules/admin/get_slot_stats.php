<?php
// AJAX endpoint for real-time slot updates
include __DIR__ . '/../../config/database.php';
session_start();

// Security check
if (!isset($_SESSION['admin_username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$municipality_id = 1;

try {
    // Get current active slot info
    $slotInfo = pg_fetch_assoc(pg_query_params($connection, "
        SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1
    ", [$municipality_id]));

    if (!$slotInfo) {
        echo json_encode(['error' => 'No active slot found']);
        exit;
    }

    // Count slots used (enrolled students) for this specific slot
    $slotsUsedQuery = pg_query_params($connection, "
        SELECT COUNT(*) as slots_used FROM students 
        WHERE slot_id = $1 AND municipality_id = $2
    ", [$slotInfo['slot_id'], $municipality_id]);
    $slotsUsed = intval(pg_fetch_assoc($slotsUsedQuery)['slots_used']);

    // Get program capacity
    $capacityResult = pg_query_params($connection, "SELECT max_capacity FROM municipalities WHERE municipality_id = $1", [$municipality_id]);
    $maxCapacity = null;
    if ($capacityResult && pg_num_rows($capacityResult) > 0) {
        $maxCapacity = intval(pg_fetch_assoc($capacityResult)['max_capacity']);
    }

    // Count current total students
    $currentTotalStudentsQuery = pg_query_params($connection, "
        SELECT COUNT(*) as total FROM students 
        WHERE municipality_id = $1 AND status IN ('under_registration', 'applicant', 'active')
    ", [$municipality_id]);
    $currentTotalStudents = intval(pg_fetch_assoc($currentTotalStudentsQuery)['total']);

    // Count pending applications for this specific slot (under_registration status)
    $pendingQuery = pg_query_params($connection, "
        SELECT COUNT(*) as pending FROM students 
        WHERE slot_id = $1 AND municipality_id = $2 AND status = 'under_registration'
    ", [$slotInfo['slot_id'], $municipality_id]);
    $pendingCount = intval(pg_fetch_assoc($pendingQuery)['pending']);

    $approvedQuery = pg_query_params($connection, "
        SELECT COUNT(*) as approved FROM students 
        WHERE slot_id = $1 AND municipality_id = $2 AND status IN ('applicant', 'verified', 'given')
    ", [$slotInfo['slot_id'], $municipality_id]);
    $approvedCount = intval(pg_fetch_assoc($approvedQuery)['approved']);

    // Calculate usage percentage
    $percentage = ($slotsUsed / max(1, $slotInfo['slot_count'])) * 100;
    $barClass = 'bg-success';
    if ($percentage >= 80) $barClass = 'bg-danger';
    elseif ($percentage >= 50) $barClass = 'bg-warning';

    // Check if at capacity
    $atCapacity = $maxCapacity !== null && $currentTotalStudents >= $maxCapacity;

    // Return data
    echo json_encode([
        'success' => true,
        'slotsUsed' => $slotsUsed,
        'slotCount' => $slotInfo['slot_count'],
        'slotsLeft' => $slotInfo['slot_count'] - $slotsUsed,
        'percentage' => round($percentage, 1),
        'barClass' => $barClass,
        'pendingCount' => $pendingCount,
        'approvedCount' => $approvedCount,
        'currentTotalStudents' => $currentTotalStudents,
        'maxCapacity' => $maxCapacity,
        'atCapacity' => $atCapacity,
        'lastUpdated' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

pg_close($connection);
?>