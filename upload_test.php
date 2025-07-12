<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>OCR Upload Test (Temp Folder)</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 600px; margin: auto; }
        h2 { color: #333; }
        label { display: block; margin-top: 10px; }
        input[type="file"] { margin-bottom: 10px; }
        textarea { width: 100%; height: 150px; margin-top: 10px; }
        .preview { margin-top: 20px; }
    </style>
</head>
<body>
    <h2>OCR Upload to Temp Folder</h2>
    <form action="" method="post" enctype="multipart/form-data">
        <label for="eaf">Upload EAF (JPG/PNG):</label>
        <input type="file" name="eaf" accept="image/*" required>

        <label for="id_card">Upload School ID (JPG/PNG):</label>
        <input type="file" name="id_card" accept="image/*" required>

        <button type="submit" name="submit">Upload & Extract</button>
    </form>

    <?php
$uploadDir = 'assets/uploads/temp/';
if (file_exists($uploadDir)) {
    $files = glob($uploadDir . '*');
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
    }
}

if (isset($_POST['submit'])) {
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

    $files = ['eaf' => $_FILES['eaf'], 'id_card' => $_FILES['id_card']];
    foreach ($files as $label => $file) {
        $tmpName = $file['tmp_name'];
        $name = basename($file['name']);
        $targetPath = $uploadDir . $name;
        move_uploaded_file($tmpName, $targetPath);

        $outputBase = $uploadDir . 'ocr_' . pathinfo($name, PATHINFO_FILENAME);
        $preprocessedPath = $uploadDir . 'pre_' . $name;

        echo "<div class='preview'>";
        echo "<h3>Processing " . strtoupper($label) . ":</h3>";

        $tesseractCheck = shell_exec("tesseract --version 2>&1");
        if (empty($tesseractCheck) || strpos($tesseractCheck, 'tesseract') === false) {
            echo "<p style='color: red;'><strong>Error:</strong> Tesseract is not installed or not in PATH.</p>";
        } else {
            echo "<p style='color: green;'>Tesseract version: " . htmlspecialchars(trim($tesseractCheck)) . "</p>";

            // Use preprocessing only for ID card
            if ($label === 'id_card') {
                $convertCommand = "magick convert " . escapeshellarg($targetPath) . 
                                  " -resize 150% -density 300 -colorspace Gray -contrast-stretch 5%x10% -negate " .
                                  escapeshellarg($preprocessedPath);
                shell_exec($convertCommand);
                $imageForOCR = $preprocessedPath;
                echo "<p><strong>Preprocessing applied for ID card using:</strong><br><code>$convertCommand</code></p>";
            } else {
                $imageForOCR = $targetPath;
                echo "<p><strong>No preprocessing applied for EAF.</strong></p>";
            }

            $command = "tesseract " . escapeshellarg($imageForOCR) . " " . escapeshellarg($outputBase) .
                       " --oem 1 --psm 6 -l eng -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 2>&1";
            $tesseractOutput = shell_exec($command);

            echo "<p><strong>Command executed:</strong> " . htmlspecialchars($command) . "</p>";
            echo "<p><strong>Tesseract output:</strong> " . htmlspecialchars($tesseractOutput) . "</p>";

            $outputFile = $outputBase . ".txt";
            if (file_exists($outputFile)) {
                $ocrResult = file_get_contents($outputFile);
                if (!empty(trim($ocrResult))) {
                    echo "<p style='color: green;'><strong>OCR extraction successful!</strong></p>";
                    echo "<textarea readonly>" . htmlspecialchars($ocrResult) . "</textarea>";
                } else {
                    echo "<p style='color: orange;'><strong>Warning:</strong> OCR ran but returned no text.</p>";
                }
            } else {
                echo "<p style='color: red;'><strong>Error:</strong> OCR output file not found.</p>";
            }
        }

        echo "</div>";
    }
}
?>
</body>
</html>
