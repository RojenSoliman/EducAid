<?php
/**
 * OCR Debug Script for Grade Documents
 * Debug OCR processing issues with uploaded grade documents
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>OCR Debug Tool for Grade Documents</h1>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_image'])) {
    $uploadedFile = $_FILES['test_image'];
    
    if ($uploadedFile['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/temp_debug/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileName = 'debug_' . time() . '_' . basename($uploadedFile['name']);
        $targetPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
            echo "<h2>File Analysis</h2>";
            echo "<p><strong>File:</strong> $fileName</p>";
            echo "<p><strong>Size:</strong> " . formatBytes(filesize($targetPath)) . "</p>";
            echo "<p><strong>Type:</strong> " . mime_content_type($targetPath) . "</p>";
            
            // Get image dimensions if it's an image
            if (function_exists('getimagesize')) {
                $imageInfo = getimagesize($targetPath);
                if ($imageInfo) {
                    echo "<p><strong>Dimensions:</strong> {$imageInfo[0]} x {$imageInfo[1]} pixels</p>";
                    echo "<p><strong>Image Type:</strong> {$imageInfo['mime']}</p>";
                }
            }
            
            echo "<h2>OCR Processing Tests</h2>";
            
            // Test 1: Basic Tesseract
            echo "<h3>1. Basic Tesseract Test</h3>";
            testBasicTesseract($targetPath, $uploadDir);
            
            // Test 2: Multiple PSM modes
            echo "<h3>2. Multiple PSM Mode Test</h3>";
            testMultiplePSM($targetPath, $uploadDir);
            
            // Test 3: Enhanced OCR Service
            echo "<h3>3. Enhanced OCR Service Test</h3>";
            testEnhancedOCR($targetPath);
            
            // Test 4: Image preprocessing suggestions
            echo "<h3>4. Image Quality Analysis</h3>";
            analyzeImageQuality($targetPath);
            
            echo "<h3>Preview</h3>";
            $webPath = str_replace(__DIR__, '', $targetPath);
            echo "<img src='$webPath' style='max-width: 500px; border: 1px solid #ccc; margin: 10px 0;' />";
            
        } else {
            echo "<p style='color: red;'>Failed to upload file.</p>";
        }
    } else {
        echo "<p style='color: red;'>Upload error: " . $uploadedFile['error'] . "</p>";
    }
}

function testBasicTesseract($imagePath, $tempDir) {
    $outputBase = $tempDir . 'basic_test_' . uniqid();
    $outputFile = $outputBase . '.txt';
    
    $cmd = "tesseract " . escapeshellarg($imagePath) . " " . 
           escapeshellarg($outputBase) . " --oem 1 --psm 6 -l eng 2>&1";
    
    echo "<p><strong>Command:</strong> <code>$cmd</code></p>";
    
    $start = microtime(true);
    $output = shell_exec($cmd);
    $duration = microtime(true) - $start;
    
    echo "<p><strong>Execution time:</strong> " . round($duration, 2) . " seconds</p>";
    
    if (file_exists($outputFile)) {
        $text = file_get_contents($outputFile);
        $textLength = strlen(trim($text));
        
        echo "<p><strong>Status:</strong> <span style='color: green;'>SUCCESS</span></p>";
        echo "<p><strong>Extracted text length:</strong> $textLength characters</p>";
        
        if ($textLength > 0) {
            echo "<div style='border: 1px solid #ccc; padding: 10px; background: #f9f9f9; max-height: 200px; overflow-y: auto;'>";
            echo "<strong>Extracted Text:</strong><br>";
            echo "<pre>" . htmlspecialchars(substr($text, 0, 1000)) . ($textLength > 1000 ? "...\n(truncated)" : "") . "</pre>";
            echo "</div>";
        } else {
            echo "<p style='color: orange;'>No text extracted.</p>";
        }
        
        unlink($outputFile);
    } else {
        echo "<p><strong>Status:</strong> <span style='color: red;'>FAILED</span></p>";
        echo "<p><strong>Error output:</strong> <pre>" . htmlspecialchars($output) . "</pre></p>";
    }
}

function testMultiplePSM($imagePath, $tempDir) {
    $psmModes = [
        3 => 'Fully automatic page segmentation, but no OSD',
        4 => 'Assume a single column of text of variable sizes',
        6 => 'Assume a single uniform block of text',
        7 => 'Treat the image as a single text line',
        8 => 'Treat the image as a single word',
        11 => 'Sparse text. Find as much text as possible',
        13 => 'Raw line. Treat image as single text line, bypassing hacks'
    ];
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>PSM Mode</th><th>Description</th><th>Result</th><th>Text Length</th></tr>";
    
    foreach ($psmModes as $psm => $description) {
        $outputBase = $tempDir . 'psm_' . $psm . '_' . uniqid();
        $outputFile = $outputBase . '.txt';
        
        $cmd = "tesseract " . escapeshellarg($imagePath) . " " . 
               escapeshellarg($outputBase) . " --oem 1 --psm $psm -l eng 2>&1";
        
        $output = shell_exec($cmd);
        
        if (file_exists($outputFile)) {
            $text = file_get_contents($outputFile);
            $textLength = strlen(trim($text));
            $status = $textLength > 0 ? "<span style='color: green;'>SUCCESS</span>" : "<span style='color: orange;'>EMPTY</span>";
            unlink($outputFile);
        } else {
            $textLength = 0;
            $status = "<span style='color: red;'>FAILED</span>";
        }
        
        echo "<tr>";
        echo "<td>$psm</td>";
        echo "<td>$description</td>";
        echo "<td>$status</td>";
        echo "<td>$textLength</td>";
        echo "</tr>";
    }
    
    echo "</table>";
}

function testEnhancedOCR($imagePath) {
    try {
        require_once __DIR__ . '/services/OCRProcessingService.php';
        
        $ocrProcessor = new OCRProcessingService([
            'tesseract_path' => 'tesseract',
            'temp_dir' => __DIR__ . '/temp_debug/',
            'max_file_size' => 10 * 1024 * 1024,
        ]);
        
        $start = microtime(true);
        $result = $ocrProcessor->processGradeDocument($imagePath);
        $duration = microtime(true) - $start;
        
        echo "<p><strong>Execution time:</strong> " . round($duration, 2) . " seconds</p>";
        
        if ($result['success']) {
            echo "<p><strong>Status:</strong> <span style='color: green;'>SUCCESS</span></p>";
            echo "<p><strong>Subjects found:</strong> " . count($result['subjects']) . "</p>";
            
            if (!empty($result['subjects'])) {
                echo "<table border='1' style='border-collapse: collapse;'>";
                echo "<tr><th>Subject</th><th>Grade</th><th>Confidence</th></tr>";
                
                foreach ($result['subjects'] as $subject) {
                    $confColor = $subject['confidence'] >= 85 ? 'green' : 'orange';
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($subject['name']) . "</td>";
                    echo "<td>" . htmlspecialchars($subject['rawGrade']) . "</td>";
                    echo "<td style='color: $confColor;'>" . $subject['confidence'] . "%</td>";
                    echo "</tr>";
                }
                
                echo "</table>";
            }
        } else {
            echo "<p><strong>Status:</strong> <span style='color: red;'>FAILED</span></p>";
            echo "<p><strong>Error:</strong> " . htmlspecialchars($result['error'] ?? 'Unknown error') . "</p>";
        }
        
    } catch (Exception $e) {
        echo "<p><strong>Status:</strong> <span style='color: red;'>ERROR</span></p>";
        echo "<p><strong>Exception:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

function analyzeImageQuality($imagePath) {
    $issues = [];
    $suggestions = [];
    
    // Check file size
    $fileSize = filesize($imagePath);
    if ($fileSize < 100 * 1024) { // Less than 100KB
        $issues[] = "File size is small (" . formatBytes($fileSize) . ")";
        $suggestions[] = "Use higher resolution image (at least 300 DPI)";
    }
    
    // Check image dimensions
    $imageInfo = getimagesize($imagePath);
    if ($imageInfo) {
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        
        if ($width < 800 || $height < 600) {
            $issues[] = "Image dimensions are small ({$width}x{$height})";
            $suggestions[] = "Use larger image dimensions (minimum 800x600)";
        }
        
        // Check aspect ratio
        $aspectRatio = $width / $height;
        if ($aspectRatio < 0.5 || $aspectRatio > 2.0) {
            $issues[] = "Unusual aspect ratio ($aspectRatio)";
            $suggestions[] = "Ensure document fills most of the frame";
        }
    }
    
    // Check file extension
    $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'tiff', 'bmp'])) {
        $issues[] = "Unsupported file format ($extension)";
        $suggestions[] = "Use JPG, PNG, or TIFF format";
    }
    
    if (empty($issues)) {
        echo "<p style='color: green;'><strong>✓ Image quality looks good!</strong></p>";
    } else {
        echo "<p><strong>Potential Issues:</strong></p><ul>";
        foreach ($issues as $issue) {
            echo "<li style='color: orange;'>$issue</li>";
        }
        echo "</ul>";
        
        echo "<p><strong>Suggestions:</strong></p><ul>";
        foreach ($suggestions as $suggestion) {
            echo "<li>$suggestion</li>";
        }
        echo "</ul>";
    }
    
    // General OCR tips
    echo "<p><strong>General OCR Tips:</strong></p>";
    echo "<ul>";
    echo "<li>Ensure good lighting and contrast</li>";
    echo "<li>Keep text horizontal (not rotated)</li>";
    echo "<li>Avoid shadows and reflections</li>";
    echo "<li>Use clean, unfolded documents</li>";
    echo "<li>Minimum text size should be 12pt equivalent</li>";
    echo "</ul>";
}

function formatBytes($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, 2) . ' ' . $units[$i];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>OCR Debug Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        pre { background: #f9f9f9; padding: 10px; border: 1px solid #ccc; }
        .upload-form { background: #f0f8ff; padding: 20px; border: 1px solid #0066cc; margin: 20px 0; }
    </style>
</head>
<body>

<div class="upload-form">
    <h2>Upload Test Image</h2>
    <form method="POST" enctype="multipart/form-data">
        <p>Upload the same image that's failing in the grade validation:</p>
        <input type="file" name="test_image" accept="image/*,.pdf" required>
        <br><br>
        <input type="submit" value="Analyze OCR Processing" style="background: #0066cc; color: white; padding: 10px 20px; border: none; cursor: pointer;">
    </form>
</div>

</body>
</html>