<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit;
}

$municipality_id = 1;

// Handle new slot post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['slot_count'])) {
    $newSlotCount = intval($_POST['slot_count']);
    pg_query_params($connection, "UPDATE signup_slots SET is_active = FALSE WHERE is_active = TRUE AND municipality_id = $1", [$municipality_id]);
    pg_query_params($connection, "INSERT INTO signup_slots (municipality_id, slot_count, is_active) VALUES ($1, $2, TRUE)", [$municipality_id, $newSlotCount]);
}

// Get active slot
$activeSlot = pg_query_params($connection, "SELECT * FROM signup_slots WHERE is_active = TRUE AND municipality_id = $1 ORDER BY created_at DESC LIMIT 1", [$municipality_id]);
$slotInfo = pg_fetch_assoc($activeSlot);

// Count current applicants since slot was activated
$slotsUsed = 0;
$slotsLeft = 0;
if ($slotInfo) {
    $countQuery = "
        SELECT COUNT(*) AS total FROM students 
        WHERE status = 'applicant' AND created_at >= $1
    ";
    $countResult = pg_query_params($connection, $countQuery, [$slotInfo['created_at']]);
    $countRow = pg_fetch_assoc($countResult);
    $slotsUsed = intval($countRow['total']);
    $slotsLeft = intval($slotInfo['slot_count']) - $slotsUsed;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Signup Slots</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h2 class="mb-4">Manage Signup Slots</h2>

    <form method="POST" class="card p-4 mb-5 shadow-sm">
        <div class="mb-3">
            <label class="form-label">Enter number of new applicant slots</label>
            <input type="number" name="slot_count" class="form-control" min="1" required>
        </div>
        <button type="submit" class="btn btn-primary">Release New Slots</button>
    </form>

    <?php if ($slotInfo): ?>
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">Current Active Slot</div>
            <div class="card-body">
                <p><strong>Activated:</strong> <?= htmlspecialchars($slotInfo['created_at']) ?></p>
                <p><strong>Slots Released:</strong> <?= htmlspecialchars($slotInfo['slot_count']) ?></p>
                <p><strong>Slots Used:</strong> <?= $slotsUsed ?></p>
                <p><strong>Slots Remaining:</strong> <?= max(0, $slotsLeft) ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">No active slot found. Add one above.</div>
    <?php endif; ?>
</div>
</body>
</html>
