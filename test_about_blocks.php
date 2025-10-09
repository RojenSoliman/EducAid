<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/website/about_content_helper.php';

header('Content-Type: text/plain');
echo "About blocks loaded: " . count($ABOUT_SAVED_BLOCKS) . "\n\n";

if (count($ABOUT_SAVED_BLOCKS) > 0) {
    echo "Sample blocks:\n";
    $count = 0;
    foreach ($ABOUT_SAVED_BLOCKS as $key => $data) {
        echo "- $key: " . substr($data['html'], 0, 50) . "...\n";
        $count++;
        if ($count >= 5) break;
    }
} else {
    echo "No blocks found in database.\n";
}

echo "\nTesting about_block function:\n";
echo about_block('about_hero_title', 'DEFAULT_HERO') . "\n";
