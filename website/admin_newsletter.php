<?php
/**
 * Simple Newsletter Subscribers Viewer
 * This is a basic admin page to view newsletter subscriptions
 */

// Basic authentication (you should implement proper admin authentication)
$admin_password = 'educaid2024'; // Change this to a secure password
$provided_password = $_GET['password'] ?? '';

if ($provided_password !== $admin_password) {
    die('<h1>Access Denied</h1><p>Add ?password=educaid2024 to the URL to access this page.</p>');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Newsletter Subscribers - EducAid Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container my-5">
        <div class="row">
            <div class="col-lg-10 offset-lg-1">
                <h1 class="mb-4">📧 Newsletter Subscribers</h1>
                
                <?php
                $logFile = '../data/newsletter_subscribers.log';
                
                if (file_exists($logFile)) {
                    $logs = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $subscribers = [];
                    
                    foreach ($logs as $log) {
                        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) - Email: ([^\s]+) - IP: (.+)/', $log, $matches)) {
                            $subscribers[] = [
                                'date' => $matches[1],
                                'email' => $matches[2],
                                'ip' => $matches[3]
                            ];
                        }
                    }
                    
                    if (!empty($subscribers)) {
                        echo '<div class="alert alert-info">';
                        echo '<strong>Total Subscribers:</strong> ' . count($subscribers);
                        echo '</div>';
                        
                        echo '<div class="table-responsive">';
                        echo '<table class="table table-striped table-hover">';
                        echo '<thead class="table-dark">';
                        echo '<tr><th>Date & Time</th><th>Email Address</th><th>IP Address</th></tr>';
                        echo '</thead>';
                        echo '<tbody>';
                        
                        // Show most recent first
                        $subscribers = array_reverse($subscribers);
                        
                        foreach ($subscribers as $subscriber) {
                            echo '<tr>';
                            echo '<td>' . htmlspecialchars($subscriber['date']) . '</td>';
                            echo '<td>' . htmlspecialchars($subscriber['email']) . '</td>';
                            echo '<td>' . htmlspecialchars($subscriber['ip']) . '</td>';
                            echo '</tr>';
                        }
                        
                        echo '</tbody>';
                        echo '</table>';
                        echo '</div>';
                        
                        // Export functionality
                        echo '<div class="mt-4">';
                        echo '<a href="?password=' . urlencode($admin_password) . '&export=csv" class="btn btn-success">';
                        echo '<i class="bi bi-download"></i> Export as CSV';
                        echo '</a>';
                        echo '</div>';
                        
                    } else {
                        echo '<div class="alert alert-warning">No valid subscriber entries found in the log file.</div>';
                    }
                    
                } else {
                    echo '<div class="alert alert-warning">No newsletter subscribers yet. The log file will be created when the first person subscribes.</div>';
                }
                
                // Handle CSV export
                if (isset($_GET['export']) && $_GET['export'] === 'csv' && !empty($subscribers)) {
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="newsletter_subscribers_' . date('Y-m-d') . '.csv"');
                    
                    $output = fopen('php://output', 'w');
                    fputcsv($output, ['Date', 'Email', 'IP Address']);
                    
                    foreach ($subscribers as $subscriber) {
                        fputcsv($output, [$subscriber['date'], $subscriber['email'], $subscriber['ip']]);
                    }
                    
                    fclose($output);
                    exit;
                }
                ?>
                
                <div class="mt-5">
                    <h3>Instructions</h3>
                    <ul class="list-group">
                        <li class="list-group-item">
                            <strong>Newsletter Form:</strong> Located in the footer of the landing page with reCAPTCHA v2 protection
                        </li>
                        <li class="list-group-item">
                            <strong>Chatbot Protection:</strong> reCAPTCHA verification kicks in after 5 messages to prevent spam
                        </li>
                        <li class="list-group-item">
                            <strong>Security:</strong> All submissions are verified through Google reCAPTCHA v2
                        </li>
                        <li class="list-group-item">
                            <strong>Data Storage:</strong> Subscribers are logged to <code>data/newsletter_subscribers.log</code>
                        </li>
                    </ul>
                </div>
                
                <div class="mt-4">
                    <a href="../landingpage.php" class="btn btn-primary">← Back to Landing Page</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>