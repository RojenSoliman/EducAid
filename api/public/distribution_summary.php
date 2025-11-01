<?php
/**
 * Public Distribution Summary API
 * Returns: status (open/closed), academic_year, semester, slot_count, slots_left, last_updated
 * Anonymous-safe: does not require session.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/database.php';

// Default municipality (public site runs single municipality). Allow override via ?municipality_id=NN
$municipality_id = isset($_GET['municipality_id']) ? intval($_GET['municipality_id']) : 1;

try {
    // Get current active slot for the municipality
    $slotRes = pg_query_params($connection, "SELECT slot_id, slot_count, semester, academic_year FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
    if (!$slotRes || pg_num_rows($slotRes) === 0) {
        echo json_encode([
            'success' => true,
            'status' => 'closed',
            'academic_year' => null,
            'semester' => null,
            'slot_count' => 0,
            'slots_left' => 0,
            'last_updated' => date('c')
        ]);
        pg_close($connection);
        exit;
    }

    $slot = pg_fetch_assoc($slotRes);
    $slot_id = intval($slot['slot_id']);
    $slot_count = intval($slot['slot_count']);

    // Count filled slots: students assigned to this active slot for this municipality
    $usedRes = pg_query_params(
        $connection,
        "SELECT COUNT(*) AS used FROM students WHERE slot_id = $1 AND municipality_id = $2",
        [$slot_id, $municipality_id]
    );
    $used = 0;
    if ($usedRes && pg_num_rows($usedRes) === 1) {
        $used = intval(pg_fetch_assoc($usedRes)['used']);
    }

    $slots_left = max(0, $slot_count - $used);

    echo json_encode([
        'success' => true,
        'status' => 'open',
        'academic_year' => $slot['academic_year'] ?? null,
        'semester' => $slot['semester'] ?? null,
        'slot_count' => $slot_count,
        'slots_left' => $slots_left,
        'last_updated' => date('c')
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

if (isset($connection)) pg_close($connection);
