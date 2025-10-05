<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/website/contact_content_helper.php';
global $CONTACT_SAVED_BLOCKS;
var_dump($CONTACT_SAVED_BLOCKS['hero_subtitle'] ?? null);
ob_start();
$IS_EDIT_MODE = true;
contact_block('hero_subtitle', "We're here to assist...", 'p', 'lead mb-4');
$html = ob_get_clean();
file_put_contents('dump_output.html', $html);
echo $html;
