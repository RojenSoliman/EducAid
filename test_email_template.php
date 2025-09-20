<?php
// Test file to preview the email template
// REMOVE THIS FILE AFTER TESTING

include_once 'includes/email_templates/otp_email_template.php';

// Test data
$test_otp = '123456';
$test_name = 'Juan Dela Cruz';
$test_purpose = 'login'; // or 'password_reset'

// Generate and display the email template
$email_html = generateOTPEmailTemplate($test_otp, $test_name, $test_purpose);

// Output the HTML for preview
echo $email_html;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Email Template Test</title>
    <style>
        body { margin: 0; padding: 20px; background: #f0f0f0; }
        .preview-header {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .preview-header h2 {
            margin: 0 0 10px 0;
            color: #333;
        }
        .preview-note {
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="preview-header">
        <h2>📧 EducAid Email Template Preview</h2>
        <div class="preview-note">
            <strong>Test OTP:</strong> <?php echo $test_otp; ?><br>
            <strong>Test Name:</strong> <?php echo $test_name; ?><br>
            <strong>Test Purpose:</strong> <?php echo $test_purpose; ?><br>
            <em>This is a preview of how your OTP emails will look. Delete this file after testing.</em>
        </div>
    </div>
</body>
</html>