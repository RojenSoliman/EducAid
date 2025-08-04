generate_qr.php

<?php
include('phpqrcode/qrlib.php');

if (isset($_GET['data']) && !empty($_GET['data'])) {
    header('Content-Type: image/png');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    QRcode::png($_GET['data'], false, QR_ECLEVEL_L, 4, 2);
    exit;
}

// Fallback: blank image with message
$im = imagecreatetruecolor(250, 250);
$bg = imagecolorallocate($im, 240, 240, 240);
$text = imagecolorallocate($im, 100, 100, 100);
imagefill($im, 0, 0, $bg);
imagestring($im, 3, 70, 115, "No QR Data", $text);
header('Content-Type: image/png');
imagepng($im);
imagedestroy($im);
exit;


