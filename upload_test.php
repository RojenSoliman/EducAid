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
    if (isset($_POST['submit'])) {
        $uploadDir = 'uploads/temp/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $files = ['eaf' => $_FILES['eaf'], 'id_card' => $_FILES['id_card']];
        foreach ($files as $label => $file) {
            $tmpName = $file['tmp_name'];
            $name = basename($file['name']);
            $targetPath = $uploadDir . $name;
            move_uploaded_file($tmpName, $targetPath);

            $outputBase = $uploadDir . 'ocr_' . pathinfo($name, PATHINFO_FILENAME);
            shell_exec("tesseract " . escapeshellarg($targetPath) . " " . escapeshellarg($outputBase));

            $ocrResult = @file_get_contents($outputBase . ".txt");

            echo "<div class='preview'>";
            echo "<h3>Extracted from " . strtoupper($label) . ":</h3>";
            echo "<textarea readonly>" . htmlspecialchars($ocrResult) . "</textarea>";
            echo "</div>";
        }
    }
    ?>
</body>
</html>