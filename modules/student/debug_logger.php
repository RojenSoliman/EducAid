<?php
// Log file for debugging grades upload issues
$log_file = __DIR__ . '/upload_debug.log';

function log_debug($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Clear previous log
file_put_contents($log_file, '');

log_debug("=== DEBUG LOG INITIALIZED ===");
?>