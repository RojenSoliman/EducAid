<?php
/**
 * view_error_log.php
 * Quick viewer for PHP error logs
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissions.php';

// Must be logged in as admin
if (!isset($_SESSION['admin_id'])) {
    die('❌ Error: Must be logged in as admin.');
}

$adminRole = getCurrentAdminRole($connection);
if ($adminRole !== 'super_admin') {
    die('❌ Error: Must be super admin.');
}

// Try to find PHP error log
$possibleLogPaths = [
    'C:\xampp\php\logs\php_error_log',
    'C:\xampp\apache\logs\error.log',
    ini_get('error_log'),
    __DIR__ . '/error_log.txt'
];

$logPath = null;
foreach ($possibleLogPaths as $path) {
    if ($path && file_exists($path)) {
        $logPath = $path;
        break;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Log Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .log-container {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 1.5rem;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            max-height: 600px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .log-entry {
            padding: 0.5rem 0;
            border-bottom: 1px solid #333;
        }
        .log-error {
            color: #f48771;
        }
        .log-warning {
            color: #dcdcaa;
        }
        .log-info {
            color: #4fc1ff;
        }
        .log-success {
            color: #4ec9b0;
        }
        .highlight-theme {
            background: #264f78;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row mb-4">
            <div class="col-12">
                <h1>
                    <i class="bi bi-file-earmark-text text-primary me-2"></i>
                    Error Log Viewer
                </h1>
                <p class="text-muted">Last 100 lines - Theme Generator logs highlighted</p>
            </div>
        </div>

        <?php if ($logPath): ?>
            <div class="row mb-3">
                <div class="col-12">
                    <div class="alert alert-info">
                        <strong>Log file:</strong> <code><?= htmlspecialchars($logPath) ?></code>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-12">
                    <button class="btn btn-primary" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise me-2"></i>Refresh Log
                    </button>
                    <a href="debug_theme_generator.php" class="btn btn-secondary">
                        <i class="bi bi-bug me-2"></i>Run Debug Tests
                    </a>
                    <a href="modules/admin/municipality_content.php" class="btn btn-success">
                        <i class="bi bi-palette me-2"></i>Municipality Content
                    </a>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="log-container">
                        <?php
                        // Read last 100 lines
                        $lines = [];
                        $file = new SplFileObject($logPath, 'r');
                        $file->seek(PHP_INT_MAX);
                        $lastLine = $file->key();
                        $startLine = max(0, $lastLine - 100);
                        
                        $file->seek($startLine);
                        while (!$file->eof()) {
                            $line = $file->fgets();
                            if ($line) {
                                $lines[] = $line;
                            }
                        }
                        
                        // Display lines
                        foreach (array_reverse($lines) as $line) {
                            $line = htmlspecialchars($line);
                            
                            // Highlight theme generator logs
                            if (strpos($line, 'THEME GEN') !== false) {
                                $line = '<span class="highlight-theme">' . $line . '</span>';
                            }
                            
                            // Color code by severity
                            $class = 'log-info';
                            if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) {
                                $class = 'log-error';
                            } elseif (stripos($line, 'warning') !== false) {
                                $class = 'log-warning';
                            } elseif (stripos($line, 'success') !== false || stripos($line, 'SUCCESS') !== false) {
                                $class = 'log-success';
                            }
                            
                            echo '<div class="log-entry ' . $class . '">' . $line . '</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-12">
                    <div class="alert alert-secondary">
                        <strong><i class="bi bi-info-circle me-2"></i>Legend:</strong>
                        <span class="log-error">■ Errors</span> |
                        <span class="log-warning">■ Warnings</span> |
                        <span class="log-info">■ Info</span> |
                        <span class="log-success">■ Success</span> |
                        <span class="highlight-theme">■ Theme Generator</span>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="row">
                <div class="col-12">
                    <div class="alert alert-warning">
                        <h5><i class="bi bi-exclamation-triangle me-2"></i>No Error Log Found</h5>
                        <p>Tried the following paths:</p>
                        <ul>
                            <?php foreach ($possibleLogPaths as $path): ?>
                                <li><code><?= htmlspecialchars($path ?: 'null') ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="mb-0">Check your PHP configuration: <code>php.ini</code> → <code>error_log</code> setting</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 5 seconds if user wants
        function enableAutoRefresh() {
            setInterval(() => {
                location.reload();
            }, 5000);
        }
        
        console.log('💡 Tip: Run enableAutoRefresh() in console to auto-refresh logs every 5 seconds');
    </script>
</body>
</html>
