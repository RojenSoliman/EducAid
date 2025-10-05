<?php
require_once __DIR__ . '/../config/database.php';
function dumpColumns($connection, $table) {
    $res = pg_query_params($connection, "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = $1 ORDER BY ordinal_position", [$table]);
    if (!$res) {
        echo "Failed to describe $table: " . pg_last_error($connection) . "\n";
        return;
    }
    echo "Columns for $table:\n";
    while ($row = pg_fetch_assoc($res)) {
        echo " - {$row['column_name']} ({$row['data_type']})\n";
    }
    pg_free_result($res);
    echo "\n";
}

dumpColumns($connection, 'contact_content_blocks');
dumpColumns($connection, 'contact_content_audit');
