<?php
// Helper one-off migration runner for sidebar_theme_settings
// Run from browser: http://localhost/EducAid/sql/migrate_sidebar_theme.php
// Or CLI: php sql/migrate_sidebar_theme.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';

function out($msg) { echo $msg . (php_sapi_name()==='cli' ? PHP_EOL : '<br>'); }

$tableCheck = pg_query($connection, "SELECT 1 FROM information_schema.tables WHERE table_name='sidebar_theme_settings'");
if ($tableCheck && pg_fetch_row($tableCheck)) {
    out('Table sidebar_theme_settings already exists.');
} else {
    out('Table does not exist yet. Creating...');
}

$sqlFile = __DIR__ . '/create_sidebar_theme_settings.sql';
if (!is_readable($sqlFile)) {
    out('ERROR: SQL file not readable: ' . $sqlFile);
    exit(1);
}

$sql = file_get_contents($sqlFile);
out('SQL file length: ' . strlen($sql) . ' bytes');

// Check connection state first
out('Connection state: ' . (pg_connection_status($connection) === PGSQL_CONNECTION_OK ? 'OK' : 'BAD'));
$txStatus = pg_transaction_status($connection);
out('Transaction status: ' . $txStatus . ' (0=IDLE, 1=ACTIVE, 2=INTRANS, 3=INERROR, 4=UNKNOWN)');

// If in error state, rollback first
if ($txStatus === PGSQL_TRANSACTION_INERROR) {
    out('Detected aborted transaction. Rolling back...');
    pg_query($connection, 'ROLLBACK');
    out('Rollback done. New status: ' . pg_transaction_status($connection));
}

// Split on semicolons that end a statement (very basic split)
$statements = array_filter(array_map('trim', preg_split('/;\s*\n|;\s*$/m', $sql)));
out('Found ' . count($statements) . ' statements to execute');
if (count($statements) === 0) {
    out('DEBUG: No statements parsed. First 500 chars of SQL:');
    out(htmlspecialchars(substr($sql, 0, 500)));
}
$ok = true;
foreach ($statements as $idx => $stmt) {
    out('Statement #' . ($idx+1) . ' length: ' . strlen($stmt));
    // Remove leading comment lines from the statement
    $lines = explode("\n", $stmt);
    $cleanLines = array_filter($lines, fn($l) => !str_starts_with(trim($l), '--'));
    $stmt = trim(implode("\n", $cleanLines));
    
    if ($stmt === '') {
        out('  Skipped (empty after removing comments)');
        continue;
    }
    out('Executing: ' . htmlspecialchars(substr($stmt,0,80)) . (strlen($stmt)>80?'...':''));
    $res = pg_query($connection, $stmt); 
    if (!$res) {
        $err = pg_last_error($connection);
        out('  FAILED: ' . $err);
        out('  Transaction status after failure: ' . pg_transaction_status($connection));
        $ok = false;
        // Try to continue after rollback
        if (pg_transaction_status($connection) === PGSQL_TRANSACTION_INERROR) {
            pg_query($connection, 'ROLLBACK');
        }
    } else {
        out('  OK (affected: ' . pg_affected_rows($res) . ')');
    }
}

// Re-check existence & row
$tableCheck2 = pg_query($connection, "SELECT 1 FROM information_schema.tables WHERE table_name='sidebar_theme_settings'");
if ($tableCheck2 && pg_fetch_row($tableCheck2)) {
    out('Verified table exists.');
    $rowCountRes = pg_query($connection, "SELECT COUNT(*) FROM sidebar_theme_settings");
    if ($rowCountRes) {
        $cnt = pg_fetch_result($rowCountRes,0,0);
        out('Row count: ' . $cnt);
        if ($cnt == 0) {
            out('WARNING: No seed row present. Attempting to insert default row...');
            $ins = pg_query($connection, "INSERT INTO sidebar_theme_settings (municipality_id) SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM sidebar_theme_settings WHERE municipality_id=1)");
            if ($ins) {
                out('Inserted default seed row.');
            } else {
                out('Failed to insert seed row: ' . pg_last_error($connection));
            }
        }
    }
} else {
    out('ERROR: Table still does not exist after running script.');
}

if (!$ok) {
    out('Completed with errors.');
    exit(2);
}

out('Migration completed successfully.');
?>