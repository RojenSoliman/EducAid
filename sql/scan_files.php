<?php
// Quick scan to see what files need updating
require_once 'update_code.php';

$updater = new CodeUpdater();
$files = $updater->scanForFiles();

echo "Found files that need updating:\n";
foreach ($files as $file) {
    echo "- " . $file . "\n";
}
?>