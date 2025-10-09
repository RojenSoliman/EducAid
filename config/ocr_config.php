<?php
// OCR Configuration
define('TESSERACT_PATH', 'C:\Program Files\Tesseract-OCR\tesseract.exe');
define('TESSERACT_DATA_DIR', 'C:\Program Files\Tesseract-OCR\tessdata');

// Validate Tesseract installation
if (!file_exists(TESSERACT_PATH)) {
    error_log('Tesseract executable not found at: ' . TESSERACT_PATH);
}

// OCR Settings
define('OCR_LANGUAGE', 'eng');
define('OCR_DPI', 300);
define('OCR_PSM', 6);

?>

