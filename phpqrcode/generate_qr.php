<?php
// Include the PHP QR code library
// IMPORTANT: Since generate_qr.php is INSIDE the 'phpqrcode' folder,
// we just need to reference 'qrlib.php' directly in the same folder.
include('phpqrcode/qrlib.php');

// Start the session to retrieve the unique_id stored earlier
session_start();

// Enable error reporting for debugging (disable in production)
// error_reporting(E_ALL); // Keep this commented or remove for production
// ini_set('display_errors', 1); // Keep this commented or remove for production

// Check if there is a unique ID stored in the session
if (isset($_SESSION['unique_id'])) {
    $unique_id = $_SESSION['unique_id'];

    // Set the content type header to tell the browser it's an image (PNG)
    header('Content-Type: image/png');
    // Add headers to prevent caching of the image
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Generate the QR code with the unique identifier and output it directly to the browser
    QRcode::png($unique_id, false, QR_ECLEVEL_L, 4, 2);

    // Stop further script execution after sending the image to prevent any extra output
    exit;
} else {
    // If no unique ID is found in the session (e.g., direct access to generate_qr.php,
    // or session expired), output a fallback blank/error image.
    $width = 250;
    $height = 250;
    $im = imagecreatetruecolor($width, $height); // Create a blank image
    $bg_color = imagecolorallocate($im, 240, 240, 240); // Light gray background
    imagefill($im, 0, 0, $bg_color);
    $text_color = imagecolorallocate($im, 100, 100, 100); // Darker gray text
    $font = 3; // Built-in font size
    $text = "No QR Data";
    $text_width = imagefontwidth($font) * strlen($text);
    $text_height = imagefontheight($font);
    $x = ($width - $text_width) / 2;
    $y = ($height - $text_height) / 2;
    imagestring($im, $font, $x, $y, $text, $text_color);

    header('Content-Type: image/png');
    imagepng($im);
    imagedestroy($im);
    exit;
}
?>