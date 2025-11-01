<?php
// scripts/insert_duptest.php
// Insert a test student with first_name 'DupTest' and status 'under_registration'.
// Usage: php scripts/insert_duptest.php

// Adjust path if you run this from a different working directory
$configPath = __DIR__ . '/../config/database.php';
if (!is_readable($configPath)) {
    echo "Cannot read database config at {$configPath}\n";
    exit(1);
}

require_once $configPath;

// Basic safety check: $connection should be a valid pg connection resource
if (!isset($connection)) {
    echo "Database connection variable \$connection not found in config.\n";
    exit(1);
}

$first = 'DupTest';
$last  = 'User';
$mobile = '09171234567'; // sensible test mobile number; change as needed for your locale
$bdate = '2000-01-01'; // default birthdate to satisfy NOT NULL constraints in some DBs
$barangay_id = 1; // default barangay id; adjust if your DB requires a different ID

// Generate a simple student_id and unique_student_id to satisfy NOT NULL + UNIQUE constraints
$student_id = 'DupTest_' . time();
$unique_student_id = $student_id;
$password = 'TempPass!23';
// Ensure status matches what Review Registrations expects
// The admin Review Registrations page selects students with status = 'under_registration'
$status = 'under_registration';
// Provide a default sex value to satisfy NOT NULL constraint in some DBs
$sex = 'Female'; // or 'Male'

// Try to insert with a preferred unique_student_id of 'DupTest'. If it conflicts, append epoch.
$preferred_unique = 'DupTest';

// Determine a valid municipality_id and barangay_id from the DB to satisfy FK constraints.
// Prefer existing rows; if none exist, insert minimal placeholder rows.
$municipality_id = null;
$barangay_id_from_db = null;

// Try to get an existing municipality
$res = @pg_query($connection, "SELECT municipality_id FROM municipalities LIMIT 1");
if ($res) {
    $row = pg_fetch_assoc($res);
    if ($row && !empty($row['municipality_id'])) {
        $municipality_id = (int)$row['municipality_id'];
    }
    pg_free_result($res);
}

if (!$municipality_id) {
    // Insert a minimal municipality placeholder
    $res = @pg_query_params($connection, "INSERT INTO municipalities (name) VALUES ($1) RETURNING municipality_id", ['DupTest Municipality']);
    if ($res) {
        $row = pg_fetch_assoc($res);
        $municipality_id = (int)($row['municipality_id'] ?? 0);
        pg_free_result($res);
    }
}

// Now try to find a barangay within that municipality
if ($municipality_id) {
    $res = @pg_query_params($connection, "SELECT barangay_id FROM barangays WHERE municipality_id = $1 LIMIT 1", [$municipality_id]);
    if ($res) {
        $row = pg_fetch_assoc($res);
        if ($row && !empty($row['barangay_id'])) {
            $barangay_id_from_db = (int)$row['barangay_id'];
        }
        pg_free_result($res);
    }
}

if (!$barangay_id_from_db) {
    // Insert a minimal barangay placeholder tied to the municipality
    $res = @pg_query_params($connection, "INSERT INTO barangays (municipality_id, name) VALUES ($1,$2) RETURNING barangay_id", [$municipality_id, 'DupTest Barangay']);
    if ($res) {
        $row = pg_fetch_assoc($res);
        $barangay_id_from_db = (int)($row['barangay_id'] ?? 0);
        pg_free_result($res);
    }
}

// Use resolved IDs
if ($municipality_id) {
    $municipality_id = (int)$municipality_id;
} else {
    $municipality_id = 1; // fallback
}

if ($barangay_id_from_db) {
    $barangay_id = (int)$barangay_id_from_db;
}

function try_insert($conn, $first, $last, $bdate, $barangay_id, $student_id, $unique_student_id, $sex, $mobile, $email, $password, $status, $municipality_id = 1) {
    // Insert required NOT NULL columns explicitly: municipality_id, first_name, last_name, bdate, barangay_id,
    // student_id, email, mobile, password, sex, status. Also set unique_student_id if present.
    $sql = "INSERT INTO students (municipality_id, first_name, last_name, bdate, barangay_id, student_id, unique_student_id, email, mobile, password, sex, status, application_date) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12, now()) RETURNING student_id, email, status, unique_student_id";
    $params = [$municipality_id, $first, $last, $bdate, $barangay_id, $student_id, $unique_student_id, $email, $mobile, $password, $sex, $status];
    $res = @pg_query_params($conn, $sql, $params);
    if ($res) {
        $row = pg_fetch_assoc($res);
        pg_free_result($res);
        return $row;
    }
    return false;
}

// Generate an email unlikely to collide
$email = 'duptest+' . (int)time() . '@example.com';

// First attempt with preferred unique_student_id
$attemptUnique = $preferred_unique;
$result = try_insert($connection, $first, $last, $bdate, $barangay_id, $student_id, $attemptUnique, $sex, $mobile, $email, $password, $status, 1);

if (!$result) {
    // If failed due to duplicate unique_student_id or email, retry with timestamp suffix
    $attemptUnique = $preferred_unique . '_' . (int)time();
    $email = 'duptest+' . (int)time() . '@example.com';
    $result = try_insert($connection, $first, $last, $bdate, $barangay_id, $student_id, $attemptUnique, $sex, $mobile, $email, $password, $status, 1);
}

if ($result) {
    echo "Inserted student: id=" . ($result['student_id'] ?? '') . " email=" . ($result['email'] ?? '') . " status=" . ($result['status'] ?? '') . " unique_student_id=" . ($result['unique_student_id'] ?? '') . "\n";
    exit(0);
} else {
    $err = pg_last_error($connection);
    echo "Insert failed: " . ($err ?: 'unknown error') . "\n";
    exit(2);
}

?>
