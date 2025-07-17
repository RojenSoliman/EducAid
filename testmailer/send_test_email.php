<?php
// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader
require 'vendor/autoload.php';

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient = $_POST['recipient'];
    $subject = $_POST['subject'];
    $body = $_POST['body'];

    // Create an instance of PHPMailer
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();                                      // Set mailer to use SMTP
        $mail->Host       = 'smtp.gmail.com';                  // Set SMTP server to Gmail
        $mail->SMTPAuth   = true;                              // Enable SMTP authentication
        $mail->Username   = 'dilucayaka02@gmail.com';            // Your Gmail email address
        $mail->Password   = 'jlld eygl hksj flvg';               // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;    // Use TLS encryption
        $mail->Port       = 587;                               // TCP port to connect to

        // Recipients
        $mail->setFrom('dilucayaka02@gmail.com', 'Test Email');   // Sender email
        $mail->addAddress($recipient);      // Recipient email

        // Content
        $mail->isHTML(true);                                  // Set email format to HTML
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);  // Plain text version for non-HTML mail clients

        // Send the email
        if ($mail->send()) {
            header("Location: test_email.php?status=success");
        } else {
            header("Location: test_email.php?status=error");
        }
    } catch (Exception $e) {
        // Catch errors and display them
        header("Location: test_email.php?status=error");
    }
}
?>
